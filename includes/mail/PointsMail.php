<?php
// includes/mail/PointsMail.php
// Emails for Goodies (member points): decisions on claims, and the life of an order.
//
//   claimUpdate        a claim was approved, not approved, or a reviewer asks for more information
//   orderPlaced        receipt for a redemption (tracking ID, what was ordered; never the address)
//   orderDispatched    the order is on its way: courier + consignment (AWB) number + optional tracking link,
//                      or "handed over by hand". Never contains the delivery address.
//   orderCancelled     the order was cancelled and the points were refunded
//
// None of them repeats what a reviewer wrote to the member (the reason or the question): the member reads that
// signed in, behind the button. Called through the Mailer facade (includes/mailer.php).
require_once __DIR__ . '/CoreMail.php';

class PointsMail {
    private static function goodsLine($goodyName, $variant) {
        return (string) $goodyName . ((string) $variant !== '' ? ' (' . $variant . ')' : '');
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
            $line = "Good news: your claim <strong>" . $e($trackingId) . "</strong> for points has been approved. The points have been credited to your balance. "
                  . "A large award may initially appear as <em>waiting for approval</em> until a second reviewer confirms it.";
            $plain = "Good news: your claim $trackingId for points has been approved. The points have been credited to your balance. A large award may initially appear as waiting for approval until a second reviewer confirms it.";
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

    /** ($email, $memberName, $trackingId, $goodyName, $variant, $points) */
    public static function orderPlaced($email, $memberName, $trackingId, $goodyName, $variant, $points) {
        $e = fn($s) => CoreMail::e($s);
        $what = self::goodsLine($goodyName, $variant);
        $url = CoreMail::appUrl() . '/member/goodies#orders';

        $inner = '<p>' . CoreMail::greeting($memberName) . '</p>'
               . "<p>Thank you. We have received your order for <strong>" . $e($what) . "</strong> (" . number_format((int) $points) . " points).</p>"
               . "<p><strong>Order ID:</strong> " . $e($trackingId) . "</p>"
               . "<p>We will email you once it is dispatched. You can track its status at any time under <em>My orders</em>.</p>"
               . CoreMail::button($url, 'View my orders');
        $alt = CoreMail::greeting($memberName, false) . "\n\nThank you. We have received your order for $what (" . number_format((int) $points) . " points).\nOrder ID: $trackingId\n\nWe will email you once it is dispatched. You can track it under My orders:\n$url\n\nDeoband Community Wikimedia";

        return CoreMail::send([
            'to' => $email, 'to_name' => CoreMail::cleanName($memberName),
            'subject' => "We have received your order - $trackingId",
            'html' => $inner, 'alt' => $alt,
            'dev_result' => true, 'dev_log' => "Order receipt for $email ($trackingId, $what)",
        ]);
    }

    /**
     * ($email, $memberName, $trackingId, $goodyName, $variant, $method, $courier, $awb, $trackUrl, $handoverNote)
     * $method is 'courier' or 'hand'. Never contains the address.
     * dev_result is false on purpose: the admin page treats false as "not sent" and offers "Send email again".
     */
    public static function orderDispatched($email, $memberName, $trackingId, $goodyName, $variant, $method, $courier, $awb, $trackUrl, $handoverNote) {
        $e = fn($s) => CoreMail::e($s);
        $what = self::goodsLine($goodyName, $variant);
        $page = CoreMail::appUrl() . '/member/goodies#orders';

        if ($method === 'hand') {
            $box = "<div style='background:#f1f7fb; border-left:4px solid #106b9a; padding:14px 18px; margin:0 0 20px; line-height:1.7;'>"
                 . "<strong>Delivery:</strong> by hand<br><strong>Details:</strong> " . $e($handoverNote) . "</div>";
            $boxPlain = "Delivery: by hand\nDetails: $handoverNote\n";
            $intro = "Your order for <strong>" . $e($what) . "</strong> is being handed over to you.";
            $introPlain = "Your order for $what is being handed over to you.";
            $subject = "Your order is being handed over - $trackingId";
        } else {
            $link = trim((string) $trackUrl) !== ''
                ? "<p><strong>Track your parcel:</strong> <a href='" . $e($trackUrl) . "' style='color:#106b9a; word-break:break-all;'>" . $e($trackUrl) . "</a></p>" : '';
            $box = "<div style='background:#f1f7fb; border-left:4px solid #106b9a; padding:14px 18px; margin:0 0 20px; line-height:1.7;'>"
                 . "<strong>Courier:</strong> " . $e($courier) . "<br>"
                 . "<strong>Consignment (AWB) number:</strong> <span style='font-family:Menlo,Consolas,monospace;'>" . $e($awb) . "</span></div>" . $link;
            $boxPlain = "Courier: $courier\nConsignment (AWB) number: $awb\n" . (trim((string) $trackUrl) !== '' ? "Track your parcel: $trackUrl\n" : '');
            $intro = "Your order for <strong>" . $e($what) . "</strong> has been dispatched to the address you provided.";
            $introPlain = "Your order for $what has been dispatched to the address you provided.";
            $subject = "Your order has been dispatched - $trackingId";
        }

        $inner = '<p>' . CoreMail::greeting($memberName) . "</p><p>$intro</p>$box"
               . "<p style='font-size:14px; color:#64748b;'>Your order ID is " . $e($trackingId) . ($method === 'hand' ? '.' : ". Please quote the consignment number above when contacting the courier; the order ID is a separate number.") . "</p>"
               . CoreMail::button($page, 'View my orders');
        $alt = CoreMail::greeting($memberName, false) . "\n\n$introPlain\n\n$boxPlain\nYour order ID is $trackingId.\nMy orders: $page\n\nDeoband Community Wikimedia";

        return CoreMail::send([
            'to' => $email, 'to_name' => CoreMail::cleanName($memberName),
            'subject' => $subject, 'html' => $inner, 'alt' => $alt,
            'dev_result' => false,
            'dev_log' => "Order dispatch email for $email ($trackingId): " . ($method === 'hand' ? "by hand, $handoverNote" : "$courier $awb"),
        ]);
    }

    /** ($email, $memberName, $trackingId, $goodyName, $variant, $points) */
    public static function orderCancelled($email, $memberName, $trackingId, $goodyName, $variant, $points) {
        $e = fn($s) => CoreMail::e($s);
        $what = self::goodsLine($goodyName, $variant);
        $url = CoreMail::appUrl() . '/member/goodies';

        $inner = '<p>' . CoreMail::greeting($memberName) . '</p>'
               . "<p>Your order <strong>" . $e($trackingId) . "</strong> for <strong>" . $e($what) . "</strong> has been cancelled, and <strong>" . number_format((int) $points) . " points</strong> have been credited back to your balance.</p>"
               . "<p style='margin-bottom:0;'>If you have any queries, please write to DCW Support from your dashboard.</p>"
               . CoreMail::button($url, 'View my points');
        $alt = CoreMail::greeting($memberName, false) . "\n\nYour order $trackingId for $what has been cancelled, and " . number_format((int) $points) . " points have been credited back to your balance.\n\nIf you have any queries, please write to DCW Support from your dashboard.\n$url\n\nDeoband Community Wikimedia";

        return CoreMail::send([
            'to' => $email, 'to_name' => CoreMail::cleanName($memberName),
            'subject' => "Your order has been cancelled - $trackingId",
            'html' => $inner, 'alt' => $alt,
            'dev_result' => true,
            'dev_log' => "Order cancelled email for $email ($trackingId)",
        ]);
    }
}
