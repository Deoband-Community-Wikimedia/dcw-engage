<?php
/**
 * DCW Engage - Internet support receipt (PDF content)
 *
 * Returns the styled HTML that views/finance/internet_receipt.php hands to
 * mPDF. Same look as the reimbursement payment confirmation: same header
 * colour, same font, plain HTML/CSS so a layout change is a normal edit.
 *
 * Generated on demand and streamed to the browser; nothing about the
 * finished document is written to disk. The logo is fetched into memory on
 * every call for the same reason.
 *
 * The amount shown is the amount the request was APPROVED for. The actual
 * price the operator charged is not recorded separately; what finance
 * records at recharge time is the operator's reference.
 */

/**
 * Fetches the DCW logo into memory as a base64 data URI. Never throws: a
 * missing or unreachable logo must not stop finance getting the receipt, so
 * this returns null on any failure and the caller falls back to text.
 */
function internet_receipt_logo_data_uri(): ?string {
    $config = require __DIR__ . '/config.php';
    $logoUrl = $config['app']['logo_url']
        ?? 'https://dcwwiki.org/dcwwiki/images/5/56/DCW_logo.png';

    $context = stream_context_create(['http' => ['timeout' => 5], 'https' => ['timeout' => 5]]);
    $bytes = @file_get_contents($logoUrl, false, $context);

    if ($bytes === false || $bytes === '') {
        return null;
    }

    return 'data:image/png;base64,' . base64_encode($bytes);
}

/**
 * Formats a database timestamp (stored in UTC) for display in India time.
 * Returns an em dash for empty input so callers need no empty-check.
 */
function internet_receipt_format_ist(?string $utcDatetime, string $format): string {
    if (empty($utcDatetime)) {
        return '—';
    }

    $dt = new DateTimeImmutable($utcDatetime, new DateTimeZone('UTC'));
    return $dt->setTimezone(new DateTimeZone('Asia/Kolkata'))->format($format);
}

/**
 * $data is the array returned by InternetSupportModel::getClosedRequestForReceipt().
 */
function internet_receipt_html(array $data): string {
    $e = function ($value): string {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    };

    $logo = internet_receipt_logo_data_uri();
    $logoHtml = $logo
        ? "<img src='" . $e($logo) . "' style='height:80px;'>"
        : "<span style='font-size:26px; font-weight:700; color:#106b9a;'>DCW Engage</span>";

    $rupees = function (int $paise): string {
        return 'Rs. ' . number_format($paise / 100, 2);
    };

    $validity = !empty($data['package_validity_days']) ? (int) $data['package_validity_days'] . ' days' : '—';
    $phone = !empty($data['phone_masked']) ? $data['phone_masked'] : '—';

    $requestedAt = internet_receipt_format_ist($data['created_at'] ?? null, 'j F Y, H:i') . ' IST';
    $approvedAt  = internet_receipt_format_ist($data['decided_at'] ?? null, 'j F Y, H:i') . ' IST';
    $rechargedAt = internet_receipt_format_ist($data['recharged_at'] ?? null, 'j F Y, H:i') . ' IST';
    $closedAt    = internet_receipt_format_ist($data['closed_at'] ?? null, 'j F Y, H:i') . ' IST';
    // "Generated" is the current moment, built directly in IST.
    $generatedAt = (new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata')))->format('j F Y, H:i') . ' IST';

    return "
    <html>
    <head>
        <style>
            body { font-family: sans-serif; color: #1e293b; font-size: 12px; }
            .header { border-bottom: 3px solid #106b9a; padding-bottom: 16px; margin-bottom: 24px; text-align: center; }
            .header .logo-wrap { margin-bottom: 10px; }
            .title { font-size: 20px; font-weight: 700; color: #106b9a; text-align: center; margin: 0 0 6px; letter-spacing: 0.01em; }
            .subtitle { font-size: 13px; font-weight: 600; color: #475569; text-align: center; }
            .amount-box { background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 6px; padding: 14px 18px; margin-bottom: 18px; text-align: center; }
            .amount-box .amount { font-size: 22px; font-weight: 700; color: #166534; }
            .amount-box .status { font-size: 11px; color: #166534; text-transform: uppercase; letter-spacing: 0.06em; }
            h2 { font-size: 12px; color: #106b9a; border-bottom: 1px solid #e2e8f0; padding-bottom: 4px; margin: 18px 0 8px; }
            table.details { width: 100%; border-collapse: collapse; margin-bottom: 4px; }
            table.details td { padding: 4px 0; vertical-align: top; }
            table.details td.label { width: 160px; color: #64748b; }
            .footer { margin-top: 30px; padding-top: 14px; border-top: 1px solid #e2e8f0; font-size: 13px; line-height: 1.7; color: #64748b; }
        </style>
    </head>
    <body>
        <div class='header'>
            <div class='logo-wrap'>$logoHtml</div>
            <div class='title'>DCW Internet Support Receipt</div>
            <div class='subtitle'>Request ID: " . $e($data['tracking_id']) . "</div>
        </div>

        <div class='amount-box'>
            <div class='status'>Recharge completed</div>
            <div class='amount'>" . $rupees((int) $data['package_price_paise']) . "</div>
        </div>

        <h2>Requester</h2>
        <table class='details'>
            <tr><td class='label'>Name</td><td>" . $e($data['applicant_name']) . "</td></tr>
            <tr><td class='label'>Email</td><td>" . $e($data['email']) . "</td></tr>
            <tr><td class='label'>Mobile Number</td><td>" . $e($phone) . "</td></tr>
            <tr><td class='label'>Requested On</td><td>" . $e($requestedAt) . "</td></tr>
        </table>

        <h2>Recharge</h2>
        <table class='details'>
            <tr><td class='label'>Operator</td><td>" . $e($data['operator']) . "</td></tr>
            <tr><td class='label'>Plan</td><td>" . $e($data['package_name']) . "</td></tr>
            <tr><td class='label'>Validity</td><td>" . $e($validity) . "</td></tr>
            <tr><td class='label'>Approved Amount</td><td>" . $rupees((int) $data['package_price_paise']) . "</td></tr>
            <tr><td class='label'>Operator Reference</td><td>" . $e($data['recharge_reference'] ?: '—') . "</td></tr>
            <tr><td class='label'>Recharged By</td><td>" . $e($data['recharged_by'] ?: '—') . "</td></tr>
            <tr><td class='label'>Recharged On</td><td>" . $e($rechargedAt) . "</td></tr>
        </table>

        <h2>Review</h2>
        <table class='details'>
            <tr><td class='label'>Approved By</td><td>" . $e($data['decided_by'] ?: '—') . "</td></tr>
            <tr><td class='label'>Approved On</td><td>" . $e($approvedAt) . "</td></tr>
        </table>

        <h2>Closure</h2>
        <table class='details'>
            <tr><td class='label'>Closed By</td><td>" . $e($data['closed_by'] ?: '—') . "</td></tr>
            <tr><td class='label'>Closed On</td><td>" . $e($closedAt) . "</td></tr>
        </table>

        <div class='footer'>
            Deoband Community Wikimedia — DCW Engage<br>
            This receipt was generated on the DCW Engage portal on " . $e($generatedAt) . ".
        </div>
    </body>
    </html>
    ";
}
