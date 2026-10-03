<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/require_role.php';
require_once __DIR__ . '/../../includes/app_log.php';
require_once __DIR__ . '/../../models/MemberModel.php';

requireRole('owner');   // who may see which chapter is an owner decision

$model = new MemberModel();
$msg = ''; $err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CSRF::validate($_POST['csrf_token'] ?? '')) die('Invalid CSRF token.');
    try {
        $email = (string) ($_POST['email'] ?? '');
        $model->setChapters($email, (array) ($_POST['chapters'] ?? []));
        app_log('Membership chapters for ' . $email . ' changed by ' . currentAdminIdentifier());
        $msg = 'Saved chapters for ' . $email . '.';
    } catch (Throwable $e) { $err = $e->getMessage(); }
}
$h = fn($v) => htmlspecialchars((string) $v);
$names = ['generic' => 'DCW Generic Community', 'amu' => 'Wiki Club AMU', 'jamia' => 'Wiki Club Jamia', 'photographers' => 'DCW Photographers Club'];
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php require __DIR__ . '/../../includes/favicon.php'; ?>
<title>Membership access - DCW Engage</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
<style>body{font-family:Inter,sans-serif;background:#f8fafc;color:#1e293b;margin:0;padding:32px}.wrap{max-width:720px;margin:auto}
h1{color:#106b9a}a{color:#106b9a}.box{background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:16px;margin:12px 0}
label{display:block;margin:4px 0}button{padding:8px 14px;border:0;border-radius:6px;background:#106b9a;color:#fff;font:600 14px Inter,sans-serif;cursor:pointer}
.ok{background:#ecfdf5;border:1px solid #6ee7b7;padding:10px 14px;border-radius:6px}.bad{background:#fef2f2;border:1px solid #f87171;padding:10px 14px;border-radius:6px}</style></head>
<body><div class="wrap"><p><a href="/admin/dashboard">&larr; Workspace</a></p>
<h1>Membership access</h1>
<p>Chapter coordinators see only the chapters ticked here. Accounts with the role <code>membership_reviewer</code>
(DCW Generic Reviewers), organizers and owners see every chapter. Create coordinator accounts from <a href="/admin/team">Team</a>.</p>
<?php if ($msg): ?><div class="ok"><?= $h($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="bad"><?= $h($err) ?></div><?php endif; ?>
<?php foreach ($model->coordinators() as $email): $have = $model->chaptersFor($email); ?>
  <form method="POST" class="box"><?= CSRF::getInputField() ?>
    <input type="hidden" name="email" value="<?= $h($email) ?>"><strong><?= $h($email) ?></strong>
    <?php foreach ($names as $k => $n): ?>
      <label><input type="checkbox" name="chapters[]" value="<?= $h($k) ?>" <?= in_array($k, $have, true) ? 'checked' : '' ?>> <?= $h($n) ?></label>
    <?php endforeach; ?>
    <?php if (!$have): ?><p style="color:#b45309;margin:6px 0">No chapters yet: this person sees nothing.</p><?php endif; ?>
    <button>Save</button>
  </form>
<?php endforeach; ?>
<?php if (!$model->coordinators()): ?><p>No accounts have the role <code>membership_coordinator</code> yet.</p><?php endif; ?>
</div></body></html>
