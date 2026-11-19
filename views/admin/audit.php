<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/audit.php';
require_once __DIR__ . '/../../includes/engage_page.php';

// The audit log records who can grant and remove access, so reading it is
// itself an owner-level concern.
Auth::requireOwner();

$entries = AuditLog::recent(200);

// Human-readable labels for the stored action keys. An unknown key falls back
// to itself, so a newly added action still shows rather than vanishing.
$labels = [
    'invite.created'         => 'Invitation sent',
    'invite.revoked'         => 'Invitation revoked',
    'invite.accepted'        => 'Invitation accepted',
    'organizer.removed'      => 'Organizer removed',
    'organizer.roles_changed' => 'Roles changed',
    'password.reset'         => 'Password reset',
];

// Colour of the action pill: grant (teal), revoke (coral), change (gold), anything else (slate).
$tones = [
    'invite.created'          => 't-grant',
    'invite.accepted'         => 't-grant',
    'invite.revoked'          => 't-revoke',
    'organizer.removed'       => 't-revoke',
    'organizer.roles_changed' => 't-change',
];

engage_header([
    'page'    => 'audit',
    'title'   => 'Audit log',
    'heading' => 'Audit log',
    'kicker'  => 'Organizer workspace',
    'lead'    => 'A permanent record of who granted or removed access, and when.',
    'wide'    => true,
    'crumbs'  => [['Workspace', '/admin/dashboard'], ['Team', '/admin/team'], ['Audit log']],
]);
?>
<section class="fcard wide">
    <h2 class="ctitle">Recent activity</h2>
    <p class="intro">Newest first, most recent 200 shown.</p>

    <?php if (empty($entries)): ?>
        <div class="empty-note">Nothing recorded yet.</div>
    <?php else: ?>
        <div class="tbl-wrap">
            <table class="tbl">
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
                <?php foreach ($entries as $entry): ?>
                    <?php
                        $action = $entry['action'];
                        $label = $labels[$action] ?? $action;
                        $tone = $tones[$action] ?? 't-plain';
                    ?>
                    <tr>
                        <td class="when"><?= htmlspecialchars(date('j M Y, H:i', strtotime($entry['created_at']))) ?></td>
                        <td class="actor"><?= htmlspecialchars($entry['actor_email']) ?></td>
                        <td><span class="pill <?= $tone ?>"><?= htmlspecialchars($label) ?></span></td>
                        <td><?= $entry['target'] !== null && $entry['target'] !== '' ? htmlspecialchars($entry['target']) : '<span class="dash">&mdash;</span>' ?></td>
                        <td class="detail"><?= $entry['detail'] !== null ? htmlspecialchars($entry['detail']) : '' ?></td>
                        <td class="ip"><?= $entry['ip_address'] !== null ? htmlspecialchars($entry['ip_address']) : '' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<?php engage_footer(); ?>
