<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/app_log.php';
require_once __DIR__ . '/../../includes/engage_page.php';
require_once __DIR__ . '/../../includes/require_role.php';
require_once __DIR__ . '/../../models/PublicDirectoryModel.php';

/**
 * DCW Engage - the public members list for staff (/admin/public-directory).
 * Shows how many people are listed, who opted out, and how many members are not listed because they
 * have no verified Wikimedia username. Membership reviewers and owners can also edit (username, clubs)
 * or remove the "Listed before Engage" entries. Opting in or out is the member's own choice
 * (/member/profile).
 */
requireRole(['membership_reviewer', 'owner']);

$model = new PublicDirectoryModel();

// "Refresh now" clears the 5-minute cache so staff can check a change straight away.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CSRF::validate($_POST['csrf_token'] ?? '')) {
        die('Invalid CSRF token.');
    }
    $act = (string) ($_POST['action'] ?? 'refresh');

    if ($act === 'remove_legacy') {
        try {
            $model->removeLegacy((int) ($_POST['id'] ?? 0), 'staff');
            header('Location: /admin/public-directory?removed=1');
        } catch (Throwable $e) {
            app_log('Remove legacy entry failed: ' . $e->getMessage());
            header('Location: /admin/public-directory?removed=0');
        }
        exit;
    }

    if ($act === 'update_legacy') {
        try {
            $model->updateLegacy(
                (int) ($_POST['id'] ?? 0),
                (string) ($_POST['username'] ?? ''),
                (array) ($_POST['chapters'] ?? []),
                'staff'
            );
            header('Location: /admin/public-directory?updated=1');
        } catch (InvalidArgumentException $e) {
            // Safe, human-readable validation messages only.
            header('Location: /admin/public-directory?updated=0&why=' . urlencode($e->getMessage()));
        } catch (Throwable $e) {
            app_log('Update legacy entry failed: ' . $e->getMessage());
            header('Location: /admin/public-directory?updated=0');
        }
        exit;
    }

    PublicDirectoryCache::clear();
    header('Location: /admin/public-directory?refreshed=1');
    exit;
}

$o = $model->overview();
$h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

/** A stored UTC datetime as a plain date in India time. */
function pdir_date(?string $utc): string
{
    if ($utc === null || $utc === '') return '';
    return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))
        ->setTimezone(new DateTimeZone('Asia/Kolkata'))->format('j M Y');
}

// One row per person: the model returns one row per club, so group them by username.
$legacyPeople = [];
foreach ($o['legacy'] as $r) {
    $k = trim(str_replace('_', ' ', (string) $r['username']));
    $legacyPeople[$k] ??= ['id' => (int) $r['id'], 'username' => $k, 'chapters' => [], 'shown' => !empty($r['shown'])];
    $legacyPeople[$k]['chapters'][(string) $r['chapter']] = true;
}

engage_header([
    'title'   => 'Public members list',
    'heading' => 'Public members list',
    'lead'    => 'Who appears on the public members page.',
    'back'    => ['Dashboard', '/admin/dashboard'],
    'compact' => true,
    'wide'    => true,
]);
?>
<style>
    .pa-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 14px; margin: 0 0 20px; }
    .pa-stat { padding: 16px 18px; background: var(--card); border: 1px solid var(--border); border-radius: 14px; }
    .pa-stat b { display: block; font-size: 28px; font-weight: 800; letter-spacing: -.02em; }
    .pa-stat span { color: var(--muted); font-size: 13.5px; }
    .pa-card { margin: 0 0 20px; padding: 20px 22px; background: var(--card); border: 1px solid var(--border); border-radius: 16px; }
    .pa-card h2 { margin: 0 0 8px; font-size: 18px; font-weight: 800; }
    .pa-card p { color: var(--muted); font-size: 14px; }
    .pa-t { width: 100%; border-collapse: collapse; font-size: 14px; }
    .pa-t th { text-align: left; color: var(--muted); font-size: 12.5px; padding: 6px 8px; }
    .pa-t td { padding: 8px; border-top: 1px solid var(--border); vertical-align: top; }
    .pa-t input[type=text] { width: 100%; padding: 6px 8px; border: 1px solid var(--border); border-radius: 8px; background: var(--card); color: inherit; font: inherit; }
    .pa-t input[type=checkbox] { width: auto; }
    .pa-clubs label { display: inline-block; margin: 0 12px 4px 0; white-space: nowrap; }
    .pa-actions { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin: 0 0 18px; }
    .pa-actions form { margin: 0; }
</style>

<div class="dash">
<?php if (isset($_GET['refreshed'])): ?>
    <div class="alert ok">Refreshed. The public page will rebuild on its next visit.</div>
<?php elseif (($_GET['removed'] ?? '') === '1'): ?>
    <div class="alert ok">Removed from the public list.</div>
<?php elseif (($_GET['removed'] ?? '') === '0'): ?>
    <div class="alert error">Could not remove that entry. It may already be gone.</div>
<?php elseif (($_GET['updated'] ?? '') === '1'): ?>
    <div class="alert ok">Entry updated.</div>
<?php elseif (($_GET['updated'] ?? '') === '0'): ?>
    <div class="alert error"><?= $h($_GET['why'] ?? 'Could not update that entry.') ?></div>
<?php endif; ?>

    <div class="pa-actions">
        <a class="btn" href="/members" target="_blank" rel="noopener">Open public page</a>
        <form method="POST" action="/admin/public-directory">
            <?= CSRF::getInputField() ?>
            <button type="submit" class="btn">Refresh now</button>
        </form>
    </div>

    <div class="pa-grid">
        <div class="pa-stat"><b><?= $o['current'] === null ? '?' : (int) $o['current'] ?></b><span>Current members listed</span></div>
        <div class="pa-stat"><b><?= $o['alumni'] === null ? '?' : (int) $o['alumni'] ?></b><span>Alumni listed</span></div>
        <div class="pa-stat"><b><?= $o['legacyShown'] === null ? '?' : (int) $o['legacyShown'] ?></b><span>Listed before Engage</span></div>
        <div class="pa-stat"><b><?= count($o['optedOut']) ?></b><span>Opted out</span></div>
        <div class="pa-stat"><b><?= $o['noUsername'] === null ? '?' : (int) $o['noUsername'] ?></b><span>Not listed: no verified username</span></div>
    </div>
    <?php if ($o['current'] === null): ?>
        <div class="alert error">The list could not be built. Check that member_public_directory.sql has been run and see the app log.</div>
    <?php endif; ?>

    <section class="pa-card">
        <h2>Listed before Engage</h2>
        <p>Imported once from Template:DCW/Members on Meta-Wiki. An entry disappears from the public page by itself once the same username is on Engage (including when that member opted out or was struck off), and the clubs shown then come from Engage. Use Save to correct a username or set which clubs appear next to it, until the person joins Engage. Use Remove for takedown requests or people who are not members. Removing a username removes it from every club.</p>
        <?php if (!$legacyPeople): ?>
            <p>No legacy entries. Run legacy_members_import.sql to add them.</p>
        <?php else: ?>
            <table class="pa-t">
                <thead><tr><th style="width:26%;">Username</th><th>Clubs</th><th>On public page?</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($legacyPeople as $p): $fid = 'lg-' . $p['id']; ?>
                    <tr>
                        <td><input type="text" name="username" form="<?= $fid ?>" value="<?= $h($p['username']) ?>" maxlength="85" required></td>
                        <td class="pa-clubs">
                            <?php foreach (MemberModel::CHAPTER_NAMES as $key => $label): ?>
                                <label>
                                    <input type="checkbox" name="chapters[]" form="<?= $fid ?>" value="<?= $h($key) ?>" <?= isset($p['chapters'][$key]) ? 'checked' : '' ?>>
                                    <?= $h($label) ?>
                                </label>
                            <?php endforeach; ?>
                        </td>
                        <td><?= $p['shown'] ? 'Yes' : 'No (already on Engage)' ?></td>
                        <td style="white-space:nowrap;">
                            <form id="<?= $fid ?>" method="POST" action="/admin/public-directory" style="display:inline;margin:0;">
                                <?= CSRF::getInputField() ?>
                                <input type="hidden" name="action" value="update_legacy">
                                <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                                <button type="submit" class="btn">Save</button>
                            </form>
                            <form method="POST" action="/admin/public-directory" onsubmit="return confirm('Remove this username from the public list?');" style="display:inline;margin:0;">
                                <?= CSRF::getInputField() ?>
                                <input type="hidden" name="action" value="remove_legacy">
                                <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                                <button type="submit" class="btn">Remove</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>

    <section class="pa-card">
        <h2>Opted out</h2>
        <p>These members chose not to be listed. Their username is hidden for every club they hold. Struck-off members are always hidden and are not shown here.</p>
        <?php if (!$o['optedOut']): ?>
            <p>No one has opted out.</p>
        <?php else: ?>
            <table class="pa-t">
                <thead><tr><th>Name</th><th>Member ID</th><th>Wikimedia username</th><th>Since</th></tr></thead>
                <tbody>
                <?php foreach ($o['optedOut'] as $r): ?>
                    <tr>
                        <td><?= $h($r['full_name'] ?? '') ?></td>
                        <td><code><?= $h($r['member_id']) ?></code></td>
                        <td><?= $h($r['username'] ?? '') ?></td>
                        <td><?= $h(pdir_date((string) $r['hidden_at'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>
</div>
<?php engage_footer(); ?>
