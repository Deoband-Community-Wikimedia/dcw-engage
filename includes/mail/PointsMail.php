<?php
// includes/mail/PointsMail.php
// Emails for Goodies (member points): decisions on claims, and the life of an order.
//
//   claimUpdate        a claim was approved, not approved, or a reviewer asks for more information
//   orderPlaced        receipt for an order: tracking ID and the goodies in it (never the address)
//   orderDispatched    the order is on its way: courier + consignment (AWB) number + optional tracking link,
//                      or "handed over in person". Lists the units that are really being sent. Never the address.
//   orderRefunded      part of an order will not be sent: those units were refunded, the rest is still coming
//   orderCancelled     the whole order was cancelled and the points were refunded
//
// A "line" is ['name' => goody name, 'variant' => size or '', 'qty' => units].
// None of them repeats what a reviewer wrote to the member (the reason or the question): the member reads that
// signed in, behind the button. Called through the Mailer facade (includes/mailer.php).
require_once __DIR__ . '/CoreMail.php';

class PointsMail {
    /** "2 × T-shirt (M)" */
    private static function linePlain(array $l) {
        $q = max(1, (int) ($l['qty'] ?? 1));
        $v = trim((string) ($l['variant'] ?? ''));
        return $q . ' × ' . (string) ($l['name'] ?? '') . ($v !== '' ? ' (' . $v . ')' : '');
    }

    private static function linesHtml(array $lines) {
        $li = '';
        foreach ($lines as $l) $li .= '<li>' . CoreMail::e(self::linePlain($l)) . '</li>';
        return "<ul style='margin:0 0 16px; padding-left:20px; line-height:1.7;'>$li</ul>";
    }

    private static function linesPlain(array $lines) {
        return implode("\n", array_map(fn($l) => '  - ' . self::linePlain($l), $lines));
    }

    /**
     * ($email, $memberName, $trackingId, $kind) with $kind = approved | rejected | info.
     * dev_result is true: the claim is already saved whether or not the email goes out.
     */
    public static function claimUpdate($email, $memberName, $trackingId, $kind) {
        $e = fn($s) => CoreMail::e($s);
        $url = CoreMail::appUrl() . '/member/goodies?claim=' . rawurlencode((string) $trackingId);

        if ($kind === 'approved') {
            $subject = "Your points claim has been approved - $trackingId";
            $line = "Good news: your claim <strong>" . $e($trackingId) . "</strong> for points has been approved and credited to your balance. "
                  . "A large award may initially appear as <em>waiting for approval</em> until a second reviewer confirms it.";
            $plain = "Good news: your claim $trackingId for points has been approved and credited to your balance. A large award may initially appear as waiting for approval until a second reviewer confirms it.";
            $button = 'View my points';
            $url = CoreMail::appUrl() . '/member/goodies';
        } elseif ($kind === 'rejected') {
            $subject = "Update regarding your points claim - $trackingId";
            $line = "Your claim <strong>" . $e($trackingId) . "</strong> for points has not been approved. You can view the reviewer's remarks on your claim.";
            $plain = "Your claim $trackingId for points has not been approved. You can view the reviewer's remarks on your claim.";
            $button = 'Read the reason';
        } else {
            $subject = "A reviewer requires further information regarding your claim - $trackingId";
            $line = "A reviewer requires a little more information before arriving at a decision on your claim <strong>" . $e($trackingId) . "</strong>. Kindly open it, review their message, and reply.";
            $plain = "A reviewer requires a little more information before arriving at a decision on your claim $trackingId. Kindly open it, review their message, and reply.";
            $button = 'Read and reply';
        }

        $inner = '<p>' . CoreMail::greeting($memberName) . '</p><p>' . $line . '</p>'
               . CoreMail::button($url, $button)
               . "<p style='margin-bottom:0;'>You will need to sign in to view the details.</p>";
        $alt = CoreMail::greeting($memberName, false) . "\n\n$plain\n\n$url\n\nYou will need to sign in to view the details.\n\nDeoband Community Wikimedia";

        return CoreMail::send([
            'to' => $email, 'to_name' => CoreMail::cleanName($memberName),
            'subject' => $subject, 'html' => $inner, 'alt' => $alt,
            'dev_result' => true, 'dev_log' => "Points claim $kind email for $email ($trackingId): $url",
        ]);
    }

    /** ($email, $memberName, $trackingId, array $lines, $points) */
    public static function orderPlaced($email, $memberName, $trackingId, $lines, $points) {
        $e = fn($s) => CoreMail::e($s);
        $url = CoreMail::appUrl() . '/member/goodies#orders';

        $inner = '<p>' . CoreMail::greeting($memberName) . '</p>'
               . "<p>Thank you. We have received your order (" . number_format((int) $points) . " points):</p>"
               . self::linesHtml($lines)
               . "<p><strong>Order ID:</strong> " . $e($trackingId) . "</p>"
               . "<p>We will email you once it is dispatched. You can track its status at any time under <em>My orders</em>.</p>"
               . CoreMail::button($url, 'View my orders');
        $alt = CoreMail::greeting($memberName, false) . "\n\nThank you. We have received your order (" . number_format((int) $points) . " points):\n"
             . self::linesPlain($lines) . "\n\nOrder ID: $trackingId\n\nWe will email you once it is dispatched. You can track it under My orders:\n$url\n\nDeoband Community Wikimedia";

        return CoreMail::send([
            'to' => $email, 'to_name' => CoreMail::cleanName($memberName),
            'subject' => "We have received your order - $trackingId",
            'html' => $inner, 'alt' => $alt,
            'dev_result' => true, 'dev_log' => "Order receipt for $email ($trackingId)",
        ]);
    }

    /**
     * ($email, $memberName, $trackingId, array $lines, $method, $courier, $awb, $trackUrl, $handoverNote)
     * $lines are the units that are really being sent. $method is 'courier' or 'hand'. Never contains the address.
     * dev_result is false on purpose: the admin page treats false as "not sent" and offers "Send email again".
     */
    public static function orderDispatched($email, $memberName, $trackingId, $lines, $method, $courier, $awb, $trackUrl, $handoverNote) {
        $e = fn($s) => CoreMail::e($s);
        $page = CoreMail::appUrl() . '/member/goodies#orders';

        if ($method === 'hand') {
            $box = "<div style='background:#f1f7fb; border-left:4px solid #106b9a; padding:14px 18px; margin:0 0 20px; line-height:1.7;'>"
                 . "<strong>Delivery:</strong> In person<br><strong>Details:</strong> " . $e($handoverNote) . "</div>";
            $boxPlain = "Delivery: In person\nDetails: $handoverNote\n";
            $intro = "Your order is ready to be handed over to you. It contains:";
            $introPlain = "Your order is ready to be handed over to you. It contains:";
            $subject = "Your order is ready for handover - $trackingId";
        } else {
            $link = trim((string) $trackUrl) !== ''
                ? "<p><strong>Track your parcel:</strong> <a href='" . $e($trackUrl) . "' style='color:#106b9a; word-break:break-all;'>" . $e($trackUrl) . "</a></p>" : '';
            $box = "<div style='background:#f1f7fb; border-left:4px solid #106b9a; padding:14px 18px; margin:0 0 20px; line-height:1.7;'>"
                 . "<strong>Courier:</strong> " . $e($courier) . "<br>"
                 . "<strong>Consignment (AWB) number:</strong> <span style='font-family:Menlo,Consolas,monospace;'>" . $e($awb) . "</span></div>" . $link;
            $boxPlain = "Courier: $courier\nConsignment (AWB) number: $awb\n" . (trim((string) $trackUrl) !== '' ? "Track your parcel: $trackUrl\n" : '');
            $intro = "Your order has been dispatched to your provided address. It contains:";
            $introPlain = "Your order has been dispatched to your provided address. It contains:";
            $subject = "Your order has been dispatched - $trackingId";
        }

        $inner = '<p>' . CoreMail::greeting($memberName) . "</p><p>$intro</p>" . self::linesHtml($lines) . $box
               . "<p style='font-size:14px; color:#64748b;'>Your order ID is " . $e($trackingId) . ($method === 'hand' ? '.' : ". Please quote the consignment number above when contacting the courier; the order ID is a separate number.") . "</p>"
               . CoreMail::button($page, 'View my orders');
        $alt = CoreMail::greeting($memberName, false) . "\n\n$introPlain\n" . self::linesPlain($lines) . "\n\n$boxPlain\nYour order ID is $trackingId.\nMy orders: $page\n\nDeoband Community Wikimedia";

        return CoreMail::send([
            'to' => $email, 'to_name' => CoreMail::cleanName($memberName),
            'subject' => $subject, 'html' => $inner, 'alt' => $alt,
            'dev_result' => false,
            'dev_log' => "Order dispatch email for $email ($trackingId): " . ($method === 'hand' ? "by hand, $handoverNote" : "$courier $awb"),
        ]);
    }

    /** ($email, $memberName, $trackingId, array $lines, $points) $lines = the refunded units, $points = what was credited. */
    public static function orderRefunded($email, $memberName, $trackingId, $lines, $points) {
        $e = fn($s) => CoreMail::e($s);
        $url = CoreMail::appUrl() . '/member/goodies#orders';

        $inner = '<p>' . CoreMail::greeting($memberName) . '</p>'
               . "<p>We are unable to fulfill part of your order <strong>" . $e($trackingId) . "</strong>:</p>"
               . self::linesHtml($lines)
               . "<p><strong>" . number_format((int) $points) . " points</strong> have been credited back to your balance. The rest of your order will still be sent.</p>"
               . "<p style='margin-bottom:0;'>You can check the details under <em>My orders</em>.</p>"
               . CoreMail::button($url, 'View my orders');
        $alt = CoreMail::greeting($memberName, false) . "\n\nWe are unable to fulfill part of your order $trackingId:\n" . self::linesPlain($lines)
             . "\n\n" . number_format((int) $points) . " points have been credited back to your balance. The rest of your order will still be sent.\n\nDetails under My orders:\n$url\n\nDeoband Community Wikimedia";

        return CoreMail::send([
            'to' => $email, 'to_name' => CoreMail::cleanName($memberName),
            'subject' => "Part of your order has been refunded - $trackingId",
            'html' => $inner, 'alt' => $alt,
            'dev_result' => true, 'dev_log' => "Order refund email for $email ($trackingId)",
        ]);
    }

    /** ($email, $memberName, $trackingId, array $lines, $points) $points = everything credited back for this order. */
    public static function orderCancelled($email, $memberName, $trackingId, $lines, $points) {
        $e = fn($s) => CoreMail::e($s);
        $url = CoreMail::appUrl() . '/member/goodies';

        $inner = '<p>' . CoreMail::greeting($memberName) . '</p>'
               . "<p>Your order <strong>" . $e($trackingId) . "</strong> has been cancelled, and <strong>" . number_format((int) $points) . " points</strong> have been credited back to your balance. It contained:</p>"
               . self::linesHtml($lines)
               . "<p style='margin-bottom:0;'>If you have any queries, please write to DCW Support from your dashboard.</p>"
               . CoreMail::button($url, 'View my points');
        $alt = CoreMail::greeting($memberName, false) . "\n\nYour order $trackingId has been cancelled, and " . number_format((int) $points) . " points have been credited back to your balance. It contained:\n"
             . self::linesPlain($lines) . "\n\nIf you have any queries, please write to DCW Support from your dashboard.\n$url\n\nDeoband Community Wikimedia";

        return CoreMail::send([
            'to' => $email, 'to_name' => CoreMail::cleanName($memberName),
            'subject' => "Your order has been cancelled - $trackingId",
            'html' => $inner, 'alt' => $alt,
            'dev_result' => true,
            'dev_log' => "Order cancelled email for $email ($trackingId)",
        ]);
    }
}
