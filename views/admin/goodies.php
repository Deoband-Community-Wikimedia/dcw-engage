<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/engage_page.php';
require_once __DIR__ . '/../../models/MemberModel.php';
require_once __DIR__ . '/../../models/MemberStrikeModel.php';
require_once __DIR__ . '/../../models/PointsModel.php';

/**
 * DCW Engage - goodies, reviewer side (/admin/goodies).
 * support_reviewer and owner only (the same roles as internet_review.php).
 *
 * Five tabs, kept in the address as ?tab=:
 *   award        award points (one person, or several: pick > preview > confirm), approve large awards
 *                (one by one or in bulk), Conversation Hour, take points back
 *   guide        read-only "what earns points" view of every active activity and its points
 *   activities   what can earn points and how many; changing points revises awards already made (#208).
 *                "Edit points in bulk" (?mode=bulk) edits many at once: review first, then one transaction.
 *   catalogue    add and edit goodies, their point costs and (optionally) their stock
 *   redemptions  the order queue: Requested > Ordered > Shipped > Delivered (or Cancelled, which refunds
 *                the points and puts the item back in stock)
 *
 * Stock (catalogue tab): optional per goody. "Track stock" off = unlimited. On = one number, or one number
 * per size when the goody has sizes/options. Stored in goodies.stock_json; see PointsModel::decodeStock().
 *
 * Owner discretion (one rule, applied the same way everywhere on this page):
 *   - Awards made by an owner (to anyone) are approved when created and never wait for a second reviewer.
 *     An owner awarding their own Member ID is logged as goodies.award_self.
 *   - An owner may approve ANY award waiting for a second reviewer, one by one or in bulk, including one they
 *     created and one made to their own Member ID. The page passes $isOwner to PointsModel::approveAward() and
 *     approveAwards(); an owner approving an award they created is logged as goodies.approve_self.
 *   - A support_reviewer who is not an owner is approved at once under PointsModel::SECOND_APPROVAL_AT; a larger
 *     award waits for a different reviewer. A non-owner can never award, approve or take back points for their
 *     own Member ID, and can never approve an award they created.
 * Every state-changing action is a CSRF-checked POST and is written to audit_log.
 *
 * Bulk award flow (award tab, "Several people"):
 *   1. bulk_preview  - the typed/pasted people and one activity go to PointsModel::bulkPlan(). Nothing is saved.
 *                      The page re-renders with the plan and a one-time batch token.
 *   2. bulk_confirm  - the SAME text is planned again on the server (the browser never sends a ready-list we
 *                      would have to trust), then PointsModel::bulkAward() saves it under that token. The token
 *                      is the batch reference, so a double click or a replayed form cannot award anyone twice.
 *
 * Conversation Hour (award tab): attendance logged in Community records is awarded per session with
 * "Award now" (PointsModel::awardForSession), and hosts are set here. "Add other attendees" sends people who
 * attended but were not logged through the same bulk preview. Re-running Award now only adds what is new.
 *
 * Layout note: the activities and catalogue tabs list one compact row per item; "Edit" opens the form
 * for that row in place (<details>).
 */
Auth::requireLogin();
// Signed in but not a support reviewer or owner: 403, the same way Auth::requireOwner() treats a non-owner.
if (!Auth::hasAnyRole(['support_reviewer', 'owner'])) {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">'
       . '<meta name="robots" content="noindex, nofollow"><title>Not allowed - DCW Engage</title></head><body '
       . 'style="font-family: Inter, -apple-system, sans-serif; background:#f8fafc; color:#1e293b; display:flex; align-items:center; '
       . 'justify-content:center; min-height:100vh; margin:0; padding:20px;"><div style="max-width:420px; text-align:center;">'
       . '<h1 style="color:#106b9a; font-size:20px; margin:0 0 8px;">Not allowed</h1>'
       . '<p style="color:#64748b; font-size:14px; line-height:1.6; margin:0 0 20px;">Goodies are managed by support reviewers. Ask an owner if you need access.</p>'
       . '<a href="/admin/dashboard" style="color:#106b9a; font-size:14px;">Back to workspace</a></div></body></html>';
    exit;
}

$actor = strtolower((string) Auth::email());
$model = new PointsModel();
$isOwner = Auth::isOwner();

// The reviewer's own Member IDs. A non-owner leaves Conversation Hour awards for these to another reviewer
// and cannot approve awards made to them; an owner may do all of it (and every owner award skips second approval).
$ownIds = [];
try { $ownIds = array_column((new MemberModel())->membershipsForEmail($actor), 'member_id'); } catch (Throwable $e) { app_log_ignored('goodies.php:own', $e); }
$planOwn = $isOwner ? [] : $ownIds;   // what chPlan() and approveAwards() should treat as "left for another reviewer"

const GOODIES_TABS = ['award' => 'Award points', 'guide' => 'Points guide', 'activities' => 'Activities and points', 'catalogue' => 'Catalogue', 'redemptions' => 'Orders'];
$tab = isset(GOODIES_TABS[$_GET['tab'] ?? '']) ? (string) $_GET['tab'] : 'award';

function goodies_flash(string $kind, string $text): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
    $_SESSION['goodies_flash'] = [$kind, $text];
}

/** What a finished bulk award shows on the next page load (the celebration panel). */
function goodies_bulk_result(array $result): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
    $_SESSION['goodies_bulk_result'] = $result;
}

function gd_h($s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES);
}

/** The members rows for a Member ID, or [] when it is malformed or unknown. */
function goodies_find_member(string $raw): array
{
    $id = strtoupper(trim($raw));
    if (MemberModel::chapterFromMemberId($id) === null) return [];
    try { return (new MemberModel())->membershipsFor($id); } catch (Throwable $e) { app_log_ignored('goodies.php:member', $e); return []; }
}

/**
 * What a reviewer typed (a name, a verified Wikimedia username or a Member ID) as [memberships, error].
 * The error says why nobody was picked, listing the candidates when the name is shared or only partly matches.
 */
function goodies_lookup(PointsModel $model, string $typed): array
{
    $hit = $model->findMember($typed);
    if (!isset($hit['member_id'])) return [[], $hit['error']];
    $rows = goodies_find_member($hit['member_id']);
    return $rows ? [$rows, ''] : [[], 'No member was found for "' . trim($typed) . '".'];
}

/** "Full Name (A48213977)" for messages, so the reviewer can see who was picked. */
function goodies_who(array $memberships): string
{
    $m = $memberships[0];
    return trim((string) $m['full_name']) . ' (' . $m['member_id'] . ')';
}

/** True when the signed-in reviewer holds one of these memberships (any email on the rows is theirs). */
function goodies_is_own(array $memberships): bool
{
    foreach ($memberships as $m) if (Auth::isOwnEmail($m['email'] ?? '')) return true;
    return false;
}

/** The status of every award saved under a bulk batch token: [MEMBER ID => status]. */
function goodies_batch_outcome(string $token): array
{
    $ref = 'bulk-' . substr((string) preg_replace('/[^a-z0-9]/i', '', $token), 0, 24);
    $st = DB::getInstance()->getConnection()->prepare('SELECT member_id, status FROM point_awards WHERE source_ref = :r');
    $st->execute([':r' => $ref]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) $out[strtoupper((string) $row['member_id'])] = (string) $row['status'];
    return $out;
}

/** "AR" for "Asha Rao": first and last initial. */
function gd_initials(string $name): string
{
    $parts = array_values(array_filter(preg_split('/\s+/u', trim($name)) ?: []));
    if (!$parts) return '?';
    $first = mb_substr($parts[0], 0, 1);
    $last = count($parts) > 1 ? mb_substr($parts[count($parts) - 1], 0, 1) : '';
    return mb_strtoupper($first . $last);
}

/** One emoji per activity: a few built-ins by code, otherwise by category. */
function gd_emoji(array $t): string
{
    $byCode = ['ch_attend' => '🎙️', 'ch_host' => '🎤', 'taw_graduate' => '🎓'];
    $byCat = ['learn' => '📚', 'contribute' => '✍️', 'community' => '🤝', 'organisation' => '🗂️'];
    return $byCode[(string) ($t['code'] ?? '')] ?? $byCat[(string) ($t['category'] ?? '')] ?? '🎯';
}

/** A titled group of items in the bulk preview. Items are already-escaped HTML. Prints nothing when empty. */
function gd_group(string $title, int $hue, array $items, string $hint = ''): void
{
    if (!$items) return;
    echo '<div class="bk-group" style="--h:' . $hue . '"><h4>' . gd_h($title) . ' <span>' . count($items) . '</span></h4>';
    if ($hint !== '') echo '<p class="gd-note" style="margin:0 0 8px">' . gd_h($hint) . '</p>';
    echo '<ul>';
    foreach ($items as $li) echo '<li>' . $li . '</li>';
    echo '</ul></div>';
}

// ---- Actions -------------------------------------------------------------------------------------
$noRedirect = false;      // the two previews (bulk award, bulk points) render in place instead of redirecting
$bulk = null;             // bulk award preview: plan, the text, the activity, the note, the shared evidence, the batch token
$pointsPreview = null;    // bulk points preview: previewPointChanges() output plus what the reviewer typed
$pointsTyped = [];        // [activity id => ['points' => .., 'below' => ..]] as typed, to refill the table

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CSRF::validate($_POST['csrf_token'] ?? '')) {
        die('Invalid CSRF token.');
    }
    $do   = (string) ($_POST['do'] ?? '');
    $back = $tab;
    try {
        if ($do === 'award' || $do === 'reverse') {
            $back = 'award';
            [$found, $notFound] = goodies_lookup($model, (string) ($_POST['member_id'] ?? ''));
            $mid   = $found ? (string) $found[0]['member_id'] : '';
            if (!$found) {
                goodies_flash('error', $notFound);
            } elseif ($do === 'award') {
                $self = goodies_is_own($found);
                if ($self && !$isOwner) {
                    goodies_flash('error', 'You cannot award points to yourself. Ask another reviewer or an owner.');
                } elseif ((new MemberStrikeModel())->isStruck($mid)) {
                    goodies_flash('error', 'That member is struck off, so points cannot be awarded.');
                } else {
                    // An owner's awards (to anyone) skip the second-approval rule. Owner awards to their own
                    // Member ID are logged as goodies.award_self.
                    $r = $model->createAward($mid, (int) ($_POST['activity_type_id'] ?? 0),
                        trim((string) ($_POST['evidence_url'] ?? '')), trim((string) ($_POST['note'] ?? '')), $actor, $isOwner, $self);
                    goodies_flash($r['ok'] ? 'ok' : 'error', $r['ok']
                        ? ($r['status'] === 'Approved' ? '🎉 ' . $r['points'] . ' points awarded to ' . goodies_who($found) . '.' : 'Saved for ' . goodies_who($found) . '. This award is ' . $r['points'] . ' points, so a different reviewer must approve it before it counts.')
                        : $r['error']);
                }
            } else {
                if (goodies_is_own($found) && !$isOwner) {
                    goodies_flash('error', 'You cannot change your own points. Ask another reviewer or an owner.');
                } else {
                    $r = $model->reverse($mid, (int) ($_POST['points'] ?? 0), (string) ($_POST['reason'] ?? ''), $actor);
                    goodies_flash($r['ok'] ? 'ok' : 'error', $r['ok'] ? 'Points taken back from ' . goodies_who($found) . '.' : $r['error']);
                }
            }
        } elseif ($do === 'bulk_preview' || $do === 'bulk_confirm') {
            $back = 'award';
            $tab = 'award';
            $typeId = (int) ($_POST['activity_type_id'] ?? 0);
            $note   = trim((string) ($_POST['note'] ?? ''));
            $shared = trim((string) ($_POST['shared_evidence'] ?? ''));

            if ($do === 'bulk_preview') {
                // The chips (one hidden input each) and the pasted list become one text, one entry per line.
                $parts = [];
                foreach ((array) ($_POST['chips'] ?? []) as $c) {
                    if (!is_scalar($c)) continue;
                    $c = trim((string) preg_replace('/\s+/u', ' ', (string) $c));
                    if ($c !== '') $parts[] = $c;
                }
                $text = trim(implode("\n", $parts) . "\n" . (string) ($_POST['paste'] ?? ''));
            } else {
                $text = trim((string) ($_POST['lines'] ?? ''));
            }
            // Planned again on every step, including confirm: the browser never tells us who is ready.
            $plan = $model->bulkPlan(PointsModel::parseBulkLines($text), $typeId, $shared, $ownIds, $isOwner);

            if ($do === 'bulk_preview') {
                $bulk = ['plan' => $plan, 'lines' => $text, 'type' => $typeId, 'note' => $note, 'evidence' => $shared, 'token' => bin2hex(random_bytes(12))];
                $noRedirect = true;
            } else {
                $token = (string) ($_POST['token'] ?? '');
                if (!preg_match('/^[a-f0-9]{16,48}$/', $token)) {
                    goodies_flash('error', 'That form has expired. Preview the award again.');
                } elseif (!empty($plan['error'])) {
                    goodies_flash('error', $plan['error']);
                } elseif (!$plan['ready']) {
                    goodies_flash('error', 'Nobody is ready to be awarded.');
                } else {
                    $r = $model->bulkAward($plan['ready'], $typeId, $note, $actor, $isOwner, $token);
                    if (!$r['ok']) {
                        goodies_flash('error', $r['error'] ?? 'Could not save the award.');
                    } elseif ($r['awarded'] + $r['pending'] === 0) {
                        goodies_flash('error', 'Nobody new was added. If you clicked twice, the first click already saved this batch.');
                    } else {
                        $ok = $pend = $skip = [];
                        try {
                            $got = goodies_batch_outcome($token);
                            foreach ($plan['ready'] as $rd) {
                                $m = strtoupper((string) $rd['member_id']);
                                if (!isset($got[$m])) $skip[] = (string) $rd['name'];
                                elseif ($got[$m] === 'Approved') $ok[] = (string) $rd['name'];
                                else $pend[] = (string) $rd['name'];
                            }
                        } catch (Throwable $e) { app_log_ignored('goodies.php:bulk_outcome', $e); }
                        goodies_bulk_result([
                            'activity' => (string) ($plan['type']['name'] ?? ''), 'each' => (int) $r['each'],
                            'awarded' => (int) $r['awarded'], 'pending' => (int) $r['pending'], 'skipped' => (int) $r['skipped'],
                            'ok' => $ok, 'pend' => $pend, 'skip' => $skip,
                        ]);
                        goodies_flash('ok', 'Saved.');
                    }
                }
            }
        } elseif ($do === 'approve' || $do === 'reject') {
            $back = 'award';
            $awardId = (int) ($_POST['award_id'] ?? 0);
            $award   = $model->award($awardId);
            $owner   = $award ? goodies_find_member((string) $award['member_id']) : [];
            if ($do === 'approve' && $owner && goodies_is_own($owner) && !$isOwner) {
                goodies_flash('error', 'You cannot approve points awarded to yourself.');
            } elseif ($do === 'approve') {
                // $isOwner lets an owner approve an award they created, or one made to their own Member ID.
                // The model still refuses a non-owner who created the award.
                $r = $model->approveAward($awardId, $actor, $isOwner);
                $ownerSelf = $isOwner && $award && (strcasecmp((string) $award['first_reviewer_email'], $actor) === 0 || ($owner && goodies_is_own($owner)));
                goodies_flash($r['ok'] ? 'ok' : 'error', $r['ok']
                    ? 'Approved. The points are now in the member’s balance.' . ($ownerSelf ? ' Logged as an owner approval of your own award.' : '')
                    : $r['error']);
            } else {
                goodies_flash($model->rejectAward($awardId, $actor) ? 'ok' : 'error', 'Award rejected.');
            }
        } elseif ($do === 'bulk_approve' || $do === 'bulk_reject') {
            $back = 'award';
            $ids = array_values(array_filter(array_map('intval', (array) ($_POST['award_ids'] ?? []))));
            if (!$ids) {
                goodies_flash('error', 'Tick at least one award first.');
            } elseif ($do === 'bulk_approve') {
                // $planOwn: a non-owner cannot approve points awarded to their own Member ID (an owner passes none).
                // $isOwner: an owner may also approve awards they created themselves.
                $r = $model->approveAwards($ids, $actor, $planOwn, $isOwner);
                $why = $isOwner ? 'already decided' : 'your own, one you created, or already decided';
                goodies_flash($r['approved'] > 0 ? 'ok' : 'error',
                    $r['approved'] . ' approved.' . ($r['skipped'] ? ' ' . $r['skipped'] . ' skipped (' . $why . ').' : ''));
            } else {
                $r = $model->rejectAwards($ids, $actor);
                goodies_flash($r['rejected'] > 0 ? 'ok' : 'error',
                    $r['rejected'] . ' rejected.' . ($r['skipped'] ? ' ' . $r['skipped'] . ' skipped (already decided).' : ''));
            }
        } elseif ($do === 'ch_award') {
            $back = 'award';
            $r = $model->awardForSession((int) ($_POST['session_id'] ?? 0), $actor, $planOwn, $isOwner);
            if (!$r['ok']) {
                goodies_flash('error', $r['error']);
            } else {
                $p = $r['plan'];
                $msg = $r['awarded'] === 0 ? 'Nothing new to award. All caught up.' : ($r['awarded'] === 1 ? '🎉 1 award made.' : '🎉 ' . $r['awarded'] . ' awards made.');
                if ($p['own'])        $msg .= ' Your own award is left for another reviewer.';
                if ($p['struck'])     $msg .= ' Skipped ' . count($p['struck']) . ' struck-off member(s).';
                if ($p['unresolved']) $msg .= ' ' . count($p['unresolved']) . ' attendee(s) could not be matched to a member.';
                goodies_flash('ok', $msg);
            }
        } elseif ($do === 'ch_host_add') {
            $back = 'award';
            [$found, $notFound] = goodies_lookup($model, (string) ($_POST['member_id'] ?? ''));
            if (!$found) {
                goodies_flash('error', $notFound);
            } else {
                $r = $model->addChHost((int) ($_POST['session_id'] ?? 0), (string) $found[0]['member_id'], $actor);
                goodies_flash($r['ok'] ? 'ok' : 'error', $r['ok'] ? goodies_who($found) . ' saved as host. Use Award now to award them.' : $r['error']);
            }
        } elseif ($do === 'ch_host_remove') {
            $back = 'award';
            $model->removeChHost((int) ($_POST['session_id'] ?? 0), (string) ($_POST['member_id'] ?? ''), $actor);
            goodies_flash('ok', 'Host removed. Points already awarded stay; take them back below if needed.');
        } elseif ($do === 'activity_save') {
            $back = 'activities';
            $r = $model->saveActivity(((int) ($_POST['id'] ?? 0)) ?: null, $_POST, $actor);
            if (!$r['ok']) {
                goodies_flash('error', $r['error']);
            } elseif ($r['revised'] > 0) {
                goodies_flash('ok', 'Saved. ' . $r['revised'] . ' award(s) revised to the new points'
                    . ($r['members'] ? ', changing ' . $r['members'] . ' member(s)\' balance by ' . sprintf('%+d', $r['change']) . ' points in total' : '') . '.');
            } else {
                goodies_flash('ok', 'Saved.');
            }
        } elseif ($do === 'points_preview' || $do === 'points_save') {
            $back = 'activities';
            $tab = 'activities';
            // [activity id => ['points' => int, 'below' => ?int]]. The model validates and ignores unchanged rows.
            $ch = [];
            foreach ((array) ($_POST['changes'] ?? []) as $cid => $c) {
                if (!is_array($c)) continue;
                $below = isset($c['below']) && is_scalar($c['below']) && trim((string) $c['below']) !== '' ? (int) $c['below'] : null;
                $ch[(int) $cid] = ['points' => (int) ($c['points'] ?? 0), 'below' => $below];
            }
            if ($do === 'points_preview') {
                $pointsTyped = $ch;
                $pointsPreview = $model->previewPointChanges($ch);
                $noRedirect = true;
            } elseif (empty($_POST['understood'])) {
                goodies_flash('error', 'Tick the box to confirm that past awards will be revised.');
            } else {
                $r = $model->savePointChanges($ch, $actor);
                goodies_flash($r['ok'] ? 'ok' : 'error', $r['ok']
                    ? 'Saved. ' . $r['activities'] . ' activit' . ($r['activities'] === 1 ? 'y' : 'ies') . ' updated, ' . $r['revised'] . ' award(s) revised across '
                      . $r['members'] . ' member(s), ' . sprintf('%+d', $r['change']) . ' points in total.'
                    : $r['error']);
            }
        } elseif ($do === 'activity_delete') {
            $back = 'activities';
            $r = $model->deleteActivity((int) ($_POST['id'] ?? 0), $actor);
            goodies_flash($r['ok'] ? 'ok' : 'error', $r['ok'] ? 'Activity deleted.' : $r['error']);
        } elseif ($do === 'save_goody') {
            $back = 'catalogue';
            // Stock: null = not tracked. Otherwise [size => qty], or [STOCK_ALL => qty] for a goody with no sizes.
            // The model keeps only the sizes that are in the "Sizes or options" field.
            $stock = null;
            if (!empty($_POST['track_stock'])) {
                $stock = [];
                $names = (array) ($_POST['stock_name'] ?? []);
                foreach ((array) ($_POST['stock_qty'] ?? []) as $i => $q) {
                    $k = is_scalar($names[$i] ?? null) ? trim((string) $names[$i]) : '';
                    $stock[$k === '' ? PointsModel::STOCK_ALL : $k] = max(0, (int) $q);
                }
            }
            $r = $model->saveGoody(((int) ($_POST['id'] ?? 0)) ?: null, (string) ($_POST['name'] ?? ''), (string) ($_POST['description'] ?? ''),
                (int) ($_POST['points_cost'] ?? 0), (string) ($_POST['variants'] ?? ''), !empty($_POST['is_active']), $actor, $stock);
            goodies_flash($r['ok'] ? 'ok' : 'error', $r['ok'] ? 'Catalogue updated.' : $r['error']);
        } elseif ($do === 'order_status') {
            $back = 'redemptions';
            $r = $model->setRedemptionStatus((int) ($_POST['id'] ?? 0), (string) ($_POST['to'] ?? ''), $actor);
            goodies_flash($r['ok'] ? 'ok' : 'error', $r['ok'] ? 'Order updated.' : $r['error']);
        }
    } catch (Throwable $e) {
        app_log('Goodies admin action failed: ' . $e->getMessage());
        goodies_flash('error', 'Something went wrong. Please try again.');
        $noRedirect = false;
        $bulk = $pointsPreview = null;
    }
    if (!$noRedirect) {
        header('Location: /admin/goodies?tab=' . $back);
        exit;
    }
}

$flash = null;
$bulkResult = null;
if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
if (!empty($_SESSION['goodies_flash'])) { $flash = $_SESSION['goodies_flash']; unset($_SESSION['goodies_flash']); }
if (!empty($_SESSION['goodies_bulk_result'])) { $bulkResult = $_SESSION['goodies_bulk_result']; unset($_SESSION['goodies_bulk_result']); }
if ($bulkResult !== null && $flash !== null && $flash[0] === 'ok') $flash = null;   // the celebration panel replaces "Saved."

// ---- Data for the page ---------------------------------------------------------------------------
$totals = ['outstanding' => 0, 'awarded' => 0, 'spent' => 0, 'awaiting_second' => 0, 'requested' => 0];
$types = $awaiting = $goodies = $orders = [];
$statusFilter = (string) ($_GET['status'] ?? '');
try {
    $totals   = $model->totals();
    $types    = $model->activityTypes();
    $awaiting = $model->awardsAwaitingSecond();
    $goodies  = $model->goodies(false);
    $orders   = $tab === 'redemptions' ? $model->redemptionQueue($statusFilter) : [];
} catch (Throwable $e) {
    app_log('Goodies admin: load failed: ' . $e->getMessage());
    $flash = ['error', 'The goodies tables are not set up yet. Run database_goodies.sql first.'];
}

// Every activity, switched on or off, with how often each has been awarded (activities tab).
$allTypes = $usage = [];
$bulkMode = $tab === 'activities' && (($_GET['mode'] ?? '') === 'bulk' || $pointsPreview !== null);
if ($tab === 'activities') {
    try {
        $allTypes = $model->activityTypes(false);
        $usage    = $model->activityUsage();
    } catch (Throwable $e) { app_log_ignored('goodies.php:activities', $e); }
}

// Type-ahead for the member fields (award tab): every member's name, verified username and Member ID.
// The fields also accept any of the three typed by hand; see goodies_lookup().
$directory = [];
if ($tab === 'award') {
    try { $directory = $model->memberDirectory(); } catch (Throwable $e) { app_log_ignored('goodies.php:directory', $e); }
}

// Conversation Hour sessions with what "Award now" would do for each (award tab only).
$chRows = [];
$chError = '';
if ($tab === 'award') {
    try {
        foreach ($model->chSessions(12) as $sess) {
            $chRows[] = ['session' => $sess, 'plan' => $model->chPlan((int) $sess['id'], $planOwn)];
        }
    } catch (Throwable $e) {
        app_log_ignored('goodies.php:ch', $e);
        $chError = 'Conversation Hour awards need the migration 2026_10_27_goodies_ch_awards.sql.';
    }
}
// The activity "Add other attendees" awards: the Conversation Hour attend activity, when it is switched on.
$chAttendId = 0;
foreach ($types as $t) if ((string) $t['code'] === 'ch_attend') $chAttendId = (int) $t['id'];

$ist = new DateTimeZone('Asia/Kolkata');
$when = fn($ts) => $ts ? (new DateTimeImmutable((string) $ts, new DateTimeZone('UTC')))->setTimezone($ist)->format('j M Y, g:i a') : '';

// One hue per category, in the order PointsModel::CATEGORIES lists them. Drives the colour bar and the points pill.
$gdHues = [205, 165, 28, 275, 340, 48, 190];
$gdCatKeys = array_keys(PointsModel::CATEGORIES);
$gdHue = function (string $cat) use ($gdCatKeys, $gdHues): int {
    $i = array_search($cat, $gdCatKeys, true);
    return $gdHues[($i === false ? count($gdCatKeys) : $i) % count($gdHues)];
};

/**
 * Activity tiles (a radio group): colour-coded by category, with an emoji and a points pill.
 * $prefix keeps the ids unique when the picker appears twice on the page.
 */
$gdTiles = function (array $list, string $prefix, int $selected = 0) use ($gdHue, $gdCatKeys, $isOwner): void {
    $by = [];
    foreach ($list as $t) $by[(string) $t['category']][] = $t;
    $order = array_values(array_unique(array_merge(array_intersect($gdCatKeys, array_keys($by)), array_keys($by))));
    if (!$by) { echo '<div class="gd-empty">No activities are switched on yet.</div>'; return; }
    echo '<div class="tl-wrap" role="radiogroup" aria-label="Activity">';
    foreach ($order as $cat) {
        echo '<div class="tl-cat" style="--h:' . $gdHue($cat) . '"><div class="tl-head">' . gd_h(PointsModel::CATEGORIES[$cat] ?? $cat) . '</div><div class="tl-grid">';
        foreach ($by[$cat] as $t) {
            $id = (int) $t['id']; $pts = (int) $t['points'];
            $second = !$isOwner && $pts >= PointsModel::SECOND_APPROVAL_AT;
            echo '<label class="tl"><input type="radio" name="activity_type_id" value="' . $id . '" data-points="' . $pts . '" data-second="' . ($second ? 1 : 0) . '"'
               . ($selected === $id ? ' checked' : '') . ' required id="' . gd_h($prefix . '-' . $id) . '">'
               . '<span class="tl-emoji" aria-hidden="true">' . gd_emoji($t) . '</span>'
               . '<span class="tl-body"><span class="tl-name">' . gd_h($t['name']) . '</span><span class="tl-tags">'
               . ($second ? '<span class="ac-tag warn">2nd approval</span>' : '')
               . ((int) $t['needs_evidence'] ? '<span class="ac-tag">Link needed</span>' : '')
               . ((int) ($t['one_time'] ?? 0) ? '<span class="ac-tag">Once</span>' : '')
               . '</span></span><span class="tl-pts">+' . number_format($pts) . '</span></label>';
        }
        echo '</div></div>';
    }
    echo '</div>';
};

/** The "Track stock" toggle and one number per size (or one number when there are no sizes). */
$gdStock = function (?array $g): void {
    $stock = $g['stock'] ?? null;
    $variants = $g['variants'] ?? [];
    $rows = $variants ?: [''];
    echo '<div class="wide gd-stock"><label class="gd-tg"><input type="checkbox" name="track_stock" value="1" class="st-track"' . ($stock !== null ? ' checked' : '') . '> Track stock</label>'
       . '<div class="st-rows"' . ($stock === null ? ' hidden' : '') . '>';
    foreach ($rows as $v) {
        $key = $v === '' ? PointsModel::STOCK_ALL : $v;
        echo '<label class="st-row"><span>' . gd_h($v === '' ? 'In stock' : $v) . '</span>'
           . '<input type="hidden" name="stock_name[]" value="' . gd_h($v) . '">'
           . '<input type="number" name="stock_qty[]" min="0" max="1000000" value="' . (int) ($stock[$key] ?? 0) . '"></label>';
    }
    echo '</div><p class="gd-note st-help" style="margin:6px 0 0">Members see sold-out goodies and sizes as out of stock and cannot order them. Leave this off for unlimited.</p></div>';
};

engage_header([
    'title'   => 'Goodies',
    'heading' => 'Goodies',
    'lead'    => 'Award points, manage the catalogue and fulfil orders.',
    'slim'    => true,
    'wide'    => true,
    'crumbs'  => [],
    'tools'   => '',
]);
?>
<style>
    [hidden] { display: none !important; }
    .gd-nav { display: flex; flex-wrap: wrap; gap: 8px; margin: 0 0 20px; }
    .gd-nav a { display: inline-flex; align-items: center; padding: 8px 18px; border-radius: 999px; border: 1px solid var(--border); background: var(--card); color: var(--ink); font-weight: 600; font-size: 14px; text-decoration: none; transition: border-color .15s, background .15s; }
    .gd-nav a:hover { border-color: var(--primary); }
    .gd-nav a.on { background: var(--primary); border-color: var(--primary); color: #fff; }
    .gd-badge { margin-left: 8px; padding: 1px 8px; border-radius: 999px; background: #e11d48; color: #fff; font-size: 12px; font-weight: 700; font-variant-numeric: tabular-nums; }

    .gd-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 12px; margin: 0 0 24px; }
    .gd-stat { position: relative; padding: 16px 18px; background: var(--card); border: 1px solid var(--border); border-radius: 14px; overflow: hidden; }
    .gd-stat::before { content: ""; position: absolute; inset: 0 auto 0 0; width: 4px; background: hsl(var(--h, 205) 65% 48%); }
    .gd-stat b { display: block; font-size: 26px; line-height: 1.15; font-variant-numeric: tabular-nums; }
    .gd-stat span { color: var(--muted); font-size: 13px; }

    .gd-card { margin: 0 0 22px; padding: 20px; background: var(--card); border: 1px solid var(--border); border-radius: 16px; }
    .gd-card h3 { margin: 0 0 12px; font-size: 17px; }

    /* Form grid. Labels are columns (text on top, field at the bottom), so a long label that wraps
       no longer pushes its neighbours out of line; the button cell sits on the bottom edge. */
    .gd-form { display: grid; gap: 14px 12px; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); align-items: stretch; }
    .gd-form > div:not(.wide):not(.ac-flags):not(.ac-foot) { align-self: end; }
    .gd-form label { display: flex; flex-direction: column; gap: 4px; font-size: 13px; font-weight: 600; }
    .gd-form label small { display: block; color: var(--muted); font-weight: 400; font-size: 12px; }
    .gd-form label > input, .gd-form label > select, .gd-form label > textarea { margin-top: auto; }
    .gd-form input[type=text], .gd-form input[type=number], .gd-form input[type=url], .gd-form select, .gd-form textarea { width: 100%; box-sizing: border-box; padding: 9px 11px; font: inherit; font-weight: 400; border: 1px solid var(--border); border-radius: 10px; background: #fff; }
    .gd-form textarea { resize: vertical; min-height: 84px; }
    .gd-form input:focus-visible, .gd-form select:focus-visible, .gd-form textarea:focus-visible, .gd-btn:focus-visible, .ac > summary:focus-visible { outline: 2px solid var(--primary); outline-offset: 2px; }
    .gd-form .wide { grid-column: 1 / -1; }

    .gd-btn { padding: 9px 18px; font: inherit; font-weight: 600; font-size: 14px; border: 1px solid var(--primary); border-radius: 999px; background: var(--primary); color: #fff; cursor: pointer; }
    .gd-btn.line { background: #fff; color: var(--primary); }
    .gd-btn.red { background: #fff; color: #97161b; border-color: #e3b5b7; }
    .gd-btn.red:hover { border-color: #97161b; }
    .gd-btn[disabled] { opacity: .5; cursor: not-allowed; }

    .gd-row { display: flex; flex-wrap: wrap; gap: 8px 16px; align-items: center; justify-content: space-between; padding: 14px 0; border-top: 1px solid var(--border); }
    .gd-row:first-of-type { border-top: 0; }
    .gd-row p { margin: 2px 0 0; color: var(--muted); font-size: 13.5px; overflow-wrap: anywhere; }
    .gd-row form { display: inline; margin: 0; }
    .gd-empty { padding: 22px; text-align: center; color: var(--muted); }
    .gd-note { margin: 0 0 12px; color: var(--muted); font-size: 13.5px; }
    .gd-warn { color: #b45309; }
    .gd-hosts { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; margin-top: 6px; }
    .gd-chip { display: inline-flex; align-items: center; gap: 4px; padding: 2px 4px 2px 10px; border: 1px solid var(--border); border-radius: 999px; font-size: 13px; }
    .gd-chip button { border: 0; background: none; color: #97161b; font: inherit; font-weight: 700; cursor: pointer; padding: 0 6px; }
    .gd-inline { display: inline-flex !important; gap: 6px; align-items: center; }
    .gd-inline input[type=text] { width: 9.5em; padding: 5px 8px; font: inherit; font-size: 13px; border: 1px solid var(--border); border-radius: 8px; }
    .gd-inline .gd-btn { padding: 5px 12px; font-size: 13px; }

    /* ---- Award tab: mode switch, activity tiles, chips, preview, celebration ---- */
    .gd-modes { display: flex; flex-wrap: wrap; gap: 6px; margin: 0 0 16px; }
    .tl-wrap { max-height: 380px; overflow: auto; padding: 2px; border: 1px solid var(--border); border-radius: 14px; background: #fbfdff; }
    .tl-cat { --h: 205; padding: 10px 12px 4px; }
    .tl-head { display: flex; align-items: center; gap: 8px; margin: 0 0 8px; font-size: 12.5px; font-weight: 700; letter-spacing: .02em; text-transform: uppercase; color: var(--muted); }
    .tl-head::before { content: ""; width: 9px; height: 9px; border-radius: 50%; background: hsl(var(--h) 65% 48%); }
    .tl-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(230px, 1fr)); gap: 8px; margin: 0 0 10px; }
    .tl { position: relative; display: flex; align-items: center; gap: 10px; padding: 10px 12px; border: 1px solid var(--border); border-left: 5px solid hsl(var(--h) 60% 52%); border-radius: 12px; background: #fff; cursor: pointer; transition: transform .12s, box-shadow .12s, background .12s; }
    .tl:hover { transform: translateY(-1px); box-shadow: 0 6px 16px rgba(15, 23, 42, .08); }
    .tl input { position: absolute; opacity: 0; inset: 0; margin: 0; cursor: pointer; }
    .tl:has(input:checked) { background: hsl(var(--h) 85% 95%); border-color: hsl(var(--h) 55% 50%); box-shadow: 0 0 0 2px hsl(var(--h) 60% 62%); }
    .tl:has(input:focus-visible) { outline: 2px solid var(--primary); outline-offset: 2px; }
    .tl-emoji { font-size: 22px; line-height: 1; flex: none; }
    .tl-body { flex: 1; min-width: 0; }
    .tl-name { display: block; font-weight: 700; font-size: 13.5px; line-height: 1.3; overflow-wrap: anywhere; }
    .tl-tags { display: flex; flex-wrap: wrap; gap: 4px; margin-top: 3px; }
    .tl-tags:empty { display: none; }
    .tl-pts { flex: none; padding: 3px 10px; border-radius: 999px; font-weight: 800; font-size: 14px; font-variant-numeric: tabular-nums; color: hsl(var(--h) 65% 24%); background: hsl(var(--h) 85% 92%); }

    .bk-lab { display: block; margin: 0 0 4px; font-size: 13px; font-weight: 600; }
    .bk-chips { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; padding: 8px 10px; border: 1px solid var(--border); border-radius: 12px; background: #fff; min-height: 46px; box-sizing: border-box; }
    .bk-chips:focus-within { outline: 2px solid var(--primary); outline-offset: 2px; }
    .bk-chips input[type=text] { flex: 1 1 180px; min-width: 120px; border: 0 !important; outline: 0 !important; padding: 4px 2px !important; margin: 0 !important; background: transparent !important; font: inherit; }
    .bk-chip { display: inline-flex; align-items: center; gap: 2px; padding: 3px 4px 3px 11px; border-radius: 999px; background: #e8f3f9; color: #0b4f73; font-size: 13px; font-weight: 600; }
    .bk-chip button { border: 0; background: none; color: #0b4f73; font: inherit; font-weight: 800; cursor: pointer; padding: 0 7px; border-radius: 999px; }
    .bk-chip button:hover { background: rgba(11, 79, 115, .12); }
    .bk-foot { display: flex; flex-wrap: wrap; gap: 12px 18px; align-items: center; justify-content: space-between; }
    .bk-live { display: inline-flex; align-items: center; gap: 6px; padding: 7px 16px; border-radius: 999px; background: #f1f5f9; color: #334155; font-weight: 700; font-size: 14px; font-variant-numeric: tabular-nums; }
    .bk-live.hot { background: #e8f3f9; color: #0b4f73; }
    .bk-live.bad { background: #fee2e2; color: #97161b; }

    .bk-preview { margin: 20px 0 0; padding: 18px; border: 2px dashed #9cc7dd; border-radius: 16px; background: #f7fbfe; }
    .bk-preview > h3 { margin: 0 0 4px; }
    .bk-group { --h: 205; margin: 0 0 14px; padding: 12px 14px 8px; border: 1px solid var(--border); border-left: 5px solid hsl(var(--h) 60% 50%); border-radius: 12px; background: #fff; }
    .bk-group h4 { margin: 0 0 8px; font-size: 14.5px; }
    .bk-group h4 span { margin-left: 6px; padding: 1px 9px; border-radius: 999px; background: hsl(var(--h) 80% 93%); color: hsl(var(--h) 65% 24%); font-size: 12px; }
    .bk-group ul { margin: 0; padding: 0; list-style: none; display: flex; flex-wrap: wrap; gap: 6px 8px; }
    .bk-group li { padding: 3px 11px; border-radius: 999px; background: #f1f5f9; font-size: 13px; overflow-wrap: anywhere; }
    .bk-confirm { display: flex; flex-wrap: wrap; gap: 12px 20px; align-items: center; justify-content: space-between; margin: 16px 0 0; padding: 16px 18px; border-radius: 14px; background: #fff; border: 1px solid #9cc7dd; }
    .bk-sum { font-size: 19px; font-weight: 800; font-variant-numeric: tabular-nums; }
    .bk-sum small { display: block; margin-top: 2px; font-size: 13px; font-weight: 500; color: var(--muted); }

    .bk-win { position: relative; margin: 0 0 22px; padding: 22px 24px; border-radius: 18px; color: #fff; background: linear-gradient(135deg, #0e8a6b 0%, #1fa37c 50%, #6bc46d 100%); overflow: hidden; }
    .bk-win h3 { margin: 0 0 4px; font-size: 24px; }
    .bk-win p { margin: 4px 0 0; font-size: 14.5px; opacity: .95; }
    .bk-win details { margin-top: 10px; font-size: 13.5px; }
    .bk-win summary { cursor: pointer; font-weight: 600; }
    .bk-win details p { opacity: 1; overflow-wrap: anywhere; }
    .bk-win a { color: #fff; }

    /* ---- Conversation Hour cards ---- */
    .ch-row { align-items: flex-start; flex-wrap: nowrap; }
    .ch-ring { position: relative; flex: none; width: 66px; height: 66px; border-radius: 50%; display: grid; place-items: center; background: conic-gradient(hsl(var(--h, 165) 60% 45%) calc(var(--p) * 1%), #e2e8f0 0); }
    .ch-ring::before { content: ""; position: absolute; inset: 7px; border-radius: 50%; background: var(--card); }
    .ch-ring span { position: relative; font-size: 13px; font-weight: 800; font-variant-numeric: tabular-nums; }
    .ch-main { flex: 1; min-width: 0; }
    .ch-top { display: flex; flex-wrap: wrap; gap: 6px 12px; align-items: center; }
    .ch-done { padding: 2px 10px; border-radius: 999px; background: #dcfce7; color: #166534; font-size: 12.5px; font-weight: 700; }
    .av-stack { display: inline-flex; align-items: center; margin-left: 8px; }
    .av { display: grid; place-items: center; width: 26px; height: 26px; margin-left: -8px; border: 2px solid var(--card); border-radius: 50%; font-size: 10.5px; font-weight: 800; background: hsl(var(--h) 70% 88%); color: hsl(var(--h) 60% 24%); }
    .av:first-child { margin-left: 0; }
    .av.more { background: #e2e8f0; color: #475569; }
    .ch-act { flex: none; }
    .gd-extra { margin-top: 8px; }
    .gd-extra > summary { display: inline-block; cursor: pointer; font-size: 13px; font-weight: 600; color: var(--primary); list-style: none; }
    .gd-extra > summary::-webkit-details-marker { display: none; }
    .gd-extra > summary::before { content: "＋ "; }
    .gd-extra[open] > summary::before { content: "－ "; }
    .gd-extra form { display: flex !important; flex-wrap: wrap; gap: 8px; align-items: flex-end; margin: 8px 0 0; }
    .gd-extra textarea { flex: 1 1 260px; min-height: 64px; padding: 8px 10px; font: inherit; font-size: 13px; border: 1px solid var(--border); border-radius: 10px; box-sizing: border-box; }
    .gd-wait-bar { display: flex; flex-wrap: wrap; gap: 8px 12px; align-items: center; margin: 0 0 8px; padding: 8px 0; }
    .gd-wait-bar label { display: inline-flex; gap: 6px; align-items: center; font-size: 13.5px; font-weight: 600; cursor: pointer; }
    .gd-wait-bar .gd-btn { padding: 6px 14px; font-size: 13px; }
    .gd-tick { display: inline-flex; margin-right: 10px; }
    .gd-tags { display: inline-flex; flex-wrap: wrap; gap: 4px; margin-left: 8px; vertical-align: middle; }

    /* ---- Points guide: read-only, one colourful card per activity ---- */
    .pg-hero { display: flex; flex-wrap: wrap; gap: 8px 24px; align-items: center; justify-content: space-between; margin: 0 0 22px; padding: 22px 24px; border-radius: 18px; color: #fff; background: linear-gradient(135deg, #106b9a 0%, #1f8fc4 55%, #36b3a0 100%); }
    .pg-hero h3 { margin: 0 0 4px; font-size: 21px; }
    .pg-hero p { margin: 0; font-size: 14px; opacity: .92; max-width: 46em; }
    .pg-hero .pg-count { text-align: center; padding: 8px 18px; border-radius: 14px; background: rgba(255, 255, 255, .18); }
    .pg-hero .pg-count b { display: block; font-size: 30px; line-height: 1.1; font-variant-numeric: tabular-nums; }
    .pg-hero .pg-count span { font-size: 12px; opacity: .9; }
    .pg-group { margin: 0 0 28px; --h: 205; }
    .pg-group > h4 { display: flex; align-items: center; gap: 10px; margin: 0 0 12px; font-size: 16px; }
    .pg-group > h4::before { content: ""; width: 12px; height: 12px; border-radius: 50%; background: hsl(var(--h) 65% 48%); }
    .pg-group > h4 span { color: var(--muted); font-weight: 500; font-size: 13px; }
    .pg-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 14px; }
    .pg-card { position: relative; display: flex; flex-direction: column; justify-content: space-between; gap: 14px; min-height: 124px; padding: 16px 18px 16px 22px; background: var(--card); border: 1px solid var(--border); border-radius: 16px; overflow: hidden; transition: transform .15s, box-shadow .15s; }
    .pg-card::before { content: ""; position: absolute; inset: 0 auto 0 0; width: 6px; background: hsl(var(--h) 62% 50%); }
    .pg-card:hover { transform: translateY(-2px); box-shadow: 0 10px 26px rgba(15, 23, 42, .10); }
    .pg-name { font-weight: 700; font-size: 15px; line-height: 1.35; overflow-wrap: anywhere; }
    .pg-foot { display: flex; align-items: flex-end; justify-content: space-between; gap: 10px; }
    .pg-tags { display: flex; flex-wrap: wrap; gap: 5px; }
    .pg-pts { flex: none; padding: 6px 14px; border-radius: 14px; text-align: center; font-weight: 800; font-size: 26px; line-height: 1.05; font-variant-numeric: tabular-nums; color: hsl(var(--h) 65% 24%); background: hsl(var(--h) 85% 93%); }
    .pg-pts small { display: block; font-size: 11px; font-weight: 600; opacity: .8; }
    .pg-edit { margin: 8px 0 0; font-size: 13.5px; color: var(--muted); }

    /* ---- Activities and catalogue: one compact row per item, Edit opens the form in place ---- */
    .ac-bar { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin: 0 0 16px; }
    .ac-bar input[type=search] { flex: 1 1 220px; min-width: 0; padding: 10px 14px; font: inherit; border: 1px solid var(--border); border-radius: 999px; background: #fff; }
    .ac-pills { display: flex; flex-wrap: wrap; gap: 6px; }
    .ac-pill { padding: 6px 14px; font: inherit; font-size: 13px; font-weight: 600; border: 1px solid var(--border); border-radius: 999px; background: var(--card); color: var(--ink); cursor: pointer; text-decoration: none; display: inline-block; }
    .ac-pill.on { background: var(--ink); border-color: var(--ink); color: #fff; }
    .ac-tick { display: inline-flex; gap: 6px; align-items: center; font-size: 13px; color: var(--muted); cursor: pointer; }

    .ac-group { margin: 0 0 26px; --h: 205; }
    .ac-group > h4 { display: flex; align-items: center; gap: 10px; margin: 0 0 10px; font-size: 15px; }
    .ac-group > h4::before { content: ""; width: 10px; height: 10px; border-radius: 50%; background: hsl(var(--h) 65% 48%); }
    .ac-group > h4 span { color: var(--muted); font-weight: 500; font-size: 13px; }

    .ac { --h: 205; margin: 0 0 8px; background: var(--card); border: 1px solid var(--border); border-left: 5px solid hsl(var(--h) 60% 50%); border-radius: 12px; }
    .ac[open] { box-shadow: 0 8px 24px rgba(15, 23, 42, .09); border-color: hsl(var(--h) 45% 70%); border-left-color: hsl(var(--h) 60% 50%); }
    .ac.off { border-left-color: #cbd5e1; }
    .ac.off > summary .ac-name { color: var(--muted); }
    .ac > summary { display: grid; grid-template-columns: 1fr auto auto; gap: 6px 16px; align-items: center; padding: 12px 16px; cursor: pointer; list-style: none; }
    .ac > summary::-webkit-details-marker { display: none; }
    .ac > summary:hover { background: #f8fafc; border-radius: 12px; }
    .ac-name { display: block; font-weight: 700; font-size: 15px; overflow-wrap: anywhere; }
    .ac-meta { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; margin-top: 4px; font-size: 12.5px; color: var(--muted); }
    .ac-tag { padding: 1px 9px; border-radius: 999px; background: #f1f5f9; color: #475569; font-weight: 600; font-size: 12px; }
    .ac-tag.warn { background: #fef3c7; color: #92400e; }
    .ac-tag.off { background: #e2e8f0; color: #475569; }
    .ac-pts { min-width: 72px; padding: 6px 12px; text-align: center; border-radius: 12px; font-weight: 800; font-size: 20px; line-height: 1.1; font-variant-numeric: tabular-nums; color: hsl(var(--h) 65% 26%); background: hsl(var(--h) 85% 94%); }
    .ac-pts small { display: block; font-size: 11px; font-weight: 600; opacity: .8; }
    .ac-edit { color: var(--primary); font-weight: 600; font-size: 14px; }
    .ac-edit::before { content: "Edit"; }
    .ac[open] .ac-edit::before { content: "Close"; }

    .ac-form { padding: 6px 18px 18px 18px; border-top: 1px dashed var(--border); margin: 0; padding-top: 16px; }
    .ac-flags { grid-column: 1 / -1; display: flex; flex-wrap: wrap; gap: 8px; }
    .gd-tg { display: inline-flex !important; flex-direction: row !important; align-items: center; gap: 8px; padding: 8px 14px; border: 1px solid var(--border); border-radius: 999px; background: #fff; font-weight: 600; cursor: pointer; }
    .gd-tg input { accent-color: var(--primary); width: 16px; height: 16px; margin: 0; }
    .gd-tg:has(input:checked) { background: #e8f3f9; border-color: var(--primary); color: #0b4f73; }
    .ac-foot { grid-column: 1 / -1; display: flex; flex-wrap: wrap; gap: 10px; align-items: center; padding-top: 4px; }
    .ac-foot .grow { margin-left: auto; }
    .ac-hint { grid-column: 1 / -1; margin: 0; padding: 10px 12px; border-radius: 10px; background: #f1f7fb; color: #334155; font-size: 13px; }

    /* Catalogue stock */
    .gd-stock .st-rows { display: flex; flex-wrap: wrap; gap: 8px 14px; margin: 10px 0 0; }
    .st-row { display: inline-flex !important; flex-direction: row !important; align-items: center; gap: 8px; padding: 6px 10px; border: 1px solid var(--border); border-radius: 10px; background: #fff; }
    .st-row span { min-width: 2em; font-weight: 700; }
    .st-row input[type=number] { width: 6em; margin: 0 !important; }

    .gd-add > summary { display: inline-flex; align-items: center; gap: 8px; padding: 10px 20px; border-radius: 999px; background: var(--primary); color: #fff; font-weight: 700; font-size: 14px; cursor: pointer; list-style: none; }
    .gd-add > summary::-webkit-details-marker { display: none; }
    .gd-add[open] > summary { background: #fff; color: var(--primary); border: 1px solid var(--primary); }
    .gd-add .gd-card { margin: 12px 0 0; }

    .gd-info { margin: 0 0 18px; padding: 12px 16px; border-radius: 12px; background: #fff7e6; border: 1px solid #f5d38a; color: #7a4b00; font-size: 13.5px; line-height: 1.5; }

    /* ---- Edit points in bulk ---- */
    .pt-tools { display: flex; flex-wrap: wrap; gap: 8px 10px; align-items: center; margin: 0 0 14px; padding: 12px 14px; border: 1px solid var(--border); border-radius: 14px; background: var(--card); }
    .pt-tools select, .pt-tools input[type=number] { padding: 7px 10px; font: inherit; font-size: 13.5px; border: 1px solid var(--border); border-radius: 10px; background: #fff; }
    .pt-tools input[type=number] { width: 6.5em; }
    .pt-tools .grow { margin-left: auto; font-weight: 700; font-size: 13.5px; color: var(--muted); }
    .pt-wrap { overflow-x: auto; border: 1px solid var(--border); border-radius: 14px; background: var(--card); }
    table.pt { width: 100%; border-collapse: collapse; font-size: 14px; }
    table.pt th { padding: 10px 14px; text-align: left; font-size: 12.5px; text-transform: uppercase; letter-spacing: .03em; color: var(--muted); border-bottom: 1px solid var(--border); background: #f8fafc; }
    table.pt td { padding: 9px 14px; border-top: 1px solid #eef2f6; vertical-align: middle; }
    table.pt tr.cat td { padding: 8px 14px; background: #f8fafc; font-weight: 700; font-size: 13px; }
    table.pt tr.cat td::before { content: ""; display: inline-block; width: 9px; height: 9px; margin-right: 8px; border-radius: 50%; background: hsl(var(--h) 65% 48%); }
    table.pt tr.chg td { background: #fffbe6; }
    table.pt tr.off .pt-n { color: var(--muted); }
    table.pt td.num { font-variant-numeric: tabular-nums; white-space: nowrap; }
    table.pt input[type=number] { width: 6.5em; padding: 6px 9px; font: inherit; border: 1px solid var(--border); border-radius: 9px; background: #fff; }
    table.pt tr.chg input.pt-new { border-color: #d9a400; box-shadow: 0 0 0 2px #fde68a; }
    .pt-sub { display: block; margin-top: 4px; font-size: 12px; color: var(--muted); }
    .pt-sub input { width: 5em; }
    .pt-review { margin: 0 0 20px; padding: 18px; border: 2px dashed #9cc7dd; border-radius: 16px; background: #f7fbfe; }
    .pt-review h3 { margin: 0 0 10px; }
    .pt-review .pos { color: #166534; font-weight: 700; }
    .pt-review .neg { color: #97161b; font-weight: 700; }

    @media (max-width: 640px) {
        .ac > summary { grid-template-columns: 1fr auto; }
        .ac-edit { display: none; }
        .ch-row { flex-wrap: wrap; }
    }
    @media (prefers-reduced-motion: reduce) { * { transition: none !important; } .pg-card:hover, .tl:hover { transform: none; } }
</style>

<?php if ($flash): ?>
    <div class="alert <?= $flash[0] === 'ok' ? 'ok' : 'error' ?>"><?= htmlspecialchars((string) $flash[1]) ?></div>
<?php endif; ?>

<div class="gd-stats">
    <div class="gd-stat" style="--h:205"><b><?= number_format($totals['outstanding']) ?></b><span>Points outstanding (≈ ₹ liability)</span></div>
    <div class="gd-stat" style="--h:165"><b><?= number_format($totals['awarded']) ?></b><span>Points awarded, ever</span></div>
    <div class="gd-stat" style="--h:28"><b><?= number_format($totals['spent']) ?></b><span>Points spent, net of refunds</span></div>
    <div class="gd-stat" style="--h:340"><b><?= (int) $totals['awaiting_second'] ?></b><span>Awards awaiting a second reviewer</span></div>
    <div class="gd-stat" style="--h:275"><b><?= (int) $totals['requested'] ?></b><span>New orders</span></div>
</div>

<nav class="gd-nav" aria-label="Goodies sections">
    <?php foreach (GOODIES_TABS as $key => $label): $badge = $key === 'award' ? (int) $totals['awaiting_second'] : ($key === 'redemptions' ? (int) $totals['requested'] : 0); ?>
        <a href="/admin/goodies?tab=<?= $key ?>"<?= $tab === $key ? ' class="on" aria-current="page"' : '' ?>><?= htmlspecialchars($label) ?><?php if ($badge > 0): ?><span class="gd-badge"><?= $badge ?></span><?php endif; ?></a>
    <?php endforeach; ?>
</nav>

<?php if ($tab === 'award'): ?>
    <?php
    $awardMode = $bulk !== null ? 'many' : 'one';
    $bkType = $bulk['type'] ?? 0;
    // The reviewer's own Member IDs as a lookup, for the "waiting for a second reviewer" list.
    $ownSet = array_fill_keys(array_map(fn($m) => strtoupper(trim((string) $m)), $ownIds), true);
    ?>

    <datalist id="gd-members">
        <?php foreach ($directory as $mid => $m): ?>
            <option value="<?= htmlspecialchars($mid) ?>"><?= htmlspecialchars($m['name'] . ($m['username'] !== '' ? ' · ' . $m['username'] : '')) ?></option>
        <?php endforeach; ?>
    </datalist>

    <?php if ($bulkResult): $br = $bulkResult; $n = (int) $br['awarded']; $pn = (int) $br['pending']; ?>
        <section class="bk-win" role="status" aria-live="polite" id="bk-win" data-celebrate="<?= $n > 0 ? 1 : 0 ?>">
            <?php if ($n > 0): ?>
                <h3>🎉 <?= $n ?> <?= $n === 1 ? 'person just got' : 'people just got' ?> +<?= (int) $br['each'] ?></h3>
                <p><?= gd_h($br['activity']) ?> · <?= number_format($n * (int) $br['each']) ?> points added to balances.</p>
            <?php else: ?>
                <h3>⏳ Saved, waiting for a second reviewer</h3>
                <p><?= gd_h($br['activity']) ?> · +<?= (int) $br['each'] ?> each.</p>
            <?php endif; ?>
            <?php if ($pn > 0): ?><p>⏳ <?= $pn ?> <?= $pn === 1 ? 'award is' : 'awards are' ?> <?= (int) $br['each'] ?> points each, so a different reviewer must approve <?= $pn === 1 ? 'it' : 'them' ?> before <?= $pn === 1 ? 'it counts' : 'they count' ?>.</p><?php endif; ?>
            <?php if ((int) $br['skipped'] > 0): ?><p><?= (int) $br['skipped'] ?> <?= (int) $br['skipped'] === 1 ? 'person was' : 'people were' ?> not added (already had it, struck off since the preview, or already saved).</p><?php endif; ?>
            <?php if ($br['ok'] || $br['pend'] || $br['skip']): ?>
                <details>
                    <summary>Who got what</summary>
                    <?php if ($br['ok']): ?><p><strong>+<?= (int) $br['each'] ?> now:</strong> <?= gd_h(implode(', ', $br['ok'])) ?></p><?php endif; ?>
                    <?php if ($br['pend']): ?><p><strong>Waiting for approval:</strong> <?= gd_h(implode(', ', $br['pend'])) ?></p><?php endif; ?>
                    <?php if ($br['skip']): ?><p><strong>Not added:</strong> <?= gd_h(implode(', ', $br['skip'])) ?></p><?php endif; ?>
                </details>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <section class="gd-card">
        <h3>Award points</h3>
        <div class="gd-modes" role="group" aria-label="Who to award">
            <button type="button" class="ac-pill<?= $awardMode === 'one' ? ' on' : '' ?>" data-mode="one">One person</button>
            <button type="button" class="ac-pill<?= $awardMode === 'many' ? ' on' : '' ?>" data-mode="many">Several people</button>
        </div>

        <!-- One person: awarded straight away -->
        <div data-pane="one"<?= $awardMode === 'one' ? '' : ' hidden' ?>>
            <form method="POST" action="/admin/goodies?tab=award" class="gd-form" data-once>
                <?= CSRF::getInputField() ?>
                <input type="hidden" name="do" value="award">
                <label>Member<small>Name, Wikimedia username or Member ID</small><input type="text" name="member_id" list="gd-members" required maxlength="190" autocomplete="off"></label>
                <label>Evidence link<small>A diff or page. Needed for some activities.</small><input type="url" name="evidence_url" maxlength="500" placeholder="https://"></label>
                <label>Note<small>Optional</small><input type="text" name="note" maxlength="500"></label>
                <div class="wide"><span class="bk-lab">Activity</span><?php $gdTiles($types, 'one', 0); ?></div>
                <div class="wide"><button type="submit" class="gd-btn">Award</button></div>
            </form>
        </div>

        <!-- Several people: pick, preview, confirm -->
        <div data-pane="many"<?= $awardMode === 'many' ? '' : ' hidden' ?>>
            <form method="POST" action="/admin/goodies?tab=award" class="gd-form" id="bk-form" data-once>
                <?= CSRF::getInputField() ?>
                <input type="hidden" name="do" value="bulk_preview">
                <div class="wide">
                    <label for="bk-input" class="bk-lab" style="display:block">Who <small style="display:inline;font-weight:400;color:var(--muted)">Type a name, username or Member ID, then press Enter. Each person becomes a chip.</small></label>
                    <div class="bk-chips" id="bk-chips"><input type="text" id="bk-input" list="gd-members" autocomplete="off" maxlength="190" placeholder="Add a member…"></div>
                </div>
                <label class="wide">Or paste a list<small>One per line: a name, username or Member ID. For a different link per person, add it after the name: <code>Asha Rao, https://en.wikipedia.org/wiki/Special:Diff/123</code></small>
                    <textarea name="paste" id="bk-paste" rows="4" placeholder="Asha Rao&#10;A48213977&#10;SomeUsername, https://…"><?= $bulk ? gd_h($bulk['lines']) : '' ?></textarea></label>
                <div class="wide"><span class="bk-lab">Activity</span><?php $gdTiles($types, 'many', (int) $bkType); ?></div>
                <label>Shared evidence link<small>For example the event page. Used for anyone without their own link.</small><input type="url" name="shared_evidence" maxlength="500" placeholder="https://" value="<?= $bulk ? gd_h($bulk['evidence']) : '' ?>"></label>
                <label>Shared note<small>Optional. Saved with every award.</small><input type="text" name="note" maxlength="500" value="<?= $bulk ? gd_h($bulk['note']) : '' ?>"></label>
                <div class="wide bk-foot">
                    <span class="bk-live" id="bk-live" aria-live="polite">0 people · pick an activity</span>
                    <button type="submit" class="gd-btn">Preview</button>
                </div>
            </form>

            <?php if ($bulk !== null): $pl = $bulk['plan']; ?>
                <div class="bk-preview" id="bk-preview">
                    <h3>Preview: nothing is saved yet</h3>
                    <?php if (!empty($pl['error'])): ?>
                        <div class="alert error" style="margin-top:10px"><?= gd_h($pl['error']) ?></div>
                    <?php else:
                        $readyOthers = array_values(array_filter($pl['ready'], fn($r) => empty($r['self'])));
                        $readySelf   = array_values(array_filter($pl['ready'], fn($r) => !empty($r['self'])));
                        $nReady = count($pl['ready']);
                        $each = (int) $pl['points'];
                    ?>
                        <p class="gd-note"><?= gd_h($pl['type']['name']) ?> · <?= $each ?> points each</p>

                        <?php
                        gd_group('Ready to award', 150, array_map(function ($r) use ($bulk) {
                            $li = gd_h($r['name']) . ' <small style="color:var(--muted)">' . gd_h($r['member_id']) . '</small>';
                            if ($r['evidence'] !== '' && $r['evidence'] !== $bulk['evidence']) $li .= ' · <a href="' . gd_h($r['evidence']) . '" target="_blank" rel="noopener noreferrer">link</a>';
                            return $li;
                        }, $readyOthers));
                        if (!$readyOthers && !$readySelf) echo '<div class="bk-group" style="--h:150"><h4>Ready to award <span>0</span></h4><p class="gd-note" style="margin:0 0 8px">Nobody can be awarded yet. Fix the groups below and preview again.</p></div>';

                        if ($isOwner) {
                            gd_group('Your own ID', 275, array_map(fn($r) => gd_h($r['name']) . ' <small style="color:var(--muted)">' . gd_h($r['member_id']) . '</small>', $readySelf),
                                'As an owner you can award yourself. These are awarded with everyone else and logged as goodies.award_self.');
                        } else {
                            gd_group('Your own ID (skipped)', 275, array_map('gd_h', $pl['own']),
                                'You cannot award points to yourself. Ask another reviewer or an owner.');
                        }
                        gd_group('Ambiguous', 48, array_map(fn($a) => '<strong>' . gd_h($a['typed']) . '</strong>: ' . gd_h($a['error']), $pl['ambiguous']),
                            'Shared or partial names. Use the Member ID of the right person and preview again.');
                        gd_group('Not found', 355, array_map('gd_h', $pl['notfound']), 'Try the full name, the verified Wikimedia username or the Member ID.');
                        gd_group('Needs an evidence link', 28, array_map(fn($i) => '<strong>' . gd_h($i['name']) . '</strong>: ' . gd_h($i['error']), $pl['invalid']));
                        gd_group('Struck off (skipped)', 0, array_map('gd_h', $pl['struck']));
                        gd_group('Already has it (once per member)', 215, array_map('gd_h', $pl['already']));
                        ?>
                        <?php if ($pl['dupes'] > 0): ?><p class="gd-note"><?= (int) $pl['dupes'] ?> repeated entr<?= $pl['dupes'] === 1 ? 'y was' : 'ies were' ?> ignored.</p><?php endif; ?>

                        <form method="POST" action="/admin/goodies?tab=award" class="bk-confirm" data-once>
                            <?= CSRF::getInputField() ?>
                            <input type="hidden" name="do" value="bulk_confirm">
                            <input type="hidden" name="token" value="<?= gd_h($bulk['token']) ?>">
                            <input type="hidden" name="activity_type_id" value="<?= (int) $bulk['type'] ?>">
                            <input type="hidden" name="note" value="<?= gd_h($bulk['note']) ?>">
                            <input type="hidden" name="shared_evidence" value="<?= gd_h($bulk['evidence']) ?>">
                            <textarea name="lines" hidden><?= gd_h($bulk['lines']) ?></textarea>
                            <div class="bk-sum">
                                <?= $nReady ?> <?= $nReady === 1 ? 'person' : 'people' ?> × <?= $each ?> pts = <?= number_format($nReady * $each) ?> pts
                                <small>
                                    <?php if ($pl['needs_second']): ?>Each award is <?= PointsModel::SECOND_APPROVAL_AT ?> points or more, so a different reviewer must approve each one before it counts.
                                    <?php elseif ($isOwner): ?>Owner awards are approved straight away.
                                    <?php else: ?>Approved straight away.<?php endif; ?>
                                </small>
                            </div>
                            <button type="submit" class="gd-btn"<?= $nReady ? '' : ' disabled' ?>>Confirm and award</button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($isOwner): ?>
            <p class="gd-note" style="margin:14px 0 0">As an owner, the points you award are approved straight away, with no second review. Awards to your own Member ID are marked <code>goodies.award_self</code> in the audit log.</p>
        <?php else: ?>
            <p class="gd-note" style="margin:14px 0 0">Awards of <?= (int) PointsModel::SECOND_APPROVAL_AT ?> points or more need approval from a different reviewer or an owner before they count.</p>
        <?php endif; ?>
    </section>

    <section class="gd-card">
        <h3>Conversation Hour</h3>
        <p class="gd-note">Points for attendance logged in <a href="/admin/community-records">Community records</a>. Members logged present earn the attend points; each host earns the host points instead (fewer if under the minimum attendance). Award now can be run again later: it only adds people logged since.</p>
        <?php if ($chError !== ''): ?>
            <div class="gd-empty"><?= htmlspecialchars($chError) ?></div>
        <?php elseif (!$chRows): ?>
            <div class="gd-empty">No sessions logged yet.</div>
        <?php else: foreach ($chRows as $row):
            $sess = $row['session']; $p = $row['plan']; $sid = (int) $sess['id'];
            $todo = count($p['award']); $done = (int) $p['done']; $total = $done + $todo;
            $pct = $total > 0 ? (int) round($done * 100 / $total) : 0;
            $caughtUp = $total > 0 && $todo === 0;
            $stack = array_slice($p['award'], 0, 5);
            $sessTitle = (string) $sess['title'];
            $sessDate = date('j M Y', strtotime((string) $sess['held_on']));
        ?>
            <div class="gd-row ch-row">
                <div class="ch-ring" style="--p:<?= $pct ?>;--h:<?= $caughtUp ? 150 : 205 ?>" role="img" aria-label="<?= $done ?> of <?= $total ?> awarded"><span><?= $total ? $done . '/' . $total . ($caughtUp ? ' ✓' : '') : '–' ?></span></div>
                <div class="ch-main">
                    <div class="ch-top">
                        <strong><?= htmlspecialchars($sessTitle) ?> · <?= htmlspecialchars($sessDate) ?></strong>
                        <?php if ($caughtUp): ?><span class="ch-done">All caught up ✨</span><?php endif; ?>
                        <?php if ($stack): ?>
                            <span class="av-stack" aria-label="<?= $todo ?> still to award">
                                <?php foreach ($stack as $a): $nm = $directory[$a['member_id']]['name'] ?? $a['member_id']; ?>
                                    <span class="av" style="--h:<?= abs(crc32((string) $a['member_id'])) % 360 ?>" title="<?= htmlspecialchars($nm) ?>"><?= htmlspecialchars(gd_initials($nm)) ?></span>
                                <?php endforeach; ?>
                                <?php if ($todo > count($stack)): ?><span class="av more">+<?= $todo - count($stack) ?></span><?php endif; ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    <p><?= (int) $p['present'] ?> present · <?= $done ?> awarded · <strong><?= $todo ?> to award</strong>
                        <?php if ($p['hosts'] && $p['host_points']): ?> · host <?= (int) $p['host_points'] ?> pts<?php endif; ?></p>
                    <?php if ($p['missing']): ?><p class="gd-warn">Switched off or missing activity: <?= htmlspecialchars(implode(', ', $p['missing'])) ?>. Nothing is awarded for it.</p><?php endif; ?>
                    <?php if ($p['own']): ?><p class="gd-warn">Your own award (<?= htmlspecialchars(implode(', ', $p['own'])) ?>) is left for another reviewer.</p><?php endif; ?>
                    <?php if ($p['struck']): ?><p class="gd-warn">Struck off, not awarded: <?= htmlspecialchars(implode(', ', $p['struck'])) ?></p><?php endif; ?>
                    <?php if ($p['unresolved']): ?><p class="gd-warn">Not matched to a member (no Member ID or verified username): <?= htmlspecialchars(implode(', ', $p['unresolved'])) ?></p><?php endif; ?>
                    <?php if ($p['gone']): ?><p class="gd-warn">Awarded, but no longer logged present: <?= htmlspecialchars(implode(', ', $p['gone'])) ?>. If that was a mistake, take the points back below.</p><?php endif; ?>
                    <div class="gd-hosts">
                        <span style="font-size:13px;color:var(--muted)">Hosts:</span>
                        <?php foreach ($p['hosts'] as $h): ?>
                            <form method="POST" action="/admin/goodies?tab=award" class="gd-chip"><?= CSRF::getInputField() ?>
                                <input type="hidden" name="do" value="ch_host_remove"><input type="hidden" name="session_id" value="<?= $sid ?>"><input type="hidden" name="member_id" value="<?= htmlspecialchars($h) ?>">
                                <?= htmlspecialchars($h) ?><button type="submit" aria-label="Remove host <?= htmlspecialchars($h) ?>" title="Remove host">×</button>
                            </form>
                        <?php endforeach; ?>
                        <form method="POST" action="/admin/goodies?tab=award" class="gd-inline"><?= CSRF::getInputField() ?>
                            <input type="hidden" name="do" value="ch_host_add"><input type="hidden" name="session_id" value="<?= $sid ?>">
                            <input type="text" name="member_id" list="gd-members" required maxlength="190" placeholder="Host: name or ID" aria-label="Host for <?= htmlspecialchars($sessTitle) ?>: name, Wikimedia username or Member ID" autocomplete="off">
                            <button type="submit" class="gd-btn line">Add host</button>
                        </form>
                    </div>
                    <?php if ($chAttendId): ?>
                        <details class="gd-extra">
                            <summary>Add other attendees</summary>
                            <p class="gd-note" style="margin:6px 0 0">For people who attended but were not logged. You will see a preview before anything is saved.</p>
                            <form method="POST" action="/admin/goodies?tab=award"><?= CSRF::getInputField() ?>
                                <input type="hidden" name="do" value="bulk_preview">
                                <input type="hidden" name="activity_type_id" value="<?= $chAttendId ?>">
                                <input type="hidden" name="note" value="<?= htmlspecialchars('Conversation Hour: ' . $sessTitle . ', ' . $sessDate) ?>">
                                <textarea name="paste" required placeholder="One per line: name, username or Member ID" aria-label="Other attendees of <?= htmlspecialchars($sessTitle) ?>"></textarea>
                                <button type="submit" class="gd-btn line">Preview</button>
                            </form>
                        </details>
                    <?php endif; ?>
                </div>
                <div class="ch-act">
                    <form method="POST" action="/admin/goodies?tab=award" data-once><?= CSRF::getInputField() ?>
                        <input type="hidden" name="do" value="ch_award"><input type="hidden" name="session_id" value="<?= $sid ?>">
                        <button type="submit" class="gd-btn"<?= $todo ? '' : ' disabled' ?>>Award now<?= $todo ? ' (' . $todo . ')' : '' ?></button>
                    </form>
                </div>
            </div>
        <?php endforeach; endif; ?>
    </section>

    <section class="gd-card">
        <h3>Waiting for a second reviewer (<?= count($awaiting) ?>)</h3>
        <?php if ($awaiting): ?>
            <?php if ($isOwner): ?>
                <p class="gd-note">As an owner you can approve any of these, including awards you created or awards made to your own Member ID. Those approvals are marked in the audit log so they stay easy to review.</p>
            <?php else: ?>
                <p class="gd-note">A different reviewer or an owner must approve each award. You cannot approve awards you created or awards made to your own Member ID.</p>
            <?php endif; ?>
        <?php endif; ?>
        <?php if (!$awaiting): ?>
            <div class="gd-empty">Nothing is waiting.</div>
        <?php else: ?>
            <form method="POST" action="/admin/goodies?tab=award" id="bulk-wait"><?= CSRF::getInputField() ?></form>
            <div class="gd-wait-bar">
                <label><input type="checkbox" id="wait-all"> Select all</label>
                <button type="submit" form="bulk-wait" name="do" value="bulk_approve" class="gd-btn">Approve selected</button>
                <button type="submit" form="bulk-wait" name="do" value="bulk_reject" class="gd-btn red"
                        onclick="return confirm('Reject the selected awards?')">Reject selected</button>
            </div>
            <?php foreach ($awaiting as $a):
                $mine = strcasecmp((string) $a['first_reviewer_email'], $actor) === 0;      // I created it
                $toMe = isset($ownSet[strtoupper((string) $a['member_id'])]);               // it is for one of my Member IDs
                $blocked = !$isOwner && ($mine || $toMe);                                   // owners are never blocked
                $blockWhy = $toMe ? 'You cannot approve points awarded to yourself' : 'A different reviewer must approve this';
            ?>
                <div class="gd-row">
                    <div>
                        <strong><label class="gd-tick"><input type="checkbox" class="wait-pick" form="bulk-wait" name="award_ids[]" value="<?= (int) $a['id'] ?>" aria-label="Select award <?= (int) $a['id'] ?>"></label><?= htmlspecialchars($a['activity_name']) ?> · <?= (int) $a['points'] ?> pts · <?= htmlspecialchars((string) $a['member_id']) ?></strong>
                        <?php if ($mine || $toMe): ?>
                            <span class="gd-tags">
                                <?php if ($mine): ?><span class="ac-tag warn">Created by you</span><?php endif; ?>
                                <?php if ($toMe): ?><span class="ac-tag warn">For your Member ID</span><?php endif; ?>
                            </span>
                        <?php endif; ?>
                        <p>Created by <?= htmlspecialchars((string) $a['first_reviewer_email']) ?> on <?= htmlspecialchars($when($a['created_at'])) ?>
                            <?php if (!empty($a['evidence_url'])): ?> · <a href="<?= htmlspecialchars((string) $a['evidence_url']) ?>" target="_blank" rel="noopener noreferrer">evidence</a><?php endif; ?>
                            <?php if (!empty($a['note'])): ?> · <?= htmlspecialchars((string) $a['note']) ?><?php endif; ?></p>
                    </div>
                    <div>
                        <form method="POST" action="/admin/goodies?tab=award"><?= CSRF::getInputField() ?>
                            <input type="hidden" name="do" value="approve"><input type="hidden" name="award_id" value="<?= (int) $a['id'] ?>">
                            <button type="submit" class="gd-btn"<?= $blocked ? ' disabled title="' . htmlspecialchars($blockWhy) . '"' : '' ?>>Approve</button>
                        </form>
                        <form method="POST" action="/admin/goodies?tab=award"><?= CSRF::getInputField() ?>
                            <input type="hidden" name="do" value="reject"><input type="hidden" name="award_id" value="<?= (int) $a['id'] ?>">
                            <button type="submit" class="gd-btn red">Reject</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>

    <section class="gd-card">
        <h3>Take points back</h3>
        <p class="gd-note">For a bad award or a code-of-conduct breach. This adds a negative entry to the member’s history; the original award stays on record. A balance can go below zero if the points were already spent.</p>
        <form method="POST" action="/admin/goodies?tab=award" class="gd-form">
            <?= CSRF::getInputField() ?>
            <input type="hidden" name="do" value="reverse">
            <label>Member<small>Name, Wikimedia username or Member ID</small><input type="text" name="member_id" list="gd-members" required maxlength="190" autocomplete="off"></label>
            <label>Points to take back<small>A positive number</small><input type="number" name="points" min="1" max="100000" required></label>
            <label class="wide">Reason<small>Shown to the member</small><input type="text" name="reason" required maxlength="230"></label>
            <div><button type="submit" class="gd-btn red">Take back</button></div>
        </form>
    </section>

    <script>
    (function () {
        var DIR = <?= json_encode(array_map(fn($m) => $m['name'], $directory), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_FORCE_OBJECT) ?>;
        var MAX = <?= (int) PointsModel::BULK_MAX ?>;
        var SECOND = <?= (int) PointsModel::SECOND_APPROVAL_AT ?>;

        // One person / Several people
        var modeBtns = [].slice.call(document.querySelectorAll('[data-mode]'));
        var panes = [].slice.call(document.querySelectorAll('[data-pane]'));
        modeBtns.forEach(function (b) {
            b.addEventListener('click', function () {
                modeBtns.forEach(function (x) { x.classList.toggle('on', x === b); });
                panes.forEach(function (p) { p.hidden = p.dataset.pane !== b.dataset.mode; });
            });
        });

        // Chips + live counter
        var form = document.getElementById('bk-form');
        if (form) {
            var box = document.getElementById('bk-chips'), inp = document.getElementById('bk-input'),
                paste = document.getElementById('bk-paste'), live = document.getElementById('bk-live');
            var chips = [];
            var norm = function (v) { return v.replace(/\s+/g, ' ').trim(); };
            var label = function (v) { var id = v.toUpperCase(); return DIR[id] ? DIR[id] + ' (' + id + ')' : v; };
            function draw() {
                [].slice.call(box.querySelectorAll('.bk-chip')).forEach(function (c) { c.remove(); });
                chips.forEach(function (v, i) {
                    var s = document.createElement('span'); s.className = 'bk-chip';
                    s.appendChild(document.createTextNode(label(v)));
                    var h = document.createElement('input'); h.type = 'hidden'; h.name = 'chips[]'; h.value = v; s.appendChild(h);
                    var x = document.createElement('button'); x.type = 'button'; x.textContent = '×'; x.title = 'Remove'; x.setAttribute('aria-label', 'Remove ' + label(v));
                    x.addEventListener('click', function () { chips.splice(i, 1); draw(); inp.focus(); });
                    s.appendChild(x); box.insertBefore(s, inp);
                });
                count();
            }
            function add(v) {
                v = norm(v); if (!v) return;
                if (DIR[v.toUpperCase()]) v = v.toUpperCase();
                if (chips.some(function (c) { return c.toLowerCase() === v.toLowerCase(); })) return;
                chips.push(v); draw();
            }
            function count() {
                var lines = paste.value.split(/\n/).filter(function (s) { return s.trim() !== ''; }).length;
                var n = chips.length + lines;
                var sel = form.querySelector('input[name=activity_type_id]:checked');
                var txt = n + (n === 1 ? ' person' : ' people');
                live.className = 'bk-live';
                if (sel) {
                    txt += ' · +' + (n * parseInt(sel.dataset.points, 10)).toLocaleString() + ' points';
                    if (sel.dataset.second === '1' && n > 0) txt += ' · each needs a second reviewer';
                    if (n > 0) live.classList.add('hot');
                } else { txt += ' · pick an activity'; }
                if (n > MAX) { txt = n + ' people is over the limit of ' + MAX; live.className = 'bk-live bad'; }
                live.textContent = txt;
            }
            inp.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ',') { e.preventDefault(); add(inp.value); inp.value = ''; }
                else if (e.key === 'Backspace' && inp.value === '' && chips.length) { chips.pop(); draw(); }
            });
            inp.addEventListener('input', function (e) {
                // A pick from the type-ahead list arrives as an input event that is not typing.
                var picked = !e.inputType || e.inputType === 'insertReplacementText';
                if (picked && DIR[inp.value.trim().toUpperCase()]) { add(inp.value); inp.value = ''; }
            });
            inp.addEventListener('change', function () { if (inp.value.trim()) { add(inp.value); inp.value = ''; } });
            inp.addEventListener('blur', function () { if (inp.value.trim()) { add(inp.value); inp.value = ''; } });
            box.addEventListener('click', function (e) { if (e.target === box) inp.focus(); });
            paste.addEventListener('input', count);
            form.addEventListener('change', count);
            form.addEventListener('submit', function () { if (inp.value.trim()) { add(inp.value); inp.value = ''; } });
            count();
        }

        // Select all in the "waiting for a second reviewer" list
        var all = document.getElementById('wait-all');
        if (all) all.addEventListener('change', function () {
            [].slice.call(document.querySelectorAll('.wait-pick')).forEach(function (c) { c.checked = all.checked; });
        });

        // A double click cannot send a form twice (the batch token is the server-side guard as well).
        [].slice.call(document.querySelectorAll('form[data-once]')).forEach(function (f) {
            f.addEventListener('submit', function () {
                setTimeout(function () {
                    [].slice.call(f.querySelectorAll('button[type=submit]')).forEach(function (b) { b.disabled = true; });
                }, 0);
            });
        });

        // The preview is the thing to look at after pressing Preview.
        var pv = document.getElementById('bk-preview');
        if (pv) pv.scrollIntoView({ block: 'start' });

        // A short confetti burst when people got points. Nothing at all for reduced motion.
        var win = document.getElementById('bk-win');
        if (win && win.dataset.celebrate === '1' && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            var cv = document.createElement('canvas');
            cv.setAttribute('aria-hidden', 'true');
            cv.style.cssText = 'position:fixed;inset:0;width:100%;height:100%;pointer-events:none;z-index:9999';
            document.body.appendChild(cv);
            var ctx = cv.getContext('2d'), W = cv.width = window.innerWidth, H = cv.height = window.innerHeight;
            var colors = ['#106b9a', '#36b3a0', '#f59e0b', '#e11d48', '#8b5cf6', '#22c55e'];
            var bits = [];
            for (var i = 0; i < 130; i++) bits.push({
                x: W / 2 + (Math.random() - .5) * 160, y: H * .35, vx: (Math.random() - .5) * 13, vy: -Math.random() * 12 - 3,
                s: 5 + Math.random() * 6, r: Math.random() * 6, vr: (Math.random() - .5) * .4, c: colors[i % colors.length]
            });
            var t0 = performance.now();
            (function frame(t) {
                var age = t - t0;
                ctx.clearRect(0, 0, W, H);
                bits.forEach(function (b) {
                    b.vy += .35; b.x += b.vx; b.y += b.vy; b.r += b.vr;
                    ctx.save(); ctx.translate(b.x, b.y); ctx.rotate(b.r);
                    ctx.globalAlpha = Math.max(0, 1 - age / 1900);
                    ctx.fillStyle = b.c; ctx.fillRect(-b.s / 2, -b.s / 3, b.s, b.s * .66); ctx.restore();
                });
                if (age < 1900) requestAnimationFrame(frame); else cv.remove();
            })(t0);
        }
    })();
    </script>

<?php elseif ($tab === 'guide'): ?>
    <?php
    // Read-only: every activity that can be awarded right now, grouped by category. $types is activityTypes() (active only).
    $pgGroups = [];
    foreach ($types as $t) $pgGroups[(string) $t['category']][] = $t;
    $pgOrder = array_values(array_unique(array_merge(array_intersect($gdCatKeys, array_keys($pgGroups)), array_keys($pgGroups))));
    ?>

    <div class="pg-hero">
        <div>
            <h3>What earns points</h3>
            <p>Every activity members can earn points for right now, and what each one is worth. This is a read-only view; to change points, use the Activities and points tab.</p>
        </div>
        <div class="pg-count"><b><?= count($types) ?></b><span>active activities</span></div>
    </div>

    <?php if (!$types): ?>
        <div class="gd-empty gd-card">No activities are switched on yet. Add one in Activities and points.</div>
    <?php endif; ?>

    <?php foreach ($pgOrder as $cat): $rows = $pgGroups[$cat]; ?>
        <section class="pg-group" style="--h:<?= $gdHue($cat) ?>">
            <h4><?= htmlspecialchars(PointsModel::CATEGORIES[$cat] ?? $cat) ?> <span><?= count($rows) ?></span></h4>
            <div class="pg-grid">
                <?php foreach ($rows as $t): $hasMin = $t['min_attendees'] !== null && $t['min_attendees'] !== ''; ?>
                    <div class="pg-card">
                        <div class="pg-name"><?= htmlspecialchars((string) $t['name']) ?></div>
                        <div class="pg-foot">
                            <div class="pg-tags">
                                <?php if ((int) $t['needs_evidence']): ?><span class="ac-tag">Evidence link</span><?php endif; ?>
                                <?php if ((int) ($t['one_time'] ?? 0)): ?><span class="ac-tag">Once per member</span><?php endif; ?>
                                <?php if ($hasMin): ?><span class="ac-tag">Host: <?= (int) $t['below_min_points'] ?> pts under <?= (int) $t['min_attendees'] ?> attendees</span><?php endif; ?>
                            </div>
                            <div class="pg-pts"><?= number_format((int) $t['points']) ?><small>points</small></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endforeach; ?>

    <p class="pg-edit">Need to change something? <a href="/admin/goodies?tab=activities">Edit activities and points</a>.</p>

<?php elseif ($tab === 'activities'): ?>
    <?php
    // Group by category, in the order PointsModel::CATEGORIES lists them; unknown categories go last.
    $groups = [];
    foreach ($allTypes as $t) $groups[(string) $t['category']][] = $t;
    $groupOrder = array_values(array_unique(array_merge(array_intersect($gdCatKeys, array_keys($groups)), array_keys($groups))));
    ?>

<?php if ($bulkMode): ?>

    <p style="margin:0 0 14px"><a class="ac-pill" href="/admin/goodies?tab=activities">← Back to activities</a></p>

    <?php if ($pointsPreview !== null): $pv = $pointsPreview; ?>
        <section class="pt-review" id="pt-review">
            <h3>Review before saving: nothing is saved yet</h3>
            <?php if ($pv['errors']): ?>
                <div class="alert error"><?php foreach ($pv['errors'] as $er): ?><div><?= gd_h($er) ?></div><?php endforeach; ?></div>
            <?php endif; ?>
            <?php if (!$pv['rows'] && !$pv['errors']): ?>
                <p class="gd-note">Nothing has changed.</p>
            <?php elseif ($pv['rows']): ?>
                <div class="pt-wrap" style="margin:0 0 12px">
                    <table class="pt">
                        <thead><tr><th>Activity</th><th>Points</th><th>Awards revised</th><th>Members</th><th>Net change</th></tr></thead>
                        <tbody>
                        <?php foreach ($pv['rows'] as $row): ?>
                            <tr>
                                <td><strong><?= gd_h($row['name']) ?></strong>
                                    <?php if (!empty($row['crosses'])): ?><span class="pt-sub" style="color:#92400e">Now <?= (int) PointsModel::SECOND_APPROVAL_AT ?>+ points: new awards by non-owner reviewers will need a second reviewer. Past awards are revised without one.</span><?php endif; ?></td>
                                <td class="num"><?= (int) $row['old'] ?> → <strong><?= (int) $row['new'] ?></strong></td>
                                <td class="num"><?= (int) $row['revised'] ?></td>
                                <td class="num"><?= (int) $row['members'] ?></td>
                                <td class="num <?= $row['net'] < 0 ? 'neg' : ($row['net'] > 0 ? 'pos' : '') ?>"><?= sprintf('%+d', $row['net']) ?> pts</td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <p><strong><?= count($pv['rows']) ?></strong> activit<?= count($pv['rows']) === 1 ? 'y' : 'ies' ?> · <strong><?= (int) $pv['awards'] ?></strong> award(s) revised · <strong><?= (int) $pv['members'] ?></strong> member(s) affected ·
                    net <span class="<?= $pv['net'] < 0 ? 'neg' : 'pos' ?>"><?= sprintf('%+d', $pv['net']) ?> points</span></p>
                <?php if ($pv['negative'] > 0): ?>
                    <p class="gd-warn"><strong><?= (int) $pv['negative'] ?></strong> member(s) would end up with a balance below zero, because these cuts reach points they have already spent.</p>
                <?php endif; ?>
                <?php if (!$pv['errors']): ?>
                    <form method="POST" action="/admin/goodies?tab=activities&amp;mode=bulk" data-once style="margin-top:14px">
                        <?= CSRF::getInputField() ?>
                        <input type="hidden" name="do" value="points_save">
                        <?php foreach ($pv['changes'] as $cid => $c): ?>
                            <input type="hidden" name="changes[<?= (int) $cid ?>][points]" value="<?= (int) $c['points'] ?>">
                            <?php if ($c['below'] !== null): ?><input type="hidden" name="changes[<?= (int) $cid ?>][below]" value="<?= (int) $c['below'] ?>"><?php endif; ?>
                        <?php endforeach; ?>
                        <label class="gd-tg" style="margin:0 0 12px"><input type="checkbox" name="understood" value="1" required> I understand past awards will be revised and balances will move</label>
                        <div><button type="submit" class="gd-btn">Save all changes</button> <span class="gd-note" style="margin-left:8px">All or nothing: if anything fails, nothing is changed.</span></div>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <p class="gd-info"><strong>Changing points revises past awards.</strong> Every award already made for an activity moves each member’s balance by the difference, and it shows in their history. You will see how many awards and members are affected before anything is saved.</p>

    <form method="POST" action="/admin/goodies?tab=activities&amp;mode=bulk" id="pt-form">
        <?= CSRF::getInputField() ?>
        <input type="hidden" name="do" value="points_preview">

        <div class="pt-tools" role="group" aria-label="Quick changes">
            <select id="pt-scope" aria-label="Apply to">
                <option value="">All activities</option>
                <?php foreach ($groupOrder as $c): ?><option value="<?= gd_h($c) ?>"><?= gd_h(PointsModel::CATEGORIES[$c] ?? $c) ?></option><?php endforeach; ?>
            </select>
            <select id="pt-op" aria-label="Change">
                <option value="add">Add points (use a minus to cut)</option>
                <option value="pct">Change by percent</option>
                <option value="set">Set all to</option>
            </select>
            <input type="number" id="pt-val" value="10" aria-label="Value">
            <button type="button" class="gd-btn line" id="pt-apply">Apply</button>
            <button type="button" class="gd-btn line" id="pt-reset">Reset</button>
            <span class="grow" id="pt-count">No changes yet</span>
        </div>

        <div class="pt-wrap">
            <table class="pt">
                <thead><tr><th>Activity</th><th>Awarded</th><th>Now</th><th>New points</th></tr></thead>
                <tbody>
                <?php foreach ($groupOrder as $cat): ?>
                    <tr class="cat" style="--h:<?= $gdHue($cat) ?>"><td colspan="4"><?= gd_h(PointsModel::CATEGORIES[$cat] ?? $cat) ?></td></tr>
                    <?php foreach ($groups[$cat] as $t):
                        $id = (int) $t['id'];
                        $hasBelow = $t['below_min_points'] !== null;
                        $curP = (int) ($pointsTyped[$id]['points'] ?? $t['points']);
                        $curB = $hasBelow ? ($pointsTyped[$id]['below'] ?? (int) $t['below_min_points']) : null;
                    ?>
                        <tr data-id="<?= $id ?>" data-cat="<?= gd_h($cat) ?>" data-points="<?= (int) $t['points'] ?>" data-below="<?= $hasBelow ? (int) $t['below_min_points'] : '' ?>"<?= (int) $t['is_active'] ? '' : ' class="off"' ?>>
                            <td><span class="pt-n"><?= gd_h($t['name']) ?></span>
                                <?php if (!(int) $t['is_active']): ?> <span class="ac-tag off">Switched off</span><?php endif; ?>
                                <?php if ($hasBelow): ?><span class="pt-sub">Host points under <?= (int) $t['min_attendees'] ?> attendees</span><?php endif; ?></td>
                            <td class="num"><?= (int) ($usage[$id] ?? 0) ?></td>
                            <td class="num"><?= (int) $t['points'] ?><?= $hasBelow ? '<span class="pt-sub">' . (int) $t['below_min_points'] . '</span>' : '' ?></td>
                            <td>
                                <input type="number" class="pt-new" name="changes[<?= $id ?>][points]" value="<?= $curP ?>" min="1" max="10000" required aria-label="New points for <?= gd_h($t['name']) ?>">
                                <?php if ($hasBelow): ?><span class="pt-sub"><input type="number" class="pt-below" name="changes[<?= $id ?>][below]" value="<?= (int) $curB ?>" min="0" max="10000" aria-label="New points under the minimum for <?= gd_h($t['name']) ?>"></span><?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p style="margin:16px 0 0"><button type="submit" class="gd-btn" id="pt-go" disabled>Review changes</button></p>
    </form>

    <script>
    (function () {
        var f = document.getElementById('pt-form');
        var rows = [].slice.call(f.querySelectorAll('tr[data-id]'));
        var counter = document.getElementById('pt-count'), go = document.getElementById('pt-go');
        function mark() {
            var n = 0;
            rows.forEach(function (r) {
                var p = r.querySelector('.pt-new'), b = r.querySelector('.pt-below');
                var ch = p.value !== r.dataset.points || (b && b.value !== r.dataset.below);
                r.classList.toggle('chg', !!ch);
                if (ch) n++;
            });
            counter.textContent = n ? n + ' changed' : 'No changes yet';
            go.disabled = !n;
        }
        rows.forEach(function (r) { r.addEventListener('input', mark); });
        document.getElementById('pt-apply').addEventListener('click', function () {
            var scope = document.getElementById('pt-scope').value, op = document.getElementById('pt-op').value;
            var v = parseInt(document.getElementById('pt-val').value, 10);
            if (isNaN(v)) return;
            rows.forEach(function (r) {
                if (scope && r.dataset.cat !== scope) return;
                var p = r.querySelector('.pt-new'), cur = parseInt(p.value, 10) || 0;
                var nv = op === 'add' ? cur + v : (op === 'pct' ? Math.round(cur * (100 + v) / 100) : v);
                p.value = Math.max(1, Math.min(10000, nv));
            });
            mark();
        });
        document.getElementById('pt-reset').addEventListener('click', function () {
            rows.forEach(function (r) {
                r.querySelector('.pt-new').value = r.dataset.points;
                var b = r.querySelector('.pt-below'); if (b) b.value = r.dataset.below;
            });
            mark();
        });
        mark();
        var rv = document.getElementById('pt-review');
        if (rv) rv.scrollIntoView({ block: 'start' });
    })();
    </script>

<?php else: ?>

    <div class="ac-bar">
        <input type="search" id="ac-q" placeholder="Search activities…" aria-label="Search activities">
        <div class="ac-pills" id="ac-cats" role="group" aria-label="Filter by category">
            <button type="button" class="ac-pill on" data-cat="">All</button>
            <?php foreach ($groupOrder as $c): ?><button type="button" class="ac-pill" data-cat="<?= htmlspecialchars($c) ?>"><?= htmlspecialchars(PointsModel::CATEGORIES[$c] ?? $c) ?></button><?php endforeach; ?>
        </div>
        <label class="ac-tick"><input type="checkbox" id="ac-hideoff"> Hide switched off</label>
        <a class="ac-pill" href="/admin/goodies?tab=activities&amp;mode=bulk" style="margin-left:auto">Edit points in bulk</a>
    </div>

    <details class="gd-add" style="margin:0 0 22px">
        <summary>＋ Add an activity</summary>
        <section class="gd-card">
            <form method="POST" action="/admin/goodies?tab=activities" class="gd-form">
                <?= CSRF::getInputField() ?>
                <input type="hidden" name="do" value="activity_save">
                <label class="wide">Name<input type="text" name="name" required maxlength="160" placeholder="Attend a Wiki Loves Monuments walk"></label>
                <label>Category
                    <select name="category" required>
                        <?php foreach (PointsModel::CATEGORIES as $key => $label): ?><option value="<?= $key ?>"><?= htmlspecialchars($label) ?></option><?php endforeach; ?>
                    </select>
                </label>
                <label>Points<input type="number" name="points" min="1" max="10000" required></label>
                <div class="ac-flags">
                    <label class="gd-tg"><input type="checkbox" name="needs_evidence" value="1"> Needs an evidence link</label>
                    <label class="gd-tg"><input type="checkbox" name="one_time" value="1"> Only once per member</label>
                    <label class="gd-tg"><input type="checkbox" name="is_active" value="1" checked> Can be awarded</label>
                </div>
                <div class="ac-foot"><button type="submit" class="gd-btn">Add activity</button></div>
            </form>
        </section>
    </details>

    <p class="gd-info"><strong>Changing points revises past awards.</strong> Every award already made for that activity moves each member’s balance by the difference, and it shows in their history. Awards of <?= (int) PointsModel::SECOND_APPROVAL_AT ?> points or more made by a reviewer need a second reviewer (awards made by an owner are the exception). An activity that has been awarded can be switched off but not deleted.</p>

    <?php foreach ($groupOrder as $cat): $rows = $groups[$cat]; ?>
        <section class="ac-group" data-cat="<?= htmlspecialchars($cat) ?>" style="--h:<?= $gdHue($cat) ?>">
            <h4><?= htmlspecialchars(PointsModel::CATEGORIES[$cat] ?? $cat) ?> <span><?= count($rows) ?></span></h4>
            <?php foreach ($rows as $t):
                $id = (int) $t['id'];
                $used = (int) ($usage[$id] ?? 0);
                $builtIn = in_array((string) $t['code'], PointsModel::BUILT_IN_CODES, true);
                $on = (int) $t['is_active'] === 1;
                $hasMin = $t['min_attendees'] !== null && $t['min_attendees'] !== '';
            ?>
                <details class="ac<?= $on ? '' : ' off' ?>" data-name="<?= htmlspecialchars(mb_strtolower((string) $t['name'])) ?>">
                    <summary>
                        <div>
                            <span class="ac-name"><?= htmlspecialchars((string) $t['name']) ?></span>
                            <span class="ac-meta">
                                <?php if (!$on): ?><span class="ac-tag off">Switched off</span><?php endif; ?>
                                <?php if ((int) $t['points'] >= PointsModel::SECOND_APPROVAL_AT): ?><span class="ac-tag warn">Needs 2nd approval</span><?php endif; ?>
                                <?php if ((int) $t['needs_evidence']): ?><span class="ac-tag">Evidence link</span><?php endif; ?>
                                <?php if ((int) ($t['one_time'] ?? 0)): ?><span class="ac-tag">Once per member</span><?php endif; ?>
                                <?php if ($hasMin): ?><span class="ac-tag">Host: <?= (int) $t['below_min_points'] ?> pts under <?= (int) $t['min_attendees'] ?> attendees</span><?php endif; ?>
                                <?php if ($builtIn): ?><span class="ac-tag">Used by the app</span><?php endif; ?>
                                <span><?= $used ? 'Awarded ' . $used . ' time' . ($used === 1 ? '' : 's') : 'Not awarded yet' ?></span>
                            </span>
                        </div>
                        <span class="ac-pts"><?= number_format((int) $t['points']) ?><small>points</small></span>
                        <span class="ac-edit" aria-hidden="true"></span>
                    </summary>

                    <form method="POST" action="/admin/goodies?tab=activities" class="gd-form ac-form gd-activity"
                          data-used="<?= $used ?>" data-points="<?= (int) $t['points'] ?>" data-below="<?= $t['below_min_points'] === null ? '' : (int) $t['below_min_points'] ?>">
                        <?= CSRF::getInputField() ?>
                        <input type="hidden" name="do" value="activity_save">
                        <input type="hidden" name="id" value="<?= $id ?>">
                        <label class="wide">Name<input type="text" name="name" value="<?= htmlspecialchars((string) $t['name']) ?>" required maxlength="160"></label>
                        <label>Category
                            <select name="category" required>
                                <?php foreach (PointsModel::CATEGORIES as $key => $label): ?><option value="<?= $key ?>"<?= $t['category'] === $key ? ' selected' : '' ?>><?= htmlspecialchars($label) ?></option><?php endforeach; ?>
                            </select>
                        </label>
                        <label>Points<input type="number" name="points" value="<?= (int) $t['points'] ?>" min="1" max="10000" required></label>
                        <label>Minimum attendance<small>Optional. For hosts only.</small><input type="number" name="min_attendees" value="<?= $t['min_attendees'] === null ? '' : (int) $t['min_attendees'] ?>" min="1" max="1000"></label>
                        <label>Points under the minimum<small>What the host earns below it.</small><input type="number" name="below_min_points" value="<?= $t['below_min_points'] === null ? '' : (int) $t['below_min_points'] ?>" min="0" max="10000"></label>
                        <div class="ac-flags">
                            <label class="gd-tg"><input type="checkbox" name="needs_evidence" value="1"<?= (int) $t['needs_evidence'] ? ' checked' : '' ?>> Needs an evidence link</label>
                            <label class="gd-tg"><input type="checkbox" name="one_time" value="1"<?= (int) ($t['one_time'] ?? 0) ? ' checked' : '' ?>> Only once per member</label>
                            <label class="gd-tg"><input type="checkbox" name="is_active" value="1"<?= $on ? ' checked' : '' ?>> Can be awarded</label>
                        </div>
                        <div class="ac-foot">
                            <button type="submit" class="gd-btn">Save changes</button>
                            <?php if (!$used && !$builtIn): ?>
                                <button type="submit" class="gd-btn red grow" form="del-<?= $id ?>" formnovalidate
                                        onclick="return confirm('Delete this activity? It has never been awarded.')">Delete activity</button>
                            <?php endif; ?>
                        </div>
                    </form>
                    <?php if (!$used && !$builtIn): ?>
                        <form method="POST" action="/admin/goodies?tab=activities" id="del-<?= $id ?>">
                            <?= CSRF::getInputField() ?>
                            <input type="hidden" name="do" value="activity_delete"><input type="hidden" name="id" value="<?= $id ?>">
                        </form>
                    <?php endif; ?>
                </details>
            <?php endforeach; ?>
        </section>
    <?php endforeach; ?>
    <div class="gd-empty gd-card" id="ac-none" hidden>No activity matches. Clear the search or pick another category.</div>

    <script>
    // Changing the points of an activity that has been awarded revises those awards: say so before saving.
    document.querySelectorAll('form.gd-activity').forEach(function (f) {
        f.addEventListener('submit', function (ev) {
            if (ev.submitter && ev.submitter.getAttribute('form')) return; // the delete button
            var used = +f.dataset.used, p = f.elements.points.value, b = f.elements.below_min_points.value;
            if (used > 0 && (p !== f.dataset.points || b !== f.dataset.below)
                && !confirm('This activity has been awarded ' + used + ' time(s). Saving new points revises all of those awards, and members\' balances move by the difference. Continue?')) {
                ev.preventDefault();
            }
        });
    });
    // Search, category and "hide switched off" filters. Nothing is sent to the server.
    (function () {
        var q = document.getElementById('ac-q'), off = document.getElementById('ac-hideoff'), cat = '';
        var items = [].slice.call(document.querySelectorAll('.ac-group .ac'));
        function run() {
            var s = q.value.trim().toLowerCase();
            items.forEach(function (el) {
                el.hidden = !((!s || el.dataset.name.indexOf(s) > -1) && (!off.checked || !el.classList.contains('off')));
            });
            document.querySelectorAll('.ac-group').forEach(function (g) {
                g.hidden = !((!cat || g.dataset.cat === cat) && g.querySelector('.ac:not([hidden])'));
            });
            document.getElementById('ac-none').hidden = !!document.querySelector('.ac-group:not([hidden])');
        }
        q.addEventListener('input', run);
        off.addEventListener('change', run);
        document.getElementById('ac-cats').addEventListener('click', function (e) {
            var b = e.target.closest('.ac-pill'); if (!b) return;
            cat = b.dataset.cat;
            document.querySelectorAll('#ac-cats .ac-pill').forEach(function (x) { x.classList.toggle('on', x === b); });
            run();
        });
    })();
    </script>

<?php endif; ?>

<?php elseif ($tab === 'catalogue'): ?>

    <details class="gd-add" style="margin:0 0 22px">
        <summary>＋ Add a goody</summary>
        <section class="gd-card">
            <form method="POST" action="/admin/goodies?tab=catalogue" class="gd-form">
                <?= CSRF::getInputField() ?>
                <input type="hidden" name="do" value="save_goody">
                <label>Name<input type="text" name="name" required maxlength="120"></label>
                <label>Cost (points)<input type="number" name="points_cost" min="1" max="100000" required></label>
                <label>Sizes or options<small>Comma separated, optional</small><input type="text" name="variants" placeholder="S, M, L, XL"></label>
                <label class="wide">Description<input type="text" name="description" maxlength="500"></label>
                <?php $gdStock(null); ?>
                <div class="ac-flags"><label class="gd-tg"><input type="checkbox" name="is_active" value="1" checked> Show to members</label></div>
                <div class="ac-foot"><button type="submit" class="gd-btn">Add goody</button></div>
            </form>
        </section>
    </details>

    <p class="gd-info">Members only see goodies that are switched on. Changing a cost never affects orders already placed. With <strong>Track stock</strong> on, each order takes one item from stock and a cancelled order puts it back.</p>

    <section>
        <h4 style="margin:0 0 10px;font-size:15px">Catalogue <span style="color:var(--muted);font-weight:500;font-size:13px"><?= count($goodies) ?></span></h4>
        <?php if (!$goodies): ?><div class="gd-empty gd-card">No goodies yet. Add the first one above.</div><?php endif; ?>
        <?php foreach ($goodies as $g): $on = (int) $g['is_active'] === 1; ?>
            <details class="ac<?= $on ? '' : ' off' ?>" style="--h:<?= abs(crc32((string) $g['name'])) % 360 ?>">
                <summary>
                    <div>
                        <span class="ac-name"><?= htmlspecialchars($g['name']) ?></span>
                        <span class="ac-meta">
                            <?php if (!$on): ?><span class="ac-tag off">Hidden from members</span><?php endif; ?>
                            <?php
                            $tracked = $g['stock'] !== null;
                            if (!PointsModel::inStock($g)): ?><span class="ac-tag warn">Out of stock</span><?php endif;
                            if ($g['variants']):
                                foreach ($g['variants'] as $v): $n = PointsModel::stockFor($g, $v); ?>
                                    <span class="ac-tag<?= $n !== null && $n < 1 ? ' warn' : '' ?>"><?= htmlspecialchars($v) ?><?= $n !== null ? ': ' . $n : '' ?></span>
                                <?php endforeach;
                            elseif ($tracked): ?><span class="ac-tag<?= PointsModel::inStock($g) ? '' : ' warn' ?>"><?= (int) PointsModel::stockFor($g) ?> in stock</span><?php endif; ?>
                            <?php if ((string) $g['description'] !== ''): ?><span><?= htmlspecialchars(mb_strimwidth((string) $g['description'], 0, 90, '…')) ?></span><?php endif; ?>
                        </span>
                    </div>
                    <span class="ac-pts"><?= number_format((int) $g['points_cost']) ?><small>points</small></span>
                    <span class="ac-edit" aria-hidden="true"></span>
                </summary>
                <form method="POST" action="/admin/goodies?tab=catalogue" class="gd-form ac-form">
                    <?= CSRF::getInputField() ?>
                    <input type="hidden" name="do" value="save_goody">
                    <input type="hidden" name="id" value="<?= (int) $g['id'] ?>">
                    <label>Name<input type="text" name="name" value="<?= htmlspecialchars($g['name']) ?>" required maxlength="120"></label>
                    <label>Cost (points)<input type="number" name="points_cost" value="<?= (int) $g['points_cost'] ?>" min="1" max="100000" required></label>
                    <label>Options<small>Comma separated</small><input type="text" name="variants" value="<?= htmlspecialchars(implode(', ', $g['variants'])) ?>"></label>
                    <label class="wide">Description<input type="text" name="description" value="<?= htmlspecialchars((string) $g['description']) ?>" maxlength="500"></label>
                    <?php $gdStock($g); ?>
                    <div class="ac-flags"><label class="gd-tg"><input type="checkbox" name="is_active" value="1"<?= $on ? ' checked' : '' ?>> Show to members</label></div>
                    <div class="ac-foot"><button type="submit" class="gd-btn">Save changes</button></div>
                </form>
            </details>
        <?php endforeach; ?>
    </section>

    <script>
    // Stock rows follow the "Sizes or options" field: one number per size, or one number when there are no sizes.
    document.querySelectorAll('.gd-stock').forEach(function (box) {
        var form = box.closest('form'), vin = form.querySelector('input[name=variants]');
        var track = box.querySelector('.st-track'), rows = box.querySelector('.st-rows');
        track.addEventListener('change', function () { rows.hidden = !track.checked; });
        vin.addEventListener('input', function () {
            var old = {};
            rows.querySelectorAll('.st-row').forEach(function (r) {
                old[r.querySelector('[name="stock_name[]"]').value] = r.querySelector('[name="stock_qty[]"]').value;
            });
            var names = vin.value.split(',').map(function (s) { return s.trim(); }).filter(function (s, i, a) { return s && a.indexOf(s) === i; });
            if (!names.length) names = [''];
            rows.innerHTML = '';
            names.forEach(function (n) {
                var l = document.createElement('label'); l.className = 'st-row';
                var sp = document.createElement('span'); sp.textContent = n || 'In stock';
                var h = document.createElement('input'); h.type = 'hidden'; h.name = 'stock_name[]'; h.value = n;
                var q = document.createElement('input'); q.type = 'number'; q.name = 'stock_qty[]'; q.min = 0; q.max = 1000000;
                q.value = old[n] !== undefined ? old[n] : 0;
                l.appendChild(sp); l.appendChild(h); l.appendChild(q); rows.appendChild(l);
            });
        });
    });
    </script>

<?php else: ?>

    <nav class="gd-nav" aria-label="Filter orders">
        <?php foreach (array_merge([''], array_keys(PointsModel::REDEMPTION_FLOW)) as $s): ?>
            <a href="/admin/goodies?tab=redemptions<?= $s !== '' ? '&amp;status=' . rawurlencode($s) : '' ?>"<?= $statusFilter === $s ? ' class="on"' : '' ?>><?= $s === '' ? 'All' : htmlspecialchars($s) ?></a>
        <?php endforeach; ?>
    </nav>

    <section class="gd-card">
        <?php if (!$orders): ?>
            <div class="gd-empty">No orders here.</div>
        <?php else: foreach ($orders as $o): $ship = $o['shipping']; ?>
            <div class="gd-row">
                <div>
                    <strong><?= htmlspecialchars($o['goody_name']) ?><?= $o['variant'] ? ' (' . htmlspecialchars((string) $o['variant']) . ')' : '' ?> · <?= (int) $o['points_spent'] ?> pts</strong>
                    <p><code><?= htmlspecialchars((string) $o['tracking_id']) ?></code> · Member <?= htmlspecialchars((string) $o['member_id']) ?> · <?= htmlspecialchars($when($o['created_at'])) ?> · <strong><?= htmlspecialchars((string) $o['status']) ?></strong></p>
                    <?php if ($ship): ?>
                        <p>Ship to <?= htmlspecialchars((string) ($ship['name'] ?? '')) ?>, <?= htmlspecialchars((string) ($ship['phone'] ?? '')) ?> · <?= nl2br(htmlspecialchars((string) ($ship['address'] ?? ''))) ?></p>
                    <?php elseif (in_array($o['status'], ['Delivered', 'Cancelled'], true)): ?>
                        <p>Shipping details cleared.</p>
                    <?php endif; ?>
                </div>
                <div>
                    <?php foreach (PointsModel::REDEMPTION_FLOW[$o['status']] as $next): ?>
                        <form method="POST" action="/admin/goodies?tab=redemptions"><?= CSRF::getInputField() ?>
                            <input type="hidden" name="do" value="order_status"><input type="hidden" name="id" value="<?= (int) $o['id'] ?>"><input type="hidden" name="to" value="<?= htmlspecialchars($next) ?>">
                            <button type="submit" class="gd-btn <?= $next === 'Cancelled' ? 'red' : 'line' ?>"<?= $next === 'Cancelled' ? ' onclick="return confirm(\'Cancel this order and refund the points?\')"' : '' ?>><?= $next === 'Cancelled' ? 'Cancel and refund' : 'Mark ' . htmlspecialchars(strtolower($next)) ?></button>
                        </form>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; endif; ?>
    </section>

<?php endif; ?>

<?php engage_footer(); ?>
