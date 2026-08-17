<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/audit.php';

// The audit log records who can grant and remove access, so reading it is
// itself an owner-level concern.
Auth::requireOwner();

$entries = AuditLog::recent(200);

// Human-readable labels for the stored action keys. An unknown key falls back
// to itself, so a newly added action still shows rather than vanishing.
$labels = [
    'invite.created'   => 'Invitation sent',
    'invite.revoked'   => 'Invitation revoked',
    'invite.accepted'  => 'Invitation accepted',
    'organizer.removed'=> 'Organizer removed',
    'password.reset'   => 'Password reset',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/png" href="https://dcwwiki.org/dcwwiki/images/5/56/DCW_logo.png">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Audit log - DCW Engage</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --primary-color: #106b9a; --border-color: #e2e8f0; }
        * { box-sizing: border-box; }
        body { font-family: 'Inter', -apple-system, sans-serif; background: #f8fafc; color: #1e293b; margin: 0; padding: 40px 20px; }
        .container { max-width: 960px; margin: auto; }
        .header { display: flex; justify-content: space-between; align-items: center; gap: 16px; margin-bottom: 8px; }
        h1 { margin: 0; color: var(--primary-color); font-size: 28px; }
        .lede { color: #64748b; font-size: 14px; margin: 0 0 30px; }
        .back { color: #64748b; font-size: 14px; text-decoration: none; white-space: nowrap; }
        .back:hover { color: var(--primary-color); }

        .panel { background: #fff; border: 1px solid var(--border-color); border-radius: 12px; padding: 8px 24px 12px; }
        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th { text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: 0.06em; color: #64748b; padding: 14px 12px 10px 0; font-weight: 600; white-space: nowrap; }
        td { padding: 12px 12px 12px 0; border-top: 1px solid var(--border-color); vertical-align: top; }
        td.when { color: #64748b; white-space: nowrap; }
        td.actor { font-weight: 500; }
        .empty { font-size: 14px; color: #64748b; margin: 20px 0; }

        .tag { display: inline-block; font-size: 12px; font-weight: 600; padding: 3px 10px; border-radius: 999px; white-space: nowrap; background: #f1f5f9; color: #475569; }
        .tag.grant { background: rgba(16,107,154,0.1); color: var(--primary-color); }
        .tag.revoke, .tag.remove { background: #fef2f2; color: #991b1b; }
        .ip { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 12px; color: #94a3b8; }
        .detail { color: #64748b; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Audit log</h1>
            <a class="back" href="/admin/team">&larr; Back to team</a>
        </div>
        <p class="lede">A permanent record of who granted or removed access, and when. Newest first, most recent 200 shown.</p>

        <div class="panel">
            <?php if (empty($entries)): ?>
                <p class="empty">Nothing recorded yet.</p>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>When</th>
                                <th>Who</th>
                                <th>Action</th>
                                <th>Target</th>
                                <th>Detail</th>
                                <th>IP</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($entries as $e): ?>
                            <?php
                                $action = $e['action'];
                                $label = $labels[$action] ?? $action;
                                $tagClass = 'tag';
                                if ($action === 'invite.created' || $action === 'invite.accepted') {
                                    $tagClass .= ' grant';
                                } elseif ($action === 'invite.revoked') {
                                    $tagClass .= ' revoke';
                                } elseif ($action === 'organizer.removed') {
                                    $tagClass .= ' remove';
                                }
                            ?>
                            <tr>
                                <td class="when"><?= htmlspecialchars(date('j M Y, H:i', strtotime($e['created_at']))) ?></td>
                                <td class="actor"><?= htmlspecialchars($e['actor_email']) ?></td>
                                <td><span class="<?= $tagClass ?>"><?= htmlspecialchars($label) ?></span></td>
                                <td><?= $e['target'] !== null && $e['target'] !== '' ? htmlspecialchars($e['target']) : '<span class="detail">&mdash;</span>' ?></td>
                                <td class="detail"><?= $e['detail'] !== null ? htmlspecialchars($e['detail']) : '' ?></td>
                                <td class="ip"><?= $e['ip_address'] !== null ? htmlspecialchars($e['ip_address']) : '' ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
