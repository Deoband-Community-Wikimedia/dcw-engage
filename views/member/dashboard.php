<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/wikitext.php';
require_once __DIR__ . '/../../includes/engage_page.php';
require_once __DIR__ . '/../../models/MemberAuthModel.php';
require_once __DIR__ . '/../../models/MemberModel.php';
require_once __DIR__ . '/../../models/FormModel.php';
require_once __DIR__ . '/../../models/FeedbackModel.php';
require_once __DIR__ . '/../../models/ApplicationModel.php';
require_once __DIR__ . '/../../models/InternetSupportModel.php';
require_once __DIR__ . '/../../models/ReimbursementSettingsModel.php';
require_once __DIR__ . '/../../models/ReimbursementModel.php';
require_once __DIR__ . '/../../models/MemberTicketModel.php';
require_once __DIR__ . '/../../models/TechIssueModel.php';
require_once __DIR__ . '/../../models/MemberCertificateModel.php';
require_once __DIR__ . '/../../models/MemberProfileModel.php';
require_once __DIR__ . '/../../models/PointsModel.php';
require_once __DIR__ . '/../../includes/member_session.php';
require_once __DIR__ . '/../../includes/member_requests.php';
require_once __DIR__ . '/../../includes/cert_rows.php';

/**
 * DCW Engage - member dashboard (/member/dashboard).
 * Signed-in members only. Shows membership status, latest certificates, what they can request,
 * and their own requests. Everything is read with the member's own email or Member ID,
 * so nobody sees anyone else's data.
 *
 * One Member ID can cover several memberships (the DCW Generic Community plus any clubs).
 * The identity is shown once, with the greeting; each membership is a slim row under it.
 *
 * Certificates: only the latest few are shown here. The full list, with search and paging,
 * is /member/certificates.
 *
 * Goodies: the points balance is a chip in the quick links, and a tile in "More from DCW", both
 * linking to /member/goodies. They only appear once the goodies tables exist.
 *
 * Link-only forms (schema "unlisted": true) are never listed. Feedback forms (schema "kind": "feedback")
 * are not applications, so they are kept out of "More from DCW" and get their own "Share your feedback"
 * panel instead. Signed-in members open a feedback form without email verification.
 * Applications the member has saved as a Draft (still to be finished, or sent back) are listed under
 * "Requires your attention", with a button that emails them a fresh resume link. Applications in any
 * other status reach the applicant by email, so they are not repeated there.
 *
 * "My applications" lists every application the member has made, in any status: drafts that can be
 * resumed come first (with the same "Email me the link" button), then the rest, newest first. Each
 * one links to /track?id=..., which opens the member's own application without retyping the email.
 * Only the latest few are shown here; the full list, with search, status and year filters and paging,
 * is /member/applications. A Discarded application is shown as "Under review", like the public
 * tracking lookup: applicants are never told it was discarded (see dash_app_status() in
 * includes/member_requests.php).
 *
 * Profile: the identity card links to /member/profile and shows the Wikimedia username status. When
 * internet support is open, a member without a verified username is also nudged from "Requires your
 * attention". Everything else about who the member is lives on the profile page, not here.
 *
 * Header: the same slim top bar as the home page, with the shared member menu (engage_member_menu()).
 * It is rendered "bare" (top bar only); the greeting lives in the identity card below.
 *
 * Shared look comes from /assets/css/engage.css. Certificate rows, buttons and icons come from
 * includes/cert_rows.php. The <style> block below only adds what the dashboard needs, scoped under .dash.
 */
MemberSession::requireLogin();
$member = MemberSession::current();

const DASH_CERT_PREVIEW = 5;   // how many certificates the dashboard shows
const DASH_APP_PREVIEW  = 8;   // how many applications the dashboard shows

$email        = strtolower((string) ($member['email'] ?? ''));
$fullName     = trim((string) ($member['full_name'] ?? ''));
$firstName    = $fullName !== '' ? explode(' ', $fullName)[0] : (string) $member['member_id'];

// ---- Draft applications waiting for the member -------------------------------------------------
// Drafts on forms that are still open. Read first so the "send resume link" POST below can only
// ever act on one of the member's own drafts. (/member/applications posts here too.)
$drafts = [];
try { $drafts = (new FormModel())->draftsForEmail($email); } catch (Throwable $e) { app_log_ignored('dashboard.php:72', $e); /* leave the list empty */ }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_draft_link') {
    if (!CSRF::validate($_POST['csrf_token'] ?? '')) {
        die("Invalid CSRF token.");
    }
    $targetId = (int) ($_POST['application_id'] ?? 0);
    $sent = false;
    foreach ($drafts as $d) {
        if ((int) $d['id'] !== $targetId) continue;
        try {
            require_once __DIR__ . '/../../models/ApplicationModel.php';
            require_once __DIR__ . '/../../includes/mailer.php';
            $token = (new ApplicationModel())->generateMagicLink($targetId, true);
            Mailer::sendMagicLink($email, $fullName !== '' ? $fullName : 'Member', $token);
            $sent = true;
        } catch (Throwable $e) {
            app_log('Member dashboard: resume link failed: ' . $e->getMessage());
        }
        break;
    }
    header('Location: /member/dashboard?draft=' . ($sent ? 'sent' : 'failed'));
    exit;
}
$draftNotice = ($_GET['draft'] ?? '');

// Initials for the avatar.
$initials = '';
foreach (array_slice(preg_split('/\s+/', $fullName) ?: [], 0, 2) as $w) {
    if ($w !== '') $initials .= mb_strtoupper(mb_substr($w, 0, 1));
}
if ($initials === '') $initials = 'M';

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
        return ['tone' => '#0f766e', 'label' => 'Active', 'line' => 'Valid until ' . $text, 'renew' => false, 'active' => true];
    }
    if ($active) {
        return ['tone' => '#b45309', 'label' => 'Expiring soon',
                'line' => 'Valid until ' . $text . ($daysLeft <= 0 ? ' (today)' : ' (' . $daysLeft . ' day' . ($daysLeft === 1 ? '' : 's') . ' left)'),
                'renew' => true, 'active' => true];
    }
    if (($m['status'] ?? '') === 'active') {
        return ['tone' => '#97161b', 'label' => 'Expired', 'line' => 'Ended on ' . $text, 'renew' => true, 'active' => false];
    }
    return ['tone' => '#97161b', 'label' => 'Inactive', 'line' => 'Currently inactive', 'renew' => true, 'active' => false];
}

// Every membership under this Member ID. Falls back to the signed-in row if the lookup fails.
$memberships = [];
try { $memberships = (new MemberModel())->membershipsFor((string) $member['member_id']); } catch (Throwable $e) { app_log_ignored('dashboard.php:133', $e); /* use the session row */ }
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

// ---- The member's profile (Wikimedia username status), read once for the identity card and the nudge ----
$dashProfile = null;
try { $dashProfile = (new MemberProfileModel())->get((string) $member['member_id']); } catch (Throwable $e) { app_log_ignored('dashboard.php:148', $e); /* table not created yet: stay quiet */ }
$wmName = (string) ($dashProfile['wikimedia_username'] ?? '');
$wmOk   = $wmName !== '' && !empty($dashProfile['username_verified_at']);

// ---- Goodies points balance (null until the goodies tables exist, so nothing shows before then) ----
$pointsBalance = null;
try { $pointsBalance = (new PointsModel())->balance((string) $member['member_id']); } catch (Throwable $e) { app_log_ignored('dashboard.php:points', $e); /* tables not created yet */ }

// ---- What is open (each guarded so the page never breaks) -------------------------------------
try { $internetOpen = (new InternetSupportModel())->isOpen(); } catch (Throwable $e) { $internetOpen = false; }
try {
    $rs = (new ReimbursementSettingsModel())->get();
    $reimbursementOpen = $rs && !empty($rs['is_active']);
} catch (Throwable $e) { $reimbursementOpen = false; }

// Open forms, split into programs ("More from DCW") and feedback forms ("Share your feedback").
// Membership forms have their own tile and link-only forms (schema "unlisted": true) are shared by
// link only, so neither is listed here. Feedback forms (schema "kind": "feedback") are not
// applications, so they never appear under programs. FormModel::getActiveForms() returns each row's
// `kind`, which FeedbackModel::isFeedbackRow() reads.
$programs = [];
$feedbackPrograms = [];
try {
    $listedForms = array_values(array_filter(
        (new FormModel())->getActiveForms(),
        fn($f) => !str_starts_with((string) $f['form_type'], 'membership-')
               && empty($f['unlisted'])
    ));
    $feedbackPrograms = array_values(array_filter($listedForms, fn($f) => FeedbackModel::isFeedbackRow($f)));
    $programs         = array_values(array_filter($listedForms, fn($f) => !FeedbackModel::isFeedbackRow($f)));
} catch (Throwable $e) { app_log_ignored('dashboard.php:174', $e); /* leave both lists empty */ }

// ---- The member's own requests -----------------------------------------------------------------
// Needs InternetSupportModel::listForMember() and ReimbursementModel::listForMember().
// dash_status() / dash_date() / dash_money() live in includes/member_requests.php (shared with /member/request).
// Applicants never see internal states: "Recharge Failed" and "Payment Failed" show as "Under review".
//
// Each row carries three figures:
//   amount   what the member requested
//   approved what the reviewer approved (same as amount unless modified)
//   paid     what finance actually disbursed / paid, or null until funds are transferred
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
} catch (Throwable $e) { app_log_ignored('dashboard.php:197', $e); /* method not added yet, or table missing: show nothing rather than break */ }
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
} catch (Throwable $e) { app_log_ignored('dashboard.php:209', $e); }
usort($requests, fn($a, $b) => strcmp((string) $b['created'], (string) $a['created']));
$totalRequests = count($requests);
$requests = array_slice($requests, 0, 8);
$needsAction = array_values(array_filter($requests, fn($r) => $r['act']));

// ---- The member's applications (any status): drafts that can be resumed first, then newest first ----
// Needs ApplicationModel::listForMember(). A draft is "resumable" only when it is in $drafts (its form is
// still open), so the "Email me the link" button can only ever act on a draft the POST above accepts.
// Applicants never see "Discarded": it shows as "Under review", like the public tracking lookup.
// dash_app_status() lives in includes/member_requests.php (shared with /member/applications).
$applications = [];
try {
    $resumable = array_map('intval', array_column($drafts, 'id'));
    foreach ((new ApplicationModel())->listForMember($email) as $a) {
        $id = (int) $a['id'];
        [$label, $tone, $act] = dash_app_status((string) $a['status'], in_array($id, $resumable, true));
        $title = trim((string) ($a['form_title'] ?? ''));
        if ($title === '') $title = ucwords(str_replace(['-', '_'], ' ', (string) $a['form_type']));
        $applications[] = ['id' => $id, 'title' => $title, 'tracking' => (string) $a['tracking_id'],
            'created' => $a['created_at'], 'label' => $label, 'tone' => $tone, 'act' => $act];
    }
} catch (Throwable $e) { app_log('Member dashboard: applications lookup failed: ' . $e->getMessage()); }
// Needs the member first, then newest first.
usort($applications, fn($a, $b) => [(int) $b['act'], (string) $b['created']] <=> [(int) $a['act'], (string) $a['created']]);
$totalApplications = count($applications);
$applications      = array_slice($applications, 0, DASH_APP_PREVIEW);

// ---- The member's conversations with DCW Support (looked up by Member ID from the session) ------
$tickets = [];
try { $tickets = (new MemberTicketModel())->listForMember((string) $member['member_id']); } catch (Throwable $e) { app_log_ignored('dashboard.php:239', $e); /* table not created yet */ }
$ticketsWaiting = array_values(array_filter($tickets,
    fn($t) => $t['last_sender'] === 'staff' && !MemberTicketModel::isClosed((string) $t['status'])));

// ---- Technical reports where the technical team answered last ----------------------------------
$techWaiting = [];
try { $techWaiting = (new TechIssueModel())->awaitingReporter('member', (string) $member['member_id']); } catch (Throwable $e) { app_log_ignored('dashboard.php:245', $e); /* table not created yet */ }

// ---- Certificates issued on certificates.dcwwiki.org, matched by the member's verified email -----
// Only the total and the latest few are read here; the full list is /member/certificates.
// Read from the view member_certificates_v. If the certificates database is unreachable or
// includes/certs_db.php is missing, the panel just shows the empty state; the dashboard never breaks.
$certCount  = 0;
$certLatest = [];
try {
    $certModel  = new MemberCertificateModel();
    $certCount  = $certModel->countForMember($email);
    $certLatest = $certCount > 0 ? $certModel->latestForMember($email, DASH_CERT_PREVIEW) : [];
} catch (Throwable $e) { app_log('Member dashboard: certificates lookup failed: ' . $e->getMessage()); }
$certBase = 'https://certificates.dcwwiki.org';

// ---- One combined "requires your attention" list ---------------------------------------------------
$alerts = [];
// Drafts first: an application the member has started but not finished.
foreach ($drafts as $d) {
    $dTitle = trim((string) ($d['title'] ?? '')) !== '' ? (string) $d['title'] : ucwords(str_replace(['-', '_'], ' ', (string) $d['form_type']));
    $alerts[] = ['who' => 'Application', 'text' => $dTitle . ': draft waiting for you to finish',
                 'draft_id' => (int) $d['id'], 'cta' => 'Email me the link'];
}
foreach ($needsAction as $r) {
    $alerts[] = ['who' => $r['type'], 'text' => $r['title'] . ': ' . strtolower($r['label']),
                 'href' => member_request_url($r['tracking']), 'cta' => 'Open request'];
}
foreach ($ticketsWaiting as $t) {
    $alerts[] = ['who' => MemberTicketModel::SUPPORT_LABEL, 'text' => 'New reply on “' . $t['subject'] . '”',
                 'href' => '/member/talk/ticket?id=' . rawurlencode($t['tracking_id']), 'cta' => 'Read reply'];
}
foreach ($techWaiting as $t) {
    $alerts[] = ['who' => 'Technical team', 'text' => 'New reply on “' . $t['title'] . '”',
                 'href' => '/member/report-problem?id=' . rawurlencode($t['tracking_id']), 'cta' => 'Read reply'];
}
// Internet support needs a verified Wikimedia username: nudge only when it is open and the member is active.
if ($memberActive && $internetOpen) {
    $profileState = '';
    if ($wmName === '') {
        $profileState = 'Add your Wikimedia username to request internet support';
    } elseif (!$wmOk) {
        $profileState = 'Your Wikimedia username is waiting to be verified';
    }
    if ($profileState !== '') {
        $alerts[] = ['who' => 'Profile', 'text' => $profileState,
                     'href' => '/member/profile', 'cta' => 'Open profile'];
    }
}

$icons = [
    'doc'      => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/>',
    'wifi'     => '<path d="M5 12.55a11 11 0 0 1 14.08 0"/><path d="M1.42 9a16 16 0 0 1 21.16 0"/><path d="M8.53 16.11a6 6 0 0 1 6.95 0"/><line x1="12" y1="20" x2="12.01" y2="20"/>',
    'card'     => '<rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/>',
    'search'   => '<circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>',
    'people'   => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
    'chat'     => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
    'alert'    => '<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>',
    'bell'     => '<path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>',
    'user'     => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
    'star'     => '<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>',
];

/** Small inline icon for the dashboard blocks. */
function dash_svg(array $icons, string $key): string
{
    return '<svg class="i" viewBox="0 0 24 24" aria-hidden="true">' . ($icons[$key] ?? '') . '</svg>';
}

/** One tile (engage.css .tile). $href = null renders a disabled tile with a brief reason in the pill. */
function dash_tile(array $icons, string $tone, string $icon, string $title, string $desc, ?string $href, string $pill = ''): void
{
    $tag = $href === null ? 'div' : 'a';
    ?>
    <<?= $tag ?> class="tile<?= $href === null ? ' off' : '' ?>"<?= $href !== null ? ' href="' . htmlspecialchars($href) . '"' : '' ?> style="--tone: <?= htmlspecialchars($tone) ?>;">
        <span class="tick"><svg viewBox="0 0 24 24" aria-hidden="true"><?= $icons[$icon] ?></svg></span>
        <span><h3><?= htmlspecialchars($title) ?></h3><p><?= htmlspecialchars($desc) ?></p></span>
        <?php if ($pill !== ''): ?><span class="pill"><?= htmlspecialchars($pill) ?></span>
        <?php else: ?><span class="arrow" aria-hidden="true">&rarr;</span><?php endif; ?>
    </<?= $tag ?>>
    <?php
}

// Why a support tile is locked, if applicable.
$lock = fn(bool $open) => !$memberActive ? 'Renew membership' : (!$open ? 'Currently closed' : '');

// Same page frame as the rest of Engage: the slim top bar with the shared member menu.
// "bare" renders the top bar only (no heading, lead or trail); the greeting is in the identity card.
engage_header([
    'title'   => 'My dashboard',
    'heading' => 'Hello, ' . $firstName,      // used for the page title and share text only
    'bare'    => true,                         // top bar only: no heading, lead or trail
    'member'  => $member,
    'tools'   => engage_member_menu($member, ['current' => '/member/dashboard']),
    'wide'    => true,
]);
cert_rows_css();   // icons, buttons and certificate rows shared with /member/certificates
?>
<style>
    /* Member dashboard only. Colors, radii and fonts come from the tokens in /assets/css/engage.css. */

    /* ---- Identity card: shown once. The logo's three-color stripe on top, like the site footer ---- */
    .d-id { position: relative; overflow: hidden; margin: 0 0 28px; padding: 26px 22px 6px; background: var(--card); border: 1px solid var(--border); border-radius: 16px; box-shadow: 0 6px 18px rgba(15,23,42,.06); }
    .d-id::before { content: ""; position: absolute; inset: 0 0 auto 0; height: 5px; background: linear-gradient(90deg, var(--primary) 0 40%, var(--accent) 40% 70%, var(--leaf) 70% 100%); }
    .d-id-top { display: flex; flex-wrap: wrap; align-items: center; gap: 12px 16px; padding: 0 2px 18px; }
    .d-id-link { display: flex; align-items: center; gap: 16px; min-width: 0; color: inherit; text-decoration: none; }
    .d-id-link:hover h2 { color: var(--primary); }
    .d-prof { margin-left: auto; padding: 7px 16px; font-size: 13.5px; }
    .d-avatar { width: 52px; height: 52px; flex: none; border-radius: 50%; display: grid; place-items: center; font-size: 18px; font-weight: 700; color: var(--primary-dark); background: var(--primary-tint); }
    .d-id-who { min-width: 0; }
    .d-id-who h2 { margin: 0; font-size: 19px; font-weight: 700; letter-spacing: -.01em; line-height: 1.3; overflow-wrap: anywhere; }
    .d-id-who p { margin: 2px 0 0; color: var(--muted); font-size: 14px; font-variant-numeric: tabular-nums; }

    /* Memberships: one slim row each, a dot and a tinted label instead of a pill-and-card */
    .d-ms { list-style: none; margin: 0; padding: 0; }
    .d-ms li { --tone: var(--primary); display: flex; flex-wrap: wrap; align-items: center; gap: 6px 14px; padding: 12px 2px; border-top: 1px solid var(--border); }
    .d-ms .dot { width: 9px; height: 9px; flex: none; border-radius: 50%; background: var(--tone); }
    .d-ms .n { flex: 1 1 200px; min-width: 0; font-size: 15px; font-weight: 600; overflow-wrap: anywhere; }
    .d-ms .st { padding: 2px 10px; border-radius: 999px; font-size: 12.5px; font-weight: 700; color: var(--tone); background: color-mix(in srgb, var(--tone) 10%, #fff); }
    .d-ms .v { color: var(--muted); font-size: 13.5px; font-variant-numeric: tabular-nums; }
    .d-ms .d-btn { padding: 6px 14px; font-size: 13px; }

    /* Quick links, inside the card as its footer */
    .d-glance { display: flex; flex-wrap: wrap; gap: 8px; padding: 12px 0 16px; border-top: 1px solid var(--border); }
    .d-chip { --tone: var(--primary); display: inline-flex; align-items: center; gap: 8px; padding: 6px 14px; border-radius: 999px; background: #f6f9fc; border: 1px solid transparent; color: var(--ink); font-size: 14px; font-weight: 600; text-decoration: none; transition: border-color .15s, background .15s; }
    .d-chip svg.i { width: 16px; height: 16px; color: var(--tone); }
    .d-chip b { color: var(--tone); font-size: 15px; font-weight: 700; }
    .d-chip:hover { border-color: var(--tone); background: color-mix(in srgb, var(--tone) 6%, #fff); }

    /* Requires your attention (same amber family as .action-banner) */
    .d-attn { margin: 0 0 30px; background: #fffbeb; border: 1px solid #fcd34d; border-radius: 14px; overflow: hidden; }
    .d-attn-head { display: flex; align-items: center; gap: 10px; padding: 13px 18px; font-weight: 800; color: #78350f; }
    .d-attn-head .num { min-width: 24px; padding: 1px 8px; border-radius: 999px; background: #92400e; color: #fff; font-size: 13px; text-align: center; }
    .d-attn-item { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 10px 16px; padding: 12px 18px; border-top: 1px solid #fcd34d; color: #78350f; font-size: 14.5px; }
    .d-attn-item p { margin: 0; min-width: 0; overflow-wrap: anywhere; }
    .d-attn-item strong { margin-right: 6px; }
    .d-attn-item form { margin: 0; }
    .d-attn-item button.d-btn { font: inherit; font-size: 13.5px; cursor: pointer; }

    /* Result of "Email me the link" */
    .d-note { margin: 0 0 20px; }

    /* A form button inside a request/application row (draft: "Email me the link") */
    .req .side form { margin: 0; }
    .req .side button.d-btn { font: inherit; font-size: 13.5px; cursor: pointer; }

    /* Certificates and applications header: title with the total, and a link to the full list */
    .c-head { display: flex; flex-wrap: wrap; align-items: baseline; justify-content: space-between; gap: 4px 16px; }
    .c-head h2 { margin: 0; }
    .c-head .c-count { margin-left: 8px; padding: 1px 10px; border-radius: 999px; font-size: 13px; font-weight: 700; color: var(--leaf-dark); background: var(--leaf-tint); vertical-align: 2px; }
    .c-head .c-all { font-size: 14px; font-weight: 600; color: var(--primary); text-decoration: none; }
    .c-head .c-all:hover { text-decoration: underline; }
    .d-more a { color: var(--primary); font-weight: 600; text-decoration: none; }
    .d-more a:hover { text-decoration: underline; }

    /* Requests and conversations side by side on wide screens */
    .d-two { display: grid; gap: 0 30px; }
    @media (min-width: 1000px) { .d-two { grid-template-columns: 1.25fr 1fr; align-items: start; } }
    .d-more { margin: 10px 0 0; text-align: center; color: var(--muted); font-size: 13px; }
    .d-empty { padding: 24px 22px; text-align: center; color: var(--muted); background: var(--card); border: 1px dashed #c3d0dc; border-radius: 14px; font-size: 14.5px; }
    .d-empty strong { display: block; margin-bottom: 4px; color: var(--ink); font-size: 16px; }
    .d-empty p { margin: 0 auto; max-width: 52ch; }
    .d-empty .d-btn { margin-top: 14px; }
</style>

<div class="dash">

    <!-- Greeting and identity (once), then memberships (one slim row each) -->
    <section class="d-id" aria-label="Membership">
        <div class="d-id-top">
            <a class="d-id-link" href="/member/profile" aria-label="Open my profile">
                <span class="d-avatar" aria-hidden="true"><?= htmlspecialchars($initials) ?></span>
                <div class="d-id-who">
                    <h2>Hello, <?= htmlspecialchars($firstName) ?></h2>
                    <p><?= htmlspecialchars($fullName !== '' ? $fullName . ' · ' : '') ?>Member ID <?= htmlspecialchars((string) $member['member_id']) ?></p>
                </div>
            </a>
            <a class="d-btn line d-prof" href="/member/profile">My profile</a>
        </div>

        <ul class="d-ms">
            <?php foreach ($strips as $s): ?>
                <li style="--tone: <?= htmlspecialchars($s['tone']) ?>;">
                    <span class="dot" aria-hidden="true"></span>
                    <span class="n"><?= htmlspecialchars($s['name'] !== '' ? $s['name'] : 'DCW membership') ?></span>
                    <span class="st"><?= htmlspecialchars($s['label']) ?></span>
                    <span class="v"><?= htmlspecialchars($s['line']) ?></span>
                    <?php if ($s['renew']): ?><a class="d-btn line" href="/membership">Renew</a><?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>

        <nav class="d-glance" aria-label="Jump to a section">
            <a class="d-chip" style="--tone: var(--leaf-dark);" href="#certificates"><?= cert_icon('award') ?><b><?= (int) $certCount ?></b> certificate<?= $certCount === 1 ? '' : 's' ?></a>
            <a class="d-chip" style="--tone: var(--primary);" href="/member/applications"><?= dash_svg($icons, 'doc') ?><b><?= (int) $totalApplications ?></b> application<?= $totalApplications === 1 ? '' : 's' ?></a>
            <a class="d-chip" style="--tone: var(--primary);" href="#requests"><?= dash_svg($icons, 'card') ?><b><?= (int) $totalRequests ?></b> request<?= $totalRequests === 1 ? '' : 's' ?></a>
            <?php if ($pointsBalance !== null): ?>
                <a class="d-chip" style="--tone: var(--accent);" href="/member/goodies"><?= dash_svg($icons, 'star') ?><b><?= number_format($pointsBalance) ?></b> point<?= $pointsBalance === 1 ? '' : 's' ?></a>
            <?php endif; ?>
            <a class="d-chip" style="--tone: <?= $wmName === '' ? 'var(--muted)' : ($wmOk ? 'var(--leaf-dark)' : '#b45309') ?>;" href="/member/profile#wikimedia"><?= dash_svg($icons, 'user') ?><?php if ($wmName === ''): ?>Add Wikimedia username<?php else: ?><b><?= htmlspecialchars($wmName) ?></b> <?= $wmOk ? 'Verified' : 'Not verified' ?><?php endif; ?></a>
            <a class="d-chip" style="--tone: var(--accent);" href="#conversations"><?= dash_svg($icons, 'chat') ?><b><?= count($tickets) ?></b> conversation<?= count($tickets) === 1 ? '' : 's' ?></a>
        </nav>
    </section>

    <!-- Result of "Email me the link" -->
    <?php if ($draftNotice === 'sent'): ?>
        <div class="alert ok d-note">We have emailed a secure link to <strong><?= htmlspecialchars($email) ?></strong>. Open it to finish your application.</div>
    <?php elseif ($draftNotice === 'failed'): ?>
        <div class="alert error d-note">We could not send the link just now. Please try again in a moment.</div>
    <?php endif; ?>

    <!-- Requires your attention -->
    <?php if (!empty($alerts)): ?>
        <section class="d-attn" role="status" aria-label="Requires your attention">
            <div class="d-attn-head">
                <?= dash_svg($icons, 'bell') ?>
                <span>Requires your attention</span>
                <span class="num"><?= count($alerts) ?></span>
            </div>
            <?php foreach ($alerts as $a): ?>
                <div class="d-attn-item">
                    <p><strong><?= htmlspecialchars((string) $a['who']) ?>:</strong><?= htmlspecialchars((string) $a['text']) ?></p>
                    <?php if (isset($a['draft_id'])): ?>
                        <form method="POST" action="/member/dashboard">
                            <?= CSRF::getInputField() ?>
                            <input type="hidden" name="action" value="send_draft_link">
                            <input type="hidden" name="application_id" value="<?= (int) $a['draft_id'] ?>">
                            <button type="submit" class="d-btn amber"><?= htmlspecialchars((string) $a['cta']) ?></button>
                        </form>
                    <?php else: ?>
                        <a class="d-btn amber" href="<?= htmlspecialchars((string) $a['href']) ?>"><?= htmlspecialchars((string) $a['cta']) ?></a>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>

    <!-- My certificates: the latest few. Everything else is on /member/certificates -->
    <section class="panel" id="certificates">
        <div class="panel-head">
            <div class="c-head">
                <h2>My certificates<?php if ($certCount > 0): ?><span class="c-count"><?= (int) $certCount ?></span><?php endif; ?></h2>
                <?php if ($certCount > count($certLatest)): ?>
                    <a class="c-all" href="/member/certificates">View all <?= (int) $certCount ?> &rarr;</a>
                <?php endif; ?>
            </div>
            <p>Issued to you for DCW events. Download the PDF, or open the menu to verify or copy a link.</p>
        </div>
        <?php if (empty($certLatest)): ?>
            <div class="d-empty">
                <strong>No certificates issued yet</strong>
                <p>Certificates appear here after an event team issues them against <?= htmlspecialchars($email) ?>. If you attended an event and cannot find your certificate, please inform DCW Support regarding the email address used.</p>
                <a class="d-btn line" href="/member/talk">Contact DCW Support</a>
            </div>
        <?php else: ?>
            <div class="c-list">
                <?php foreach ($certLatest as $c) cert_row($c, $certBase); ?>
            </div>
        <?php endif; ?>
    </section>

    <!-- Get support -->
    <section class="panel" id="support">
        <div class="panel-head">
            <h2>Get support</h2>
            <p>Request data pack assistance, claim event expenses, reach out to <?= htmlspecialchars(MemberTicketModel::SUPPORT_LABEL) ?>, or report a technical issue.</p>
        </div>
        <div class="tiles">
            <?php
            $l = $lock($internetOpen);
            dash_tile($icons, '#0f766e', 'wifi', 'Internet support', 'Financial assistance for a data pack to facilitate your continued contributions.',
                $l === '' ? '/member/support?type=internet' : null, $l);
            $l = $lock($reimbursementOpen);
            dash_tile($icons, '#0f766e', 'card', 'Reimbursement', 'Claim reimbursement for expenses incurred toward a DCW-aligned event.',
                $l === '' ? '/member/support?type=reimbursement' : null, $l);
            // Open to every signed-in member, including expired ones: someone may need to state the reason.
            dash_tile($icons, '#106b9a', 'chat', 'Talk to ' . MemberTicketModel::SUPPORT_LABEL,
                'Raise a query, lodge a grievance, or share a suggestion.', '/member/talk');
            // Also open to every signed-in member, including expired ones.
            dash_tile($icons, '#0e7490', 'alert', 'Report a problem',
                'Encountered an issue on this portal? Notify the technical team.', '/member/report-problem');
            ?>
        </div>
    </section>

    <!-- My applications: the latest few. Drafts to finish come first. Everything else is on /member/applications -->
    <section class="panel" id="applications">
        <div class="panel-head">
            <div class="c-head">
                <h2>My applications<?php if ($totalApplications > 0): ?><span class="c-count"><?= (int) $totalApplications ?></span><?php endif; ?></h2>
                <?php if ($totalApplications > count($applications)): ?>
                    <a class="c-all" href="/member/applications">View all <?= (int) $totalApplications ?> &rarr;</a>
                <?php endif; ?>
            </div>
            <p>Applications you have started or submitted. Finish a draft, or open one to check its status.</p>
        </div>
        <?php if (empty($applications)): ?>
            <div class="d-empty">
                <strong>No applications yet</strong>
                <p>Programs you apply to will be listed here, including drafts you have not finished.</p>
                <a class="d-btn line" href="/">Browse programs</a>
            </div>
        <?php else: ?>
            <div class="reqs">
                <?php foreach ($applications as $a): ?>
                    <div class="req<?= $a['act'] ? ' act' : '' ?>" style="--tone: <?= htmlspecialchars($a['tone']) ?>;">
                        <span class="tick"><svg viewBox="0 0 24 24" aria-hidden="true"><?= $icons['doc'] ?></svg></span>
                        <div>
                            <h3><?= htmlspecialchars($a['title']) ?></h3>
                            <p class="meta">
                                Application &middot; <?= htmlspecialchars(dash_date($a['created'])) ?>
                                &middot; <code><?= htmlspecialchars($a['tracking']) ?></code><button type="button" class="copy" data-copy="<?= htmlspecialchars($a['tracking']) ?>">Copy</button>
                            </p>
                        </div>
                        <div class="side">
                            <span class="pill"><?= htmlspecialchars($a['label']) ?></span>
                            <?php if ($a['act']): ?>
                                <form method="POST" action="/member/dashboard">
                                    <?= CSRF::getInputField() ?>
                                    <input type="hidden" name="action" value="send_draft_link">
                                    <input type="hidden" name="application_id" value="<?= (int) $a['id'] ?>">
                                    <button type="submit" class="d-btn amber">Email me the link</button>
                                </form>
                            <?php else: ?>
                                <a href="/track?id=<?= htmlspecialchars(rawurlencode($a['tracking'])) ?>">View &rarr;</a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php if ($totalApplications > count($applications)): ?>
                <p class="d-more">Showing the latest <?= count($applications) ?> of <?= $totalApplications ?> applications. <a href="/member/applications">View all</a></p>
            <?php endif; ?>
        <?php endif; ?>
    </section>

    <!-- Requests and conversations -->
    <div class="d-two">
        <section class="panel" id="requests">
            <div class="panel-head">
                <h2>My requests</h2>
                <p>Open a request to reply to a reviewer or upload a receipt.</p>
            </div>
            <?php if (empty($requests)): ?>
                <div class="d-empty">
                    <strong>No requests submitted yet</strong>
                    <p>Internet support and reimbursement requests submitted by you will be tracked here alongside their status updates.</p>
                    <a class="d-btn line" href="#support">View support options</a>
                </div>
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
                                <a href="<?= htmlspecialchars(member_request_url($r['tracking'])) ?>"><?= $r['act'] ? 'Take action' : 'View' ?> &rarr;</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php if ($totalRequests > count($requests)): ?>
                    <p class="d-more">Showing the latest <?= count($requests) ?> of <?= $totalRequests ?> requests.</p>
                <?php endif; ?>
            <?php endif; ?>
        </section>

        <section class="panel" id="conversations">
            <div class="panel-head">
                <h2>My conversations</h2>
                <p>Queries, grievances, and suggestions submitted to <?= htmlspecialchars(MemberTicketModel::SUPPORT_LABEL) ?>.</p>
            </div>
            <?php if (empty($tickets)): ?>
                <div class="d-empty">
                    <strong>No conversations initiated</strong>
                    <p>Have a query or a proposal? Start a conversation, and responses will appear here.</p>
                    <a class="d-btn line" href="/member/talk">Start a conversation</a>
                </div>
            <?php else: ?>
                <div class="reqs">
                    <?php foreach (array_slice($tickets, 0, 8) as $t): ?>
                        <div class="req<?= ($t['last_sender'] === 'staff' && !MemberTicketModel::isClosed((string) $t['status'])) ? ' act' : '' ?>" style="--tone: #106b9a;">
                            <span class="tick"><svg viewBox="0 0 24 24" aria-hidden="true"><?= $icons['chat'] ?></svg></span>
                            <div>
                                <h3><?= htmlspecialchars($t['subject']) ?></h3>
                                <p class="meta"><?= htmlspecialchars(MemberTicketModel::LABELS[$t['type']] ?? '') ?> &middot; <?= htmlspecialchars(dash_date($t['updated_at'])) ?> &middot; <code><?= htmlspecialchars($t['tracking_id']) ?></code></p>
                            </div>
                            <div class="side">
                                <span class="pill"><?= htmlspecialchars($t['status']) ?></span>
                                <a href="/member/talk/ticket?id=<?= htmlspecialchars(rawurlencode($t['tracking_id'])) ?>">Open &rarr;</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php if (count($tickets) > 8): ?>
                    <p class="d-more">Showing the latest 8 of <?= count($tickets) ?> conversations.</p>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    </div>

    <!-- More from DCW -->
    <section class="panel">
        <div class="panel-head">
            <h2>More from DCW</h2>
            <p>Active programs and quick links.</p>
        </div>
        <div class="tiles">
            <?php
            dash_tile($icons, '#106b9a', 'search', 'Track an application', 'Check the status of a public application (ID begins with DCW-).', '/track');
            dash_tile($icons, '#97161b', 'people', $memberActive ? 'Membership' : 'Renew membership', 'Join a club or renew your membership.', '/membership');
            if ($pointsBalance !== null) {
                dash_tile($icons, '#106b9a', 'star', 'My goodies', 'Redeem the points you have earned for contributing to DCW.', '/member/goodies');
            }
            foreach ($programs as $p) {
                $title = $p['title'] ?: ucwords(str_replace(['-', '_'], ' ', $p['form_type']));
                $desc = !empty($p['description']) ? MiniWikiText::stripToPlainText($p['description']) : 'Currently open for applications.';
                dash_tile($icons, '#106b9a', 'doc', $title, mb_strimwidth($desc, 0, 90, '…'), '/' . $p['form_type'], 'Open');
            }
            ?>
        </div>
    </section>

    <!-- Share your feedback: feedback forms are not applications. Members open them without email verification. -->
    <?php if (!empty($feedbackPrograms)): ?>
    <section class="panel" id="feedback">
        <div class="panel-head">
            <h2>Share your feedback</h2>
            <p>Tell us what you think. Signed-in members skip email verification.</p>
        </div>
        <div class="tiles">
            <?php
            foreach ($feedbackPrograms as $p) {
                $title = $p['title'] ?: ucwords(str_replace(['-', '_'], ' ', $p['form_type']));
                $desc = !empty($p['description']) ? MiniWikiText::stripToPlainText($p['description']) : 'Tell us what you think.';
                dash_tile($icons, '#97161b', 'star', $title, mb_strimwidth($desc, 0, 90, '…'), '/' . $p['form_type'], 'Give feedback');
            }
            ?>
        </div>
    </section>
    <?php endif; ?>

</div>

<?php cert_rows_js(); /* copy buttons (tracking IDs, certificate IDs, links) and the certificate "more" menus */ ?>
<?php engage_footer(); ?>
