<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/require_role.php';
require_once __DIR__ . '/../../includes/reimbursement_receipt_template.php';
require_once __DIR__ . '/../../models/ReimbursementModel.php';

// Same boundary as the rest of finance: this document contains payment
// details (masked account number, UPI ID, transaction reference), so it
// gets the same access as reimbursement_queue.php, not the wider
// owner/organizer review access.
requireRole(['finance', 'owner']);

// $requestId comes from the router — see the
// /finance/reimbursements/receipt/{id} route in index.php.
global $reimbursementReceiptId;
$requestId = (int) $reimbursementReceiptId;

$reimbursementModel = new ReimbursementModel();
$data = $reimbursementModel->getPaidRequestForReceipt($requestId);

if (!$data) {
    http_response_code(404);
    die("No paid reimbursement found with that ID. A confirmation can only be generated once a request has actually been marked Paid.");
}

require_once __DIR__ . '/../../vendor/autoload.php';

try {
    $mpdf = new \Mpdf\Mpdf([
        'format' => 'A4',
        'margin_top' => 20, 'margin_bottom' => 15,
        'margin_left' => 20, 'margin_right' => 20,
    ]);
    $mpdf->WriteHTML(reimbursement_receipt_html($data));

    $filename = 'DCW-Reimbursement-' . $data['tracking_id'] . '.pdf';
    // 'D' forces a download rather than an inline preview — this is meant
    // to be saved as a record, not just glanced at in the browser. The PDF
    // is generated entirely in memory and streamed here; nothing about it
    // is ever written to disk on the server.
    $mpdf->Output($filename, \Mpdf\Output\Destination::DOWNLOAD);
} catch (\Throwable $e) {
    require_once __DIR__ . '/../../includes/app_log.php';
    app_log("Reimbursement receipt PDF generation failed for request #$requestId: " . $e->getMessage());
    http_response_code(500);
    die("Something went wrong generating the confirmation PDF. This has been logged.");
}
