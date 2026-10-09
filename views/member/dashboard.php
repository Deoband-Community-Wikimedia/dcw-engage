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
require_once __DIR__ . '/../../models/MemberCertificateModel.php';
require_once __DIR__ . '/../../includes/member_session.php';
require_once __DIR__ . '/../../includes/member_requests.php';

/**
 * DCW Engage - member dashboard (/member/dashboard).
 * Signed-in members only. Shows membership status, certificates, what they can request,
 * and their own requests. Everything is read with the member's own email or Member ID,
 * so nobody sees anyone else's data.
 *
 * One Member ID can cover several memberships (the DCW Generic Community plus any clubs).
 * They are all listed under the ID the member signed in with.
 *
 * Shared look comes from /assets/css/engage.css. The small <style> block below only adds
 * what the dashboard needs on top of it, scoped under .dash.
 */
MemberSession::requireLogin();
$member = MemberSession::current();

$email        = strtolower((string) ($member['email'] ?? ''));
$fullName     = trim((string) ($member['full_name'] ?? ''));
$firstName    = $fullName !== '' ? explode(' ', $fullName)[0] : (string) $member['member_id'];

// Initials for the membership card avatar.
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
        return ['tone' => '#0f766e', 'label' => 'Active member', 'line' => 'Valid until ' . $text, 'renew' => false, 'active' => true];
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

// ---- Certificates issued on certificates.dcwwiki.org, matched by the member's verified email -----
// Read from the view member_certificates_v. If the certificates database is unreachable or
// includes/certs_db.php is missing, the panel just shows the empty state; the dashboard never breaks.
$certs = [];
try { $certs = (new MemberCertificateModel())->listForMember($email); } catch (Throwable $e) { error_log('Engage certificates lookup failed: ' . $e->getMessage()); }
$certBase = 'https://certificates.dcwwiki.org';

// ---- One combined "requires your attention" list ---------------------------------------------------
$alerts = [];
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

$icons = [
    'doc'      => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/>',
    'wifi'     => '<path d="M5 12.55a11 11 0 0 1 14.08 0"/><path d="M1.42 9a16 16 0 0 1 21.16 0"/><path d="M8.53 16.11a6 6 0 0 1 6.95 0"/><line x1="12" y1="20" x2="12.01" y2="20"/>',
    'card'     => '<rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/>',
    'search'   => '<circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>',
    'people'   => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
    'chat'     => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
    'alert'    => '<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>',
    // New, used by the certificates and attention blocks
    'award'    => '<circle cx="12" cy="8" r="7"/><polyline points="8.21 13.89 7 23 12 20 17 23 15.79 13.88"/>',
    'download' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>',
    'link'     => '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
    'verified' => '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>',
    'bell'     => '<path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>',
];

/** Small inline icon for the new dashboard blocks. */
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

// Same page frame as the rest of Engage.
engage_header([
    'title'   => 'My dashboard',
    'heading' => 'Hello, ' . $firstName,
    'kicker'  => 'Member ID ' . $member['member_id'],
    'lead'    => 'Your membership, certificates, and support, all in one place.',
    'member'  => $member,
    'tools'   => '<a class="chip-btn" href="/member/logout">Sign out</a>',
    'wide'    => true,
    'crumbs'  => [['Home', '/'], ['My dashboard']],
]);
?>
<style>
    /* Member dashboard only. Colors, radii and fonts come from the tokens in /assets/css/engage.css. */
    .dash svg.i { width: 18px; height: 18px; flex: none; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }

    /* Buttons (pills, same family as .btn-primary / .btn-ghost) */
    .d-btn { display: inline-flex; align-items: center; justify-content: center; gap: 7px; padding: 9px 18px; border-radius: 999px; border: 1px solid transparent; font: inherit; font-size: 14px; font-weight: 700; line-height: 1.2; text-decoration: none; cursor: pointer; transition: transform .15s, box-shadow .15s, background .15s; }
    .d-btn.fill { color: #fff; background: linear-gradient(135deg, var(--primary-dark), var(--primary)); box-shadow: 0 5px 14px rgba(46,101,153,.3); }
    .d-btn.fill:hover { transform: translateY(-2px); box-shadow: 0 9px 20px rgba(46,101,153,.38); }
    .d-btn.line { color: var(--primary); background: #fff; border-color: var(--primary); }
    .d-btn.line:hover { background: var(--primary-tint); }
    .d-btn.amber { color: #fff; background: #92400e; padding: 8px 16px; }
    .d-btn.amber:hover { background: #78350f; }

    /* ---- Membership ID card: the logo's three-color stripe on top, like the site footer ---- */
    .d-cards { display: grid; gap: 16px; grid-template-columns: repeat(auto-fit, minmax(min(100%, 380px), 1fr)); margin: 0 0 16px; }
    .d-id { --tone: var(--leaf-dark); position: relative; overflow: hidden; padding: 28px 24px 20px; background: var(--card); border: 1px solid var(--border); border-radius: 18px; box-shadow: 0 16px 34px rgba(15,23,42,.12); }
    .d-id::before { content: ""; position: absolute; inset: 0 0 auto 0; height: 6px; background: linear-gradient(90deg, var(--primary) 0 40%, var(--accent) 40% 70%, var(--leaf) 70% 100%); }
    .d-id-top { display: flex; align-items: center; gap: 16px; }
    .d-avatar { width: 58px; height: 58px; flex: none; border-radius: 50%; display: grid; place-items: center; font-size: 20px; font-weight: 800; color: #fff; background: linear-gradient(135deg, var(--primary-dark), var(--primary)); box-shadow: 0 0 0 3px #fff, 0 0 0 5px var(--tone); }
    .d-id-who { flex: 1; min-width: 0; }
    .d-id-who h2 { margin: 0; font-size: 20px; font-weight: 800; letter-spacing: -.02em; line-height: 1.25; overflow-wrap: anywhere; }
    .d-id-who p { margin: 2px 0 0; color: var(--muted); font-size: 14px; font-weight: 600; }
    .d-id-foot { display: flex; flex-wrap: wrap; align-items: flex-end; gap: 12px 30px; margin-top: 20px; padding-top: 16px; border-top: 1px dashed var(--border); }
    .d-id-foot .l { margin: 0; font-size: 12.5px; color: var(--muted); }
    .d-id-foot .v { margin: 2px 0 0; font-size: 16px; font-weight: 700; font-variant-numeric: tabular-nums; letter-spacing: .02em; }
    .d-id-foot .btn-pill { margin-left: auto; }

    /* Quick links under the card */
    .d-glance { display: flex; flex-wrap: wrap; gap: 10px; margin: 0 0 28px; }
    .d-chip { --tone: var(--primary); display: inline-flex; align-items: center; gap: 8px; padding: 8px 16px; border-radius: 999px; background: var(--card); border: 1px solid var(--border); color: var(--ink); font-size: 14px; font-weight: 600; text-decoration: none; transition: border-color .15s, background .15s; }
    .d-chip svg.i { color: var(--tone); }
    .d-chip b { color: var(--tone); font-size: 16px; font-weight: 800; }
    .d-chip:hover { border-color: var(--tone); background: color-mix(in srgb, var(--tone) 6%, #fff); }

    /* Requires your attention (same amber family as .action-banner) */
    .d-attn { margin: 0 0 30px; background: #fffbeb; border: 1px solid #fcd34d; border-radius: 14px; overflow: hidden; }
    .d-attn-head { display: flex; align-items: center; gap: 10px; padding: 13px 18px; font-weight: 800; color: #78350f; }
    .d-attn-head .num { min-width: 24px; padding: 1px 8px; border-radius: 999px; background: #92400e; color: #fff; font-size: 13px; text-align: center; }
    .d-attn-item { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 10px 16px; padding: 12px 18px; border-top: 1px solid #fcd34d; color: #78350f; font-size: 14.5px; }
    .d-attn-item p { margin: 0; min-width: 0; overflow-wrap: anywhere; }
    .d-attn-item strong { margin-right: 6px; }

    /* ---- Certificates ---- */
    .d-certs { display: grid; gap: 16px; grid-template-columns: repeat(auto-fill, minmax(min(100%, 320px), 1fr)); }
    .d-cert { position: relative; display: flex; flex-direction: column; gap: 14px; overflow: hidden; padding: 22px 20px 18px; background: var(--card); border: 1px solid var(--border); border-radius: 16px; box-shadow: 0 6px 18px rgba(15,23,42,.06); }
    .d-cert::before { content: ""; position: absolute; inset: 0 0 auto 0; height: 5px; background: var(--leaf); }
    .d-cert-top { display: flex; gap: 14px; align-items: flex-start; }
    .d-seal { width: 44px; height: 44px; flex: none; border-radius: 50%; display: grid; place-items: center; color: var(--leaf-dark); background: var(--leaf-tint); }
    .d-seal svg.i { width: 22px; height: 22px; }
    .d-cert h3 { margin: 0; font-size: 17px; font-weight: 800; line-height: 1.3; overflow-wrap: anywhere; }
    .d-cert .sub { margin: 3px 0 0; font-size: 13.5px; color: var(--muted); }
    .d-idrow { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; font-size: 13px; color: var(--muted); }
    .d-code { font-size: 12.5px; background: #eef3f8; padding: 2px 8px; border-radius: 6px; color: var(--ink); overflow-wrap: anywhere; }
    .d-copy { padding: 0 9px; font: inherit; font-size: 12px; font-weight: 600; color: var(--primary); background: none; border: 1px solid var(--border); border-radius: 999px; cursor: pointer; }
    .d-copy:hover { background: var(--primary-tint); }
    .d-acts { display: flex; flex-wrap: wrap; gap: 8px; margin-top: auto; }

    /* Requests and conversations side by side on wide screens */
    .d-two { display: grid; gap: 0 30px; }
    @media (min-width: 1000px) { .d-two { grid-template-columns: 1.25fr 1fr; align-items: start; } }
    .d-more { margin: 10px 0 0; text-align: center; color: var(--muted); font-size: 13px; }
    .d-empty { padding: 24px 22px; text-align: center; color: var(--muted); background: var(--card); border: 1px dashed #c3d0dc; border-radius: 14px; font-size: 14.5px; }
    .d-empty strong { display: block; margin-bottom: 4px; color: var(--ink); font-size: 16px; }
    .d-empty p { margin: 0 auto; max-width: 52ch; }
    .d-empty .d-btn { margin-top: 14px; }

    @media (max-width: 520px) {
        .d-id-top { flex-wrap: wrap; }
        .d-id-foot .btn-pill { margin-left: 0; width: 100%; text-align: center; }
        .d-acts .d-btn { flex: 1 1 auto; }
    }
</style>

<div class="dash">

    <!-- Membership card(s) -->
    <div class="d-cards">
        <?php foreach ($strips as $s): ?>
            <article class="d-id" style="--tone: <?= htmlspecialchars($s['tone']) ?>;">
                <div class="d-id-top">
                    <span class="d-avatar" aria-hidden="true"><?= htmlspecialchars($initials) ?></span>
                    <div class="d-id-who">
                        <h2><?= htmlspecialchars($fullName !== '' ? $fullName : (string) $member['member_id']) ?></h2>
                        <p><?= htmlspecialchars($s['name'] !== '' ? $s['name'] : 'DCW membership') ?></p>
                    </div>
                    <span class="pill" style="--tone: <?= htmlspecialchars($s['tone']) ?>;"><?= htmlspecialchars($s['label']) ?></span>
                </div>
                <div class="d-id-foot">
                    <div>
                        <p class="l">Member ID</p>
                        <p class="v"><?= htmlspecialchars((string) $member['member_id']) ?></p>
                    </div>
                    <div>
                        <p class="l">Validity</p>
                        <p class="v"><?= htmlspecialchars($s['line']) ?></p>
                    </div>
                    <?php if ($s['renew']): ?>
                        <a class="btn-pill" href="/membership">Renew membership</a>
                    <?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
    </div>

    <!-- Quick links -->
    <nav class="d-glance" aria-label="Jump to a section">
        <a class="d-chip" style="--tone: var(--leaf-dark);" href="#certificates"><?= dash_svg($icons, 'award') ?><b><?= count($certs) ?></b> certificate<?= count($certs) === 1 ? '' : 's' ?></a>
        <a class="d-chip" style="--tone: var(--primary);" href="#requests"><?= dash_svg($icons, 'doc') ?><b><?= (int) $totalRequests ?></b> request<?= $totalRequests === 1 ? '' : 's' ?></a>
        <a class="d-chip" style="--tone: var(--accent);" href="#conversations"><?= dash_svg($icons, 'chat') ?><b><?= count($tickets) ?></b> conversation<?= count($tickets) === 1 ? '' : 's' ?></a>
    </nav>

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
                    <a class="d-btn amber" href="<?= htmlspecialchars((string) $a['href']) ?>"><?= htmlspecialchars((string) $a['cta']) ?></a>
                </div>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>

    <!-- My certificates -->
    <section class="panel" id="certificates">
        <div class="panel-head">
            <h2>My certificates</h2>
            <p>Issued to you for DCW events. Download the PDF or copy a public verification link.</p>
        </div>
        <?php if (empty($certs)): ?>
            <div class="d-empty">
                <strong>No certificates issued yet</strong>
                <p>Certificates appear here after an event team issues them against <?= htmlspecialchars($email) ?>. If you attended an event and cannot find your certificate, please inform DCW Support regarding the email address used.</p>
                <a class="d-btn line" href="/member/talk">Contact DCW Support</a>
            </div>
        <?php else: ?>
            <div class="d-certs">
                <?php foreach ($certs as $c): ?>
                    <?php
                    // Per-row base URL if the view provides one (multi-organisation), else the DCW portal.
                    $base      = rtrim((string) (!empty($c['base_url']) ? $c['base_url'] : $certBase), '/');
                    $cid       = rawurlencode((string) $c['certificate_id']);
                    $verifyUrl = $base . '/verify/' . $cid;
                    ?>
                    <article class="d-cert">
                        <div class="d-cert-top">
                            <span class="d-seal"><?= dash_svg($icons, 'award') ?></span>
                            <div>
                                <h3><?= htmlspecialchars((string) $c['event_name']) ?></h3>
                                <p class="sub">
                                    <?php if (!empty($c['org_name'])): ?><?= htmlspecialchars((string) $c['org_name']) ?>, <?php endif; ?>
                                    <?= htmlspecialchars((string) ($c['role_name'] ?: 'Participant')) ?>, issued on <?= htmlspecialchars(dash_date($c['issued_at'])) ?>
                                </p>
                            </div>
                        </div>
                        <div class="d-idrow">
                            <span>Certificate ID</span>
                            <code class="d-code"><?= htmlspecialchars((string) $c['certificate_id']) ?></code>
                            <button type="button" class="d-copy" data-copy="<?= htmlspecialchars((string) $c['certificate_id']) ?>"><span>Copy</span></button>
                        </div>
                        <div class="d-acts">
                            <a class="d-btn fill" href="<?= htmlspecialchars($base) ?>/download.php?id=<?= $cid ?>"><?= dash_svg($icons, 'download') ?>Download</a>
                            <a class="d-btn line" href="<?= htmlspecialchars($verifyUrl) ?>" target="_blank" rel="noopener"><?= dash_svg($icons, 'verified') ?>Verify</a>
                            <button type="button" class="d-btn line" data-copy="<?= htmlspecialchars($verifyUrl) ?>"><?= dash_svg($icons, 'link') ?><span>Copy link</span></button>
                        </div>
                    </article>
                <?php endforeach; ?>
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
            <p>Active programmes and quick links.</p>
        </div>
        <div class="tiles">
            <?php
            dash_tile($icons, '#106b9a', 'search', 'Track an application', 'Check the status of a public application (ID begins with DCW-).', '/track');
            dash_tile($icons, '#97161b', 'people', $memberActive ? 'Membership' : 'Renew membership', 'Join a club or renew your membership.', '/membership');
            foreach ($programs as $p) {
                $title = $p['title'] ?: ucwords(str_replace(['-', '_'], ' ', $p['form_type']));
                $desc = !empty($p['description']) ? MiniWikiText::stripToPlainText($p['description']) : 'Currently open for applications.';
                dash_tile($icons, '#106b9a', 'doc', $title, mb_strimwidth($desc, 0, 90, '…'), '/' . $p['form_type'], 'Open');
            }
            ?>
        </div>
    </section>

</div>

<script>
    // One handler for every copy button (tracking IDs, certificate IDs, verification links).
    document.querySelectorAll('[data-copy]').forEach(function (b) {
        b.addEventListener('click', function () {
            if (!navigator.clipboard) return;
            var label = b.querySelector('span') || b;
            var original = label.textContent;
            navigator.clipboard.writeText(b.dataset.copy).then(function () {
                label.textContent = 'Copied';
                setTimeout(function () { label.textContent = original; }, 1500);
            });
        });
    });
</script>
<?php engage_footer(); ?>
