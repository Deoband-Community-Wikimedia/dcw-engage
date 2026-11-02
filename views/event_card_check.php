<?php
require_once __DIR__ . '/../models/EventCardModel.php';
require_once __DIR__ . '/../models/MemberCardModel.php';

/**
 * Public page opened by scanning an event card's QR code: /event-card/<token>.
 * No sign-in. The 32-character token identifies the card (see EventCardModel). The page shows only the
 * first name and last initial, the role and the event; never an email, an application or a full name.
 * An unknown, re-issued or removed token all look the same: "not recognised".
 * Rate limiting reuses MemberCardModel::allow() (30 checks per connection per minute).
 */
global $eventCardToken;

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

$h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

$result = null;
$limited = !MemberCardModel::allow();
if ($limited) {
    http_response_code(429);
} else {
    try {
        $result = (new EventCardModel())->lookup((string) $eventCardToken);
    } catch (Throwable $e) {
        app_log('Event card lookup failed: ' . $e->getMessage());
        http_response_code(503);
    }
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Event card check</title>
<style>
    body { margin: 0; font-family: system-ui, -apple-system, "Segoe UI", sans-serif; background: #eaf2fa; color: #0f172a; }
    main { max-width: 26rem; margin: 3rem auto; padding: 0 1rem; }
    .card { background: #fff; border-radius: 12px; overflow: hidden; box-shadow: 0 2px 12px rgba(27, 63, 102, .12); text-align: center; }
    .bar  { height: 6px; background: linear-gradient(90deg, #2d6499 33%, #8f1c10 33% 66%, #539a6d 66%); }
    .body { padding: 1.6rem 1.4rem 1.4rem; }
    .tick { font-size: 2rem; line-height: 1; }
    h1    { font-size: 1.5rem; margin: .6rem 0 .2rem; color: #1b3f66; }
    .role { display: inline-block; margin: .6rem 0; padding: .25rem 1rem; border-radius: 999px; color: #fff; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; font-size: .85rem; }
    .ev   { font-weight: 600; }
    .ed   { color: #475569; font-size: .9rem; }
    .foot { font-size: .8rem; color: #64748b; padding: 0 1.4rem 1.2rem; }
</style>
</head>
<body>
<main>
    <div class="card">
        <div class="bar"></div>
        <div class="body">
        <?php if ($limited): ?>
            <h1>Too many checks</h1>
            <p>Please wait a minute and scan again.</p>
        <?php elseif ($result !== null): ?>
            <div class="tick" style="color: #2f7a4f;" aria-hidden="true">&#10003;</div>
            <h1><?= $h($result['name']) ?></h1>
            <div class="role" style="background: <?= $h($result['color']) ?>;"><?= $h($result['role']) ?></div>
            <div class="ev"><?= $h($result['event_name']) ?></div>
            <?php if ($result['edition'] !== ''): ?><div class="ed"><?= $h($result['edition']) ?></div><?php endif; ?>
        <?php else: ?>
            <div class="tick" style="color: #8f1c10;" aria-hidden="true">&#10007;</div>
            <h1>Card not recognised</h1>
            <p>This card is not valid. It may have been replaced, or the person is no longer on the list.</p>
        <?php endif; ?>
        </div>
        <div class="foot">Confirms participation only, not identity. Ask for photo ID if unsure.</div>
    </div>
</main>
</body>
</html>
