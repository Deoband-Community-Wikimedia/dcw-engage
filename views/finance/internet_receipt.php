<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/require_role.php';
require_once __DIR__ . '/../../includes/internet_receipt_template.php';
require_once __DIR__ . '/../../models/InternetSupportModel.php';

// Same boundary as the rest of internet support finance: this document
// contains a masked mobile number and the operator reference, so it gets the
// same access as the recharge queue (owners and finance), not the wider
// review access.
requireRole(['finance', 'owner']);

// $requestId comes from the router — see the
// /finance/internet-support/receipt/{id} route in index.php.
global $internetReceiptId;
$requestId = (int) $internetReceiptId;

$internetModel = new InternetSupportModel();
$data = $internetModel->getClosedRequestForReceipt($requestId);

if (!$data) {
    http_response_code(404);
    die("No closed internet support request found with that ID. A receipt can only be generated once a request has been closed.");
}

require_once __DIR__ . '/../../vendor/autoload.php';

try {
    $mpdf = new \Mpdf\Mpdf([
        'format' => 'A4',
        'margin_top' => 20, 'margin_bottom' => 15,
        'margin_left' => 20, 'margin_right' => 20,
    ]);
    $mpdf->SetTitle('DCW Internet Support Receipt ' . $data['tracking_id']);
    $mpdf->WriteHTML(internet_receipt_html($data));

    $filename = 'DCW-InternetSupport-' . preg_replace('/[^A-Za-z0-9_-]/', '_', $data['tracking_id']) . '.pdf';
    // 'D' forces a download rather than an inline preview — this is meant to
    // be saved as a record. The PDF is generated entirely in memory and
    // streamed here; nothing about it is ever written to disk.
    $mpdf->Output($filename, \Mpdf\Output\Destination::DOWNLOAD);
} catch (\Throwable $e) {
    require_once __DIR__ . '/../../includes/app_log.php';
    app_log("Internet support receipt PDF generation failed for request #$requestId: " . $e->getMessage());
    http_response_code(500);
    die("Something went wrong generating the receipt PDF. This has been logged.");
}
