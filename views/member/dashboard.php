<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/wikitext.php';
require_once __DIR__ . '/../../includes/engage_page.php';
require_once __DIR__ . '/../../models/MemberAuthModel.php';
require_once __DIR__ . '/../../models/MemberModel.php';
require_once __DIR__ . '/../../models/FormModel.php';
require_once __DIR__ . '/../../models/InternetSupportModel.php';
require_once __DIR__ . '/../../models/ReimbursementSettingsModel.php';
require_once __DIR__ . '/../../models/ReimbursementModel.php';
require_once __DIR__ . '/../../models/MemberTicketModel.php';
require_once __DIR__ . '/../../models/TechIssueModel.php';
require_once __DIR__ . '/../../models/MemberCertificateModel.php'; // NEW
require_once __DIR__ . '/../../includes/member_session.php';
require_once __DIR__ . '/../../includes/member_requests.php';

/**
 * DCW Engage - member dashboard (/member/dashboard).
 * Signed-in members only. Shows membership status, what they can request, and their own requests.
 * Everything is read with the member's own email, so nobody sees anyone else's requests.
 *
 * One Member ID can cover several memberships (the DCW Generic Community plus any clubs).
 * They are all listed under the ID the member signed in with.
 */
MemberSession::requireLogin();
$member = MemberSession::current();

$email        = strtolower((string) ($member['email'] ?? ''));
$fullName     = trim((string) ($member['full_name'] ?? ''));
$firstName    = $fullName !== '' ? explode(' ', $fullName)[0] : (string) $member['member_id'];

// ---- Membership status (stored UTC, shown in IST) ----------------------------------------------
$utc = new DateTimeZone('UTC');
$ist = new DateTimeZone('Asia/Kolkata');

/** Tone, label and line for one membership row. */
function dash_member_state(array $m, DateTimeZone $utc, DateTimeZone $ist): array
{
    $active   = MemberAuthModel::isActive($m);
    $expires  = !empty($m['expires_at']) ? new DateTimeImmutable((string) $m['expires_at'], $utc) : null;
    $daysLeft = $expires ? (int) floor(($expires->getTimestamp() - time()) / 86400) : null;
    $text     = $expires ? $expires->setTimezone($ist)->format('j M Y') : '';

    if ($active && $daysLeft !== null && $daysLeft > 30) {
        return ['tone' => '#0f766e', 'label' => 'Active member', 'line' => 'Valid until ' . $text, 'renew' => false, 'active' => true];
    }
    if ($active) {
        return ['tone' => '#b45309', 'label' => 'Expiring soon',
                'line' => 'Valid until ' . $text . ($daysLeft <= 0 ? ' (today)' : ' (' . $daysLeft . ' day' . ($daysLeft === 1 ? '' : 's') . ' left)'),
                'renew' => true, 'active' => true];
    }
    if (($m['status'] ?? '') === 'active') {
        return ['tone' => '#97161b', 'label' => 'Expired', 'line' => 'Your membership ended on ' . $text, 'renew' => true, 'active' => false];
    }
    return ['tone' => '#97161b', 'label' => 'Not active', 'line' => 'Your membership is not active right now.', 'renew' => true, 'active' => false];
}

// Every membership under this Member ID. Falls back to the signed-in row if the lookup fails.
$memberships = [];
try { $memberships = (new MemberModel())->membershipsFor((string) $member['member_id']); } catch (Throwable $e) { /* use the session row */ }
if (!$memberships) $memberships = [$member];

$strips = [];
$memberActive = false;
foreach ($memberships as $m) {
    $state = dash_member_state($m, $utc, $ist);
    $key = trim((string) ($m['chapter'] ?? ''));
    $state['name'] = MemberModel::CHAPTER_NAMES[$key] ?? $key;
    $strips[] = $state;
    if ($state['active']) $memberActive = true;
}

// ---- What is open (each guarded so the page never breaks) -------------------------------------
try { $internetOpen = (new InternetSupportModel())->isOpen(); } catch (Throwable $e) { $internetOpen = false; }
try {
    $rs = (new ReimbursementSettingsModel())->get();
    $reimbursementOpen = $rs && !empty($rs['is_active']);
} catch (Throwable $e) { $reimbursementOpen = false; }
try {
    $programs = array_values(array_filter(
        (new FormModel())->getActiveForms(),
        fn($f) => !str_starts_with((string) $f['form_type'], 'membership-')
    ));
} catch (Throwable $e) { $programs = []; }

// ---- The member's own requests -----------------------------------------------------------------
// Needs InternetSupportModel::listForMember() and ReimbursementModel::listForMember().
// dash_status() / dash_date() / dash_money() live in includes/member_requests.php (shared with /member/request).
// Applicants never see internal states: "Recharge Failed" and "Payment Failed" show as "In review".
//
// Each row carries three figures:
//   amount   what the member asked for
//   approved what the reviewer approved (same as amount unless changed)
//   paid     what finance actually recharged / paid, or null until money has moved
$requests = [];
try {
    foreach ((new InternetSupportModel())->listForMember($email) as $r) {
        [$label, $tone, $act] = dash_status('internet', (string) $r['status']);
        $requests[] = ['type' => 'Internet support', 'icon' => 'wifi', 'title' => $r['package_name'],
            'amount' => (int) $r['package_price_paise'],
            'approved' => (int) $r['approved_paise'],
            'paid' => $r['paid_paise'] !== null ? (int) $r['paid_paise'] : null,
            'paid_label' => 'Recharged',
            'tracking' => $r['tracking_id'], 'created' => $r['created_at'],
            'label' => $label, 'tone' => $tone, 'act' => $act];
    }
} catch (Throwable $e) { /* method not added yet, or table missing: show nothing rather than break */ }
try {
    foreach ((new ReimbursementModel())->listForMember($email) as $r) {
        [$label, $tone, $act] = dash_status('reimbursement', (string) $r['status']);
        $requests[] = ['type' => 'Reimbursement', 'icon' => 'card', 'title' => $r['event_name'],
            'amount' => (int) $r['total_amount_paise'],
            'approved' => (int) $r['approved_paise'],
            'paid' => $r['paid_paise'] !== null ? (int) $r['paid_paise'] : null,
            'paid_label' => 'Paid',
            'tracking' => $r['tracking_id'], 'created' => $r['created_at'],
            'label' => $label, 'tone' => $tone, 'act' => $act];
    }
} catch (Throwable $e) { }
usort($requests, fn($a, $b) => strcmp((string) $b['created'], (string) $a['created']));
$totalRequests = count($requests);
$requests = array_slice($requests, 0, 8);
$needsAction = array_values(array_filter($requests, fn($r) => $r['act']));

// ---- The member's conversations with DCW Support (looked up by Member ID from the session) ------
$tickets = [];
try { $tickets = (new MemberTicketModel())->listForMember((string) $member['member_id']); } catch (Throwable $e) { /* table not created yet */ }
$ticketsWaiting = array_values(array_filter($tickets,
    fn($t) => $t['last_sender'] === 'staff' && !MemberTicketModel::isClosed((string) $t['status'])));

// ---- Technical reports where the technical team answered last ----------------------------------
$techWaiting = [];
try { $techWaiting = (new TechIssueModel())->awaitingReporter('member', (string) $member['member_id']); } catch (Throwable $e) { /* table not created yet */ }

// ---- NEW: certificates issued on certificates.dcwwiki.org, matched by the member's verified email
// Read from the view member_certificates_v. If the certificates database is unreachable or
// includes/certs_db.php is missing, the panel just shows the empty note; the dashboard never breaks.
$certs = [];
try { $certs = (new MemberCertificateModel())->listForMember($email); } catch (Throwable $e) { error_log('Engage certificates lookup failed: ' . $e->getMessage()); }
$certBase = 'https://certificates.dcwwiki.org';

$icons = [
    'doc'    => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/>',
    'wifi'   => '<path d="M5 12.55a11 11 0 0 1 14.08 0"/><path d="M1.42 9a16 16 0 0 1 21.16 0"/><path d="M8.53 16.11a6 6 0 0 1 6.95 0"/><line x1="12" y1="20" x2="12.01" y2="20"/>',
    'card'   => '<rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/>',
    'search' => '<circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>',
    'people' => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
    'chat'   => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
    'alert'  => '<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>',
];

/** One tile. $href = null renders a disabled tile with a short reason in the pill. */
function dash_tile(array $icons, string $tone, string $icon, string $title, string $desc, ?string $href, string $pill = ''): void
{
    $tag = $href === null ? 'div' : 'a';
    ?>
    <<?= $tag ?> class="tile<?= $href === null ? ' off' : '' ?>"<?= $href !== null ? ' href="' . htmlspecialchars($href) . '"' : '' ?> style="--tone: <?= htmlspecialchars($tone) ?>;">
        <span class="tick"><svg viewBox="0 0 24 24" aria-hidden="true"><?= $icons[$icon] ?></svg></span>
        <span><h3><?= htmlspecialchars($title) ?></h3><p><?= htmlspecialchars($desc) ?></p></span>
        <?php if ($pill !== ''): ?><span class="pill"><?= htmlspecialchars($pill) ?></span>
        <?php else: ?><span class="arrow" aria-hidden="true">→</span><?php endif; ?>
    </<?= $tag ?>>
    <?php
}

// Why a support tile is locked, if it is.
$lock = fn(bool $open) => !$memberActive ? 'Renew to unlock' : (!$open ? 'Closed right now' : '');

// Same page frame as the rest of Engage.
engage_header([
    'title'   => 'My dashboard',
    'heading' => 'Hello, ' . $firstName,
    'kicker'  => 'Member ID ' . $member['member_id'],
    'lead'    => 'Your membership, your requests, and everything open to you right now.',
    'member'  => $member,
    'tools'   => '<a class="chip-btn" href="/member/logout">Sign out</a>',
    'wide'    => true,
    'crumbs'  => [['Home', '/'], ['My dashboard']],
]);
?>
<style>
    /* Member dashboard only. Everything else comes from /assets/css/engage.css */
    .mstrip + .mstrip { margin-top: 14px; }
    .mstrip .which { margin: 0 0 4px; font-size: 13px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: var(--muted); }
</style>
        <?php foreach ($strips as $s): ?>
            <section class="mstrip" style="--tone: <?= $s['tone'] ?>;">
                <div>
                    <?php if ($s['name'] !== ''): ?><p class="which"><?= htmlspecialchars($s['name']) ?></p><?php endif; ?>
                    <span class="pill" style="--tone: <?= $s['tone'] ?>;"><?= htmlspecialchars($s['label']) ?></span>
                    <h2><?= htmlspecialchars($s['line']) ?></h2>
                    <p><?= htmlspecialchars((string) $member['member_id']) ?></p>
                </div>
                <?php if ($s['renew']): ?>
                    <a class="btn-pill" href="/membership">Renew membership</a>
                <?php endif; ?>
            </section>
        <?php endforeach; ?>

        <?php foreach ($needsAction as $r): ?>
            <div class="action-banner" role="status">
                <strong><?= htmlspecialchars($r['type']) ?>:</strong>
                <span><?= htmlspecialchars($r['title']) ?> &ndash; <?= htmlspecialchars(strtolower($r['label'])) ?>.</span>
                <a href="<?= htmlspecialchars(member_request_url($r['tracking'])) ?>">Open request</a>
            </div>
        <?php endforeach; ?>

        <?php foreach ($ticketsWaiting as $t): ?>
            <div class="action-banner" role="status">
                <strong><?= htmlspecialchars(MemberTicketModel::SUPPORT_LABEL) ?>:</strong>
                <span>New reply on &ldquo;<?= htmlspecialchars($t['subject']) ?>&rdquo;.</span>
                <a href="/member/talk/ticket?id=<?= htmlspecialchars(rawurlencode($t['tracking_id'])) ?>">Read it</a>
            </div>
        <?php endforeach; ?>

        <?php foreach ($techWaiting as $t): ?>
            <div class="action-banner" role="status">
                <strong>Technical team:</strong>
                <span>New reply on &ldquo;<?= htmlspecialchars($t['title']) ?>&rdquo;.</span>
                <a href="/member/report-problem?id=<?= htmlspecialchars(rawurlencode($t['tracking_id'])) ?>">Read it</a>
            </div>
        <?php endforeach; ?>

        <section class="panel">
            <div class="panel-head">
                <h2>Get support</h2>
                <p>Help with a data pack, claim back event expenses, talk to <?= htmlspecialchars(MemberTicketModel::SUPPORT_LABEL) ?>, or report a technical problem.</p>
            </div>
            <div class="tiles">
                <?php
                $l = $lock($internetOpen);
                dash_tile($icons, '#0f766e', 'wifi', 'Internet support', 'Help paying for a data pack so you can keep contributing.',
                    $l === '' ? '/member/support?type=internet' : null, $l);
                $l = $lock($reimbursementOpen);
                dash_tile($icons, '#0f766e', 'card', 'Reimbursement', 'Claim back expenses for a DCW-aligned event.',
                    $l === '' ? '/member/support?type=reimbursement' : null, $l);
                // Open to every signed-in member, including expired ones: someone may need to say why.
                dash_tile($icons, '#106b9a', 'chat', 'Talk to ' . MemberTicketModel::SUPPORT_LABEL,
                    'Ask a question, make a complaint, or share a suggestion.', '/member/talk');
                // Also open to every signed-in member, including expired ones.
                dash_tile($icons, '#0e7490', 'alert', 'Report a problem',
                    'Something not working on this site? Tell the technical team.', '/member/report-problem');
                ?>
            </div>
        </section>

        <section class="panel">
            <div class="panel-head">
                <h2>My requests</h2>
                <p>Your internet support and reimbursement requests. Open one to reply to a reviewer or upload a receipt.</p>
            </div>
            <?php if (empty($requests)): ?>
                <div class="empty-note">You have not made any requests yet. Pick one above to get started.</div>
            <?php else: ?>
                <div class="reqs">
                    <?php foreach ($requests as $r): ?>
                        <div class="req<?= $r['act'] ? ' act' : '' ?>" style="--tone: <?= htmlspecialchars($r['tone']) ?>;">
                            <span class="tick"><svg viewBox="0 0 24 24" aria-hidden="true"><?= $icons[$r['icon']] ?></svg></span>
                            <div>
                                <h3><?= htmlspecialchars($r['title']) ?></h3>
                                <p class="meta">
                                    <?= htmlspecialchars($r['type']) ?> &middot; Requested &#8377;<?= dash_money($r['amount']) ?>
                                    <?php if ($r['paid'] === null && $r['approved'] !== $r['amount']): ?>
                                        &middot; Approved &#8377;<?= dash_money($r['approved']) ?>
                                    <?php endif; ?>
                                    <?php if ($r['paid'] !== null): ?>
                                        &middot; <strong><?= htmlspecialchars($r['paid_label']) ?> &#8377;<?= dash_money($r['paid']) ?></strong>
                                    <?php endif; ?>
                                    &middot; <?= htmlspecialchars(dash_date($r['created'])) ?>
                                    &middot; <code><?= htmlspecialchars($r['tracking']) ?></code><button type="button" class="copy" data-copy="<?= htmlspecialchars($r['tracking']) ?>">Copy</button>
                                </p>
                            </div>
                            <div class="side">
                                <span class="pill"><?= htmlspecialchars($r['label']) ?></span>
                                <a href="<?= htmlspecialchars(member_request_url($r['tracking'])) ?>"><?= $r['act'] ? 'Act now' : 'View' ?> &rarr;</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php if ($totalRequests > count($requests)): ?>
                    <p class="meta" style="text-align:center; color:var(--muted); font-size:13px;">Showing your latest <?= count($requests) ?> of <?= $totalRequests ?> requests.</p>
                <?php endif; ?>
            <?php endif; ?>
        </section>

        <?php /* NEW: My certificates (from certificates.dcwwiki.org via the member_certificates_v view) */ ?>
        <section class="panel">
            <div class="panel-head">
                <h2>My certificates</h2>
                <p>Certificates issued to you for DCW events. Download them or share the verification link.</p>
            </div>
            <?php if (empty($certs)): ?>
                <div class="empty-note">No certificates have been issued to <?= htmlspecialchars($email) ?> yet.</div>
            <?php else: ?>
                <div class="reqs">
                    <?php foreach ($certs as $c): ?>
                        <?php
                        // Per-row base URL if the view provides one (multi-organisation), else the DCW portal.
                        $base = rtrim((string) (!empty($c['base_url']) ? $c['base_url'] : $certBase), '/');
                        $cid  = rawurlencode((string) $c['certificate_id']);
                        ?>
                        <div class="req" style="--tone: #0f766e;">
                            <span class="tick"><svg viewBox="0 0 24 24" aria-hidden="true"><?= $icons['doc'] ?></svg></span>
                            <div>
                                <h3><?= htmlspecialchars((string) $c['event_name']) ?></h3>
                                <p class="meta">
                                    <?php if (!empty($c['org_name'])): ?><?= htmlspecialchars((string) $c['org_name']) ?> &middot; <?php endif; ?>
                                    <?= htmlspecialchars((string) ($c['role_name'] ?: 'Participant')) ?>
                                    &middot; <?= htmlspecialchars(dash_date($c['issued_at'])) ?>
                                    &middot; <code><?= htmlspecialchars((string) $c['certificate_id']) ?></code><button type="button" class="copy" data-copy="<?= htmlspecialchars((string) $c['certificate_id']) ?>">Copy</button>
                                </p>
                            </div>
                            <div class="side">
                                <a href="<?= htmlspecialchars($base) ?>/download.php?id=<?= $cid ?>">Download &darr;</a>
                                <a href="<?= htmlspecialchars($base) ?>/verify/<?= $cid ?>" target="_blank" rel="noopener">Verify &rarr;</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="panel">
            <div class="panel-head">
                <h2>More from DCW</h2>
                <p>Open programs and shortcuts.</p>
            </div>
            <div class="tiles">
                <?php
                dash_tile($icons, '#106b9a', 'search', 'Track an application', 'Check the status of a public application (ID starts with DCW-).', '/track');
                dash_tile($icons, '#97161b', 'people', $memberActive ? 'Membership' : 'Renew membership', 'Join a club, or renew your membership.', '/membership');
                foreach ($programs as $p) {
                    $title = $p['title'] ?: ucwords(str_replace(['-', '_'], ' ', $p['form_type']));
                    $desc = !empty($p['description']) ? MiniWikiText::stripToPlainText($p['description']) : 'Open for applications now.';
                    dash_tile($icons, '#106b9a', 'doc', $title, mb_strimwidth($desc, 0, 90, '…'), '/' . $p['form_type'], 'Open');
                }
                ?>
            </div>
        </section>

    <script>
        document.querySelectorAll('.copy').forEach(function (b) {
            b.addEventListener('click', function () {
                if (!navigator.clipboard) return;
                navigator.clipboard.writeText(b.dataset.copy).then(function () {
                    b.textContent = 'Copied';
                    setTimeout(function () { b.textContent = 'Copy'; }, 1500);
                });
            });
        });
    </script>
<?php engage_footer(); ?>
