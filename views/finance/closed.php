<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/require_role.php';
require_once __DIR__ . '/../../includes/engage_page.php';
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

$e = fn($s) => htmlspecialchars((string) $s);

engage_header([
    'title'   => 'Closed requests',
    'heading' => 'Closed requests',
    'kicker'  => 'Finance',
    'lead'    => 'Paid reimbursements and closed internet support requests.',
    'wide'    => true,
    'crumbs'  => [['Home', '/'], ['Finance queue', '/finance?tab=' . $tab], ['Closed requests']],
    'tools'   => '<span class="who">' . $e(Auth::email()) . '</span>'
               . '<a class="chip-btn" href="/finance?tab=' . $e($tab) . '">&larr; Finance queue</a>',
]);
?>
<div class="fcard wide">
    <p class="note">
        Click a row's PDF link to generate it on demand. Nothing is stored here; each PDF is built fresh when you ask for it.
    </p>

    <nav class="tabs" aria-label="Closed request type">
        <a class="tab<?= $tab === 'reimbursement' ? ' on' : '' ?>" href="/finance/closed?tab=reimbursement">Reimbursements paid <span class="count"><?= count($paid) ?></span></a>
        <a class="tab<?= $tab === 'internet' ? ' on' : '' ?>" href="/finance/closed?tab=internet">Internet support closed <span class="count"><?= count($closed) ?></span></a>
    </nav>

<?php if ($tab === 'reimbursement'): ?>
    <?php if (empty($paid)): ?>
        <div class="empty-note">No requests have been paid yet.</div>
    <?php else: ?>
        <div class="tbl-wrap"><table class="tbl">
            <thead><tr>
                <th>Applicant</th><th>Event</th><th class="num">Amount</th><th>Date processed</th><th>Paid by</th><th>Receipt</th>
            </tr></thead>
            <tbody>
            <?php foreach ($paid as $req): ?>
                <tr>
                    <td><?= $e($req['applicant_name']) ?><span class="sub">#<?= $e($req['tracking_id']) ?></span></td>
                    <td><?= $e($req['event_name']) ?></td>
                    <td class="num">₹<?= number_format($req['total_amount_paise'] / 100, 2) ?></td>
                    <td><?= $e(date('j M Y', strtotime($req['paid_at']))) ?></td>
                    <td><?= $e($req['paid_by']) ?></td>
                    <td><a class="dl" href="/finance/reimbursements/receipt/<?= (int) $req['id'] ?>" target="_blank">⬇ PDF</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>

<?php else: ?>
    <?php if (empty($closed)): ?>
        <div class="empty-note">No internet support requests have been closed yet.</div>
    <?php else: ?>
        <div class="tbl-wrap"><table class="tbl">
            <thead><tr>
                <th>Applicant</th><th>Operator / pack</th><th class="num">Amount</th><th>Recharge ref</th><th>Date closed</th><th>Closed by</th><th>Receipt</th>
            </tr></thead>
            <tbody>
            <?php foreach ($closed as $req): ?>
                <tr>
                    <td><?= $e($req['applicant_name']) ?><span class="sub">#<?= $e($req['tracking_id']) ?></span></td>
                    <td><?= $e($req['operator']) ?><span class="sub"><?= $e($req['package_name']) ?></span></td>
                    <td class="num">₹<?= number_format($req['package_price_paise'] / 100, 2) ?></td>
                    <td><?= $e((string) $req['recharge_reference']) ?></td>
                    <td><?= $e(date('j M Y', strtotime($req['closed_at']))) ?></td>
                    <td><?= $e((string) $req['closed_by']) ?></td>
                    <td><a class="dl" href="/finance/internet-support/receipt/<?= (int) $req['id'] ?>" target="_blank">⬇ PDF</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
<?php endif; ?>
</div>
<?php engage_footer(); ?>
