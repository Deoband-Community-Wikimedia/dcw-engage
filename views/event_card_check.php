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
    'page'    => 'event-card-check',
    'title'   => 'Event card check',
    'heading' => 'Event card check',
    'lead'    => 'Deoband Community Wikimedia',
    'compact' => true,
    'noindex' => true,
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
