<?php
// includes/goodies_orders_admin.php
// The Orders tab of /admin/goodies: moving an order along, and recording HOW it is sent.
//
// "Mark shipped" now opens a small form instead of being a single button:
//   - Courier:  the courier, the consignment (AWB) number and, if you have one, the courier's tracking link
//   - By hand:  who hands it over, and where or when (for example at an event)
// The member is emailed either way (never the address) and sees the same details on My orders.
// If that email does not go out, the order shows "Send email again".
//
// Needs goodies_flash() and gd_h() from goodies.php, and PointsModel (setRedemptionStatus, resendDispatchEmail).

/** Handle order_status and order_resend. */
function goodies_orders_post(string $do, PointsModel $model, string $actor): void
{
    $id = (int) ($_POST['id'] ?? 0);

    if ($do === 'order_resend') {
        $r = $model->resendDispatchEmail($id, $actor);
        goodies_flash($r['ok'] ? 'ok' : 'error', $r['ok'] ? 'Email sent to the member.' : $r['error']);
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

/** The buttons for one order: next statuses, with the dispatch form for "Shipped". */
function goodies_order_actions(array $o): void
{
    static $styled = false;
    if (!$styled) {
        $styled = true; ?>
        <style>
            .od-ship > summary { display: inline-block; padding: 9px 18px; border: 1px solid var(--primary); border-radius: 999px; background: #fff; color: var(--primary); font-weight: 600; font-size: 14px; cursor: pointer; list-style: none; }
            .od-ship > summary::-webkit-details-marker { display: none; }
            .od-ship[open] > summary { background: var(--primary); color: #fff; }
            .od-ship form { display: grid; gap: 10px; min-width: min(380px, 80vw); margin: 10px 0 0; padding: 14px; background: #F3F8FC; border: 1.5px solid var(--border); border-radius: 12px; text-align: left; }
            .od-ship label { display: block; font-size: 13px; font-weight: 600; }
            .od-ship input[type=text], .od-ship input[type=url] { width: 100%; box-sizing: border-box; margin-top: 4px; padding: 8px 11px; font: inherit; font-weight: 400; border: 1.5px solid #BCCFDD; border-radius: 10px; background: #fff; }
            .od-ship .od-how { display: flex; gap: 8px; }
            .od-ship .od-how label { display: inline-flex; gap: 6px; align-items: center; padding: 8px 14px; border: 1.5px solid var(--border); border-radius: 999px; background: #fff; cursor: pointer; }
            .od-ship .od-how label:has(input:checked) { background: #e8f3f9; border-color: var(--primary); color: #0b4f73; }
            .od-ship small { display: block; margin-top: 3px; color: var(--muted); font-weight: 400; font-size: 12px; }
        </style>
        <script>
        document.addEventListener('change', function (e) {
            if (e.target.name !== 'method') return;
            var f = e.target.form, hand = e.target.value === 'hand';
            f.querySelector('.od-courier').hidden = hand;
            f.querySelector('.od-hand').hidden = !hand;
            f.querySelector('.od-courier input[name=courier]').required = !hand;
            f.querySelector('.od-courier input[name=awb]').required = !hand;
            f.querySelector('.od-hand input[name=handover_note]').required = hand;
        });
        </script>
        <datalist id="od-couriers">
            <option value="India Post"><option value="Speed Post"><option value="DTDC"><option value="Delhivery">
            <option value="Blue Dart"><option value="Ecom Express"><option value="XpressBees"><option value="Shadowfax">
        </datalist>
    <?php }

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
                    <div><button type="submit" class="gd-btn">Mark shipped and email the member</button></div>
                </form>
            </details>
        <?php else: ?>
            <form method="POST" action="/admin/goodies?tab=redemptions"><?= CSRF::getInputField() ?>
                <input type="hidden" name="do" value="order_status"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="to" value="<?= gd_h($next) ?>">
                <button type="submit" class="gd-btn <?= $next === 'Cancelled' ? 'red' : 'line' ?>"<?= $next === 'Cancelled' ? ' onclick="return confirm(\'Cancel this order and refund the points?\')"' : '' ?>><?= $next === 'Cancelled' ? 'Cancel and refund' : 'Mark ' . gd_h(strtolower($next)) ?></button>
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
