<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../models/FormModel.php';

Auth::requireLogin();

$formModel = new FormModel();
$forms = $formModel->getAllForms();

// Each flag mirrors the exact requireRole() call on the page it links to, so
// a link only ever appears for someone who can actually get past its gate:
//   reimbursement_review.php -> requireRole(['owner', 'organizer'])
//   internet_review.php      -> requireRole(['support_reviewer', 'owner'])
//   finance/queue.php        -> requireRole(['finance', 'owner'])   (combined: reimbursements + internet support)
//   finance/closed.php       -> requireRole(['finance', 'owner'])
$canReviewReimbursements = in_array(Auth::role(), ['owner', 'organizer'], true);
$canReviewInternet       = in_array(Auth::role(), ['support_reviewer', 'owner'], true);
$canProcessFinance       = in_array(Auth::role(), ['finance', 'owner'], true);

$canReviewAny  = $canReviewReimbursements || $canReviewInternet;
$canSeeSupport = $canReviewAny || $canProcessFinance;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <?php require __DIR__ . '/../../includes/favicon.php'; ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Organizer Workspace - DCW Engage</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #106b9a;
            --background: #f8fafc;
            --card-bg: #ffffff;
            --text-color: #1e293b;
            --border-color: #e2e8f0;
        }
        body { font-family: 'Inter', sans-serif; background: var(--background); padding: 40px; color: var(--text-color); margin: 0;}
        .container { max-width: 1200px; margin: auto; }

        .header { display: flex; justify-content: space-between; align-items: center; gap: 16px; margin-bottom: 30px; }
        h1 { margin: 0; color: var(--primary-color); font-size: 28px;}

        .toolbar { display: flex; align-items: center; justify-content: flex-end; flex-wrap: wrap; gap: 12px; font-size: 14px; color: #64748b; }
        .toolbar form { margin: 0; }
        .btn-outline {
            background: none; color: #64748b; text-decoration: none;
            border: 1px solid var(--border-color); padding: 6px 12px; border-radius: 6px;
            font-family: inherit; font-size: 13px; cursor: pointer;
        }
        .btn-outline:hover { border-color: #cbd5e1; color: var(--text-color); }

        :root { --heading-font: 'Plus Jakarta Sans', 'Inter', sans-serif; }
        h1, .card-title { font-family: var(--heading-font); }

        /* Cards keep a fixed max width and sit in the middle of the page. */
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 340px)); justify-content: center; gap: 24px; }

        .card {
            background: var(--card-bg);
            border-radius: 12px;
            border: 1px solid var(--border-color);
            padding: 24px;
            transition: all 0.2s ease;
            text-decoration: none;
            color: inherit;
            display: flex;
            flex-direction: column;
            position: relative;
        }
        .card:hover { transform: translateY(-4px); box-shadow: 0 10px 15px -3px rgba(0,0,0,0.05); border-color: #cbd5e1;}

        .card-new {
            border: 2px dashed #cbd5e1;
            background: transparent;
            align-items: center;
            justify-content: center;
            color: var(--primary-color);
        }
        .card-new:hover { border-color: var(--primary-color); background: rgba(16, 107, 154, 0.02);}

        .card-title { font-size: 18px; font-weight: 600; margin: 0 0 10px 0; line-height: 1.3;}
        .card-meta { font-size: 13px; color: #64748b; margin: 0 0 15px 0;}

        .status-dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; margin-right: 6px;}
        .status-active { background: #10b981; }
        .status-closed { background: #ef4444; }

        .card-footer { margin-top: auto; border-top: 1px solid var(--border-color); padding-top: 15px; display: flex; justify-content: space-between; font-size: 13px; color: #64748b; font-weight: 500;}

        .section-head { text-align: center; margin: 56px auto 28px; max-width: 640px; }
        .section-title {
            font-family: var(--heading-font);
            font-size: 28px; font-weight: 800; letter-spacing: -0.02em; line-height: 1.2;
            color: var(--text-color); margin: 0 0 10px;
        }
        .section-title::after {
            content: ''; display: block; width: 44px; height: 3px; border-radius: 2px;
            background: var(--primary-color); margin: 12px auto 0;
        }
        .section-intro { font-size: 15px; line-height: 1.6; color: #64748b; margin: 0; }
        .group-label {
            font-family: var(--heading-font);
            text-align: center; font-size: 15px; font-weight: 700; letter-spacing: 0.02em;
            color: var(--primary-color); margin: 32px 0 16px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Workspace</h1>

            <div class="toolbar">
                <span><?= htmlspecialchars(Auth::email()) ?></span>
                <?php if (Auth::isOwner()): ?>
                    <a href="/admin/team" class="btn-outline">Team</a>
                    <a href="/admin/audit" class="btn-outline">Audit Log</a>
                <?php endif; ?>
                <form method="POST" action="/admin/logout">
                    <?= CSRF::getInputField() ?>
                    <button type="submit" class="btn-outline">Sign Out</button>
                </form>
            </div>
        </div>

        <div class="section-head" style="margin-top: 16px;">
            <h2 class="section-title">Application forms</h2>
            <p class="section-intro">Create, open, and close the forms volunteers apply through, and review their responses.</p>
        </div>
        <div class="grid">
            <!-- Create New Form Card -->
            <a href="/admin/builder" class="card card-new">
                <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-bottom: 10px;"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                <span style="font-weight: 600; font-size: 16px;">Create Blank Form</span>
            </a>

            <!-- Existing Forms -->
            <?php foreach ($forms as $form): ?>
                <a href="/admin/form_manager?id=<?= $form['id'] ?>" class="card">
                    <h3 class="card-title"><?= htmlspecialchars($form['title']) ?></h3>
                    <p class="card-meta">Slug: /<?= htmlspecialchars($form['form_type']) ?></p>

                    <div class="card-footer">
                        <div>
                            <?php if ($form['is_active']): ?>
                                <span class="status-dot status-active"></span>Active
                            <?php else: ?>
                                <span class="status-dot status-closed"></span>Closed
                            <?php endif; ?>
                        </div>
                        <div><?= $form['applicant_count'] ?> Response<?= $form['applicant_count'] !== 1 ? 's' : '' ?></div>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>

        <?php if ($canSeeSupport): ?>
            <div class="section-head">
                <h2 class="section-title">Volunteer Support Ecosystem</h2>
                <p class="section-intro">
                    Reimbursements and internet support through review to payment.
                </p>
            </div>

            <?php if ($canReviewAny): ?>
                <div class="group-label" style="margin-top: 0;">Review</div>
                <div class="grid">
                    <?php if ($canReviewReimbursements): ?>
                        <a href="/admin/reimbursements/review" class="card">
                            <h3 class="card-title">Reimbursement Review</h3>
                            <p class="card-meta">Approve or reject claims — line items and receipts, no payment details.</p>
                        </a>
                    <?php endif; ?>
                    <?php if ($canReviewInternet): ?>
                        <a href="/admin/internet-review" class="card">
                            <h3 class="card-title">Internet Support Review</h3>
                            <p class="card-meta">Decide whether volunteer requests are reasonable — reasons and packages, no phone numbers.</p>
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($canProcessFinance): ?>
                <div class="group-label">Finance</div>
                <div class="grid">
                    <a href="/finance" class="card">
                        <h3 class="card-title">Finance Queue</h3>
                        <p class="card-meta">Pay approved reimbursements, recharge approved numbers, and check uploaded receipts — one queue, a tab for each.</p>
                    </a>
                    <a href="/finance/closed" class="card">
                        <h3 class="card-title">Closed Requests</h3>
                        <p class="card-meta">Paid reimbursements and closed internet support requests, with a receipt PDF for each.</p>
                    </a>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</body>
</html>
