<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/app_log.php';
require_once __DIR__ . '/../../includes/engage_page.php';
require_once __DIR__ . '/../../includes/member_session.php';
require_once __DIR__ . '/../../includes/cert_rows.php';          // .d-btn buttons, and the copy buttons
require_once __DIR__ . '/../../includes/qr.php';
require_once __DIR__ . '/../../includes/member_card_template.php'; // member_card_theme(), member_card_project_line()
require_once __DIR__ . '/../../models/MemberCardModel.php';
require_once __DIR__ . '/../../models/MemberProfileModel.php';

/**
 * DCW Engage - the member's own membership card (/member/card).
 * Signed-in members only. Same design as the PDF: club logo on the left, name in the middle and the DCW
 * logo on the right (the community card has its own logo only), username, Member ID or title, main
 * project, other current chapters and the QR code. Status, validity dates, the PDF download and
 * "Replace card" sit below the card, because they are not part of the card itself.
 *
 * The first visit creates the member's card (a random token). The QR code carries the public link
 * /card/<token>. Replacing the card retires the old token at once.
 */
MemberSession::requireLogin();
$member   = MemberSession::current();
$memberId = (string) $member['member_id'];
$cards    = new MemberCardModel();
$error    = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CSRF::validate($_POST['csrf_token'] ?? '')) {
        die('Invalid CSRF token.');
    }
    if ((string) ($_POST['action'] ?? '') === 'replace_card') {
        try {
            $cards->replace($memberId, 'member:' . $memberId);
            header('Location: /member/card?saved=replaced');
            exit;
        } catch (Throwable $e) {
            app_log('Member card replace failed: ' . $e->getMessage());
            $error = 'We could not replace your card just now. Please try again in a moment.';
        }
    }
}

$h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

$data = null;
$url  = '';
$svg  = '';
try {
    $token = $cards->issue($memberId);
    $data  = $cards->summary($memberId);
    $url   = MemberCardModel::url($token);
    $svg   = QrCode::svg($url, 'QR code for your DCW membership card');
} catch (Throwable $e) {
    app_log('Member card load failed: ' . $e->getMessage());
    if ($error === '') {
        $error = 'We could not load your card just now. Please try again in a moment.';
    }
}

// Title (shown in place of the Member ID) and main project both come from the profile.
$cardTitle   = '';
$cardProject = '';
try {
    $profileRow  = (new MemberProfileModel())->get($memberId);
    $cardTitle   = trim((string) ($profileRow['card_title'] ?? ''));
    // TODO: confirm the real profile field for the member's main project (same field as the PDF).
    $cardProject = member_card_project_line((string) ($profileRow['main_project'] ?? ''), 40);
} catch (Throwable $e) {
    app_log('Member card profile read failed: ' . $e->getMessage());
}

// Who signs the card, shown in the "Issued by" block as on the PDF.
$issuer = member_card_issuer_info();
$sigSrc = '';
if ($issuer['file'] !== '') {
    $ext  = strtolower(pathinfo($issuer['file'], PATHINFO_EXTENSION));
    $mime = $ext === 'svg' ? 'image/svg+xml' : 'image/png';
    $raw  = @file_get_contents($issuer['file']);
    if ($raw !== false && $raw !== '') {
        $sigSrc = 'data:' . $mime . ';base64,' . base64_encode($raw);
    }
}

$saved = (string) ($_GET['saved'] ?? '');

// overall state => [colour, headline]
$look = [
    'valid'    => ['#047857', 'Your membership is current'],
    'expired'  => ['#b45309', 'Your membership has expired'],
    'inactive' => ['#b91c1c', 'Your membership is not active'],
];
[$tone, $headline] = $look[$data['overall'] ?? 'inactive'];

// Theme, header and "also a member of" follow the PDF.
$theme   = member_card_theme('generic');
$others  = [];
$isOrg   = true;
$dcwLogo = '';
if ($data !== null) {
    $org    = 'Deoband Community Wikimedia';
    $key    = (string) $data['primary']['chapter'];
    $theme  = member_card_theme($key);
    $isOrg  = $key === 'generic' || strcasecmp(trim((string) $data['primary']['name']), $org) === 0;
    foreach (array_slice($data['memberships'], 1) as $m) {
        if ($m['state'] === 'active') {
            $others[] = $m['name'];
        }
    }
    // The DCW logo on the right of club cards. Skipped when the club has no logo of its own yet
    // (the model then falls back to the DCW logo, which would show twice).
    if (!$isOrg) {
        $own = MemberCardModel::logoDataUri($key);
        $dcw = MemberCardModel::logoDataUri('generic');
        if ($dcw !== null && $dcw !== $own) {
            $dcwLogo = $dcw;
        }
    }
}

engage_header([
    'page'    => 'member-card',
    'title'   => 'Membership card',
    'heading' => 'Membership card',
    'lead'    => 'Show this code to prove you are a DCW member.',
    'back'    => ['Dashboard', '/member/dashboard'],
    'compact' => true,
    'member'  => $member,
    'tools'   => engage_member_menu($member, ['current' => '/member/card']),
]);
cert_rows_css();
?>
<div class="mc">
<?php if ($saved === 'replaced'): ?>
    <div class="alert ok">Your card has been replaced. The old QR code no longer works.</div>
<?php endif; ?>
<?php if ($error !== ''): ?>
    <div class="alert error"><strong>Notice:</strong> <?= $h($error) ?></div>
<?php endif; ?>

<?php if ($data !== null): ?>
    <section class="mc-card" aria-label="Membership card"
             style="--accent: <?= $h($theme['accent']) ?>; --ink: <?= $h($theme['ink']) ?>; --tint: <?= $h($theme['tint']) ?>; --accent2: <?= $h($theme['stripe'][1] ?? $theme['accent']) ?>;">
        <div class="mc-head <?= $dcwLogo !== '' ? 'club' : 'solo' ?>">
            <?php if (!empty($data['primary']['logo'])): ?>
                <img class="mc-logo" src="<?= $h($data['primary']['logo']) ?>" alt="">
            <?php else: ?>
                <span></span>
            <?php endif; ?>
            <div class="mc-text">
                <h2 class="mc-chapter"><?= $h($data['primary']['name']) ?></h2>
                <p class="mc-org<?= $isOrg ? ' up' : '' ?>"><?= $isOrg ? 'Membership card' : 'Part of Deoband Community Wikimedia' ?></p>
            </div>
            <?php if ($dcwLogo !== ''): ?>
                <img class="mc-logo" src="<?= $h($dcwLogo) ?>" alt="Deoband Community Wikimedia">
            <?php endif; ?>
        </div>
        <div class="mc-rule" aria-hidden="true">
            <?php foreach ($theme['stripe'] as $col): ?><span style="background: <?= $h($col) ?>;"></span><?php endforeach; ?>
        </div>
        <div class="mc-main">
            <div>
                <?php if ($data['username'] !== ''): ?>
                    <p class="mc-name"><?= $h($data['username']) ?></p>
                <?php endif; ?>
                <?php if ($cardTitle !== ''): ?>
                    <p class="mc-title"><?= $h($cardTitle) ?></p>
                <?php else: ?>
                    <p class="mc-lb">Member ID</p>
                    <p class="mc-id"><?= $h($memberId) ?></p>
                <?php endif; ?>
                <?php if ($cardProject !== ''): ?>
                    <div class="mc-sec">
                        <p class="mc-lb">Works on</p>
                        <p class="mc-project"><?= $h($cardProject) ?></p>
                    </div>
                <?php endif; ?>
                <?php if ($others): ?>
                    <div class="mc-sec">
                        <p class="mc-lb">Also a member of</p>
                        <ul class="mc-also">
                            <?php foreach ($others as $n):
                                $dot = member_card_theme(member_card_key_for_name((string) $n))['accent']; ?>
                                <li><span class="dot" style="color: <?= $h($dot) ?>;">&#9679;</span><?= $h(member_card_display_name((string) $n)) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
            </div>
            <div>
                <div class="mc-qr"><?= $svg /* generated by QrCode::svg, label escaped there */ ?></div>
                <p class="mc-scan">Scan to verify</p>
            </div>
        </div>
        <div class="mc-issue">
            <div>
                <p class="mc-lb">Issued by</p>
                <p class="mc-isn">Deoband Community Wikimedia</p>
                <p class="mc-iss">Executive Board</p>
            </div>
            <div class="mc-sig">
                <?php if ($sigSrc !== ''): ?><img src="<?= $h($sigSrc) ?>" alt="Signature"><?php endif; ?>
                <div class="line"></div>
                <?php if ($issuer['name'] !== ''): ?>
                    <p class="mc-sgn"><?= $h($issuer['name']) ?></p>
                    <?php if ($issuer['title'] !== ''): ?><p class="mc-sgt"><?= $h($issuer['title']) ?></p><?php endif; ?>
                <?php else: ?>
                    <p class="mc-sgn"><?= $h($issuer['title'] !== '' ? $issuer['title'] : 'Authorised signatory') ?></p>
                <?php endif; ?>
            </div>
        </div>
        <p class="mc-foot">Identify<i>&#9670;</i>Learn<i>&#9670;</i>Think<i>&#9670;</i>Mentor</p>
    </section>

    <section class="mc-box" aria-label="Membership status" style="--tone: <?= $h($tone) ?>;">
        <p class="mc-state"><?= $h($headline) ?></p>
        <p class="mc-hint">Anyone can scan the code to check your membership. It confirms membership only, not identity.</p>
        <?php if ($data['username'] === ''): ?>
            <p class="mc-hint">Your Wikimedia username appears on the card once it is verified. <a href="/member/profile#wikimedia">Check it on your profile</a>.</p>
        <?php endif; ?>

        <ul class="mc-ms">
            <?php foreach ($data['memberships'] as $m):
                $mtone = $m['state'] === 'active' ? '#047857' : ($m['state'] === 'expired' ? '#b45309' : '#b91c1c'); ?>
                <li style="--tone: <?= $h($mtone) ?>;">
                    <span class="n">
                        <?php if (!empty($m['logo'])): ?><img src="<?= $h($m['logo']) ?>" alt="" width="26" height="26"><?php endif; ?>
                        <?= $h($m['name']) ?>
                    </span>
                    <span class="st">
                        <?php if ($m['state'] === 'active'): ?>
                            <span class="mc-pill">Current</span><?= $m['date'] !== '' ? 'valid until ' . $h($m['date']) : '' ?>
                        <?php elseif ($m['state'] === 'expired'): ?>
                            <span class="mc-pill">Expired</span><?= $m['date'] !== '' ? 'on ' . $h($m['date']) : '' ?>
                        <?php else: ?>
                            <span class="mc-pill">Not active</span>
                        <?php endif; ?>
                    </span>
                </li>
            <?php endforeach; ?>
        </ul>

        <div class="mc-actions">
            <a class="d-btn fill" href="/member/card/pdf">Download PDF</a>
            <button type="button" class="d-btn line" data-copy="<?= $h($url) ?>"><span>Copy link</span></button>
        </div>
    </section>

    <section class="mc-box" aria-label="Replace card">
        <details>
            <summary>Lost your card or shared it by mistake?</summary>
            <form method="POST" action="/member/card" onsubmit="return confirm('Replace your card? The old QR code will stop working straight away.');">
                <?= CSRF::getInputField() ?>
                <input type="hidden" name="action" value="replace_card">
                <p>Replacing makes a new QR code. Any printed or saved copy of the old card will show &ldquo;Card not valid&rdquo; from then on, so download the new PDF afterwards.</p>
                <button type="submit" class="d-btn line">Replace my card</button>
            </form>
        </details>
    </section>
<?php endif; ?>
</div>
<?php cert_rows_js(); /* copy buttons */ ?>
<?php engage_footer(); ?>
