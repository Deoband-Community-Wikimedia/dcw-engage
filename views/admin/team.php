<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/mailer.php';
require_once __DIR__ . '/../../models/InviteModel.php';

// Authenticated *and* an owner. Everything below can grant or remove access.
Auth::requireOwner();

$invites = new InviteModel();

/**
 * Flash messages survive the redirect after a POST, which keeps a refresh
 * from re-sending an invitation.
 */
function team_flash($type, $message, $link = null) {
    $_SESSION['team_flash'] = ['type' => $type, 'message' => $message, 'link' => $link];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CSRF::validate($_POST['csrf_token'] ?? '')) {
        team_flash('error', 'Your session expired. Please try again.');
        header('Location: /admin/team');
        exit;
    }

    // A double click fires two valid requests. The first consumes the token;
    // the second finds it gone and is dropped here, before anything is created
    // or emailed. No flash is set, so the result of the first submission is
    // what the visitor ends up seeing.
    if (!CSRF::consumeSubmitToken($_POST['submit_token'] ?? '')) {
        header('Location: /admin/team');
        exit;
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'invite') {
        $email = trim($_POST['email'] ?? '');
        $role  = ($_POST['role'] ?? 'organizer') === 'owner' ? 'owner' : 'organizer';

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            team_flash('error', 'That is not a valid email address.');
        } elseif ($invites->emailHasAccount($email)) {
            // The reader is an owner-trusted colleague, so naming the reason
            // is helpful here rather than an account-enumeration risk.
            team_flash('error', $email . ' already has an account.');
        } else {
            $invite = $invites->create($email, $role, Auth::id(), Auth::email());

            $config = require __DIR__ . '/../../includes/config.php';
            $link = $config['app']['url'] . '/admin/accept-invite?token=' . urlencode($invite['token']);

            $sent = Mailer::sendOrganizerInvite(
                $email,
                $invite['token'],
                Auth::email(),
                $invite['expires_at']
            );

            if ($sent) {
                team_flash('success', 'Invitation sent to ' . $email . '.');
            } else {
                // The invitation is valid; only delivery failed. Hand the owner
                // the link so a mail outage cannot strand it. This is the one
                // and only time the raw token is shown.
                team_flash(
                    'warning',
                    'Invitation created, but the email could not be sent. Copy this link and give it to '
                        . $email . ' yourself. It will not be shown again.',
                    $link
                );
            }
        }
    } elseif ($action === 'revoke') {
        $ok = $invites->revoke($_POST['invite_id'] ?? 0);
        team_flash(
            $ok ? 'success' : 'error',
            $ok ? 'Invitation revoked.' : 'That invitation was already used or revoked.'
        );
    } elseif ($action === 'remove') {
        $result = $invites->removeOrganizer($_POST['admin_id'] ?? 0, Auth::id());

        if ($result['ok']) {
            team_flash('success', 'Organizer removed. They can no longer sign in.');
        } elseif ($result['reason'] === 'self') {
            team_flash('error', 'You cannot remove your own account.');
        } elseif ($result['reason'] === 'last_owner') {
            team_flash('error', 'You cannot remove the last owner. Make someone else an owner first.');
        } else {
            team_flash('error', 'That account no longer exists.');
        }
    }

    header('Location: /admin/team');
    exit;
}

$flash = $_SESSION['team_flash'] ?? null;
unset($_SESSION['team_flash']);

$pending    = $invites->listPending();
$organizers = $invites->listOrganizers();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Team - DCW Engage</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --primary-color: #106b9a; --border-color: #e2e8f0; }
        * { box-sizing: border-box; }
        body { font-family: 'Inter', -apple-system, sans-serif; background: #f8fafc; color: #1e293b; margin: 0; padding: 40px 20px; }
        .container { max-width: 860px; margin: auto; }
        .header { display: flex; justify-content: space-between; align-items: center; gap: 16px; margin-bottom: 8px; }
        h1 { margin: 0; color: var(--primary-color); font-size: 28px; }
        .lede { color: #64748b; font-size: 14px; margin: 0 0 30px; }
        .back { color: #64748b; font-size: 14px; text-decoration: none; }
        .back:hover { color: var(--primary-color); }

        .panel { background: #fff; border: 1px solid var(--border-color); border-radius: 12px; padding: 24px; margin-bottom: 24px; }
        h2 { font-size: 16px; margin: 0 0 4px; }
        .panel-sub { font-size: 13px; color: #64748b; margin: 0 0 20px; line-height: 1.55; }

        label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px; }
        input[type=email], select {
            width: 100%; padding: 11px; border: 1px solid var(--border-color);
            border-radius: 6px; font-family: inherit; font-size: 15px; background: #fff;
        }
        input:focus, select:focus { outline: 2px solid var(--primary-color); outline-offset: -1px; border-color: transparent; }
        .field-row { display: flex; gap: 14px; flex-wrap: wrap; }
        .field-row > div:first-child { flex: 1 1 260px; }
        .field-row > div:last-child { flex: 0 1 180px; }
        .hint { font-size: 12px; color: #64748b; margin: 10px 0 0; line-height: 1.55; }

        button.primary {
            margin-top: 18px; padding: 12px 22px; background: var(--primary-color); color: #fff;
            border: none; border-radius: 6px; font-family: inherit; font-size: 15px; font-weight: 600; cursor: pointer;
        }
        button.primary:hover { background: #0d587f; }
        button.link {
            background: none; border: none; color: #b91c1c; font-family: inherit;
            font-size: 13px; cursor: pointer; padding: 0; text-decoration: underline;
        }

        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th { text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: 0.06em; color: #64748b; padding: 0 0 10px; font-weight: 600; }
        td { padding: 12px 0; border-top: 1px solid var(--border-color); vertical-align: middle; }
        td.right, th.right { text-align: right; }
        .empty { font-size: 14px; color: #64748b; margin: 0; }

        .pill { display: inline-block; font-size: 11px; font-weight: 600; padding: 3px 9px; border-radius: 999px; }
        .pill-owner { background: rgba(16,107,154,0.1); color: var(--primary-color); }
        .pill-organizer { background: #f1f5f9; color: #475569; }
        .pill-expired { background: #fef2f2; color: #991b1b; }

        .flash { padding: 13px 15px; border-radius: 6px; font-size: 14px; margin-bottom: 24px; line-height: 1.55; }
        .flash-success { background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; }
        .flash-error { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }
        .flash-warning { background: #fffbeb; border: 1px solid #fde68a; color: #92400e; }
        .flash code { display: block; margin-top: 10px; padding: 10px; background: rgba(0,0,0,0.06); border-radius: 4px; font-size: 12px; word-break: break-all; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Team</h1>
            <a class="back" href="/admin/dashboard">&larr; Back to workspace</a>
        </div>
        <p class="lede">Everyone listed here can read every application. Invite carefully.</p>

        <?php if ($flash): ?>
            <div class="flash flash-<?= htmlspecialchars($flash['type']) ?>">
                <?= htmlspecialchars($flash['message']) ?>
                <?php if (!empty($flash['link'])): ?>
                    <code><?= htmlspecialchars($flash['link']) ?></code>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="panel">
            <h2>Invite an organizer</h2>
            <p class="panel-sub">
                They receive a one-time link and choose their own password.
                No account exists until they open it.
            </p>

            <form method="POST" autocomplete="off">
                <?= CSRF::getInputField() ?>
                <?= CSRF::getSubmitField() ?>
                <input type="hidden" name="action" value="invite">

                <div class="field-row">
                    <div>
                        <label for="email">Email address</label>
                        <input type="email" name="email" id="email" required placeholder="name@dcwwiki.org">
                    </div>
                    <div>
                        <label for="role">Role</label>
                        <select name="role" id="role">
                            <option value="organizer">Organizer</option>
                            <option value="owner">Owner</option>
                        </select>
                    </div>
                </div>

                <p class="hint">
                    Organizers manage forms and applications. Owners can additionally
                    invite people and revoke invitations from this page.
                </p>

                <button type="submit" class="primary">Send invitation</button>
            </form>
        </div>

        <div class="panel">
            <h2>Pending invitations</h2>
            <p class="panel-sub">Not yet accepted. Revoking one kills its link immediately.</p>

            <?php if (empty($pending)): ?>
                <p class="empty">No invitations are waiting.</p>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Invited by</th>
                            <th>Expires</th>
                            <th class="right">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($pending as $invite): ?>
                        <tr>
                            <td><?= htmlspecialchars($invite['email']) ?></td>
                            <td>
                                <span class="pill pill-<?= htmlspecialchars($invite['role']) ?>">
                                    <?= htmlspecialchars(ucfirst($invite['role'])) ?>
                                </span>
                            </td>
                            <td><?= htmlspecialchars($invite['invited_by_email']) ?></td>
                            <td>
                                <?php if ($invite['is_expired']): ?>
                                    <span class="pill pill-expired">Expired</span>
                                <?php else: ?>
                                    <?= htmlspecialchars(date('j M Y', strtotime($invite['expires_at']))) ?>
                                <?php endif; ?>
                            </td>
                            <td class="right">
                                <form method="POST" style="margin:0;">
                                    <?= CSRF::getInputField() ?>
                                    <?= CSRF::getSubmitField() ?>
                                    <input type="hidden" name="action" value="revoke">
                                    <input type="hidden" name="invite_id" value="<?= (int) $invite['id'] ?>">
                                    <button type="submit" class="link">Revoke</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <div class="panel">
            <h2>Organizers</h2>
            <p class="panel-sub">Accounts that can currently sign in to this workspace.</p>

            <table>
                <thead>
                    <tr>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Last signed in</th>
                        <th class="right">Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($organizers as $person): ?>
                    <tr>
                        <td>
                            <?= htmlspecialchars($person['email']) ?>
                            <?php if ((int) $person['id'] === (int) Auth::id()): ?>
                                <span style="color:#64748b; font-size:12px;">(you)</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="pill pill-<?= htmlspecialchars($person['role']) ?>">
                                <?= htmlspecialchars(ucfirst($person['role'])) ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($person['last_login']): ?>
                                <?= htmlspecialchars(date('j M Y', strtotime($person['last_login']))) ?>
                            <?php else: ?>
                                <span style="color:#94a3b8;">Never</span>
                            <?php endif; ?>
                        </td>
                        <td class="right">
                            <?php if ((int) $person['id'] === (int) Auth::id()): ?>
                                <span style="color:#cbd5e1;">&mdash;</span>
                            <?php else: ?>
                                <form method="POST" style="margin:0;">
                                    <?= CSRF::getInputField() ?>
                                    <?= CSRF::getSubmitField() ?>
                                    <input type="hidden" name="action" value="remove">
                                    <input type="hidden" name="admin_id" value="<?= (int) $person['id'] ?>">
                                    <button type="submit" class="link"
                                            onclick="return confirm('Remove this organizer? They will lose access immediately.');">Remove</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <script>
        // Progressive enhancement only. With scripting off, the single-use
        // submit token on the server still makes a second POST a no-op.
        document.querySelectorAll('form').forEach(function (form) {
            form.addEventListener('submit', function () {
                var button = form.querySelector('button[type=submit]');
                if (!button || button.disabled) return;

                // Width is pinned before the label changes so the button does
                // not resize and shift the row underneath it.
                button.style.minWidth = button.offsetWidth + 'px';
                button.disabled = true;
                if (button.classList.contains('primary')) {
                    button.textContent = 'Sending...';
                }
            });
        });
    </script>
</body>
</html>
