<?php
// includes/goodies_orders_admin.php
// The Orders tab of /admin/goodies: moving an order along, refunding part of it, and recording HOW it is sent.
//
// An order can hold several goodies (a cart). Each line shows what was ordered and what is still to ship.
//   "Refund…" on a line   refunds some or all of that line's units (for example the item is out of stock) and
//                         the rest of the order is shipped as normal. Possible until the order is Shipped.
//                         "Return to stock" puts the units back in stock: leave it OFF when the reason is stock.
//   "Cancel and refund"   refunds every unit still open and cancels the order.
// "Mark shipped" opens a small form:
//   - Courier:  the courier, the consignment (AWB) number and, if you have one, the courier's tracking link
//   - By hand:  who hands it over, and where or when (for example at an event)
// The member is emailed either way (never the address) and sees the same details on My orders.
// If that email does not go out, the order shows "Send email again".
//
// Needs goodies_flash() and gd_h() from goodies.php, and PointsModel (setRedemptionStatus, refundItem, resendDispatchEmail).

/** Handle order_status, order_refund and order_resend. For order_refund the posted "id" is the ITEM id. */
function goodies_orders_post(string $do, PointsModel $model, string $actor): void
{
    $id = (int) ($_POST['id'] ?? 0);

    if ($do === 'order_resend') {
        $r = $model->resendDispatchEmail($id, $actor);
        goodies_flash($r['ok'] ? 'ok' : 'error', $r['ok'] ? 'Email sent to the member.' : $r['error']);
        return;
    }

    if ($do === 'order_refund') {
        $why = (string) ($_POST['why'] ?? '');
        if (!in_array($why, ['Out of stock', 'Member request', 'Other'], true)) $why = 'Other';
        $note = trim((string) ($_POST['note'] ?? ''));
        $reason = $why . ($note !== '' ? ': ' . mb_substr($note, 0, 150) : '');
        $r = $model->refundItem($id, (int) ($_POST['qty'] ?? 0), $reason, !empty($_POST['restock']), $actor);
        if (!$r['ok']) { goodies_flash('error', $r['error']); return; }
        goodies_flash('ok', number_format((int) $r['points']) . ' points refunded to the member.'
            . ($r['cancelled'] ? ' Nothing is left in the order, so it is now cancelled.' : ' The rest of the order can still be shipped.')
            . ($r['email'] ? ' The member has been emailed.' : ''));
        return;
    }

    $to = (string) ($_POST['to'] ?? '');
    $dispatch = [
        'method'        => (string) ($_POST['method'] ?? ''),
        'courier'       => (string) ($_POST['courier'] ?? ''),
        'awb'           => (string) ($_POST['awb'] ?? ''),
        'tracking_url'  => (string) ($_POST['tracking_url'] ?? ''),
        'handover_note' => (string) ($_POST['handover_note'] ?? ''),
    ];
    $r = $model->setRedemptionStatus($id, $to, $actor, $dispatch);
    if (!$r['ok']) { goodies_flash('error', $r['error']); return; }

    $msg = 'Order updated.';
    if ($to === 'Shipped') {
        $msg = $r['email'] ? 'Marked shipped. The member has been emailed.'
                           : 'Marked shipped, but the email to the member could not be sent. Use “Send email again” on the order.';
    } elseif ($to === 'Cancelled') {
        $msg = 'Order cancelled and the points refunded.' . ($r['email'] ? ' The member has been emailed.' : '');
    }
    goodies_flash($r['email'] === false && $to === 'Shipped' ? 'error' : 'ok', $msg);
}

/** The small scripts and the courier list, printed once per page. The styles live in assets/css/engage.css. */
function goodies_orders_assets(): void
{
    static $done = false;
    if ($done) return;
    $done = true; ?>
    <script>
    document.addEventListener('change', function (e) {
        var f = e.target.form;
        if (e.target.name === 'method' && f) {
            var hand = e.target.value === 'hand';
            f.querySelector('.od-courier').hidden = hand;
            f.querySelector('.od-hand').hidden = !hand;
            f.querySelector('.od-courier input[name=courier]').required = !hand;
            f.querySelector('.od-courier input[name=awb]').required = !hand;
            f.querySelector('.od-hand input[name=handover_note]').required = hand;
        }
        // A refund for "Out of stock" must NOT put the units back in stock; any other reason usually should.
        if (e.target.name === 'why' && f) f.querySelector('input[name=restock]').checked = e.target.value !== 'Out of stock';
    });
    </script>
    <datalist id="od-couriers">
        <option value="India Post"><option value="Speed Post"><option value="DTDC"><option value="Delhivery">
        <option value="Blue Dart"><option value="Ecom Express"><option value="XpressBees"><option value="Shadowfax">
    </datalist>
<?php }

/** One line under an order: how it was sent. Prints nothing for an order that has not shipped. */
function goodies_order_info(array $o): void
{
    if (empty($o['delivery_method'])) return;
    $when = !empty($o['dispatched_at'])
        ? (new DateTimeImmutable((string) $o['dispatched_at'], new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Asia/Kolkata'))->format('j M Y, g:i a') : '';
    echo '<p>';
    if ($o['delivery_method'] === 'hand') {
        echo '🤝 <strong>By hand</strong>: ' . gd_h($o['handover_note']);
    } else {
        echo '📦 <strong>' . gd_h($o['courier']) . '</strong> · AWB <code>' . gd_h($o['awb']) . '</code>';
        if (!empty($o['tracking_url'])) echo ' · <a href="' . gd_h($o['tracking_url']) . '" target="_blank" rel="noopener noreferrer">track</a>';
    }
    echo ($when !== '' ? ' · sent ' . gd_h($when) . ' by ' . gd_h((string) $o['dispatched_by']) : '');
    echo empty($o['email_sent_at']) ? ' · <span class="gd-warn">member not emailed yet</span>' : ' · member emailed';
    echo '</p>';
}

/** The lines of an order: what was ordered, what still ships, what was refunded, and a Refund form per line. */
function goodies_order_items(array $o): void
{
    goodies_orders_assets();
    if (empty($o['items'])) return;
    $canRefund = in_array((string) $o['status'], ['Requested', 'Ordered'], true);
    echo '<ul class="od-items">';
    foreach ($o['items'] as $it) {
        $open = (int) $it['qty_open'];
        $label = PointsModel::itemLabel((string) $it['name'], (string) ($it['variant'] ?? ''));
        echo '<li><div class="od-l"><span class="od-n">' . (int) $it['qty'] . ' × ' . gd_h($label) . '</span>';
        if ($canRefund && $open > 0) {
            ?>
            <details class="od-ship">
                <summary>Refund…</summary>
                <form method="POST" action="/admin/goodies?tab=redemptions"><?= CSRF::getInputField() ?>
                    <input type="hidden" name="do" value="order_refund"><input type="hidden" name="id" value="<?= (int) $it['id'] ?>">
                    <label>Units to refund (<?= $open ?> still open)<input type="number" name="qty" min="1" max="<?= $open ?>" value="<?= $open ?>" required></label>
                    <label>Reason
                        <select name="why"><option>Out of stock</option><option>Member request</option><option>Other</option></select>
                    </label>
                    <label>Note <span style="font-weight:400;color:var(--muted)">(optional)</span><input type="text" name="note" maxlength="150"><small>The member reads the reason and note on My orders.</small></label>
                    <label class="gd-tg"><input type="checkbox" name="restock" value="1"> Return to stock <small style="display:inline">(leave off when it is out of stock)</small></label>
                    <div><button type="submit" class="gd-btn red">Refund points</button></div>
                </form>
            </details>
            <?php
        }
        echo '</div><div class="od-s">';
        if ($open > 0) echo '<b>' . $open . ' to ship</b>'; else echo 'Nothing left to ship';
        if ((int) $it['qty_refunded'] > 0) echo ' · ' . (int) $it['qty_refunded'] . ' refunded';
        if ($canRefund && $open > 0 && $it['stock_left'] !== null && (int) $it['stock_left'] < 1) echo ' · <span class="out">Out of stock now</span>';
        foreach ($it['refunds'] as $rf) {
            echo '<br>↩ ' . (int) $rf['qty'] . ' refunded (+' . number_format((int) $rf['points']) . ' pts): ' . gd_h((string) $rf['reason']);
        }
        echo '</div></li>';
    }
    echo '</ul>';
}

/** The buttons for one order: next statuses, with the dispatch form for "Shipped". */
function goodies_order_actions(array $o): void
{
    goodies_orders_assets();
    $id = (int) $o['id'];
    foreach (PointsModel::REDEMPTION_FLOW[$o['status']] as $next):
        if ($next === 'Shipped'): ?>
            <details class="od-ship">
                <summary>Mark shipped…</summary>
                <form method="POST" action="/admin/goodies?tab=redemptions"><?= CSRF::getInputField() ?>
                    <input type="hidden" name="do" value="order_status"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="to" value="Shipped">
                    <div class="od-how" role="radiogroup" aria-label="How is it being sent?">
                        <label><input type="radio" name="method" value="courier" checked> Courier</label>
                        <label><input type="radio" name="method" value="hand"> By hand</label>
                    </div>
                    <div class="od-courier">
                        <label>Courier<input type="text" name="courier" list="od-couriers" maxlength="60" required placeholder="e.g. India Post"></label>
                        <label style="margin-top:10px">Consignment (AWB) number<input type="text" name="awb" maxlength="80" required><small>The courier’s number, not the GD- order ID.</small></label>
                        <label style="margin-top:10px">Tracking link <span style="font-weight:400;color:var(--muted)">(optional)</span><input type="url" name="tracking_url" maxlength="500" placeholder="https://"></label>
                    </div>
                    <div class="od-hand" hidden>
                        <label>Who is handing it over, and where or when<input type="text" name="handover_note" maxlength="255" placeholder="e.g. Given by Asha at the Mehfil, 14 Nov"><small>The member reads this in the email and on My orders.</small></label>
                    </div>
                    <p class="gd-note" style="margin:0">Only the units still marked “to ship” are in the parcel. Refund any item you cannot send <strong>before</strong> marking shipped.</p>
                    <div><button type="submit" class="gd-btn">Mark shipped and email the member</button></div>
                </form>
            </details>
        <?php else: ?>
            <form method="POST" action="/admin/goodies?tab=redemptions"><?= CSRF::getInputField() ?>
                <input type="hidden" name="do" value="order_status"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="to" value="<?= gd_h($next) ?>">
                <button type="submit" class="gd-btn <?= $next === 'Cancelled' ? 'red' : 'line' ?>"<?= $next === 'Cancelled' ? ' onclick="return confirm(\'Cancel the WHOLE order and refund every item still open?\')"' : '' ?>><?= $next === 'Cancelled' ? 'Cancel whole order' : 'Mark ' . gd_h(strtolower($next)) ?></button>
            </form>
        <?php endif;
    endforeach;

    if (!empty($o['delivery_method']) && in_array($o['status'], ['Shipped', 'Delivered'], true) && empty($o['email_sent_at'])): ?>
        <form method="POST" action="/admin/goodies?tab=redemptions"><?= CSRF::getInputField() ?>
            <input type="hidden" name="do" value="order_resend"><input type="hidden" name="id" value="<?= $id ?>">
            <button type="submit" class="gd-btn line">Send email again</button>
        </form>
    <?php endif;
}
