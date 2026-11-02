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
    'title'   => 'Membership verification',
    'heading' => 'Membership verification',
    'lead'    => 'Deoband Community Wikimedia',
    'compact' => true,
]);
?>
<style>
    /* Public membership check only. Uses the shared tokens from engage.css (--card, --border, --ink, --edge, --lift-hi...).
       --tone is set on the card from PHP (the state's accent color). */
    .cc { max-width: 560px; margin: 0 auto 30px; }

    /* The card follows the same recipe as .fcard and .prog: 1.5px border, 18px radius, raised edge,
       and a 5px accent bar across the top (no side stripe, so nothing is clipped at the corners). */
    .cc-box {
        --tone: var(--primary);
        position: relative; overflow: hidden; padding: 30px 26px 20px;
        background: var(--card); border: 1.5px solid var(--border); border-radius: 18px;
        box-shadow: 0 2px 0 var(--edge), var(--lift-hi);
    }
    .cc-box::before { content: ''; position: absolute; inset: 0 0 auto 0; height: 5px; background: var(--tone); }

    /* Status first: icon tile, small label, then the headline in normal ink so it does not compete with the page heading. */
    .cc-top { display: flex; align-items: flex-start; gap: 16px; }
    .cc-ico { flex: none; width: 48px; height: 48px; border-radius: 14px; display: grid; place-items: center; background: color-mix(in srgb, var(--tone) 13%, #fff); }
    .cc-ico svg { width: 24px; height: 24px; stroke: var(--tone); fill: none; stroke-width: 2.2; stroke-linecap: round; stroke-linejoin: round; }
    .cc-main { min-width: 0; }
    .cc-chip {
        display: inline-block; margin: 0 0 7px; padding: 3px 11px; border-radius: 999px;
        font-size: 11.5px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase;
        color: var(--tone); background: color-mix(in srgb, var(--tone) 12%, #fff);
    }
    .cc-head { margin: 0; font-size: 22px; font-weight: 800; letter-spacing: -.02em; line-height: 1.25; color: var(--ink); overflow-wrap: anywhere; }
    .cc-sub { margin: 6px 0 0; color: var(--muted); font-size: 15px; line-height: 1.55; }

    /* The member: a small label so it is clear this is the Wikimedia username. */
    .cc-who { margin: 22px 0 0; padding: 16px 0; border-top: 1px solid var(--border); }
    .cc-label { display: block; font-size: 12px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: var(--muted); }
    .cc-name { margin: 4px 0 0; font-size: 22px; font-weight: 800; letter-spacing: -.01em; line-height: 1.3; overflow-wrap: anywhere; }

    /* Chapter list (routine membership check only). */
    .cc-list { list-style: none; margin: 0; padding: 0; border-top: 1px solid var(--border); }
    .cc-top + .cc-list { margin-top: 22px; }
    .cc-list li { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 4px 12px; padding: 12px 0; border-top: 1px solid var(--border); font-size: 15px; }
    .cc-list li:first-child { border-top: 0; }
    .cc-list .n { display: inline-flex; align-items: center; gap: 10px; font-weight: 600; }
    .cc-lg { width: 28px; height: 28px; object-fit: contain; flex: none; }
    .cc-list .st { font-size: 13.5px; color: var(--muted); }
    .cc-pill { padding: 2px 10px; border-radius: 999px; font-size: 12px; font-weight: 700; margin-right: 6px; color: var(--tone); background: color-mix(in srgb, var(--tone) 12%, #fff); }

    /* Footer: what this confirms on the left, when it was checked on the right (wraps on a phone). */
    .cc-note {
        display: flex; flex-wrap: wrap; justify-content: space-between; gap: 4px 16px;
        margin: 4px 0 0; padding-top: 14px; border-top: 1px solid var(--border);
        color: var(--muted); font-size: 13px; line-height: 1.5;
    }
    .cc-who + .cc-note, .cc-list + .cc-note { margin-top: 0; }
    .cc-note time { font-variant-numeric: tabular-nums; font-weight: 600; }

    @media (max-width: 480px) {
        .cc-box { padding: 26px 18px 16px; }
        .cc-top { gap: 12px; }
        .cc-ico { width: 40px; height: 40px; border-radius: 12px; }
        .cc-ico svg { width: 20px; height: 20px; }
        .cc-head, .cc-name { font-size: 20px; }
    }
</style>

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
