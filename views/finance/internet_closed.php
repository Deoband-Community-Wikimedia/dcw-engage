<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/require_role.php';
require_once __DIR__ . '/../../models/InternetSupportModel.php';

// Same boundary as the recharge queue itself: owners and finance only.
requireRole(['finance', 'owner']);

$model = new InternetSupportModel();
$closed = $model->listClosedForFinance();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <?php require __DIR__ . '/../../includes/favicon.php'; ?>
    <title>Closed Internet Support Requests</title>
    <link rel="stylesheet" href="/assets/css/forms.css?v=2">
</head>
<body>
    <div class="container">
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
            <h1 style="margin:0;">Closed Internet Support Requests</h1>
            <a href="/finance/internet-support" style="color:#106b9a; font-size:14px; font-weight:600; text-decoration:none;">&larr; Back to recharge queue</a>
        </div>
        <p style="color:#64748b; font-size:14px;">
            Every request that's been recharged and closed. Click a row's link to generate its receipt PDF on demand. Nothing is stored here, each PDF is built fresh when you ask for it.
        </p>

        <?php if (empty($closed)): ?>
            <p>No requests have been closed yet.</p>
        <?php else: ?>
            <div style="overflow-x:auto;">
                <table style="width:100%; border-collapse:collapse; font-size:14px;">
                    <thead>
                        <tr style="text-align:left; border-bottom:2px solid #106b9a;">
                            <th style="padding:8px 6px;">Name</th>
                            <th style="padding:8px 6px;">Requester</th>
                            <th style="padding:8px 6px; text-align:right;">Amount Requested</th>
                            <th style="padding:8px 6px;">Date Closed</th>
                            <th style="padding:8px 6px;">Closed By</th>
                            <th style="padding:8px 6px;">Receipt</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($closed as $req): ?>
                            <tr style="border-bottom:1px solid #e2e8f0;">
                                <td style="padding:8px 6px;">
                                    <?= htmlspecialchars($req['applicant_name']) ?><br>
                                    <span style="color:#94a3b8; font-size:12px;">#<?= htmlspecialchars($req['tracking_id']) ?></span>
                                </td>
                                <td style="padding:8px 6px;"><?= htmlspecialchars($req['email']) ?></td>
                                <td style="padding:8px 6px; text-align:right;">₹<?= number_format($req['package_price_paise'] / 100, 2) ?></td>
                                <td style="padding:8px 6px;"><?= htmlspecialchars(date('j M Y', strtotime($req['closed_at']))) ?></td>
                                <td style="padding:8px 6px;"><?= htmlspecialchars((string) $req['closed_by']) ?></td>
                                <td style="padding:8px 6px;">
                                    <a href="/finance/internet-support/receipt/<?= (int) $req['id'] ?>" target="_blank"
                                       style="color:#106b9a; font-weight:600; text-decoration:none;">
                                        ⬇ PDF
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
