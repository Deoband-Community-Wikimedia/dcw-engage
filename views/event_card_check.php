<?php
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/app_log.php';
require_once __DIR__ . '/../includes/engage_page.php';
require_once __DIR__ . '/../models/MemberAuthModel.php';
require_once __DIR__ . '/../models/MemberCardModel.php';
require_once __DIR__ . '/../models/EventCardModel.php';

/**
 * DCW Engage - public event card check (/event-card/<token>), opened by scanning an event card's QR code.
 *
 * PUBLIC: no sign-in, and it does not use the session at all.
 *
 * The 32-character token identifies the card (see EventCardModel). The page shows only the first name and
 * last initial, the role and the event; never an email, an application or a full name. An unknown,
 * re-issued or removed token all get the same plain "not recognised" page, so nothing can be learned by guessing.
 * Rate limiting reuses MemberCardModel::allow() (30 checks per connection per minute).
 *
 * Not indexed and not cached. $eventCardToken comes from the router.
 */
global $eventCardToken;

header('Cache-Control: no-store, max-age=0');
header('Pragma: no-cache');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('Referrer-Policy: no-referrer');

$h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

$state  = 'unknown';   // valid | unknown | busy | error
$result = null;

if (!MemberCardModel::allow()) {
    http_response_code(429);
    header('Retry-After: 60');
    $state = 'busy';
} else {
    try {
        $result = (new EventCardModel())->lookup((string) $eventCardToken);
        if ($result === null) {
            http_response_code(404);
        } else {
            $state = 'valid';
        }
    } catch (Throwable $e) {
        app_log('Event card lookup failed: ' . $e->getMessage());
        http_response_code(503);
        $state  = 'error';
        $result = null;
    }
}

// state => [accent color, headline, one line under it, small label above the headline, icon]
$look = [
    'valid'   => ['#047857', 'Valid event card',    'This card is on the list for the event below.',                                                    'Recognised',  'ok'],
    'unknown' => ['#b91c1c', 'Card not recognised', 'This card is not valid. It may have been replaced, or the person is no longer on the list.',      'Not valid',   'bad'],
    'busy'    => ['#b45309', 'Please wait a moment', 'Too many checks from this network. Kindly scan again after a minute.',                            'Try again',   'warn'],
    'error'   => ['#b45309', 'Unable to verify',    'Something went wrong. Kindly try again in a short while.',                                          'Error',       'warn'],
];
[$accent, $headline, $subline, $chip, $iconKey] = $look[$state];

$icons = [
    'ok'   => '<path d="M5 12.5l4.5 4.5L19 7.5"/>',
    'warn' => '<circle cx="12" cy="12" r="9"/><path d="M12 7.5v5.2M12 16.3v.1"/>',
    'bad'  => '<circle cx="12" cy="12" r="9"/><path d="M9 9l6 6M15 9l-6 6"/>',
];

// A recognised card shows the person's name as the headline.
if ($result !== null) {
    $headline = $result['name'];
    $subline  = '';
}

// The role color comes from the model; only a plain hex color is ever printed into the style attribute.
$roleColor = ($result !== null && preg_match('/^#[0-9a-fA-F]{3,8}\z/', (string) $result['color'])) ? (string) $result['color'] : $accent;

$checkedAt = MemberAuthModel::formatIst(gmdate('Y-m-d H:i:s'), 'j M Y, g:i A');

engage_header([
    'title'   => 'Event card check',
    'heading' => 'Event card check',
    'lead'    => 'Deoband Community Wikimedia',
    'compact' => true,
    'noindex' => true,
]);
?>
<style>
    /* Public event card check only. Same card recipe as the membership check (views/card_check.php):
       shared tokens from engage.css, a 5px accent bar across the top. --tone is set from PHP. */
    .cc { max-width: 560px; margin: 0 auto 30px; }
    .cc-box {
        --tone: var(--primary);
        position: relative; overflow: hidden; padding: 30px 26px 20px;
        background: var(--card); border: 1.5px solid var(--border); border-radius: 18px;
        box-shadow: 0 2px 0 var(--edge), var(--lift-hi);
    }
    .cc-box::before { content: ''; position: absolute; inset: 0 0 auto 0; height: 5px; background: var(--tone); }

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

    .cc-role { display: inline-block; margin: 10px 0 0; padding: 4px 14px; border-radius: 999px; color: #fff; font-size: 12.5px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; }

    .cc-who { margin: 22px 0 0; padding: 16px 0; border-top: 1px solid var(--border); }
    .cc-label { display: block; font-size: 12px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: var(--muted); }
    .cc-name { margin: 4px 0 0; font-size: 18px; font-weight: 800; letter-spacing: -.01em; line-height: 1.3; overflow-wrap: anywhere; }
    .cc-ed { margin: 2px 0 0; color: var(--muted); font-size: 15px; }

    .cc-note {
        display: flex; flex-wrap: wrap; justify-content: space-between; gap: 4px 16px;
        margin: 4px 0 0; padding-top: 14px; border-top: 1px solid var(--border);
        color: var(--muted); font-size: 13px; line-height: 1.5;
    }
    .cc-who + .cc-note { margin-top: 0; }
    .cc-note time { font-variant-numeric: tabular-nums; font-weight: 600; }

    @media (max-width: 480px) {
        .cc-box { padding: 26px 18px 16px; }
        .cc-top { gap: 12px; }
        .cc-ico { width: 40px; height: 40px; border-radius: 12px; }
        .cc-ico svg { width: 20px; height: 20px; }
        .cc-head { font-size: 20px; }
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
                <?php if ($subline !== ''): ?><p class="cc-sub"><?= $h($subline) ?></p><?php endif; ?>
                <?php if ($result !== null): ?>
                    <span class="cc-role" style="background: <?= $h($roleColor) ?>;"><?= $h($result['role']) ?></span>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($result !== null): ?>
            <div class="cc-who">
                <span class="cc-label">Event</span>
                <p class="cc-name"><?= $h($result['event_name']) ?></p>
                <?php if ($result['edition'] !== ''): ?><p class="cc-ed"><?= $h($result['edition']) ?></p><?php endif; ?>
            </div>
            <p class="cc-note">
                <span>This confirms the holder of this card as a valid participant of <?= $h($result['event_name']) ?>.</span>
                <time>Checked <?= $h($checkedAt) ?> IST</time>
            </p>
        <?php endif; ?>
    </section>
</div>
<?php engage_footer(); ?>
