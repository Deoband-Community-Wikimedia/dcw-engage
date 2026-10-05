<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/mailer.php';
require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../includes/engage_page.php';
require_once __DIR__ . '/../../models/InviteModel.php';
require_once __DIR__ . '/../../models/MemberModel.php';

// Authenticated *and* an owner. Everything below can grant or remove access.
Auth::requireOwner();

$invites = new InviteModel();
$members = new MemberModel();

/**
 * Flash messages survive the redirect after a POST, which keeps a refresh
 * from re-sending an invitation.
 */
function team_flash($type, $message, $link = null) {
    $_SESSION['team_flash'] = ['type' => $type, 'message' => $message, 'link' => $link];
}

/** Human label for a stored role value ('support_reviewer' -> 'Support reviewer'). */
function team_role_label($role) {
    $labels = [
        'owner'                  => 'Owner',
        'organizer'              => 'Organizer',
        'finance'                => 'Finance',
        'support_reviewer'       => 'Support reviewer',
        'member_support'         => 'DCW Support',
        'membership_reviewer'    => 'Membership reviewer',
        'membership_coordinator' => 'Membership coordinator',
        'technical_manager'      => 'Technical manager',
    ];
    return $labels[$role] ?? ucfirst(str_replace('_', ' ', $role));
}

/** One pill per role, e.g. for a person who is both Finance and Support reviewer. */
function team_role_pills(array $roles) {
    $html = '<span class="pills">';
    foreach ($roles as $role) {
        $html .= '<span class="pill pill-' . htmlspecialchars($role) . '">'
              . htmlspecialchars(team_role_label($role)) . '</span>';
    }
    return $html . '</span>';
}

/** The role checkboxes, shared by the invite form and the per-person editor. */
function team_role_checkboxes(array $checked, $legend = 'Roles (pick one or more)') {
    $html = '<fieldset class="group"><legend>' . htmlspecialchars($legend) . '</legend><div class="role-grid">';
    foreach (InviteModel::ROLES as $role) {
        $html .= '<label class="check"><input type="checkbox" name="roles[]" value="'
              . htmlspecialchars($role) . '"' . (in_array($role, $checked, true) ? ' checked' : '')
              . '> ' . htmlspecialchars(team_role_label($role)) . '</label>';
    }
    return $html . '</div></fieldset>';
}

/**
 * Chapter checkboxes for a membership coordinator. Always rendered; a little
 * script at the bottom hides the block unless "Membership coordinator" is
 * ticked in the same form (with scripting off it simply stays visible).
 */
function team_chapter_checkboxes(array $checked) {
    $html = '<fieldset class="group chapter-box"><legend>Chapters this coordinator can see</legend>'
          . '<div class="role-grid">';
    foreach (InviteModel::CHAPTERS as $key => $label) {
        $html .= '<label class="check"><input type="checkbox" name="chapters[]" value="'
              . htmlspecialchars($key) . '"' . (in_array($key, $checked, true) ? ' checked' : '')
              . '> ' . htmlspecialchars($label) . '</label>';
    }
    return $html . '</div></fieldset>';
}

/** "Wiki Club AMU, Wiki Club Jamia" for a list of chapter keys. */
function team_chapter_names(array $keys) {
    $names = [];
    foreach ($keys as $key) {
        $names[] = InviteModel::CHAPTERS[$key] ?? $key;
    }
    return implode(', ', $names);
}

function team_role_names(array $roles) {
    return implode(', ', array_map('team_role_label', $roles));
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

        // The whitelist lives in InviteModel::ROLES, and normalizeRoles()
        // drops anything not on it. Only an owner can reach this branch at
        // all (Auth::requireOwner() above), which is what satisfies the
        // model's "only an owner may invite" caller contract.
        $roles = InviteModel::normalizeRoles($_POST['roles'] ?? []);
        $isCoordinator = in_array('membership_coordinator', $roles, true);
        $chapters = $isCoordinator ? InviteModel::normalizeChapters($_POST['chapters'] ?? []) : [];

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            team_flash('error', 'That is not a valid email address.');
        } elseif (!$roles) {
            team_flash('error', 'Pick at least one role.');
        } elseif ($isCoordinator && !$chapters) {
            team_flash('error', 'Pick at least one chapter for a membership coordinator, or they will see nothing.');
        } elseif ($invites->emailHasAccount($email)) {
            // The reader is an owner-trusted colleague, so naming the reason
            // is helpful here rather than an account-enumeration risk.
            team_flash('error', $email . ' already has an account.');
        } else {
            $invite = $invites->create($email, $roles, Auth::id(), Auth::email(), $chapters);
            AuditLog::record(
                'invite.created', Auth::id(), Auth::email(), $email,
                'Roles: ' . implode(', ', $roles) . ($chapters ? '; Chapters: ' . implode(', ', $chapters) : '')
            );

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
    } elseif ($action === 'update_roles') {
        $newRoles = InviteModel::normalizeRoles($_POST['roles'] ?? []);
        $isCoordinator = in_array('membership_coordinator', $newRoles, true);
        $chapters = $isCoordinator ? InviteModel::normalizeChapters($_POST['chapters'] ?? []) : [];

        if ($isCoordinator && !$chapters) {
            // Checked before anything is saved, so a refusal changes nothing.
            team_flash('error', 'Pick at least one chapter for a membership coordinator, or they will see nothing.');
            header('Location: /admin/team');
            exit;
        }

        $result = $invites->setRoles($_POST['admin_id'] ?? 0, $newRoles, Auth::id());

        if ($result['ok']) {
            // Roles are saved. Chapters go through MemberModel::setChapters().
            // Chapters are left untouched when the coordinator role is
            // removed: access is role-gated, and the owner will see the old
            // ticks again if the role is re-added.
            $chapterError = null;
            if ($isCoordinator) {
                try {
                    $members->setChapters($result['email'], $chapters);
                } catch (Throwable $e) {
                    $chapterError = $e->getMessage();
                }
            }

            AuditLog::record(
                'organizer.roles_changed', Auth::id(), Auth::email(), $result['email'],
                'Roles: ' . implode(', ', $result['old']) . ' -> ' . implode(', ', $result['new'])
                    . ($isCoordinator && !$chapterError ? '; Chapters: ' . implode(', ', $chapters) : '')
            );

            if ($chapterError) {
                team_flash('warning', 'Roles updated for ' . $result['email'] . ', but chapters could not be saved ('
                    . $chapterError . '). Open Edit roles and save again.');
            } else {
                team_flash('success', 'Roles updated for ' . $result['email'] . '. They take effect on their next page load.');
            }
        } elseif ($result['reason'] === 'empty') {
            team_flash('error', 'Pick at least one role. To take away all access, remove the account instead.');
        } elseif ($result['reason'] === 'self') {
            team_flash('error', 'You cannot change your own roles. Ask another owner.');
        } elseif ($result['reason'] === 'last_owner') {
            team_flash('error', 'You cannot take the owner role from the last owner. Make someone else an owner first.');
        } else {
            team_flash('error', 'That account no longer exists.');
        }
    } elseif ($action === 'revoke') {
        $inviteId = (int) ($_POST['invite_id'] ?? 0);
        $ok = $invites->revoke($inviteId);
        if ($ok) {
            AuditLog::record('invite.revoked', Auth::id(), Auth::email(), null, 'Invitation #' . $inviteId);
        }
        team_flash(
            $ok ? 'success' : 'error',
            $ok ? 'Invitation revoked.' : 'That invitation was already used or revoked.'
        );
    } elseif ($action === 'remove') {
        $result = $invites->removeOrganizer($_POST['admin_id'] ?? 0, Auth::id());

        if ($result['ok']) {
            AuditLog::record('organizer.removed', Auth::id(), Auth::email(), $result['email'], 'Roles: ' . $result['role']);
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

engage_header([
    'title'   => 'Team',
    'heading' => 'Team',
    'kicker'  => 'Organizer workspace',
    'lead'    => 'Organizers, finance and support staff can read applications. Membership roles only see membership applications. Invite carefully.',
    'tools'   => '<a class="chip-btn" href="/admin/audit">Audit log</a>',
    'wide'    => true,
    'crumbs'  => [['Workspace', '/admin/dashboard'], ['Team']],
]);
?>
<style>
    /* Team page only. Everything else comes from /assets/css/engage.css */
    .ctitle { margin: 0 0 6px; font-size: 20px; font-weight: 800; letter-spacing: -.02em; }
    .invite-form .field { max-width: 460px; }

    .role-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 8px 16px; margin: 4px 0 14px; }
    label.check { display: flex; align-items: center; gap: 8px; margin: 0; font-size: 14px; font-weight: 500; cursor: pointer; }
    label.check input { width: 16px; height: 16px; margin: 0; accent-color: var(--primary); }

    .pills { display: inline-flex; flex-wrap: wrap; gap: 4px; }
    .pill-owner { --tone: var(--primary); }
    .pill-organizer { --tone: #475569; }
    .pill-finance { --tone: #3730a3; }
    .pill-support_reviewer { --tone: #b45309; }
    .pill-member_support { --tone: #106b9a; }
    .pill-membership_reviewer { --tone: #6d28d9; }
    .pill-membership_coordinator { --tone: #047857; }
    .pill-technical_manager { --tone: #0e7490; }
    .pill-expired { --tone: #b91c1c; }

    .tbl .sub { margin-top: 6px; }
    .warn-text { color: #b45309; }
    .tbl th.r, .tbl td.r { text-align: right; }

    /* engage.css gives every submit button inside .fcard the full-width gradient look.
       These rules turn the small in-table buttons and the invite button back into compact ones. */
    .fcard .invite-form .send { width: auto; padding: 12px 30px; }
    .fcard .tbl .tb-btn { width: auto; padding: 6px 16px; font-size: 13px; box-shadow: none; }
    .fcard .tbl .tb-btn:hover:not(:disabled) { transform: none; box-shadow: none; }
    .fcard .tbl .tb-danger { background: #fff; color: #991b1b; border: 1px solid #f87171; }
    .fcard .tbl .tb-danger:hover:not(:disabled) { background: #fef2f2; }
    .tb-save { margin-top: 12px; }

    details.edit { margin-top: 10px; }
    details.edit summary { font-size: 13px; font-weight: 600; color: var(--primary); cursor: pointer; }
    details.edit form { margin: 10px 0 0; padding: 14px; background: #f8fafc; border: 1px solid var(--border); border-radius: 12px; }
    details.edit fieldset.group { background: #fff; margin-bottom: 0; }

    .flash-link { display: block; flex-basis: 100%; margin-top: 10px; padding: 10px; background: rgba(0,0,0,.06); border-radius: 6px; font-size: 12px; word-break: break-all; }
</style>

<?php if ($flash):
    $flashClass = $flash['type'] === 'success' ? 'alert ok' : ($flash['type'] === 'error' ? 'alert error' : 'action-banner'); ?>
    <div class="<?= $flashClass ?>">
        <span><?= htmlspecialchars($flash['message']) ?></span>
        <?php if (!empty($flash['link'])): ?>
            <code class="flash-link"><?= htmlspecialchars($flash['link']) ?></code>
        <?php endif; ?>
    </div>
<?php endif; ?>

<section class="fcard wide">
    <h2 class="ctitle">Invite an organizer</h2>
    <p class="intro">
        They receive a one-time link and choose their own password.
        No account exists until they open it.
    </p>

    <form method="POST" autocomplete="off" class="invite-form">
        <?= CSRF::getInputField() ?>
        <?= CSRF::getSubmitField() ?>
        <input type="hidden" name="action" value="invite">

        <div class="field">
            <label for="email">Email address</label>
            <input type="email" name="email" id="email" required placeholder="name@dcwwiki.org">
        </div>

        <?= team_role_checkboxes(['organizer']) ?>
        <?= team_chapter_checkboxes([]) ?>

        <p class="note">
            Organizers manage application forms and read their responses.
            Finance can process reimbursement payments and internet support
            recharges. Support reviewers decide reimbursement claims and
            internet support requests. DCW Support answers
            the complaints, suggestions and questions members send from their
            dashboard (complaints about team members stay with owners).
            Technical managers work the problems members and team people
            report, and run the portal diagnostics. Membership coordinators
            review membership applications only for the chapters ticked for
            them (use "Edit roles" on a person to change these later). Membership reviewers
            (DCW Generic Reviewers) review membership applications from every
            chapter. Choose the chapters when you tick Membership coordinator;
            they are applied as soon as the invitation is accepted. Owners can
            additionally invite people, change roles and
            revoke invitations from this page. Someone with several roles gets
            the access of each.
        </p>

        <button type="submit" class="send">Send invitation</button>
    </form>
</section>

<section class="fcard wide">
    <h2 class="ctitle">Pending invitations</h2>
    <p class="intro">Not yet accepted. Revoking one kills its link immediately.</p>

    <?php if (empty($pending)): ?>
        <div class="empty-note">No invitations are waiting.</div>
    <?php else: ?>
        <div class="tbl-wrap">
            <table class="tbl">
                <thead>
                    <tr>
                        <th>Email</th>
                        <th>Roles</th>
                        <th>Invited by</th>
                        <th>Expires</th>
                        <th class="r">Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($pending as $invite): ?>
                    <tr>
                        <td><?= htmlspecialchars($invite['email']) ?></td>
                        <td>
                            <?= team_role_pills($invite['role_list']) ?>
                            <?php if (!empty($invite['chapter_list'])): ?>
                                <span class="sub">Chapters: <?= htmlspecialchars(team_chapter_names($invite['chapter_list'])) ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($invite['invited_by_email']) ?></td>
                        <td>
                            <?php if ($invite['is_expired']): ?>
                                <span class="pill pill-expired">Expired</span>
                            <?php else: ?>
                                <?= htmlspecialchars(Mailer::formatExpiryIST($invite['expires_at'])) ?>
                            <?php endif; ?>
                        </td>
                        <td class="r">
                            <form method="POST" style="margin:0;">
                                <?= CSRF::getInputField() ?>
                                <?= CSRF::getSubmitField() ?>
                                <input type="hidden" name="action" value="revoke">
                                <input type="hidden" name="invite_id" value="<?= (int) $invite['id'] ?>">
                                <button type="submit" class="tb-btn tb-danger">Revoke</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="fcard wide">
    <h2 class="ctitle">Organizers</h2>
    <p class="intro">Accounts that can currently sign in to this workspace.</p>

    <div class="tbl-wrap">
        <table class="tbl">
            <thead>
                <tr>
                    <th>Email</th>
                    <th>Roles</th>
                    <th>Last signed in</th>
                    <th class="r">Action</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($organizers as $person): ?>
                <?php
                    $isSelf = (int) $person['id'] === (int) Auth::id();
                    $isCoord = in_array('membership_coordinator', $person['role_list'], true);
                    $personChapters = [];
                    if ($isCoord) {
                        try {
                            $personChapters = InviteModel::normalizeChapters($members->chaptersFor($person['email']));
                        } catch (Throwable $e) {
                            $personChapters = [];
                        }
                    }
                ?>
                <tr>
                    <td>
                        <?= htmlspecialchars($person['email']) ?>
                        <?php if ($isSelf): ?>
                            <span class="sub">(you)</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?= team_role_pills($person['role_list']) ?>
                        <?php if ($isCoord): ?>
                            <?php if ($personChapters): ?>
                                <span class="sub">Chapters: <?= htmlspecialchars(team_chapter_names($personChapters)) ?></span>
                            <?php else: ?>
                                <span class="sub warn-text">No chapters yet: sees nothing</span>
                            <?php endif; ?>
                        <?php endif; ?>
                        <?php if (!$isSelf): ?>
                            <details class="edit">
                                <summary>Edit roles</summary>
                                <form method="POST">
                                    <?= CSRF::getInputField() ?>
                                    <?= CSRF::getSubmitField() ?>
                                    <input type="hidden" name="action" value="update_roles">
                                    <input type="hidden" name="admin_id" value="<?= (int) $person['id'] ?>">
                                    <?= team_role_checkboxes($person['role_list'], 'Roles') ?>
                                    <?= team_chapter_checkboxes($personChapters) ?>
                                    <button type="submit" class="tb-btn tb-save">Save roles</button>
                                </form>
                            </details>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($person['last_login']): ?>
                            <?= htmlspecialchars(date('j M Y', strtotime($person['last_login']))) ?>
                        <?php else: ?>
                            <span style="color:#94a3b8;">Never</span>
                        <?php endif; ?>
                    </td>
                    <td class="r">
                        <?php if ($isSelf): ?>
                            <span style="color:#cbd5e1;">&mdash;</span>
                        <?php else: ?>
                            <form method="POST" style="margin:0;">
                                <?= CSRF::getInputField() ?>
                                <?= CSRF::getSubmitField() ?>
                                <input type="hidden" name="action" value="remove">
                                <input type="hidden" name="admin_id" value="<?= (int) $person['id'] ?>">
                                <button type="submit" class="tb-btn tb-danger"
                                        onclick="return confirm('Remove this organizer? They will lose access immediately.');">Remove</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<script>
    // Show the chapter picker only while "Membership coordinator" is ticked
    // in that form. With scripting off the picker just stays visible, and
    // the server ignores chapters unless the role is ticked.
    document.querySelectorAll('.chapter-box').forEach(function (box) {
        var form = box.closest('form');
        var coordinator = form && form.querySelector('input[value=membership_coordinator]');
        if (!coordinator) return;
        function sync() { box.style.display = coordinator.checked ? '' : 'none'; }
        coordinator.addEventListener('change', sync);
        sync();
    });

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
            if (button.classList.contains('send')) {
                button.textContent = 'Sending...';
            }
        });
    });
</script>
<?php engage_footer(); ?>
