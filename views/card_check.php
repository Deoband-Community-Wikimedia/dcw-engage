<?php
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/app_log.php';
require_once __DIR__ . '/../includes/engage_page.php';
require_once __DIR__ . '/../models/MemberAuthModel.php';
require_once __DIR__ . '/../models/MemberCardModel.php';

/**
 * DCW Engage - public membership check (/card/<token>), opened by scanning a member's QR code.
 *
 * PUBLIC: no sign-in, and it does not use the session at all. A scan usually comes from a camera or another
 * app, so there is no session cookie to rely on.
 *
 * What it shows is deliberately small: the member's verified Wikimedia username (never their real name),
 * each chapter (with its logo), and whether it is current. Never an email, phone, Member ID or real name.
 * A member with no verified username is shown without a name line. An unknown token (never issued,
 * replaced when the member replaced their card, or the member was struck off) gets the same plain "not valid"
 * page, so nothing can be learned by guessing.
 *
 * Office-bearers (MemberCardModel::OFFICE_BEARERS) get a role statement instead of the routine membership
 * check: no chapter list, no Active/Expired pills. The model decides who is an office-bearer; this page only
 * displays what lookup() returns in 'office'.
 *
 * Not indexed and not cached, so an old answer is never shown and search engines never keep one.
 * $cardToken comes from the router (the /card/{token} route in index.php).
 */
global $cardToken;
$token = (string) $cardToken;

header('Cache-Control: no-store, max-age=0');
header('Pragma: no-cache');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('Referrer-Policy: no-referrer');

$h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

$state   = 'unknown';    // office | valid | expired | inactive | unknown | busy | error
$card    = null;
$office  = null;         // ['title', 'detail'] when the card belongs to an office-bearer

if (!MemberCardModel::allow()) {
    http_response_code(429);
    header('Retry-After: 60');
    $state = 'busy';
} else {
    try {
        $card = (new MemberCardModel())->lookup($token);
        if ($card === null) {
            http_response_code(404);
        } else {
            $office = $card['office'] ?? null;
            $state  = $office !== null ? 'office' : $card['overall'];
        }
    } catch (Throwable $e) {
        app_log('Card check failed: ' . $e->getMessage());
        http_response_code(500);
        $state = 'error';
        $card  = null;
        $office = null;
    }
}

// state => [accent color, headline, one line under it, small label above the headline, icon]
$look = [
    'office'   => ['#1F4A75', 'DCW office bearer',      'This member is an office bearer of Deoband Community Wikimedia.', 'Office bearer',   'ok'],
    'valid'    => ['#047857', 'Valid DCW member',       'This membership is active.',                                      'Active',          'ok'],
    'expired'  => ['#b45309', 'Membership expired',     'This member’s tenure has lapsed.',                                'Expired',         'warn'],
    'inactive' => ['#b91c1c', 'Not an active member',   'This membership is currently inactive.',                          'Inactive',        'bad'],
    'unknown'  => ['#b91c1c', 'Invalid card',           'This card is not recognised. The card may have been reissued or cancelled. Kindly ask the person for their latest card.', 'Not valid', 'bad'],
    'busy'     => ['#b45309', 'Please wait a moment',   'Too many verification attempts from this network. Kindly try again after a minute.', 'Try again', 'warn'],
    'error'    => ['#b45309', 'Unable to verify',       'Something went wrong. Kindly try again in a short while.',        'Error',           'warn'],
];
[$accent, $headline, $subline, $chip, $iconKey] = $look[$state];

// Static icon shapes (24x24 viewBox, stroked in the card's accent color).
$icons = [
    'ok'   => '<path d="M5 12.5l4.5 4.5L19 7.5"/>',
    'warn' => '<circle cx="12" cy="12" r="9"/><path d="M12 7.5v5.2M12 16.3v.1"/>',
    'bad'  => '<circle cx="12" cy="12" r="9"/><path d="M9 9l6 6M15 9l-6 6"/>',
];

// An office-bearer's own title and role replace the generic headline.
if ($office !== null) {
    $headline = $office['title'];
    $subline  = $office['detail'];
}

$checkedAt = MemberAuthModel::formatIst(gmdate('Y-m-d H:i:s'), 'j M Y, g:i A');

engage_header([
    'page'    => 'card-check',
    'title'   => 'Membership verification',
    'heading' => 'Membership verification',
    'lead'    => 'Deoband Community Wikimedia',
    'compact' => true,
]);
?>

<div class="cc">
    <section class="cc-box" style="--tone: <?= $h($accent) ?>;" aria-live="polite">
        <div class="cc-top">
            <span class="cc-ico" aria-hidden="true">
                <svg viewBox="0 0 24 24"><?= $icons[$iconKey] ?></svg>
            </span>
            <div class="cc-main">
                <span class="cc-chip"><?= $h($chip) ?></span>
                <h2 class="cc-head"><?= $h($headline) ?></h2>
                <p class="cc-sub"><?= $h($subline) ?></p>
            </div>
        </div>

        <?php if ($card !== null && $office !== null): ?>
            <?php if ($card['name'] !== ''): ?>
                <div class="cc-who">
                    <span class="cc-label">Wikimedia username</span>
                    <p class="cc-name"><?= $h($card['name']) ?></p>
                </div>
            <?php endif; ?>
            <p class="cc-note">
                <span>Confirms the office held in Deoband Community Wikimedia.</span>
                <time>Checked <?= $h($checkedAt) ?> IST</time>
            </p>

        <?php elseif ($card !== null): ?>
            <?php if ($card['name'] !== ''): ?>
                <div class="cc-who">
                    <span class="cc-label">Wikimedia username</span>
                    <p class="cc-name"><?= $h($card['name']) ?></p>
                </div>
            <?php endif; ?>
            <ul class="cc-list">
                <?php foreach ($card['memberships'] as $m):
                    $tone = $m['state'] === 'active' ? '#047857' : ($m['state'] === 'expired' ? '#b45309' : '#b91c1c'); ?>
                    <li style="--tone: <?= $h($tone) ?>;">
                        <span class="n">
                            <?php if (!empty($m['logo'])): ?><img class="cc-lg" src="<?= $h($m['logo']) ?>" alt="" width="28" height="28"><?php endif; ?>
                            <?= $h($m['name']) ?>
                        </span>
                        <span class="st">
                            <?php if ($m['state'] === 'active'): ?>
                                <span class="cc-pill">Active</span><?= $m['date'] !== '' ? 'valid up to ' . $h($m['date']) : '' ?>
                            <?php elseif ($m['state'] === 'expired'): ?>
                                <span class="cc-pill">Expired</span><?= $m['date'] !== '' ? 'on ' . $h($m['date']) : '' ?>
                            <?php else: ?>
                                <span class="cc-pill">Inactive</span>
                            <?php endif; ?>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
            <p class="cc-note">
                <span>Confirms membership status only.</span>
                <time>Checked <?= $h($checkedAt) ?> IST</time>
            </p>
        <?php endif; ?>
    </section>
</div>
<?php engage_footer(); ?>
