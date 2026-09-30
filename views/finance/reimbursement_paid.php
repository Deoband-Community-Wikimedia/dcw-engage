<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/require_role.php';
require_once __DIR__ . '/../../models/ReimbursementModel.php';

// Same boundary as the queue itself: owners and finance, not organizers.
requireRole(['finance', 'owner']);

$reimbursementModel = new ReimbursementModel();
$paid = $reimbursementModel->listPaidForFinance();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Closed Reimbursement Requests</title>
    <link rel="stylesheet" href="/assets/css/forms.css?v=2">
</head>
<body>
    <div class="container">
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
            <h1 style="margin:0;">Closed Reimbursement Requests</h1>
            <a href="/finance/reimbursements" style="color:#106b9a; font-size:14px; font-weight:600; text-decoration:none;">&larr; Back to payment queue</a>
        </div>
        <p style="color:#64748b; font-size:14px;">
            Every request that's been paid. Click a row's link to generate its payment confirmation PDF on demand — nothing is stored here, each PDF is built fresh when you ask for it.
        </p>

        <?php if (empty($paid)): ?>
            <p>No requests have been paid yet.</p>
        <?php else: ?>
            <table style="width:100%; border-collapse:collapse; font-size:14px;">
                <thead>
                    <tr style="text-align:left; border-bottom:2px solid #106b9a;">
                        <th style="padding:8px 6px;">Applicant</th>
                        <th style="padding:8px 6px;">Event</th>
                        <th style="padding:8px 6px; text-align:right;">Amount</th>
                        <th style="padding:8px 6px;">Date Processed</th>
                        <th style="padding:8px 6px;">Paid By</th>
                        <th style="padding:8px 6px;">Receipt</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($paid as $req): ?>
                        <tr style="border-bottom:1px solid #e2e8f0;">
                            <td style="padding:8px 6px;">
                                <?= htmlspecialchars($req['applicant_name']) ?><br>
                                <span style="color:#94a3b8; font-size:12px;">#<?= htmlspecialchars($req['tracking_id']) ?></span>
                            </td>
                            <td style="padding:8px 6px;"><?= htmlspecialchars($req['event_name']) ?></td>
                            <td style="padding:8px 6px; text-align:right;">₹<?= number_format($req['total_amount_paise'] / 100, 2) ?></td>
                            <td style="padding:8px 6px;"><?= htmlspecialchars(date('j M Y', strtotime($req['paid_at']))) ?></td>
                            <td style="padding:8px 6px;"><?= htmlspecialchars($req['paid_by']) ?></td>
                            <td style="padding:8px 6px;">
                                <a href="/finance/reimbursements/receipt/<?= (int) $req['id'] ?>" target="_blank"
                                   style="color:#106b9a; font-weight:600; text-decoration:none;">
                                    ⬇ PDF
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</body>
</html>
