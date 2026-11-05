<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/engage_page.php';
require_once __DIR__ . '/../../includes/member_session.php';
require_once __DIR__ . '/../../includes/cert_rows.php';
require_once __DIR__ . '/../../models/MemberStrikeModel.php';
require_once __DIR__ . '/../../models/PointsModel.php';
require_once __DIR__ . '/../../models/GoodyImages.php';

/**
 * DCW Engage - My goodies (/member/goodies).
 * Signed-in members only. A member sees only their own points, history and orders: everything is
 * read with the Member ID from the session, never from the request.
 *
 * Points never expire. Any signed-in member can redeem, whatever their membership status, except a
 * member who is struck off (MemberStrikeModel). Change $canRedeem below if the rule is decided differently.
 * Redeeming asks for shipping details, which are kept only until the order is finished and are then
 * cleared by PointsModel::scrubShipping().
 *
 * Stock is optional per goody (goodies.stock_json). Sold-out goodies and sizes are shown as out of stock and
 * cannot be ordered; PointsModel::redeem() re-checks stock inside the same transaction that spends the points.
 *
 * Pictures are optional per goody (goodies.images_json, see GoodyImages). The first is the cover on the card;
 * the redeem dialog shows the cover large, with thumbnails to flip through when there are several. A goody
 * with no pictures keeps the coloured initial-letter placeholder.
 */
MemberSession::requireLogin();
$member   = MemberSession::current();
$memberId = (string) $member['member_id'];
$model    = new PointsModel();
$canRedeem = true;
// A struck-off member keeps their balance on record but cannot spend it. The page does not say why:
// the same plain message is shown to anyone who cannot redeem.
try { if ((new MemberStrikeModel())->isStruck($memberId)) $canRedeem = false; } catch (Throwable $e) { app_log_ignored('member/goodies.php:strike', $e); }

function mgoodies_flash(string $kind, string $text): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
    $_SESSION['member_goodies_flash'] = [$kind, $text];
}

// ---- Redeem ---------------------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'redeem') {
    if (!CSRF::validate($_POST['csrf_token'] ?? '')) {
        die('Invalid CSRF token.');
    }
    $name    = trim((string) ($_POST['ship_name'] ?? ''));
    $phone   = trim((string) ($_POST['ship_phone'] ?? ''));
    $address = trim((string) ($_POST['ship_address'] ?? ''));
    $digits  = preg_replace('/\D+/', '', $phone);

    if (!$canRedeem) {
        mgoodies_flash('error', 'Redeeming is not available right now.');
    } elseif ($name === '' || mb_strlen($name) > 120 || $address === '' || mb_strlen($address) > 400) {
        mgoodies_flash('error', 'Please enter your name and delivery address.');
    } elseif (strlen($digits) < 10 || strlen($digits) > 15) {
        mgoodies_flash('error', 'Please enter a phone number the courier can call.');
    } else {
        try {
            $r = $model->redeem($memberId, (int) ($_POST['goody_id'] ?? 0), trim((string) ($_POST['variant'] ?? '')),
                ['name' => $name, 'phone' => $phone, 'address' => $address]);
            mgoodies_flash($r['ok'] ? 'ok' : 'error', $r['ok']
                ? 'Order placed. Your tracking ID is ' . $r['tracking_id'] . '. You can follow its status under My orders.'
                : $r['error']);
        } catch (Throwable $e) {
            app_log('Member goodies: redeem failed: ' . $e->getMessage());
            mgoodies_flash('error', 'We could not place your order just now. Please try again.');
        }
    }
    header('Location: /member/goodies');
    exit;
}

$flash = null;
if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
if (!empty($_SESSION['member_goodies_flash'])) { $flash = $_SESSION['member_goodies_flash']; unset($_SESSION['member_goodies_flash']); }

// ---- Data (each guarded so a missing table shows an empty page, not an error) ----------------------
$balance = 0; $history = $goodies = $orders = []; $setupError = false;
try {
    $balance = $model->balance($memberId);
    $history = $model->history($memberId, 30);
    $goodies = $model->goodies(true);
    $orders  = $model->redemptionsForMember($memberId);
} catch (Throwable $e) {
    app_log('Member goodies: load failed: ' . $e->getMessage());
    $setupError = true;
}

$ist  = new DateTimeZone('Asia/Kolkata');
$when = fn($ts) => $ts ? (new DateTimeImmutable((string) $ts, new DateTimeZone('UTC')))->setTimezone($ist)->format('j M Y') : '';

// The nearest goody the member cannot afford yet: the hero shows how close they are to it.
// Sold-out goodies are skipped, so the hero never points at something that cannot be ordered.
$next = null;
foreach ($goodies as $g) {
    if (!PointsModel::inStock($g)) continue;
    $c = (int) $g['points_cost'];
    if ($c > $balance && ($next === null || $c < (int) $next['points_cost'])) $next = $g;
}
$affordable = 0;
foreach ($goodies as $g) if ($canRedeem && PointsModel::inStock($g) && $balance >= (int) $g['points_cost']) $affordable++;

// A steady colour for each goody, from its name, so the catalogue is easy to tell apart at a glance.
$mgHue = fn(string $s): int => abs(crc32($s)) % 360;
$mgInitial = fn(string $s): string => mb_strtoupper(mb_substr(trim($s), 0, 1)) ?: '★';
$steps = ['Requested', 'Ordered', 'Shipped', 'Delivered'];

engage_header([
    'title'   => 'My goodies',
    'heading' => 'My goodies',
    'lead'    => 'Points you have earned for contributing to DCW. They never expire.',
    'member'  => $member,
    'tools'   => engage_member_menu($member, ['current' => '/member/goodies']),
    'wide'    => true,
]);
cert_rows_css();
?>
<style>
    [hidden] { display: none !important; }

    /* Hero: the balance, and how far it goes. This is the one loud thing on the page. */
    .mg-hero { position: relative; overflow: hidden; margin: 0 0 28px; padding: 28px 30px; border-radius: 22px; color: #fff;
        background: radial-gradient(circle at 92% 12%, rgba(255,255,255,.22) 0 8%, transparent 8.5%),
                    radial-gradient(circle at 92% 12%, rgba(255,255,255,.12) 0 18%, transparent 18.5%),
                    radial-gradient(circle at 92% 12%, rgba(255,255,255,.07) 0 30%, transparent 30.5%),
                    linear-gradient(135deg, #0b4f73 0%, #106b9a 55%, #1b8fb8 100%);
        box-shadow: 0 14px 34px rgba(16,107,154,.28); }
    .mg-hero .who { margin: 0; font-size: 14px; opacity: .85; }
    .mg-hero .big { display: flex; align-items: baseline; gap: 10px; margin: 6px 0 0; }
    .mg-hero .big b { font-size: clamp(48px, 9vw, 76px); line-height: 1; font-weight: 800; letter-spacing: -.02em; font-variant-numeric: tabular-nums; }
    .mg-hero .big span { font-size: 18px; font-weight: 600; opacity: .9; }
    .mg-goal { margin: 20px 0 0; max-width: 520px; }
    .mg-goal p { margin: 0 0 8px; font-size: 14.5px; }
    .mg-bar { height: 10px; border-radius: 999px; background: rgba(255,255,255,.25); overflow: hidden; }
    .mg-bar i { display: block; height: 100%; border-radius: 999px; background: #ffd166; }
    .mg-hero .go { display: inline-block; margin-top: 18px; padding: 9px 20px; border-radius: 999px; background: #fff; color: #0b4f73; font-weight: 700; font-size: 14px; text-decoration: none; }

    .mg-title { display: flex; flex-wrap: wrap; align-items: baseline; justify-content: space-between; gap: 6px 16px; margin: 0 0 14px; }
    .mg-title h2 { margin: 0; font-size: 21px; }
    .mg-title p { margin: 0; color: var(--muted); font-size: 13.5px; max-width: 520px; }

    /* Catalogue */
    .mg-grid { display: grid; gap: 18px; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); margin: 0 0 34px; }
    .mg-card { display: flex; flex-direction: column; overflow: hidden; background: var(--card); border: 1px solid var(--border); border-radius: 18px; transition: transform .18s, box-shadow .18s; }
    .mg-card:hover { transform: translateY(-3px); box-shadow: 0 12px 28px rgba(15,23,42,.12); }
    .mg-card.locked { opacity: .92; }
    .mg-art { position: relative; display: flex; align-items: center; justify-content: center; height: 116px; background: linear-gradient(135deg, hsl(var(--h) 70% 90%), hsl(calc(var(--h) + 35) 75% 82%)); }
    .mg-art span { display: grid; place-items: center; width: 64px; height: 64px; border-radius: 50%; background: #fff; color: hsl(var(--h) 60% 32%); font-size: 30px; font-weight: 800; box-shadow: 0 6px 16px rgba(15,23,42,.14); }
    /* A goody with a picture: the cover fills the top of the card. */
    .mg-art.has-img { height: 180px; background: #f1f5f9; }
    .mg-art.has-img img { display: block; width: 100%; height: 100%; object-fit: cover; }
    .mg-more { position: absolute; right: 10px; bottom: 10px; padding: 2px 10px; border-radius: 999px; background: rgba(15,23,42,.72); color: #fff; font-size: 12px; font-weight: 700; }
    .mg-body { display: flex; flex: 1; flex-direction: column; padding: 16px 18px 18px; }
    .mg-body h3 { margin: 0 0 4px; font-size: 17px; }
    .mg-body p { margin: 0 0 12px; color: var(--muted); font-size: 14px; line-height: 1.45; }
    .mg-opts { display: flex; flex-wrap: wrap; gap: 5px; margin: 0 0 12px; }
    .mg-opts span { padding: 1px 9px; border-radius: 999px; background: #f1f5f9; color: #475569; font-size: 12px; font-weight: 600; }
    .mg-foot { margin-top: auto; }
    .mg-cost { display: flex; align-items: baseline; gap: 5px; font-variant-numeric: tabular-nums; }
    .mg-cost b { font-size: 24px; font-weight: 800; color: hsl(var(--h) 60% 28%); }
    .mg-cost span { color: var(--muted); font-size: 13px; }
    .mg-mini { height: 6px; margin: 10px 0 6px; border-radius: 999px; background: #e8edf2; overflow: hidden; }
    .mg-mini i { display: block; height: 100%; background: hsl(var(--h) 60% 50%); border-radius: 999px; }
    .mg-need { margin: 0 !important; color: #92400e !important; font-size: 13px !important; font-weight: 600; }
    .mg-btn { width: 100%; margin-top: 12px; padding: 10px 16px; font: inherit; font-weight: 700; border: 0; border-radius: 999px; background: var(--primary); color: #fff; cursor: pointer; }
    .mg-btn:hover { background: var(--primary-dark); }
    .mg-btn.ghost { background: #fff; color: var(--primary); border: 1px solid var(--border); }
    .mg-btn:focus-visible, .mg-dlg input:focus-visible, .mg-dlg select:focus-visible, .mg-dlg textarea:focus-visible, .mg-thumbs button:focus-visible { outline: 2px solid var(--primary); outline-offset: 2px; }

    /* Stock */
    .mg-opts span.out { text-decoration: line-through; opacity: .6; }
    .mg-tag { padding: 2px 10px; border-radius: 999px; font-size: 12px; font-weight: 700; }
    .mg-tag.out { background: #fbe4e5; color: #97161b; text-decoration: none; }
    .mg-tag.low { background: #fef3c7; color: #92400e; }
    .mg-card.soldout .mg-art { filter: grayscale(1); opacity: .7; }

    /* Redeem dialog */
    .mg-dlg { width: min(460px, calc(100vw - 32px)); max-height: calc(100vh - 32px); padding: 0; border: 0; border-radius: 20px; box-shadow: 0 24px 60px rgba(15,23,42,.35); }
    .mg-dlg::backdrop { background: rgba(15,23,42,.5); }
    .mg-dlg header { padding: 20px 22px 14px; background: linear-gradient(135deg, hsl(var(--h) 70% 92%), hsl(calc(var(--h) + 35) 75% 86%)); }
    .mg-dlg header h3 { margin: 0 0 2px; font-size: 19px; }
    .mg-dlg header p { margin: 0; color: #475569; font-size: 13.5px; }
    .mg-dlg form { padding: 8px 22px 22px; }
    .mg-dlg label { display: block; margin: 12px 0 0; font-size: 13px; font-weight: 600; }
    .mg-dlg input[type=text], .mg-dlg input[type=tel], .mg-dlg select, .mg-dlg textarea { width: 100%; box-sizing: border-box; margin-top: 4px; padding: 10px 12px; font: inherit; border: 1px solid var(--border); border-radius: 10px; }
    .mg-dlg .fine { margin: 12px 0 0; color: var(--muted); font-size: 12.5px; }
    .mg-dlg .acts { display: flex; gap: 10px; margin-top: 16px; }
    .mg-dlg .acts .mg-btn { margin: 0; }

    /* Pictures in the redeem dialog: a large one, and thumbnails when there are several */
    .mg-gal { background: #f1f5f9; }
    .mg-gal .main { display: block; width: 100%; height: 260px; object-fit: contain; background: #f8fafc; }
    .mg-thumbs { display: flex; flex-wrap: wrap; gap: 6px; padding: 8px 22px; background: #fff; border-bottom: 1px solid var(--border); }
    .mg-thumbs button { width: 54px; height: 54px; padding: 0; border: 2px solid transparent; border-radius: 10px; overflow: hidden; background: #f1f5f9; cursor: pointer; }
    .mg-thumbs button[aria-current="true"] { border-color: var(--primary); }
    .mg-thumbs img { display: block; width: 100%; height: 100%; object-fit: cover; }

    .mg-empty { padding: 26px; text-align: center; color: var(--muted); background: var(--card); border: 1px dashed #c3d0dc; border-radius: 16px; }

    /* History */
    .mg-hist { margin: 0; padding: 0; list-style: none; }
    .mg-hist li { display: grid; grid-template-columns: 38px 1fr auto; gap: 12px; align-items: center; padding: 11px 0; border-top: 1px solid var(--border); }
    .mg-hist li:first-child { border-top: 0; }
    .mg-hist .ic { display: grid; place-items: center; width: 38px; height: 38px; border-radius: 50%; font-weight: 800; font-size: 18px; }
    .mg-hist .pos .ic { background: #d9f3ee; color: #0f766e; }
    .mg-hist .neg .ic { background: #fbe4e5; color: #97161b; }
    .mg-hist .why { font-size: 14.5px; font-weight: 600; overflow-wrap: anywhere; }
    .mg-hist .on { display: block; color: var(--muted); font-size: 12.5px; font-weight: 400; margin-top: 1px; }
    .mg-hist .n { font-weight: 800; font-size: 17px; font-variant-numeric: tabular-nums; white-space: nowrap; }
    .mg-hist .pos .n { color: #0f766e; } .mg-hist .neg .n { color: #97161b; }

    /* Orders */
    .mg-orders { display: grid; gap: 12px; }
    .mg-order { padding: 14px 16px; background: var(--card); border: 1px solid var(--border); border-radius: 14px; }
    .mg-order .top { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 6px 12px; align-items: baseline; }
    .mg-order h3 { margin: 0; font-size: 15.5px; }
    .mg-order .meta { margin: 3px 0 0; color: var(--muted); font-size: 13px; }
    .mg-order .st { padding: 2px 11px; border-radius: 999px; background: #e8f3f9; color: #0b4f73; font-size: 12.5px; font-weight: 700; }
    .mg-order .st.done { background: #d9f3ee; color: #0f766e; }
    .mg-order .st.cx { background: #fbe4e5; color: #97161b; }
    .mg-track { display: grid; grid-template-columns: repeat(4, 1fr); gap: 4px; margin: 14px 0 0; padding: 0; list-style: none; }
    .mg-track li { position: relative; padding-top: 16px; text-align: center; color: #94a3b8; font-size: 12px; font-weight: 600; }
    .mg-track li::before { content: ""; position: absolute; top: 0; left: 0; right: 0; height: 6px; border-radius: 999px; background: #e2e8f0; }
    .mg-track li.on { color: var(--primary-dark); }
    .mg-track li.on::before { background: var(--primary); }
    .mg-order .note { margin: 10px 0 0; color: #97161b; font-size: 13px; }

    @media (prefers-reduced-motion: reduce) { .mg-card { transition: none; } .mg-card:hover { transform: none; } }
    @media (max-width: 520px) { .mg-hero { padding: 22px 20px; border-radius: 18px; } .mg-gal .main { height: 220px; } }
</style>

<div class="dash">

    <?php if ($flash): ?>
        <div class="alert <?= $flash[0] === 'ok' ? 'ok' : 'error' ?>"><?= htmlspecialchars((string) $flash[1]) ?></div>
    <?php endif; ?>
    <?php if ($setupError): ?>
        <div class="alert error">Goodies are not available just now. Please check back soon.</div>
    <?php endif; ?>

    <section class="mg-hero" aria-label="Points balance">
        <p class="who">Member ID <?= htmlspecialchars($memberId) ?></p>
        <div class="big"><b><?= number_format($balance) ?></b><span>points</span></div>
        <?php if ($next): $cost = (int) $next['points_cost']; $pct = max(3, min(100, (int) floor($balance / $cost * 100))); ?>
            <div class="mg-goal">
                <p><strong><?= number_format($cost - $balance) ?> more points</strong> to get <?= htmlspecialchars($next['name']) ?></p>
                <div class="mg-bar" role="progressbar" aria-valuemin="0" aria-valuemax="<?= $cost ?>" aria-valuenow="<?= max(0, $balance) ?>"><i style="width:<?= $pct ?>%"></i></div>
            </div>
        <?php elseif ($goodies && $affordable): ?>
            <div class="mg-goal"><p>You have enough for every goody below.</p></div>
        <?php elseif (!$goodies): ?>
            <div class="mg-goal"><p>Your points are safe while the catalogue is empty.</p></div>
        <?php endif; ?>
        <?php if ($affordable): ?><a class="go" href="#catalogue">You can redeem <?= $affordable ?> goodies now</a><?php endif; ?>
    </section>

    <section id="catalogue" aria-labelledby="mg-cat-h">
        <div class="mg-title">
            <h2 id="mg-cat-h">Goodies you can redeem</h2>
            <p>Points have no cash value and cannot be transferred. The catalogue and point costs may change; points you have earned do not expire.</p>
        </div>
        <?php if (!$goodies): ?>
            <div class="mg-empty" style="margin-bottom:34px">No goodies are available right now. Your points are safe; check back soon.</div>
        <?php else: ?>
            <div class="mg-grid">
                <?php foreach ($goodies as $g):
                    $cost = (int) $g['points_cost'];
                    $soldOut = !PointsModel::inStock($g);
                    $can = $canRedeem && !$soldOut && $balance >= $cost;
                    $h = $mgHue((string) $g['name']); $dlg = 'redeem-' . (int) $g['id'];
                    $leftAll = !$g['variants'] ? PointsModel::stockFor($g) : null;   // null = not tracked or has sizes
                    $imgs = $g['images'] ?? [];
                    $cover = $imgs[0] ?? null;
                ?>
                    <article class="mg-card<?= $can ? '' : ' locked' ?><?= $soldOut ? ' soldout' : '' ?>" style="--h:<?= $h ?>">
                        <?php if ($cover): ?>
                            <div class="mg-art has-img">
                                <img src="<?= htmlspecialchars(GoodyImages::url($cover['file'])) ?>" alt="<?= htmlspecialchars(GoodyImages::alt((string) $g['name'], $cover)) ?>" loading="lazy">
                                <?php if (count($imgs) > 1): ?><b class="mg-more" aria-label="<?= count($imgs) ?> pictures">1/<?= count($imgs) ?></b><?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="mg-art" aria-hidden="true"><span><?= htmlspecialchars($mgInitial((string) $g['name'])) ?></span></div>
                        <?php endif; ?>
                        <div class="mg-body">
                            <h3><?= htmlspecialchars($g['name']) ?></h3>
                            <?php if ((string) $g['description'] !== ''): ?><p><?= htmlspecialchars((string) $g['description']) ?></p><?php endif; ?>
                            <?php if ($g['variants']): ?><div class="mg-opts" aria-label="Options"><?php foreach ($g['variants'] as $v): $n = PointsModel::stockFor($g, $v); $out = $n !== null && $n < 1; ?><span<?= $out ? ' class="out" title="Out of stock"' : '' ?>><?= htmlspecialchars($v) ?><?= $out ? ' · out of stock' : '' ?></span><?php endforeach; ?></div><?php endif; ?>
                            <?php if ($soldOut): ?><div class="mg-opts"><span class="mg-tag out">Out of stock</span></div>
                            <?php elseif ($leftAll !== null && $leftAll <= 5): ?><div class="mg-opts"><span class="mg-tag low">Only <?= $leftAll ?> left</span></div><?php endif; ?>
                            <div class="mg-foot">
                                <div class="mg-cost"><b><?= number_format($cost) ?></b><span>points</span></div>
                                <?php if (!$canRedeem): ?>
                                    <p class="mg-need" style="margin-top:8px !important">Redeeming is not available on your account. Please contact DCW Support.</p>
                                <?php elseif ($soldOut): ?>
                                    <p class="mg-need" style="margin-top:8px !important">Currently out of stock. Check back soon; your points are safe.</p>
                                <?php elseif (!$can): $pct = max(0, min(100, (int) floor($balance / $cost * 100))); ?>
                                    <div class="mg-mini" aria-hidden="true"><i style="width:<?= $pct ?>%"></i></div>
                                    <p class="mg-need">You need <?= number_format(max(0, $cost - $balance)) ?> more points.</p>
                                <?php else: ?>
                                    <button type="button" class="mg-btn" data-open="<?= $dlg ?>">Redeem</button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </article>

                    <?php if ($can): ?>
                        <dialog class="mg-dlg" id="<?= $dlg ?>" style="--h:<?= $h ?>" aria-labelledby="<?= $dlg ?>-t">
                            <header>
                                <h3 id="<?= $dlg ?>-t"><?= htmlspecialchars($g['name']) ?></h3>
                                <p><?= number_format($cost) ?> points · you will have <?= number_format($balance - $cost) ?> left</p>
                            </header>
                            <?php if ($cover): ?>
                                <div class="mg-gal" data-gal>
                                    <img class="main" src="<?= htmlspecialchars(GoodyImages::url($cover['file'])) ?>" alt="<?= htmlspecialchars(GoodyImages::alt((string) $g['name'], $cover)) ?>">
                                </div>
                                <?php if (count($imgs) > 1): ?>
                                    <div class="mg-thumbs" role="group" aria-label="Pictures of <?= htmlspecialchars($g['name']) ?>">
                                        <?php foreach ($imgs as $i => $im): $alt = GoodyImages::alt((string) $g['name'], $im); ?>
                                            <button type="button" data-src="<?= htmlspecialchars(GoodyImages::url($im['file'])) ?>" data-alt="<?= htmlspecialchars($alt) ?>"
                                                    aria-current="<?= $i === 0 ? 'true' : 'false' ?>" aria-label="Show picture <?= $i + 1 ?> of <?= count($imgs) ?>">
                                                <img src="<?= htmlspecialchars(GoodyImages::url($im['file'])) ?>" alt="" loading="lazy">
                                            </button>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                            <form method="POST" action="/member/goodies">
                                <?= CSRF::getInputField() ?>
                                <input type="hidden" name="action" value="redeem">
                                <input type="hidden" name="goody_id" value="<?= (int) $g['id'] ?>">
                                <?php if ($g['variants']): ?>
                                    <label>Size or option
                                        <select name="variant" required>
                                            <option value="">Choose…</option>
                                            <?php foreach ($g['variants'] as $v): $n = PointsModel::stockFor($g, $v); $out = $n !== null && $n < 1; ?>
                                                <option value="<?= htmlspecialchars($v) ?>"<?= $out ? ' disabled' : '' ?>><?= htmlspecialchars($v) ?><?= $out ? ' (out of stock)' : ($n !== null && $n <= 5 ? ' (only ' . $n . ' left)' : '') ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </label>
                                <?php endif; ?>
                                <label>Name for delivery<input type="text" name="ship_name" required maxlength="120" value="<?= htmlspecialchars((string) ($member['full_name'] ?? '')) ?>" autocomplete="name"></label>
                                <label>Phone<input type="tel" name="ship_phone" required maxlength="20" autocomplete="tel"></label>
                                <label>Delivery address<textarea name="ship_address" required maxlength="400" rows="3" autocomplete="street-address"></textarea></label>
                                <p class="fine">Your address is used only to send this order and is cleared after delivery.</p>
                                <div class="acts">
                                    <button type="button" class="mg-btn ghost" data-close>Cancel</button>
                                    <button type="submit" class="mg-btn">Spend <?= number_format($cost) ?> points</button>
                                </div>
                            </form>
                        </dialog>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <div class="d-two">
        <section id="history" aria-labelledby="mg-hist-h">
            <div class="mg-title"><h2 id="mg-hist-h">Points history</h2><p>Every award and redemption, newest first.</p></div>
            <?php if (!$history): ?>
                <div class="mg-empty">No points yet. They are awarded for attending sessions, mentoring and contributing.</div>
            <?php else: ?>
                <ul class="mg-hist">
                    <?php foreach ($history as $h): $p = (int) $h['points']; ?>
                        <li class="<?= $p >= 0 ? 'pos' : 'neg' ?>">
                            <span class="ic" aria-hidden="true"><?= $p >= 0 ? '+' : '−' ?></span>
                            <span class="why"><?= htmlspecialchars((string) $h['reason']) ?><span class="on"><?= htmlspecialchars($when($h['created_at'])) ?></span></span>
                            <span class="n"><?= $p >= 0 ? '+' : '−' ?><?= number_format(abs($p)) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <section id="orders" aria-labelledby="mg-ord-h">
            <div class="mg-title"><h2 id="mg-ord-h">My orders</h2><p>Goodies you have redeemed.</p></div>
            <?php if (!$orders): ?>
                <div class="mg-empty">No orders yet. Pick a goody above to place your first one.</div>
            <?php else: ?>
                <div class="mg-orders">
                    <?php foreach ($orders as $o): $status = (string) $o['status']; $at = array_search($status, $steps, true); $cx = $status === 'Cancelled'; ?>
                        <div class="mg-order">
                            <div class="top">
                                <div>
                                    <h3><?= htmlspecialchars($o['goody_name']) ?><?= $o['variant'] ? ' (' . htmlspecialchars((string) $o['variant']) . ')' : '' ?></h3>
                                    <p class="meta"><?= number_format((int) $o['points_spent']) ?> points · <?= htmlspecialchars($when($o['created_at'])) ?> · <code><?= htmlspecialchars((string) $o['tracking_id']) ?></code><button type="button" class="copy" data-copy="<?= htmlspecialchars((string) $o['tracking_id']) ?>">Copy</button></p>
                                </div>
                                <span class="st<?= $status === 'Delivered' ? ' done' : '' ?><?= $cx ? ' cx' : '' ?>"><?= htmlspecialchars($status) ?></span>
                            </div>
                            <?php if ($cx): ?>
                                <p class="note">This order was cancelled and the points were refunded.</p>
                            <?php else: ?>
                                <ol class="mg-track" aria-label="Order progress">
                                    <?php foreach ($steps as $i => $s): ?><li<?= $at !== false && $i <= $at ? ' class="on"' : '' ?>><?= $s ?></li><?php endforeach; ?>
                                </ol>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>

</div>

<script>
// Redeem dialogs: open from the card, close with Cancel, Escape or a click on the backdrop.
document.querySelectorAll('[data-open]').forEach(function (b) {
    b.addEventListener('click', function () { document.getElementById(b.dataset.open).showModal(); });
});
document.querySelectorAll('dialog.mg-dlg').forEach(function (d) {
    d.addEventListener('click', function (e) { if (e.target === d) d.close(); });
    var c = d.querySelector('[data-close]'); if (c) c.addEventListener('click', function () { d.close(); });

    // Pictures: a thumbnail swaps the large picture.
    var main = d.querySelector('[data-gal] .main'), thumbs = [].slice.call(d.querySelectorAll('.mg-thumbs button'));
    thumbs.forEach(function (t) {
        t.addEventListener('click', function () {
            main.src = t.dataset.src; main.alt = t.dataset.alt;
            thumbs.forEach(function (x) { x.setAttribute('aria-current', x === t ? 'true' : 'false'); });
        });
    });
});
</script>
<?php cert_rows_js(); /* copy buttons */ ?>
<?php engage_footer(); ?>
