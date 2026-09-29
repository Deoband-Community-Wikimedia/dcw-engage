<?php
/**
 * DCW Engage - Reimbursement payment confirmation (PDF content)
 *
 * Returns the styled HTML that reimbursement_receipt.php hands to mPDF.
 * Kept as plain HTML/CSS (not raw PDF drawing calls) so it can reuse this
 * app's existing look — same header colour, same font — and so a future
 * change to the layout is a normal HTML edit, not repositioning cells by
 * coordinate.
 *
 * The PDF itself is generated on demand and streamed straight to the
 * browser (see reimbursement_receipt.php's Output(..., DOWNLOAD)) — nothing
 * about the finished document ever touches disk. The logo below is fetched
 * fresh into memory on every call for the same reason: no cache file, no
 * server-side footprint, at the cost of one small outbound request per PDF.
 */

/**
 * Fetches the DCW logo into memory as a base64 data URI. Never throws — a
 * missing or unreachable logo shouldn't block a finance officer from
 * getting their payment confirmation, so this returns null on any failure
 * and the caller falls back to a text wordmark instead.
 */
function reimbursement_receipt_logo_data_uri(): ?string {
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
 * $data is the array returned by ReimbursementModel::getPaidRequestForReceipt().
 */
function reimbursement_receipt_html(array $data): string {
    $logo = reimbursement_receipt_logo_data_uri();
    $logoHtml = $logo
        ? "<img src='" . htmlspecialchars($logo) . "' style='height:48px;'>"
        : "<span style='font-size:20px; font-weight:700; color:#106b9a;'>DCW Engage</span>";

    $rupees = function (int $paise): string {
        return 'Rs. ' . number_format($paise / 100, 2);
    };

    $method = $data['payment_method'] === 'upi' ? 'UPI' : 'Bank Transfer';

    $paymentDetailsHtml = $data['payment_method'] === 'upi'
        ? "<tr><td class='label'>UPI ID</td><td>" . htmlspecialchars($data['upi_id']) . "</td></tr>"
        : "<tr><td class='label'>Account Holder</td><td>" . htmlspecialchars($data['bank_account_name']) . "</td></tr>"
          . "<tr><td class='label'>Account Number</td><td>" . htmlspecialchars($data['bank_account_number_masked'] ?? '—') . "</td></tr>"
          . "<tr><td class='label'>IFSC</td><td>" . htmlspecialchars($data['bank_ifsc']) . "</td></tr>";

    $eventDate = !empty($data['event_date']) ? date('j F Y', strtotime($data['event_date'])) : '—';
    $decidedAt = !empty($data['decided_at']) ? date('j F Y, H:i', strtotime($data['decided_at'])) : '—';
    $paidAt = !empty($data['paid_at']) ? date('j F Y, H:i', strtotime($data['paid_at'])) : '—';
    $generatedAt = date('j F Y, H:i');

    return "
    <html>
    <head>
        <style>
            body { font-family: sans-serif; color: #1e293b; font-size: 12px; }
            .header { border-bottom: 3px solid #106b9a; padding-bottom: 12px; margin-bottom: 20px; }
            .header table { width: 100%; }
            .title { font-size: 16px; font-weight: 700; color: #106b9a; text-align: right; }
            .subtitle { font-size: 10px; color: #64748b; text-align: right; }
            .amount-box { background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 6px; padding: 14px 18px; margin-bottom: 18px; text-align: center; }
            .amount-box .amount { font-size: 22px; font-weight: 700; color: #166534; }
            .amount-box .status { font-size: 11px; color: #166534; text-transform: uppercase; letter-spacing: 0.06em; }
            h2 { font-size: 12px; color: #106b9a; border-bottom: 1px solid #e2e8f0; padding-bottom: 4px; margin: 18px 0 8px; }
            table.details { width: 100%; border-collapse: collapse; margin-bottom: 4px; }
            table.details td { padding: 4px 0; vertical-align: top; }
            table.details td.label { width: 160px; color: #64748b; }
            .footer { margin-top: 30px; padding-top: 10px; border-top: 1px solid #e2e8f0; font-size: 9px; color: #94a3b8; }
        </style>
    </head>
    <body>
        <div class='header'>
            <table>
                <tr>
                    <td style='width:50%;'>$logoHtml</td>
                    <td style='width:50%;'>
                        <div class='title'>Payment Confirmation</div>
                        <div class='subtitle'>Reimbursement Request " . htmlspecialchars($data['tracking_id']) . "</div>
                    </td>
                </tr>
            </table>
        </div>

        <div class='amount-box'>
            <div class='status'>Paid</div>
            <div class='amount'>" . $rupees((int) $data['total_amount_paise']) . "</div>
        </div>

        <h2>Applicant</h2>
        <table class='details'>
            <tr><td class='label'>Name</td><td>" . htmlspecialchars($data['applicant_name']) . "</td></tr>
            <tr><td class='label'>Email</td><td>" . htmlspecialchars($data['email']) . "</td></tr>
            <tr><td class='label'>Event</td><td>" . htmlspecialchars($data['event_name']) . "</td></tr>
            <tr><td class='label'>Event Date</td><td>" . htmlspecialchars($eventDate) . "</td></tr>
        </table>

        <h2>Payment</h2>
        <table class='details'>
            <tr><td class='label'>Method</td><td>" . htmlspecialchars($method) . "</td></tr>
            $paymentDetailsHtml
            <tr><td class='label'>Transaction Reference</td><td>" . htmlspecialchars($data['payment_reference'] ?: '—') . "</td></tr>
            <tr><td class='label'>Paid By</td><td>" . htmlspecialchars($data['paid_by']) . "</td></tr>
            <tr><td class='label'>Paid On</td><td>" . htmlspecialchars($paidAt) . "</td></tr>
        </table>

        <h2>Review</h2>
        <table class='details'>
            <tr><td class='label'>Approved By</td><td>" . htmlspecialchars($data['decided_by']) . "</td></tr>
            <tr><td class='label'>Approved On</td><td>" . htmlspecialchars($decidedAt) . "</td></tr>
        </table>

        <div class='footer'>
            Deoband Community Wikimedia — DCW Engage<br>
            This is a computer-generated payment confirmation, produced " . htmlspecialchars($generatedAt) . ".
            It does not itemise individual expenses.
        </div>
    </body>
    </html>
    ";
}
