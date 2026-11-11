<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/engage_page.php';
require_once __DIR__ . '/../../includes/member_session.php';
require_once __DIR__ . '/../../includes/cert_rows.php';
require_once __DIR__ . '/../../includes/points_ui.php';
require_once __DIR__ . '/../../includes/points_claim_ui.php';
require_once __DIR__ . '/../../models/PointClaimModel.php';
require_once __DIR__ . '/../../models/MemberStrikeModel.php';
require_once __DIR__ . '/../../models/PointsModel.php';
require_once __DIR__ . '/../../models/GoodyImages.php';

/**
 * DCW Engage - My goodies (/member/goodies).
 * Signed-in members only. A member sees only their own points, history and orders: everything is
 * read with the Member ID from the session, never from the request.
 *
 * Two views of the same page:
 *   /member/goodies                  balance, orders on their way, the catalogue, the latest points activity, past orders
 *   /member/goodies?view=history     the full points history: year / type / category filters, 25 rows a page
 *   /member/goodies?view=claim       claim points: the form, the member's claims, and the ways to earn that DCW adds itself
 *   /member/goodies?claim=PC-...     one claim: status, the conversation with the reviewer, reply or withdraw
 * Claims are PointClaimModel's (migrations/2026_11_goodies_claims.sql). If those tables are not there yet the
 * claim buttons are simply left out. A claim never changes the balance: a reviewer approves it into an award.
 *
 * Cart: a card opens a detail dialog (pictures, size, quantity) and "Add to cart" puts it in the member's cart. The cart
 * lives in the browser (localStorage, one per Member ID) and is only a convenience: checkout posts the lines to
 * PointsModel::redeemCart(), which re-reads prices, stock, limits and the balance itself and either places ONE order
 * (one GD- tracking ID, one address) or nothing at all. Each goody can have a "max per order" (goodies.max_per_order).
 * Pictures open in a viewer that can zoom (click, or the Zoom button), from the card and from the detail dialog.
 *
 * Points history reads PointsModel::ledgerDetailed() (activity, session and order names joined in) and is
 * shown by includes/points_ui.php: grouped by month with a subtotal, repeats folded into one row, and
 * awards still waiting for a second reviewer listed as Pending. Award notes are never shown to members.
 *
 * Points never expire. Any signed-in member can redeem, whatever their membership status, except a
 * member who is struck off (MemberStrikeModel). Redeeming asks for shipping details, which are kept only until the
 * order is finished and are then cleared by PointsModel::scrubShipping().
 *
 * Orders: an order can hold several goodies. If one cannot be sent, a reviewer refunds just those units and the rest
 * ships; My orders shows each line, what was refunded and why.
 *
 * Stock is optional per goody (goodies.stock_json). Sold-out goodies and sizes cannot be ordered.
 * Pictures are optional per goody (goodies.images_json, see GoodyImages). A goody with none keeps the coloured initial.
 *
 * Catalogue order: what the member can redeem now first, then the rest by cost, sold-out goodies last.
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

// ---- Checkout: one order for the whole cart -------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'redeem_cart') {
    if (!CSRF::validate($_POST['csrf_token'] ?? '')) {
        die('Invalid CSRF token.');
    }
    $name    = trim((string) ($_POST['ship_name'] ?? ''));
    $phone   = trim((string) ($_POST['ship_phone'] ?? ''));
    $address = trim((string) ($_POST['ship_address'] ?? ''));
    $digits  = preg_replace('/\D+/', '', $phone);
    $cartRaw = (string) ($_POST['cart'] ?? '');
    $cart    = strlen($cartRaw) <= 20000 ? json_decode($cartRaw, true) : null;

    if (!$canRedeem) {
        mgoodies_flash('error', 'Redeeming is not available right now.');
    } elseif (!is_array($cart) || !$cart) {
        mgoodies_flash('error', 'Your cart is empty.');
    } elseif ($name === '' || mb_strlen($name) > 120 || $address === '' || mb_strlen($address) > 400) {
        mgoodies_flash('error', 'Please enter your name and delivery address.');
    } elseif (strlen($digits) < 10 || strlen($digits) > 15) {
        mgoodies_flash('error', 'Please enter a phone number the courier can call.');
    } else {
        try {
            $r = $model->redeemCart($memberId, $cart, ['name' => $name, 'phone' => $phone, 'address' => $address]);
            if ($r['ok']) {
                if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
                $_SESSION['member_goodies_clear_cart'] = 1;   // the page empties the browser's cart once
                mgoodies_flash('ok', 'Order placed. Your tracking ID is ' . $r['tracking_id'] . '. You can follow its status under My orders.');
            } else {
                mgoodies_flash('error', $r['error']);      // the cart stays, so nothing has to be picked again
            }
        } catch (Throwable $e) {
            app_log('Member goodies: checkout failed: ' . $e->getMessage());
            mgoodies_flash('error', 'We could not place your order just now. Please try again.');
        }
    }
    header('Location: /member/goodies');
    exit;
}

// ---- Claims: send, reply, withdraw ----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && str_starts_with((string) ($_POST['action'] ?? ''), 'claim_')) {
    if (!CSRF::validate($_POST['csrf_token'] ?? '')) {
        die('Invalid CSRF token.');
    }
    if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
    $goTo = '/member/goodies?view=claim';
    try {
        $cm  = new PointClaimModel();
        $act = (string) $_POST['action'];
        $ref = strtoupper(trim((string) ($_POST['tracking'] ?? '')));
        if ($act === 'claim_submit') {
            $r = $cm->submit($memberId, (int) ($_POST['activity_type_id'] ?? 0), (string) ($_POST['event_date'] ?? ''),
                             (string) ($_POST['evidence_url'] ?? ''), (string) ($_POST['note'] ?? ''));
            if ($r['ok']) {
                mgoodies_flash('ok', 'Claim sent. Your tracking ID is ' . $r['tracking_id'] . '. A reviewer will look at it; you can follow it here.');
                $goTo = '/member/goodies?claim=' . rawurlencode($r['tracking_id']);
            } else {
                mgoodies_flash('error', $r['error']);
                $_SESSION['member_claim_old'] = [
                    'activity_type_id' => (int) ($_POST['activity_type_id'] ?? 0), 'event_date' => (string) ($_POST['event_date'] ?? ''),
                    'evidence_url' => (string) ($_POST['evidence_url'] ?? ''), 'note' => (string) ($_POST['note'] ?? ''),
                ];
            }
        } elseif ($act === 'claim_reply' && $ref !== '') {
            $r = $cm->reply($ref, $memberId, (string) ($_POST['body'] ?? ''));
            mgoodies_flash($r['ok'] ? 'ok' : 'error', $r['ok'] ? 'Your reply has been sent to the reviewers.' : $r['error']);
            $goTo = '/member/goodies?claim=' . rawurlencode($ref);
        } elseif ($act === 'claim_withdraw' && $ref !== '') {
            $r = $cm->withdraw($ref, $memberId);
            mgoodies_flash($r['ok'] ? 'ok' : 'error', $r['ok'] ? 'Your claim was withdrawn.' : $r['error']);
            $goTo = '/member/goodies?claim=' . rawurlencode($ref);
        }
    } catch (Throwable $e) {
        app_log('Member goodies: claim action failed: ' . $e->getMessage());
        mgoodies_flash('error', 'Something went wrong. Please try again.');
    }
    header('Location: ' . $goTo);
    exit;
}

$flash = null;
if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
if (!empty($_SESSION['member_goodies_flash'])) { $flash = $_SESSION['member_goodies_flash']; unset($_SESSION['member_goodies_flash']); }
$clearCart = !empty($_SESSION['member_goodies_clear_cart']);
unset($_SESSION['member_goodies_clear_cart']);

$historyMode = ($_GET['view'] ?? '') === 'history';
$claimMode   = ($_GET['view'] ?? '') === 'claim';
$claimRef    = strtoupper(trim((string) ($_GET['claim'] ?? '')));
$claimPage   = $claimMode || $claimRef !== '';       // the claim screens: no catalogue, no orders
$claimOld    = [];
if ($claimMode && !empty($_SESSION['member_claim_old'])) { $claimOld = (array) $_SESSION['member_claim_old']; unset($_SESSION['member_claim_old']); }

// ---- Data (each guarded so a missing table shows an empty page, not an error) ----------------------
$balance = 0; $ledger = $pending = $goodies = $orders = []; $setupError = false;
try {
    $balance = $model->balance($memberId);
    $ledger  = $model->ledgerDetailed($memberId);
    $pending = $model->pendingForMember($memberId);
    if (!$historyMode && !$claimPage) {
        $goodies = $model->goodies(true);
        $orders  = $model->redemptionsForMember($memberId);
    }
} catch (Throwable $e) {
    app_log('Member goodies: load failed: ' . $e->getMessage());
    $setupError = true;
}
$pendingSum = (int) array_sum(array_column($pending, 'points'));

// ---- Claims (null = the claim tables are not there yet: the claim buttons are left out) -------------
$claims = null; $claimActivities = $claimOthers = $claimOne = null; $claimMessages = []; $claimCooldowns = [];
try {
    $claimModel = new PointClaimModel();
    $claims = $claimModel->forMember($memberId);
    if ($claimMode) {
        $claimActivities = $claimModel->claimableActivities();
        $claimOthers     = $claimModel->otherActivities();
        $claimCooldowns  = $claimModel->cooldowns($memberId);   // [activity id => "date it can be claimed again"]
    }
    if ($claimRef !== '') {
        $claimOne = $claimModel->findForMember($claimRef, $memberId);
        $claimMessages = $claimOne ? $claimModel->messages((int) $claimOne['id']) : [];
    }
} catch (Throwable $e) {
    app_log_ignored('member/goodies.php:claims', $e);
    $claims = null;
}
$openClaims = $claims === null ? 0 : count(array_filter($claims, fn($c) => in_array($c['status'], PointClaimModel::OPEN, true)));
$claimsReply = $claims === null ? 0 : count(array_filter($claims, fn($c) => $c['status'] === 'Needs info'));

$ist  = new DateTimeZone('Asia/Kolkata');
$when = fn($ts) => $ts ? (new DateTimeImmutable((string) $ts, new DateTimeZone('UTC')))->setTimezone($ist)->format('j M Y') : '';

// ---- Catalogue: goal, count, order ---------------------------------------------------------------
$goal       = PointsModel::nextGoal($goodies, $balance, $canRedeem);
$next       = $goal['next'];
$affordable = $goal['affordable'];

$sorted = $goodies;
usort($sorted, function ($a, $b) use ($canRedeem, $balance) {
    $rank = fn($g) => !PointsModel::inStock($g) ? 2 : (($canRedeem && $balance >= (int) $g['points_cost']) ? 0 : 1);
    return [$rank($a), (int) $a['points_cost'], (string) $a['name']] <=> [$rank($b), (int) $b['points_cost'], (string) $b['name']];
});

// What the cart and the viewer need to know about each goody (the server re-checks everything at checkout).
$mgGoodies = [];
foreach ($sorted as $g) {
    $stock = [];
    if ($g['variants']) { foreach ($g['variants'] as $v) $stock[$v] = PointsModel::stockFor($g, $v); }
    else { $stock['_'] = PointsModel::stockFor($g); }      // null = not tracked
    $mgGoodies[] = [
        'id' => (int) $g['id'], 'name' => (string) $g['name'], 'desc' => (string) ($g['description'] ?? ''),
        'cost' => (int) $g['points_cost'], 'variants' => array_values($g['variants']), 'stock' => (object) $stock,
        'cap' => $g['max_per_order'] ?? null,
        'images' => array_map(fn($im) => ['url' => GoodyImages::url($im['file']), 'alt' => GoodyImages::alt((string) $g['name'], $im)], $g['images'] ?? []),
    ];
}
$mgPayload = ['member' => $memberId, 'balance' => $balance, 'canRedeem' => $canRedeem, 'clear' => $clearCart, 'goodies' => $mgGoodies];

// A steady colour for each goody, from its name, so the catalogue is easy to tell apart at a glance.
$mgHue = fn(string $s): int => abs(crc32($s)) % 360;
$mgInitial = fn(string $s): string => mb_strtoupper(mb_substr(trim($s), 0, 1)) ?: '★';
$steps = ['Requested', 'Ordered', 'Shipped', 'Delivered'];

// ---- Orders: on their way first, finished ones lower down -----------------------------------------
$activeOrders = PointsModel::ordersInProgress($orders);
$pastOrders   = array_values(array_filter($orders, fn($o) => !in_array($o, $activeOrders, true)));
$ordersAnchor = $activeOrders ? '#orders' : '#past-orders';

$renderOrders = function (array $list) use ($steps, $when): void { ?>
    <div class="mg-orders">
        <?php foreach ($list as $o): $status = (string) $o['status']; $at = array_search($status, $steps, true); $cx = $status === 'Cancelled'; ?>
            <div class="mg-order">
                <div class="top">
                    <div>
                        <h3><?= htmlspecialchars((string) $o['goody_name']) ?></h3>
                        <p class="meta"><?= number_format((int) $o['net_points']) ?> points<?= (int) $o['refunded_points'] > 0 && !$cx ? ' (' . number_format((int) $o['refunded_points']) . ' refunded)' : '' ?> · <?= htmlspecialchars($when($o['created_at'])) ?> · <code><?= htmlspecialchars((string) $o['tracking_id']) ?></code><button type="button" class="copy" data-copy="<?= htmlspecialchars((string) $o['tracking_id']) ?>">Copy</button></p>
                    </div>
                    <span class="st<?= $status === 'Delivered' ? ' done' : '' ?><?= $cx ? ' cx' : '' ?>"><?= htmlspecialchars($status) ?></span>
                </div>
                <?php if (!empty($o['items'])): ?>
                    <ul class="mg-lines">
                        <?php foreach ($o['items'] as $it): ?>
                            <li><span><?= (int) $it['qty'] ?> × <?= htmlspecialchars(PointsModel::itemLabel((string) $it['name'], (string) ($it['variant'] ?? ''))) ?></span>
                                <?php foreach ($it['refunds'] as $rf): $why = (string) $rf['reason']; ?>
                                    <em class="rf">↩ <?= (int) $rf['qty'] ?> refunded (+<?= number_format((int) $rf['points']) ?> points)<?= $why !== '' && $why !== 'Order cancelled' ? ': ' . htmlspecialchars($why) : '' ?></em>
                                <?php endforeach; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <?php if (!empty($o['delivery_method']) && !$cx): ?>
                    <p class="meta" style="margin:8px 0 0">
                        <?php if ($o['delivery_method'] === 'hand'): ?>
                            🤝 <strong>Handed over by hand</strong>: <?= htmlspecialchars((string) $o['handover_note']) ?>
                        <?php else: ?>
                            📦 <strong><?= htmlspecialchars((string) $o['courier']) ?></strong> &middot; Consignment (AWB) <code><?= htmlspecialchars((string) $o['awb']) ?></code><button type="button" class="copy" data-copy="<?= htmlspecialchars((string) $o['awb']) ?>">Copy</button>
                            <?php if (!empty($o['tracking_url'])): ?>&middot; <a href="<?= htmlspecialchars((string) $o['tracking_url']) ?>" target="_blank" rel="noopener noreferrer">Track your parcel</a><?php endif; ?>
                        <?php endif; ?>
                    </p>
                <?php endif; ?>
                <?php if ($cx): ?>
                    <p class="note">This order was cancelled and the points were refunded.</p>
                <?php else: ?>
                    <?php if ((int) $o['refunded_points'] > 0): ?><p class="meta" style="margin:8px 0 0">Refunded items are not part of the parcel.</p><?php endif; ?>
                    <ol class="mg-track" aria-label="Order progress">
                        <?php foreach ($steps as $i => $s): ?><li<?= $at !== false && $i <= $at ? ' class="on"' : '' ?>><?= $s ?></li><?php endforeach; ?>
                    </ol>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
<?php };

// ---- Full history: filters and paging (history view only) -----------------------------------------
const MG_HIST_PER_PAGE = 25;
const MG_HIST_PREVIEW  = 8;
$yearOf = fn(array $r): int => (int) (new DateTimeImmutable((string) $r['created_at'], new DateTimeZone('UTC')))->setTimezone($ist)->format('Y');

$years = [];
foreach ($ledger as $r) $years[$yearOf($r)] = true;
$years = array_keys($years);
rsort($years);

$fy = (int) ($_GET['year'] ?? 0);
if (!in_array($fy, $years, true)) $fy = 0;
$ft = in_array($_GET['type'] ?? '', ['earned', 'spent'], true) ? (string) $_GET['type'] : '';
$fc = isset(PointsModel::CATEGORIES[(string) ($_GET['cat'] ?? '')]) ? (string) $_GET['cat'] : '';
$filtering = $fy || $ft !== '' || $fc !== '';

$histRows = []; $histTotal = 0; $histPages = 1; $histPage = 1;
if ($historyMode) {
    $filtered = array_values(array_filter($ledger, function ($r) use ($fy, $ft, $fc, $yearOf) {
        if ($fy && $yearOf($r) !== $fy) return false;
        if ($ft === 'earned' && (int) $r['points'] <= 0) return false;
        if ($ft === 'spent' && (int) $r['points'] >= 0) return false;
        if ($fc !== '' && (string) ($r['category'] ?? '') !== $fc) return false;
        return true;
    }));
    $histTotal = count($filtered);
    $histPages = max(1, (int) ceil($histTotal / MG_HIST_PER_PAGE));
    $histPage  = min($histPages, max(1, (int) ($_GET['page'] ?? 1)));
    $histRows  = array_slice($filtered, ($histPage - 1) * MG_HIST_PER_PAGE, MG_HIST_PER_PAGE);
}
$histUrl = function (int $p) use ($fy, $ft, $fc): string {
    $args = array_filter(['view' => 'history', 'year' => $fy ?: null, 'type' => $ft, 'cat' => $fc, 'page' => $p > 1 ? $p : null],
        fn($v) => $v !== null && $v !== '');
    return '/member/goodies?' . http_build_query($args);
};
$summary = $historyMode ? points_summary($ledger, $fy ?: (int) (new DateTimeImmutable('now', $ist))->format('Y')) : null;

// ---- Page frame ------------------------------------------------------------------------------------
if ($claimPage) {
    engage_header([
        'title'   => 'Claim points',
        'heading' => $claimRef !== '' ? 'My claim' : 'Claim points',
        'lead'    => $claimRef !== '' ? 'Where your claim stands.' : 'Ask for points for something you did that DCW did not log for you.',
        'compact' => true,
        'back'    => $claimRef !== '' ? ['Claim points', '/member/goodies?view=claim'] : ['My goodies', '/member/goodies'],
        'member'  => $member,
        'tools'   => engage_member_menu($member, ['current' => '/member/goodies']),
        'wide'    => true,
    ]);
} elseif ($historyMode) {
    engage_header([
        'title'   => 'Points history',
        'heading' => 'Points history',
        'lead'    => 'Every award and redemption, newest first. Balance now: ' . number_format($balance) . ' points.',
        'compact' => true,
        'back'    => ['My goodies', '/member/goodies'],
        'member'  => $member,
        'tools'   => engage_member_menu($member, ['current' => '/member/goodies']),
        'wide'    => true,
    ]);
} else {
    engage_header([
        'title'   => 'My goodies',
        'heading' => 'My goodies',
        'lead'    => 'Points you have earned for contributing to DCW. They never expire.',
        'member'  => $member,
        'tools'   => engage_member_menu($member, ['current' => '/member/goodies']),
        'wide'    => true,
    ]);
}
cert_rows_css();
points_history_css();
points_claim_css();
?>
<style>
    [hidden] { display: none !important; }

    /* Hero: the balance, and how far it goes. This is the one loud thing on the page. */
    .mg-hero { position: relative; overflow: hidden; margin: 0 0 18px; padding: 28px 30px; border-radius: 22px; color: #fff;
        background: radial-gradient(circle at 92% 12%, rgba(255,255,255,.22) 0 8%, transparent 8.5%),
                    radial-gradient(circle at 92% 12%, rgba(255,255,255,.12) 0 18%, transparent 18.5%),
                    radial-gradient(circle at 92% 12%, rgba(255,255,255,.07) 0 30%, transparent 30.5%),
                    linear-gradient(135deg, var(--primary-dark) 0%, var(--primary) 60%, var(--primary-light) 100%);
        box-shadow: 0 14px 34px rgba(31,74,117,.28); }
    .mg-hero .who { margin: 0; font-size: 14px; opacity: .85; }
    .mg-hero .big { display: flex; flex-wrap: wrap; align-items: baseline; gap: 6px 10px; margin: 6px 0 0; }
    .mg-hero .big b { font-size: clamp(48px, 9vw, 76px); line-height: 1; font-weight: 800; letter-spacing: -.02em; font-variant-numeric: tabular-nums; }
    .mg-hero .big span { font-size: 18px; font-weight: 600; opacity: .9; }
    .mg-hero .big em { align-self: center; padding: 3px 12px; border-radius: 999px; background: rgba(255,255,255,.2); font-size: 13.5px; font-style: normal; font-weight: 700; }
    .mg-goal { margin: 20px 0 0; max-width: 520px; }
    .mg-goal p { margin: 0 0 8px; font-size: 14.5px; }
    .mg-bar { height: 10px; border-radius: 999px; background: rgba(255,255,255,.25); overflow: hidden; }
    .mg-bar i { display: block; height: 100%; border-radius: 999px; background: #ffd166; }
    .mg-hero .go { display: inline-block; margin-top: 18px; padding: 9px 20px; border-radius: 999px; background: #fff; color: var(--primary-dark); font-weight: 700; font-size: 14px; text-decoration: none; }

    /* Jump links under the hero */
    .mg-nav { display: flex; flex-wrap: wrap; gap: 8px; margin: 0 0 26px; }
    .mg-nav a { padding: 7px 16px; border-radius: 999px; background: var(--card); border: 1px solid var(--border); color: var(--ink); font-size: 14px; font-weight: 600; text-decoration: none; transition: border-color .15s; }
    .mg-nav a:hover { border-color: var(--primary); color: var(--primary-dark); }

    .mg-title { display: flex; flex-wrap: wrap; align-items: baseline; justify-content: space-between; gap: 6px 16px; margin: 0 0 14px; }
    .mg-title h2 { margin: 0; font-size: 21px; }
    .mg-title p { margin: 0; color: var(--muted); font-size: 13.5px; max-width: 520px; }
    .mg-title a { font-size: 14px; font-weight: 600; color: var(--primary); text-decoration: none; }
    .mg-title a:hover { text-decoration: underline; }

    /* Two columns on wide screens (history and past orders). The dashboard defines its own .d-two; this page needs this one. */
    .mg-two { display: grid; gap: 0 34px; margin: 0 0 34px; }
    @media (min-width: 1000px) { .mg-two { grid-template-columns: 1.2fr 1fr; align-items: start; } }

    /* Catalogue */
    .mg-grid { display: grid; gap: 18px; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); margin: 0 0 34px; }
    .mg-card { display: flex; flex-direction: column; overflow: hidden; background: var(--card); border: 1px solid var(--border); border-radius: 18px; transition: transform .18s, box-shadow .18s; }
    .mg-card:hover { transform: translateY(-3px); box-shadow: 0 12px 28px rgba(15,23,42,.12); }
    .mg-card.locked { opacity: .92; }
    .mg-art { position: relative; display: flex; align-items: center; justify-content: center; width: 100%; height: 116px; padding: 0; border: 0; background: linear-gradient(135deg, hsl(var(--h) 70% 90%), hsl(calc(var(--h) + 35) 75% 82%)); }
    .mg-art span.ini { display: grid; place-items: center; width: 64px; height: 64px; border-radius: 50%; background: #fff; color: hsl(var(--h) 60% 32%); font-size: 30px; font-weight: 800; box-shadow: 0 6px 16px rgba(15,23,42,.14); }
    /* A goody with a picture: the cover fills the top of the card, and opens the picture viewer. */
    .mg-art.has-img { height: 180px; background: #f1f5f9; cursor: zoom-in; }
    .mg-art.has-img img { display: block; width: 100%; height: 100%; object-fit: cover; }
    .mg-more { position: absolute; right: 10px; bottom: 10px; padding: 2px 10px; border-radius: 999px; background: rgba(15,23,42,.72); color: #fff; font-size: 12px; font-weight: 700; }
    .mg-zoom { position: absolute; left: 10px; bottom: 10px; padding: 2px 10px; border-radius: 999px; background: rgba(15,23,42,.72); color: #fff; font-size: 12px; font-weight: 700; }
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
    .mg-btn[disabled] { opacity: .5; cursor: not-allowed; }
    .mg-btn:focus-visible, .mg-dlg input:focus-visible, .mg-dlg select:focus-visible, .mg-dlg textarea:focus-visible, .mg-thumbs button:focus-visible,
    .mg-art:focus-visible, .mg-line button:focus-visible, .mg-lb button:focus-visible, .mg-gal-main:focus-visible { outline: 2px solid var(--primary); outline-offset: 2px; }

    /* Stock and limits. The strikethrough is for a size chip only: the "Out of stock" tag keeps its text readable. */
    .mg-opts span.out:not(.mg-tag) { text-decoration: line-through; opacity: .6; }
    .mg-tag { padding: 2px 10px; border-radius: 999px; font-size: 12px; font-weight: 700; }
    .mg-tag.out { background: #fbe4e5; color: #97161b; text-decoration: none; }
    .mg-tag.low { background: #fef3c7; color: #92400e; }
    .mg-tag.cap { background: #e8f3f9; color: #0b4f73; }
    .mg-card.soldout .mg-art { filter: grayscale(1); opacity: .7; }

    /* Dialogs: detail, cart */
    .mg-dlg { width: min(480px, calc(100vw - 32px)); max-height: calc(100vh - 32px); padding: 0; border: 0; border-radius: 20px; box-shadow: 0 24px 60px rgba(15,23,42,.35); }
    .mg-dlg::backdrop { background: rgba(15,23,42,.5); }
    .mg-dlg header { padding: 20px 22px 14px; background: linear-gradient(135deg, hsl(var(--h, 205) 70% 92%), hsl(calc(var(--h, 205) + 35) 75% 86%)); }
    .mg-dlg header h3 { margin: 0 0 2px; font-size: 19px; }
    .mg-dlg header p { margin: 0; color: #475569; font-size: 13.5px; }
    .mg-dlg form, .mg-dbody { padding: 8px 22px 22px; }
    .mg-dlg label { display: block; margin: 12px 0 0; font-size: 13px; font-weight: 600; }
    .mg-dlg input[type=text], .mg-dlg input[type=tel], .mg-dlg input[type=number], .mg-dlg select, .mg-dlg textarea { width: 100%; box-sizing: border-box; margin-top: 4px; padding: 10px 12px; font: inherit; border: 1px solid var(--border); border-radius: 10px; }
    .mg-dlg .fine { margin: 12px 0 0; color: var(--muted); font-size: 12.5px; }
    .mg-dlg .acts { display: flex; gap: 10px; margin-top: 16px; }
    .mg-dlg .acts .mg-btn { margin: 0; }
    .mg-dbody p.desc { margin: 10px 0 0; color: #475569; font-size: 14px; line-height: 1.5; }
    .mg-qty { display: inline-flex; align-items: center; gap: 8px; margin-top: 4px; }
    .mg-qty button { width: 34px; height: 34px; border: 1px solid var(--border); border-radius: 50%; background: #fff; font: inherit; font-weight: 800; font-size: 17px; cursor: pointer; }
    .mg-qty button[disabled] { opacity: .4; cursor: not-allowed; }
    .mg-qty input[type=number] { width: 4.2em !important; margin: 0 !important; text-align: center; }
    .mg-qty b { min-width: 1.6em; text-align: center; font-variant-numeric: tabular-nums; }

    /* Pictures in the detail dialog: a large one that opens the viewer, and thumbnails when there are several */
    .mg-gal { position: relative; background: #f1f5f9; }
    .mg-gal-main { position: relative; display: block; width: 100%; padding: 0; border: 0; background: #f8fafc; cursor: zoom-in; }
    .mg-gal-main img { display: block; width: 100%; height: 260px; object-fit: contain; }
    .mg-thumbs { display: flex; flex-wrap: wrap; gap: 6px; padding: 8px 22px; background: #fff; border-bottom: 1px solid var(--border); }
    .mg-thumbs button { width: 54px; height: 54px; padding: 0; border: 2px solid transparent; border-radius: 10px; overflow: hidden; background: #f1f5f9; cursor: pointer; }
    .mg-thumbs button[aria-current="true"] { border-color: var(--primary); }
    .mg-thumbs img { display: block; width: 100%; height: 100%; object-fit: cover; }

    /* Cart bar and cart lines */
    .mg-cartbar { position: fixed; left: 50%; bottom: 16px; transform: translateX(-50%); z-index: 50; display: flex; align-items: center; gap: 14px; max-width: calc(100vw - 24px); padding: 10px 12px 10px 20px; border-radius: 999px; background: var(--ink); color: #fff; box-shadow: 0 12px 30px rgba(15,23,42,.35); font-weight: 700; font-size: 14.5px; }
    .mg-cartbar .mg-btn { width: auto; margin: 0; padding: 8px 18px; background: #ffd166; color: #1e293b; }
    .mg-line { display: grid; grid-template-columns: 52px minmax(0, 1fr) auto; gap: 4px 12px; align-items: center; padding: 12px 0; border-top: 1px solid var(--border); }
    .mg-line:first-child { border-top: 0; }
    .mg-line .th { width: 52px; height: 52px; border-radius: 10px; overflow: hidden; background: #f1f5f9; display: grid; place-items: center; font-weight: 800; color: #475569; }
    .mg-line .th img { width: 100%; height: 100%; object-fit: cover; }
    .mg-line .nm { font-weight: 700; font-size: 14.5px; overflow-wrap: anywhere; }
    .mg-line .nm small { display: block; color: var(--muted); font-weight: 500; font-size: 12.5px; }
    .mg-line .pr { text-align: right; font-weight: 800; font-variant-numeric: tabular-nums; }
    .mg-line .ctl { grid-column: 2 / -1; display: flex; align-items: center; gap: 10px; }
    .mg-line .rm { border: 0; background: none; color: #97161b; font: inherit; font-size: 13px; font-weight: 700; cursor: pointer; }
    .mg-sum { margin: 10px 0 4px; padding: 10px 14px; border-radius: 12px; background: #e8f3f9; color: #0b4f73; font-weight: 700; font-size: 14.5px; }
    .mg-sum.bad { background: #fee2e2; color: #97161b; }
    @media (max-width: 520px) { .mg-cartbar { bottom: 10px; padding-left: 16px; } }

    /* Picture viewer: full picture, click or the Zoom button to zoom in, scroll to look around */
    .mg-lb { width: 100vw; max-width: 100vw; height: 100vh; height: 100dvh; max-height: 100vh; max-height: 100dvh; margin: 0; padding: 0; border: 0; background: rgba(8,12,20,.96); color: #fff; }
    .mg-lb[open] { display: flex; flex-direction: column; }
    .mg-lb::backdrop { background: rgba(0,0,0,.8); }
    .mg-lb-bar { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 10px 14px; font-weight: 700; }
    .mg-lb button { padding: 8px 16px; border: 0; border-radius: 999px; background: rgba(255,255,255,.16); color: #fff; font: inherit; font-weight: 700; cursor: pointer; }
    .mg-lb button:hover { background: rgba(255,255,255,.28); }
    .mg-lb-wrap { position: relative; flex: 1; min-height: 0; display: flex; }
    .mg-lb-stage { flex: 1; display: flex; overflow: auto; cursor: zoom-in; touch-action: pan-x pan-y pinch-zoom; }
    .mg-lb-stage.zoomed { cursor: zoom-out; }
    .mg-lb-stage img { display: block; margin: auto; max-width: 100%; max-height: 100%; object-fit: contain; }
    .mg-lb-nav { position: absolute; top: 50%; transform: translateY(-50%); width: 44px; height: 44px; padding: 0 !important; font-size: 22px; }
    .mg-lb-nav.prev { left: 12px; } .mg-lb-nav.next { right: 12px; }
    .mg-lb-cap { margin: 0; padding: 8px 14px 14px; text-align: center; font-size: 14px; color: #cbd5e1; }

    .mg-empty { padding: 26px; text-align: center; color: var(--muted); background: var(--card); border: 1px dashed #c3d0dc; border-radius: 16px; }

    /* History filters and pager (full history view) */
    .mg-filter { display: flex; flex-wrap: wrap; gap: 10px; margin: 0 0 16px; }
    .mg-filter select { font: inherit; font-size: 14.5px; padding: 9px 14px; color: var(--ink); background: #fff; border: 1px solid var(--border); border-radius: 999px; }
    .mg-filter select:focus-visible { outline: 2px solid var(--primary); outline-offset: 1px; }
    .mg-filter .clear { align-self: center; font-size: 14px; font-weight: 600; color: var(--primary); }
    .mg-count { margin: 0 0 12px; font-size: 13.5px; color: var(--muted); }
    .mg-pager { display: flex; align-items: center; justify-content: center; gap: 14px; margin: 18px 0 0; font-size: 14px; color: var(--muted); }
    .mg-pager .off { opacity: .45; pointer-events: none; }
    .mg-hist-wrap { max-width: 760px; }

    /* Orders */
    .mg-orders { display: grid; gap: 12px; }
    .mg-order { padding: 14px 16px; background: var(--card); border: 1px solid var(--border); border-radius: 14px; }
    .mg-order .top { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 6px 12px; align-items: baseline; }
    .mg-order h3 { margin: 0; font-size: 15.5px; }
    .mg-order .meta { margin: 3px 0 0; color: var(--muted); font-size: 13px; }
    .mg-order .st { padding: 2px 11px; border-radius: 999px; background: var(--primary-tint); color: var(--primary-dark); font-size: 12.5px; font-weight: 700; }
    .mg-order .st.done { background: #d9f3ee; color: #0f766e; }
    .mg-order .st.cx { background: #fbe4e5; color: #97161b; }
    .mg-lines { list-style: none; margin: 10px 0 0; padding: 0; display: grid; gap: 4px; font-size: 14px; }
    .mg-lines em.rf { display: block; margin-top: 1px; color: #92400e; font-size: 12.5px; font-style: normal; font-weight: 600; }
    .mg-track { display: grid; grid-template-columns: repeat(4, 1fr); gap: 4px; margin: 14px 0 0; padding: 0; list-style: none; }
    .mg-track li { position: relative; padding-top: 16px; text-align: center; color: #94a3b8; font-size: 12px; font-weight: 600; }
    .mg-track li::before { content: ""; position: absolute; top: 0; left: 0; right: 0; height: 6px; border-radius: 999px; background: #e2e8f0; }
    .mg-track li.on { color: var(--primary-dark); }
    .mg-track li.on::before { background: var(--primary); }
    .mg-order .note { margin: 10px 0 0; color: #97161b; font-size: 13px; }

    @media (prefers-reduced-motion: reduce) { .mg-card { transition: none; } .mg-card:hover { transform: none; } }
    @media (max-width: 520px) { .mg-hero { padding: 22px 20px; border-radius: 18px; } .mg-gal-main img { height: 220px; } }
</style>

<div class="dash">

    <?php if ($flash): ?>
        <div class="alert <?= $flash[0] === 'ok' ? 'ok' : 'error' ?>"><?= htmlspecialchars((string) $flash[1]) ?></div>
    <?php endif; ?>
    <?php if ($setupError): ?>
        <div class="alert error">Goodies are not available just now. Please check back soon.</div>
    <?php endif; ?>
    <div class="alert error" id="mg-cart-note" hidden>Your cart was updated because stock or availability changed. Please check it before you order.</div>

<?php if ($claimPage): ?>

    <div class="pc-wrap">
    <?php if ($claims === null): ?>
        <div class="pc-empty">Claiming points is not available just now. Please check back soon.</div>
    <?php elseif ($claimRef !== ''): ?>
        <?php if (!$claimOne): ?>
            <div class="pc-empty">We could not find that claim in your account. <a href="/member/goodies?view=claim">See your claims</a>.</div>
        <?php else: points_claim_render_detail($claimOne, $claimMessages); endif; ?>
    <?php else: ?>
        <?php points_claim_render_form($claimActivities ?? [], $claimOld, $claimCooldowns); ?>
        <section class="pc-card">
            <h2>My claims</h2>
            <p class="sub">Approved claims add points to your balance. Open one to follow it or to reply.</p>
            <?php points_claim_render_list($claims); ?>
        </section>
        <?php points_claim_render_other($claimOthers ?? []); ?>
    <?php endif; ?>
    </div>

<?php elseif ($historyMode): ?>

    <div class="mg-hist-wrap">
        <?php if ($ledger || $pending): ?>
            <div class="ph-sum" aria-label="Summary for <?= (int) $summary['year'] ?>">
                <span><b><?= (int) $summary['year'] ?></b> · <?= number_format($summary['earned']) ?> earned · <?= number_format($summary['spent']) ?> spent</span>
                <?php foreach ($summary['cats'] as $cat => $pts): ?>
                    <span class="c" style="--h:<?= points_cat_hue($cat) ?>"><?= points_cat_emoji($cat) ?> <?= points_h(PointsModel::CATEGORIES[$cat] ?? $cat) ?> <b><?= number_format($pts) ?></b></span>
                <?php endforeach; ?>
            </div>

            <form class="mg-filter" method="get" action="/member/goodies">
                <input type="hidden" name="view" value="history">
                <select name="year" aria-label="Year" onchange="this.form.submit()">
                    <option value="0">All years</option>
                    <?php foreach ($years as $y): ?><option value="<?= $y ?>"<?= $y === $fy ? ' selected' : '' ?>><?= $y ?></option><?php endforeach; ?>
                </select>
                <select name="type" aria-label="Earned or spent" onchange="this.form.submit()">
                    <option value="">Earned and spent</option>
                    <option value="earned"<?= $ft === 'earned' ? ' selected' : '' ?>>Earned only</option>
                    <option value="spent"<?= $ft === 'spent' ? ' selected' : '' ?>>Spent only</option>
                </select>
                <select name="cat" aria-label="Category" onchange="this.form.submit()">
                    <option value="">All categories</option>
                    <?php foreach (PointsModel::CATEGORIES as $key => $label): ?><option value="<?= $key ?>"<?= $key === $fc ? ' selected' : '' ?>><?= htmlspecialchars($label) ?></option><?php endforeach; ?>
                </select>
                <?php if ($filtering): ?><a class="clear" href="/member/goodies?view=history">Clear</a><?php endif; ?>
            </form>
        <?php endif; ?>

        <?php if (!$ledger && !$pending): ?>
            <div class="mg-empty">No points yet. They are awarded for attending sessions, mentoring and contributing.</div>
        <?php elseif ($histTotal === 0 && !($pending && !$filtering)): ?>
            <div class="mg-empty">Nothing matches those filters. <a href="/member/goodies?view=history">Show everything</a>.</div>
        <?php else: ?>
            <?php if (!$filtering && $histPage === 1) points_render_pending($pending); ?>
            <?php if ($histTotal > 0): ?>
                <p class="mg-count" role="status">Showing <?= ($histPage - 1) * MG_HIST_PER_PAGE + 1 ?>&ndash;<?= min($histTotal, $histPage * MG_HIST_PER_PAGE) ?> of <?= $histTotal ?>.</p>
                <?php points_render_months(points_months($histRows)); ?>
            <?php endif; ?>
            <?php if ($histPages > 1): ?>
                <nav class="mg-pager" aria-label="Pages">
                    <a class="d-btn line<?= $histPage <= 1 ? ' off' : '' ?>" href="<?= htmlspecialchars($histUrl($histPage - 1)) ?>" <?= $histPage <= 1 ? 'aria-disabled="true" tabindex="-1"' : '' ?>>Previous</a>
                    <span>Page <?= $histPage ?> of <?= $histPages ?></span>
                    <a class="d-btn line<?= $histPage >= $histPages ? ' off' : '' ?>" href="<?= htmlspecialchars($histUrl($histPage + 1)) ?>" <?= $histPage >= $histPages ? 'aria-disabled="true" tabindex="-1"' : '' ?>>Next</a>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </div>

<?php else: ?>

    <section class="mg-hero" aria-label="Points balance">
        <p class="who">Member ID <?= htmlspecialchars($memberId) ?></p>
        <div class="big"><b><?= number_format($balance) ?></b><span>points</span>
            <?php if ($pendingSum > 0): ?><em>+<?= number_format($pendingSum) ?> waiting for approval</em><?php endif; ?></div>
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
        <?php if ($affordable): ?><a class="go" href="#catalogue">You can redeem <?= $affordable ?> <?= $affordable === 1 ? 'goody' : 'goodies' ?> now</a><?php endif; ?>
        <?php if ($claims !== null): ?><a class="go" style="margin-left:8px" href="/member/goodies?view=claim">Claim points<?= $openClaims ? ' (' . $openClaims . ' open)' : '' ?></a><?php endif; ?>
    </section>

    <?php if ($claimsReply > 0): ?>
        <div class="alert error" style="background:#fffbeb;border-color:#fcd34d;color:#78350f"><strong>A reviewer replied on <?= $claimsReply === 1 ? 'a claim' : $claimsReply . ' claims' ?>.</strong> <a href="/member/goodies?view=claim">Open your claims</a> to answer.</div>
    <?php endif; ?>

    <nav class="mg-nav" aria-label="On this page">
        <a href="#catalogue">Redeem</a>
        <a href="<?= $ordersAnchor ?>">My orders<?= $activeOrders ? ' (' . count($activeOrders) . ' on the way)' : '' ?></a>
        <a href="#history">Points history</a>
        <?php if ($claims !== null): ?><a href="/member/goodies?view=claim">Claim points</a><?php endif; ?>
    </nav>

    <?php if ($activeOrders): ?>
        <section id="orders" aria-labelledby="mg-act-h" style="margin:0 0 34px">
            <div class="mg-title"><h2 id="mg-act-h">On its way</h2><p>Orders you have placed that have not been delivered yet.</p></div>
            <?php $renderOrders($activeOrders); ?>
        </section>
    <?php endif; ?>

    <section id="catalogue" aria-labelledby="mg-cat-h">
        <div class="mg-title">
            <h2 id="mg-cat-h">Goodies you can redeem</h2>
            <p>Add what you like to your cart and order it all at once. Points have no cash value and cannot be transferred. The catalogue and point costs may change; points you have earned do not expire.</p>
        </div>
        <?php if (!$goodies): ?>
            <div class="mg-empty" style="margin-bottom:34px">No goodies are available right now. Your points are safe; check back soon.</div>
        <?php else: ?>
            <div class="mg-grid">
                <?php foreach ($sorted as $g):
                    $cost = (int) $g['points_cost'];
                    $soldOut = !PointsModel::inStock($g);
                    $can = $canRedeem && !$soldOut && $balance >= $cost;
                    $h = $mgHue((string) $g['name']);
                    $leftAll = !$g['variants'] ? PointsModel::stockFor($g) : null;   // null = not tracked or has sizes
                    $imgs = $g['images'] ?? [];
                    $cover = $imgs[0] ?? null;
                    $cap = $g['max_per_order'] ?? null;
                ?>
                    <article class="mg-card<?= $can ? '' : ' locked' ?><?= $soldOut ? ' soldout' : '' ?>" style="--h:<?= $h ?>">
                        <?php if ($cover): ?>
                            <button type="button" class="mg-art has-img" data-zoom="<?= (int) $g['id'] ?>" aria-label="View pictures of <?= htmlspecialchars((string) $g['name']) ?>">
                                <img src="<?= htmlspecialchars(GoodyImages::url($cover['file'])) ?>" alt="<?= htmlspecialchars(GoodyImages::alt((string) $g['name'], $cover)) ?>" loading="lazy">
                                <span class="mg-zoom">🔍 View</span>
                                <?php if (count($imgs) > 1): ?><b class="mg-more" aria-hidden="true">1/<?= count($imgs) ?></b><?php endif; ?>
                            </button>
                        <?php else: ?>
                            <div class="mg-art" aria-hidden="true"><span class="ini"><?= htmlspecialchars($mgInitial((string) $g['name'])) ?></span></div>
                        <?php endif; ?>
                        <div class="mg-body">
                            <h3><?= htmlspecialchars($g['name']) ?></h3>
                            <?php if ((string) $g['description'] !== ''): ?><p><?= htmlspecialchars((string) $g['description']) ?></p><?php endif; ?>
                            <?php /* When everything is sold out one tag says so; five struck-through sizes add nothing. */ ?>
                            <?php if ($g['variants'] && !$soldOut): ?><div class="mg-opts" aria-label="Options"><?php foreach ($g['variants'] as $v): $n = PointsModel::stockFor($g, $v); $out = $n !== null && $n < 1; ?><span<?= $out ? ' class="out" title="Out of stock"' : '' ?>><?= htmlspecialchars($v) ?><?= $out ? ' · out of stock' : '' ?></span><?php endforeach; ?></div><?php endif; ?>
                            <?php if ($soldOut || ($leftAll !== null && $leftAll <= 5) || $cap !== null): ?>
                                <div class="mg-opts">
                                    <?php if ($soldOut): ?><span class="mg-tag out">Out of stock</span>
                                    <?php elseif ($leftAll !== null && $leftAll <= 5): ?><span class="mg-tag low">Only <?= $leftAll ?> left</span><?php endif; ?>
                                    <?php if ($cap !== null): ?><span class="mg-tag cap">Max <?= (int) $cap ?> per order</span><?php endif; ?>
                                </div>
                            <?php endif; ?>
                            <div class="mg-foot">
                                <div class="mg-cost"><b><?= number_format($cost) ?></b><span>points</span></div>
                                <?php if (!$canRedeem): ?>
                                    <p class="mg-need" style="margin-top:8px !important">Redeeming is not available on your account. Please contact DCW Support.</p>
                                <?php elseif ($soldOut): ?>
                                    <p class="mg-need" style="margin-top:8px !important">Currently out of stock. Check back soon; your points are safe.</p>
                                <?php elseif (!$can): $pct = max(0, min(100, (int) floor($balance / $cost * 100))); ?>
                                    <div class="mg-mini" aria-hidden="true"><i style="width:<?= $pct ?>%"></i></div>
                                    <p class="mg-need">You need <?= number_format(max(0, $cost - $balance)) ?> more points.</p>
                                <?php endif; ?>
                                <button type="button" class="mg-btn<?= $can ? '' : ' ghost' ?>" data-detail="<?= (int) $g['id'] ?>"><?= $can ? 'Add to cart' : 'View details' ?></button>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <div class="mg-two">
        <section id="history" aria-labelledby="mg-hist-h">
            <div class="mg-title">
                <h2 id="mg-hist-h">Points history</h2>
                <?php if (count($ledger) > MG_HIST_PREVIEW): ?><a href="/member/goodies?view=history">View all <?= count($ledger) ?> &rarr;</a><?php endif; ?>
            </div>
            <?php if (!$ledger && !$pending): ?>
                <div class="mg-empty">No points yet. They are awarded for attending sessions, mentoring and contributing.</div>
            <?php else: ?>
                <?php points_render_pending($pending); ?>
                <?php points_render_months(points_months(array_slice($ledger, 0, MG_HIST_PREVIEW))); ?>
                <?php if (count($ledger) > MG_HIST_PREVIEW): ?>
                    <p style="margin:0"><a class="d-btn line" href="/member/goodies?view=history">See the full history</a></p>
                <?php endif; ?>
            <?php endif; ?>
        </section>

        <section id="past-orders" aria-labelledby="mg-ord-h">
            <div class="mg-title"><h2 id="mg-ord-h"><?= $activeOrders ? 'Past orders' : 'My orders' ?></h2><p>Goodies you have redeemed.</p></div>
            <?php if (!$pastOrders): ?>
                <div class="mg-empty"><?= $activeOrders ? 'No finished orders yet.' : 'No orders yet. Pick a goody above to place your first one.' ?></div>
            <?php else: ?>
                <?php $renderOrders($pastOrders); ?>
            <?php endif; ?>
        </section>
    </div>

    <?php if ($goodies): ?>
    <!-- Cart bar -->
    <div class="mg-cartbar" id="mg-cartbar" hidden>
        <span id="mg-cartbar-text"></span>
        <button type="button" class="mg-btn" id="mg-cartbar-open">View cart</button>
    </div>

    <!-- Goody detail: pictures, size, quantity, add to cart (filled in by the script below) -->
    <dialog class="mg-dlg" id="mg-detail" aria-labelledby="mg-d-title">
        <header><h3 id="mg-d-title"></h3><p id="mg-d-sub"></p></header>
        <div class="mg-gal" id="mg-d-gal" hidden>
            <button type="button" class="mg-gal-main" id="mg-d-main" aria-label="Zoom this picture"><img src="" alt=""><span class="mg-zoom">🔍 Tap to zoom</span></button>
        </div>
        <div class="mg-thumbs" id="mg-d-thumbs" role="group" aria-label="Pictures" hidden></div>
        <div class="mg-dbody">
            <p class="desc" id="mg-d-desc"></p>
            <label id="mg-d-varwrap">Size or option<select id="mg-d-var"></select></label>
            <div id="mg-d-qtywrap"><label>Quantity</label>
                <span class="mg-qty"><button type="button" id="mg-d-minus" aria-label="One fewer">−</button><input type="number" id="mg-d-qty" min="1" value="1" inputmode="numeric" aria-label="Quantity"><button type="button" id="mg-d-plus" aria-label="One more">+</button></span></div>
            <p class="fine" id="mg-d-note" role="status"></p>
            <div class="acts">
                <button type="button" class="mg-btn ghost" data-close>Close</button>
                <button type="button" class="mg-btn" id="mg-d-add">Add to cart</button>
            </div>
        </div>
    </dialog>

    <!-- Cart and checkout -->
    <dialog class="mg-dlg" id="mg-cart" aria-labelledby="mg-c-title">
        <header><h3 id="mg-c-title">Your cart</h3><p>One order, one tracking ID.</p></header>
        <form method="POST" action="/member/goodies" id="mg-c-form">
            <?= CSRF::getInputField() ?>
            <input type="hidden" name="action" value="redeem_cart">
            <input type="hidden" name="cart" id="mg-c-json" value="[]">
            <div id="mg-c-lines"></div>
            <div class="mg-sum" id="mg-c-sum" role="status"></div>
            <label>Name for delivery<input type="text" name="ship_name" required maxlength="120" value="<?= htmlspecialchars((string) ($member['full_name'] ?? '')) ?>" autocomplete="name"></label>
            <label>Phone<input type="tel" name="ship_phone" required maxlength="20" autocomplete="tel"></label>
            <label>Delivery address<textarea name="ship_address" required maxlength="400" rows="3" autocomplete="street-address"></textarea></label>
            <p class="fine">Your address is used only to send this order and is cleared after delivery. If something in your order cannot be sent, those items are refunded and the rest still ships.</p>
            <div class="acts">
                <button type="button" class="mg-btn ghost" data-close>Keep browsing</button>
                <button type="submit" class="mg-btn" id="mg-c-go">Place order</button>
            </div>
        </form>
    </dialog>

    <!-- Picture viewer -->
    <dialog class="mg-lb" id="mg-lb" aria-label="Picture viewer">
        <div class="mg-lb-bar">
            <span id="mg-lb-count"></span>
            <span><button type="button" id="mg-lb-zoom">Zoom</button> <button type="button" id="mg-lb-close">Close</button></span>
        </div>
        <div class="mg-lb-wrap">
            <div class="mg-lb-stage" id="mg-lb-stage"><img id="mg-lb-img" src="" alt=""></div>
            <button type="button" class="mg-lb-nav prev" id="mg-lb-prev" aria-label="Previous picture">‹</button>
            <button type="button" class="mg-lb-nav next" id="mg-lb-next" aria-label="Next picture">›</button>
        </div>
        <p class="mg-lb-cap" id="mg-lb-cap"></p>
    </dialog>

    <script type="application/json" id="mg-data"><?= json_encode($mgPayload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
    <?php endif; ?>

<?php endif; ?>

</div>

<script>
(function () {
    var dataEl = document.getElementById('mg-data');
    if (!dataEl) return;
    var D = JSON.parse(dataEl.textContent);
    var byId = {};
    D.goodies.forEach(function (g) { byId[g.id] = g; });
    var KEY = 'mg_cart_' + D.member;
    var MAXU = 50;                                   // sanity limit for goodies with no "max per order"
    var $ = function (id) { return document.getElementById(id); };
    var nf = function (n) { return Number(n).toLocaleString('en-IN'); };

    // ---- helpers ----
    function el(tag, props, kids) {
        var e = document.createElement(tag);
        Object.keys(props || {}).forEach(function (k) {
            if (k === 'text') e.textContent = props[k];
            else if (k === 'class') e.className = props[k];
            else if (k.slice(0, 2) === 'on') e.addEventListener(k.slice(2), props[k]);
            else e.setAttribute(k, props[k]);
        });
        (kids || []).forEach(function (c) { e.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
        return e;
    }
    var store = {
        get: function () { try { var v = JSON.parse(localStorage.getItem(KEY) || '[]'); return Array.isArray(v) ? v : []; } catch (e) { return []; } },
        set: function (v) { try { localStorage.setItem(KEY, JSON.stringify(v)); } catch (e) {} },
        clear: function () { try { localStorage.removeItem(KEY); } catch (e) {} }
    };

    // ---- the cart: [{id, v (size or ''), q}] ----
    var cart = [];
    function stockOf(g, v) { var k = g.variants.length ? v : '_'; var s = g.stock[k]; return (s === undefined || s === null) ? Infinity : s; }
    function capOf(g) { return g.cap === null ? MAXU : g.cap; }
    function inCart(id, v) { return cart.filter(function (l) { return l.id === id && (v === undefined || l.v === v); }).reduce(function (a, l) { return a + l.q; }, 0); }
    function maxAdd(g, v) { return Math.max(0, Math.min(capOf(g) - inCart(g.id), stockOf(g, v) - inCart(g.id, v))); }
    function total() { return cart.reduce(function (a, l) { return a + byId[l.id].cost * l.q; }, 0); }
    function units() { return cart.reduce(function (a, l) { return a + l.q; }, 0); }

    // Drops goodies that are gone, sizes that no longer exist, and trims quantities to the limit and the stock.
    function clean() {
        var out = [];
        cart.forEach(function (l) {
            var g = byId[l.id]; if (!g) return;
            var v = String(l.v || '');
            if (g.variants.length ? g.variants.indexOf(v) < 0 : v !== '') return;
            var have = out.filter(function (x) { return x.id === g.id; }).reduce(function (a, x) { return a + x.q; }, 0);
            var room = Math.min(stockOf(g, v), capOf(g) - have);
            var q = Math.min(parseInt(l.q, 10) || 0, room);
            if (q >= 1) out.push({ id: g.id, v: v, q: q });
        });
        return out;
    }
    function save() { cart = clean(); store.set(cart); drawBar(); }

    function drawBar() {
        var bar = $('mg-cartbar');
        bar.hidden = !cart.length || !D.canRedeem;
        if (!bar.hidden) $('mg-cartbar-text').textContent = '🛒 ' + units() + (units() === 1 ? ' item' : ' items') + ' · ' + nf(total()) + ' points';
    }

    // ---- detail dialog ----
    var cur = null, curImg = 0;
    function showMain() {
        var im = cur.images[curImg], b = $('mg-d-main').querySelector('img');
        b.src = im.url; b.alt = im.alt;
        [].slice.call($('mg-d-thumbs').children).forEach(function (t, i) { t.setAttribute('aria-current', i === curImg ? 'true' : 'false'); });
    }
    function syncDetail() {
        var g = cur; if (!g) return;
        var v = g.variants.length ? $('mg-d-var').value : '';
        var noVar = g.variants.length > 0 && v === '';
        var soldOut = g.variants.length ? g.variants.every(function (x) { return stockOf(g, x) < 1; }) : stockOf(g, '') < 1;
        var room = noVar ? 0 : maxAdd(g, v);
        var msg = '';
        if (!D.canRedeem) msg = 'Redeeming is not available on your account. Please contact DCW Support.';
        else if (soldOut) msg = 'Currently out of stock. Check back soon; your points are safe.';
        else if (g.cost > D.balance) msg = 'You need ' + nf(g.cost - D.balance) + ' more points.';
        else if (noVar) msg = 'Choose a size or option.';
        else if (room < 1) msg = 'Your cart already has the most you can order of this one.';
        var q = $('mg-d-qty');
        q.max = Math.max(1, room);
        q.value = Math.min(Math.max(1, parseInt(q.value, 10) || 1), Math.max(1, room));
        $('mg-d-qtywrap').hidden = g.cap === 1;
        $('mg-d-minus').disabled = +q.value <= 1;
        $('mg-d-plus').disabled = +q.value >= room;
        var bits = [];
        if (g.cap !== null) bits.push('Max ' + g.cap + ' per order.');
        var st = stockOf(g, v);
        if (isFinite(st) && st > 0 && st <= 5 && !noVar) bits.push('Only ' + st + ' left.');
        if (inCart(g.id)) bits.push(inCart(g.id) + ' already in your cart.');
        $('mg-d-note').textContent = [msg].concat(bits).filter(Boolean).join(' ');
        $('mg-d-add').disabled = !!msg;
    }
    function openDetail(id) {
        var g = byId[id]; if (!g) return;
        cur = g; curImg = 0;
        $('mg-d-title').textContent = g.name;
        $('mg-d-sub').textContent = nf(g.cost) + ' points';
        $('mg-d-desc').textContent = g.desc; $('mg-d-desc').hidden = !g.desc;
        $('mg-detail').style.setProperty('--h', Math.abs(hashHue(g.name)) % 360);

        var gal = $('mg-d-gal'), th = $('mg-d-thumbs'); th.textContent = '';
        gal.hidden = !g.images.length; th.hidden = g.images.length < 2;
        if (g.images.length) {
            g.images.forEach(function (im, i) {
                th.appendChild(el('button', { type: 'button', 'aria-label': 'Show picture ' + (i + 1) + ' of ' + g.images.length, onclick: function () { curImg = i; showMain(); } },
                    [el('img', { src: im.url, alt: '', loading: 'lazy' })]));
            });
            showMain();
        }
        var sel = $('mg-d-var'); sel.textContent = '';
        $('mg-d-varwrap').hidden = !g.variants.length;
        if (g.variants.length) {
            sel.appendChild(el('option', { value: '', text: 'Choose…' }));
            g.variants.forEach(function (v) {
                var n = stockOf(g, v);
                var o = el('option', { value: v, text: v + (n < 1 ? ' (out of stock)' : (isFinite(n) && n <= 5 ? ' (only ' + n + ' left)' : '')) });
                if (n < 1) o.disabled = true;
                sel.appendChild(o);
            });
        }
        $('mg-d-qty').value = 1;
        syncDetail();
        $('mg-detail').showModal();
    }
    function hashHue(s) { var h = 0; for (var i = 0; i < s.length; i++) h = (h * 31 + s.charCodeAt(i)) | 0; return h; }

    // ---- cart dialog ----
    function renderCart() {
        var box = $('mg-c-lines'); box.textContent = '';
        if (!cart.length) box.appendChild(el('p', { class: 'fine', text: 'Your cart is empty. Add something you like from the catalogue.' }));
        cart.forEach(function (l) {
            var g = byId[l.id];
            var room = Math.min(stockOf(g, l.v), capOf(g) - (inCart(g.id) - l.q));
            var th = el('span', { class: 'th' }, g.images.length ? [el('img', { src: g.images[0].url, alt: '', loading: 'lazy' })] : [(g.name.charAt(0) || '★').toUpperCase()]);
            var nm = el('span', { class: 'nm' }, [g.name, el('small', { text: (l.v ? l.v + ' · ' : '') + nf(g.cost) + ' points each' + (g.cap !== null ? ' · max ' + g.cap + ' per order' : '') })]);
            var pr = el('span', { class: 'pr', text: nf(g.cost * l.q) });
            var ctl = el('span', { class: 'ctl' }, [
                g.cap === 1 ? el('span', { class: 'fine', text: 'Quantity 1' }) : el('span', { class: 'mg-qty' }, [
                    el('button', Object.assign({ type: 'button', 'aria-label': 'One fewer', onclick: function () { setQty(l, l.q - 1); } }, l.q <= 1 ? { disabled: '' } : {}), ['−']),
                    el('b', { text: String(l.q) }),
                    el('button', Object.assign({ type: 'button', 'aria-label': 'One more', onclick: function () { setQty(l, l.q + 1); } }, l.q >= room ? { disabled: '' } : {}), ['+'])
                ]),
                el('button', { type: 'button', class: 'rm', onclick: function () { setQty(l, 0); } }, ['Remove'])
            ]);
            box.appendChild(el('div', { class: 'mg-line' }, [th, nm, pr, ctl]));
        });
        var t = total(), left = D.balance - t, over = left < 0, sum = $('mg-c-sum'), go = $('mg-c-go');
        sum.className = 'mg-sum' + (over ? ' bad' : '');
        sum.textContent = over ? 'You need ' + nf(-left) + ' more points for this cart.' : nf(t) + ' points · you will have ' + nf(left) + ' left';
        go.disabled = over || !cart.length;
        go.textContent = 'Spend ' + nf(t) + ' points';
        $('mg-c-json').value = JSON.stringify(cart.map(function (l) { return { goody_id: l.id, variant: l.v, qty: l.q }; }));
    }
    function setQty(l, q) {
        if (q < 1) cart = cart.filter(function (x) { return x !== l; }); else l.q = q;
        save(); renderCart();
    }

    // ---- picture viewer ----
    var lb = { list: [], i: 0, zoomed: false };
    function lbReset() {
        lb.zoomed = false;
        var img = $('mg-lb-img'), st = $('mg-lb-stage');
        img.style.width = ''; img.style.maxWidth = ''; img.style.maxHeight = '';
        st.classList.remove('zoomed'); st.scrollLeft = 0; st.scrollTop = 0;
        $('mg-lb-zoom').textContent = 'Zoom';
    }
    function lbShow() {
        var im = lb.list[lb.i];
        lbReset();
        $('mg-lb-img').src = im.url; $('mg-lb-img').alt = im.alt;
        $('mg-lb-cap').textContent = im.alt;
        $('mg-lb-count').textContent = lb.list.length > 1 ? (lb.i + 1) + ' / ' + lb.list.length : '';
        $('mg-lb-prev').hidden = $('mg-lb-next').hidden = lb.list.length < 2;
    }
    function lbZoom(cx, cy) {
        var img = $('mg-lb-img'), st = $('mg-lb-stage');
        if (lb.zoomed) { lbReset(); return; }
        var r = img.getBoundingClientRect();
        var fx = r.width ? (cx - r.left) / r.width : .5, fy = r.height ? (cy - r.top) / r.height : .5;
        fx = Math.min(1, Math.max(0, fx)); fy = Math.min(1, Math.max(0, fy));
        lb.zoomed = true; st.classList.add('zoomed');
        img.style.maxWidth = 'none'; img.style.maxHeight = 'none'; img.style.width = Math.round(st.clientWidth * 2.5) + 'px';
        st.scrollLeft = fx * img.offsetWidth - st.clientWidth / 2;
        st.scrollTop = fy * img.offsetHeight - st.clientHeight / 2;
        $('mg-lb-zoom').textContent = 'Zoom out';
    }
    function openLb(list, i) { if (!list.length) return; lb.list = list; lb.i = i || 0; lbShow(); $('mg-lb').showModal(); }
    function lbStep(d) { lb.i = (lb.i + d + lb.list.length) % lb.list.length; lbShow(); }

    // ---- wiring ----
    document.querySelectorAll('[data-detail]').forEach(function (b) { b.addEventListener('click', function () { openDetail(+b.dataset.detail); }); });
    document.querySelectorAll('[data-zoom]').forEach(function (b) { b.addEventListener('click', function () { var g = byId[+b.dataset.zoom]; if (g) openLb(g.images, 0); }); });
    document.querySelectorAll('dialog.mg-dlg').forEach(function (d) {
        d.addEventListener('click', function (e) { if (e.target === d) d.close(); });
        [].slice.call(d.querySelectorAll('[data-close]')).forEach(function (c) { c.addEventListener('click', function () { d.close(); }); });
    });
    $('mg-d-main').addEventListener('click', function () { if (cur) openLb(cur.images, curImg); });
    $('mg-d-var').addEventListener('change', syncDetail);
    $('mg-d-qty').addEventListener('input', syncDetail);
    $('mg-d-minus').addEventListener('click', function () { $('mg-d-qty').value = (+$('mg-d-qty').value || 1) - 1; syncDetail(); });
    $('mg-d-plus').addEventListener('click', function () { $('mg-d-qty').value = (+$('mg-d-qty').value || 1) + 1; syncDetail(); });
    $('mg-d-add').addEventListener('click', function () {
        var g = cur, v = g.variants.length ? $('mg-d-var').value : '';
        var q = Math.min(Math.max(1, parseInt($('mg-d-qty').value, 10) || 1), maxAdd(g, v));
        if (q < 1) return;
        var line = cart.filter(function (l) { return l.id === g.id && l.v === v; })[0];
        if (line) line.q += q; else cart.push({ id: g.id, v: v, q: q });
        save(); $('mg-detail').close();
    });
    $('mg-cartbar-open').addEventListener('click', function () { renderCart(); $('mg-cart').showModal(); });
    $('mg-c-form').addEventListener('submit', function (e) {
        if (!cart.length || total() > D.balance) { e.preventDefault(); return; }
        setTimeout(function () { $('mg-c-go').disabled = true; }, 0);    // one click, one order
    });
    $('mg-lb-close').addEventListener('click', function () { $('mg-lb').close(); });
    $('mg-lb-zoom').addEventListener('click', function () { var s = $('mg-lb-stage').getBoundingClientRect(); lbZoom(s.left + s.width / 2, s.top + s.height / 2); });
    $('mg-lb-stage').addEventListener('click', function (e) { lbZoom(e.clientX, e.clientY); });
    $('mg-lb-prev').addEventListener('click', function () { lbStep(-1); });
    $('mg-lb-next').addEventListener('click', function () { lbStep(1); });
    document.addEventListener('keydown', function (e) {
        if (!$('mg-lb').open) return;
        if (e.key === 'ArrowLeft' && lb.list.length > 1) lbStep(-1);
        else if (e.key === 'ArrowRight' && lb.list.length > 1) lbStep(1);
    });

    // ---- start ----
    if (D.clear) store.clear();
    cart = store.get().map(function (l) { return { id: +l.id, v: String(l.v || ''), q: +l.q }; });
    var before = JSON.stringify(cart);
    cart = clean();
    if (JSON.stringify(cart) !== before) { store.set(cart); if (before !== '[]') $('mg-cart-note').hidden = false; }
    drawBar();
})();
</script>
<?php cert_rows_js(); /* copy buttons */ ?>
<?php engage_footer(); ?>
