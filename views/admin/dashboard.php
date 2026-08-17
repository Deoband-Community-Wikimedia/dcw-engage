<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../models/FormModel.php';

Auth::requireLogin();

$formModel = new FormModel();
$forms = $formModel->getAllForms();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/png" href="https://dcwwiki.org/dcwwiki/images/5/56/DCW_logo.png">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Organizer Workspace - DCW Engage</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
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
        
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px;}
        h1 { margin: 0; color: var(--primary-color); font-size: 28px;}
        
        .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 24px; }
        
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
    </style>
</head>
<body>
    <div class="container">
        <div class="header" style="display: flex; align-items: center; justify-content: space-between; gap: 16px;">
            <h1>Workspace</h1>

            <div style="display: flex; align-items: center; gap: 12px; font-size: 14px; color: #64748b;">
                <span><?= htmlspecialchars(Auth::email()) ?></span>
                <?php if (Auth::isOwner()): ?>
                    <a href="/admin/team" style="color: #64748b; text-decoration: none; border: 1px solid #e2e8f0; padding: 6px 12px; border-radius: 6px; font-size: 13px;">Team</a>
                <?php endif; ?>
                <form method="POST" action="/admin/logout" style="margin: 0;">
                    <?= CSRF::getInputField() ?>
                    <button type="submit" style="background: none; border: 1px solid #e2e8f0; color: #64748b; padding: 6px 12px; border-radius: 6px; font-family: inherit; font-size: 13px; cursor: pointer;">Sign Out</button>
                </form>
            </div>
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
    </div>
</body>
</html>
