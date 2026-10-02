<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/require_role.php';
require_once __DIR__ . '/../../models/ReimbursementModel.php';
require_once __DIR__ . '/../../models/InternetSupportModel.php';

// Same boundary as the queue itself: owners and finance, not organizers.
requireRole(['finance', 'owner']);

$tab = $_GET['tab'] ?? ($financeDefaultTab ?? 'reimbursement');
if (!in_array($tab, ['reimbursement', 'internet'], true)) {
    $tab = 'reimbursement';
}

$paid   = (new ReimbursementModel())->listPaidForFinance();
$closed = (new InternetSupportModel())->listClosedForFinance();

$tabStyle = function ($active) {
    return 'padding:8px 16px; border-radius:6px 6px 0 0; text-decoration:none; font-weight:600; font-size:14px; '
        . ($active ? 'background:#106b9a; color:#fff;' : 'background:#e2e8f0; color:#334155;');
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Closed Finance Requests</title>
    <link rel="stylesheet" href="/assets/css/forms.css?v=2">
</head>
<body>
    <div class="container">
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
            <h1 style="margin:0;">Closed Requests</h1>
            <a href="/finance?tab=<?= htmlspecialchars($tab) ?>" style="color:#106b9a; font-size:14px; font-weight:600; text-decoration:none;">&larr; Back to finance queue</a>
        </div>
        <p style="color:#64748b; font-size:14px;">
            Click a row's link to generate its PDF on demand — nothing is stored here, each PDF is built fresh when you ask for it.
        </p>

        <div style="display:flex; gap:6px; margin:20px 0 16px; border-bottom:2px solid #106b9a; flex-wrap:wrap;">
            <a href="/finance/closed?tab=reimbursement" style="<?= $tabStyle($tab === 'reimbursement') ?>">Reimbursements paid (<?= count($paid) ?>)</a>
            <a href="/finance/closed?tab=internet" style="<?= $tabStyle($tab === 'internet') ?>">Internet support closed (<?= count($closed) ?>)</a>
        </div>

<?php if ($tab === 'reimbursement'): ?>
        <?php if (empty($paid)): ?>
            <p>No requests have been paid yet.</p>
        <?php else: ?>
            <div style="overflow-x:auto;">
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
                                   style="color:#106b9a; font-weight:600; text-decoration:none;">⬇ PDF</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        <?php endif; ?>

<?php else: ?>
        <?php if (empty($closed)): ?>
            <p>No internet support requests have been closed yet.</p>
        <?php else: ?>
            <div style="overflow-x:auto;">
            <table style="width:100%; border-collapse:collapse; font-size:14px;">
                <thead>
                    <tr style="text-align:left; border-bottom:2px solid #106b9a;">
                        <th style="padding:8px 6px;">Applicant</th>
                        <th style="padding:8px 6px;">Operator / Pack</th>
                        <th style="padding:8px 6px; text-align:right;">Amount</th>
                        <th style="padding:8px 6px;">Recharge Ref</th>
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
                            <td style="padding:8px 6px;">
                                <?= htmlspecialchars($req['operator']) ?><br>
                                <span style="color:#64748b; font-size:12px;"><?= htmlspecialchars($req['package_name']) ?></span>
                            </td>
                            <td style="padding:8px 6px; text-align:right;">₹<?= number_format($req['package_price_paise'] / 100, 2) ?></td>
                            <td style="padding:8px 6px;"><?= htmlspecialchars((string) $req['recharge_reference']) ?></td>
                            <td style="padding:8px 6px;"><?= htmlspecialchars(date('j M Y', strtotime($req['closed_at']))) ?></td>
                            <td style="padding:8px 6px;"><?= htmlspecialchars((string) $req['closed_by']) ?></td>
                            <td style="padding:8px 6px;">
                                <a href="/finance/internet-support/receipt/<?= (int) $req['id'] ?>" target="_blank"
                                   style="color:#106b9a; font-weight:600; text-decoration:none;">⬇ PDF</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        <?php endif; ?>
<?php endif; ?>
    </div>
</body>
</html>
