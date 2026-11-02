<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/engage_page.php';
require_once __DIR__ . '/../../includes/member_session.php';
require_once __DIR__ . '/../../includes/cert_rows.php';
require_once __DIR__ . '/../../models/MemberStrikeModel.php';
require_once __DIR__ . '/../../models/PointsModel.php';

/**
 * DCW Engage - My goodies (/member/goodies).
 * Signed-in members only. A member sees only their own points, history and orders: everything is
 * read with the Member ID from the session, never from the request.
 *
 * Points never expire. Any signed-in member can redeem, whatever their membership status, except a
 * member who is struck off (MemberStrikeModel). Change $canRedeem below if the rule is decided differently.
 * Redeeming asks for shipping details, which are kept only until the order is finished and are then
 * cleared by PointsModel::scrubShipping().
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
    .mg-bal { margin: 0 0 24px; padding: 22px; background: var(--card); border: 1px solid var(--border); border-radius: 16px; box-shadow: 0 6px 18px rgba(15,23,42,.06); }
    .mg-bal b { display: block; font-size: 40px; line-height: 1.1; font-variant-numeric: tabular-nums; color: var(--primary-dark); }
    .mg-bal span { color: var(--muted); font-size: 14px; }
    .mg-grid { display: grid; gap: 16px; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); }
    .mg-card { padding: 16px; background: var(--card); border: 1px solid var(--border); border-radius: 14px; }
    .mg-card h3 { margin: 0 0 4px; font-size: 17px; }
    .mg-card p { margin: 0 0 10px; color: var(--muted); font-size: 14px; }
    .mg-cost { font-weight: 700; font-variant-numeric: tabular-nums; }
    .mg-card details { margin-top: 10px; }
    .mg-card summary { cursor: pointer; font-weight: 600; color: var(--primary); }
    .mg-card label { display: block; margin: 8px 0 0; font-size: 13px; font-weight: 600; }
    .mg-card input[type=text], .mg-card input[type=tel], .mg-card select, .mg-card textarea { width: 100%; box-sizing: border-box; padding: 8px 10px; font: inherit; border: 1px solid var(--border); border-radius: 8px; }
    .mg-card button { margin-top: 12px; padding: 8px 16px; font: inherit; font-weight: 600; border: 0; border-radius: 999px; background: var(--primary); color: #fff; cursor: pointer; }
    .mg-card .need { color: #b45309; font-size: 13.5px; font-weight: 600; }
    .mg-hist { width: 100%; border-collapse: collapse; font-size: 14px; }
    .mg-hist td { padding: 9px 4px; border-top: 1px solid var(--border); vertical-align: top; }
    .mg-hist td.n { text-align: right; font-weight: 700; font-variant-numeric: tabular-nums; white-space: nowrap; }
    .mg-hist .pos { color: #0f766e; } .mg-hist .neg { color: #97161b; }
    .mg-hist .d { color: var(--muted); white-space: nowrap; }
    .mg-empty { padding: 20px; text-align: center; color: var(--muted); background: var(--card); border: 1px dashed #c3d0dc; border-radius: 14px; }
</style>

<div class="dash">

    <?php if ($flash): ?>
        <div class="alert <?= $flash[0] === 'ok' ? 'ok' : 'error' ?>"><?= htmlspecialchars((string) $flash[1]) ?></div>
    <?php endif; ?>
    <?php if ($setupError): ?>
        <div class="alert error">Goodies are not available just now. Please check back soon.</div>
    <?php endif; ?>

    <section class="mg-bal" aria-label="Points balance">
        <b><?= number_format($balance) ?></b>
        <span>points · Member ID <?= htmlspecialchars($memberId) ?></span>
    </section>

    <section class="panel" id="catalogue">
        <div class="panel-head">
            <h2>Goodies you can redeem</h2>
            <p>Points have no cash value and cannot be transferred. The catalogue and point costs may change; points you have earned do not expire.</p>
        </div>
        <?php if (!$goodies): ?>
            <div class="mg-empty">No goodies are available right now. Your points are safe; check back soon.</div>
        <?php else: ?>
            <div class="mg-grid">
                <?php foreach ($goodies as $g): $cost = (int) $g['points_cost']; $can = $canRedeem && $balance >= $cost; ?>
                    <article class="mg-card">
                        <h3><?= htmlspecialchars($g['name']) ?></h3>
                        <p><?= htmlspecialchars((string) $g['description']) ?></p>
                        <div class="mg-cost"><?= number_format($cost) ?> points</div>
                        <?php if (!$can): ?>
                            <p class="need" style="margin-top:8px"><?= !$canRedeem ? 'Redeeming is not available on your account. Please contact DCW Support.' : 'You need ' . number_format(max(0, $cost - $balance)) . ' more points.' ?></p>
                        <?php else: ?>
                            <details>
                                <summary>Redeem</summary>
                                <form method="POST" action="/member/goodies">
                                    <?= CSRF::getInputField() ?>
                                    <input type="hidden" name="action" value="redeem">
                                    <input type="hidden" name="goody_id" value="<?= (int) $g['id'] ?>">
                                    <?php if ($g['variants']): ?>
                                        <label>Size or option
                                            <select name="variant" required>
                                                <option value="">Choose…</option>
                                                <?php foreach ($g['variants'] as $v): ?><option value="<?= htmlspecialchars($v) ?>"><?= htmlspecialchars($v) ?></option><?php endforeach; ?>
                                            </select>
                                        </label>
                                    <?php endif; ?>
                                    <label>Name for delivery<input type="text" name="ship_name" required maxlength="120" value="<?= htmlspecialchars((string) ($member['full_name'] ?? '')) ?>" autocomplete="name"></label>
                                    <label>Phone<input type="tel" name="ship_phone" required maxlength="20" autocomplete="tel"></label>
                                    <label>Delivery address<textarea name="ship_address" required maxlength="400" rows="3" autocomplete="street-address"></textarea></label>
                                    <p style="margin:8px 0 0;font-size:12.5px">Your address is used only to send this order and is cleared after delivery.</p>
                                    <button type="submit">Spend <?= number_format($cost) ?> points</button>
                                </form>
                            </details>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <div class="d-two">
        <section class="panel" id="history">
            <div class="panel-head"><h2>Points history</h2><p>Every award and redemption, newest first.</p></div>
            <?php if (!$history): ?>
                <div class="mg-empty">No points yet. They are awarded for attending sessions, mentoring and contributing.</div>
            <?php else: ?>
                <table class="mg-hist"><tbody>
                    <?php foreach ($history as $h): $p = (int) $h['points']; ?>
                        <tr>
                            <td class="d"><?= htmlspecialchars($when($h['created_at'])) ?></td>
                            <td><?= htmlspecialchars((string) $h['reason']) ?></td>
                            <td class="n <?= $p >= 0 ? 'pos' : 'neg' ?>"><?= $p >= 0 ? '+' : '−' ?><?= number_format(abs($p)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody></table>
            <?php endif; ?>
        </section>

        <section class="panel" id="orders">
            <div class="panel-head"><h2>My orders</h2><p>Goodies you have redeemed.</p></div>
            <?php if (!$orders): ?>
                <div class="mg-empty">No orders yet.</div>
            <?php else: ?>
                <div class="reqs">
                    <?php foreach ($orders as $o): ?>
                        <div class="req" style="--tone: #106b9a;">
                            <span class="tick"></span>
                            <div>
                                <h3><?= htmlspecialchars($o['goody_name']) ?><?= $o['variant'] ? ' (' . htmlspecialchars((string) $o['variant']) . ')' : '' ?></h3>
                                <p class="meta"><?= number_format((int) $o['points_spent']) ?> points · <?= htmlspecialchars($when($o['created_at'])) ?> · <code><?= htmlspecialchars((string) $o['tracking_id']) ?></code><button type="button" class="copy" data-copy="<?= htmlspecialchars((string) $o['tracking_id']) ?>">Copy</button></p>
                            </div>
                            <div class="side"><span class="pill"><?= htmlspecialchars((string) $o['status']) ?></span></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>

</div>

<?php cert_rows_js(); /* copy buttons */ ?>
<?php engage_footer(); ?>
