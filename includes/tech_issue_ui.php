<?php
/**
 * DCW Engage - shared helpers for the technical issue pages
 * (member report page, team report page, technical team queue).
 * Load after includes/init.php.
 */
require_once __DIR__ . '/../models/TechIssueModel.php';

function tech_flash(string $type, string $message): void {
    $_SESSION['tech_flash'] = ['type' => $type, 'message' => $message];
}

function tech_flash_html(): void {
    $f = $_SESSION['tech_flash'] ?? null;
    unset($_SESSION['tech_flash']);
    if (!$f) return;
    echo '<div class="alert ' . ($f['type'] === 'success' ? 'ok' : 'error') . '"><span>' . htmlspecialchars($f['message']) . '</span></div>';
}

/** Stored UTC, shown in IST, like the rest of Engage. */
function tech_date(?string $utc): string {
    if (!$utc) return '';
    return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))
        ->setTimezone(new DateTimeZone('Asia/Kolkata'))->format('j M Y, g:i a');
}

function tech_status_tone(string $status): string {
    return ['Open' => '#106b9a', 'Investigating' => '#b45309', 'Waiting on reporter' => '#6d28d9', 'Resolved' => '#15803d'][$status] ?? '#475569';
}

/** Gate for the technical team pages. Same intent as requireRole(['technical_manager', 'owner']). */
function tech_require_staff(): void {
    if (Auth::hasAnyRole(TechIssueModel::STAFF_ROLES)) return;
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="robots" content="noindex, nofollow">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Not allowed - DCW Engage</title></head>'
       . '<body style="font-family: Inter, -apple-system, sans-serif; background:#f8fafc; color:#1e293b; display:flex; '
       . 'align-items:center; justify-content:center; min-height:100vh; margin:0; padding:20px;"><div style="max-width:420px; text-align:center;">'
       . '<h1 style="color:#106b9a; font-size:20px; margin:0 0 8px;">Not allowed</h1>'
       . '<p style="color:#64748b; font-size:14px; line-height:1.6; margin:0 0 20px;">This page is for the technical team. Ask an owner if you need access.</p>'
       . '<a href="/admin/dashboard" style="color:#106b9a; font-size:14px;">Back to workspace</a></div></body></html>';
    exit;
}

/** Small stylesheet shared by the issue pages. Everything else comes from engage.css. */
function tech_styles(): void { ?>
<style>
    .tech-form .field { max-width: 560px; margin-bottom: 14px; }
    .tech-form textarea, .tech-form select, .tech-form input[type=text] {
        width: 100%; box-sizing: border-box; padding: 11px 12px; font: inherit; font-size: 14px;
        border: 1px solid var(--border, #e2e8f0); border-radius: 10px; background: #fff; color: inherit;
    }
    .tech-form textarea { min-height: 130px; resize: vertical; }
    .fcard .tech-form .send { width: auto; padding: 12px 30px; }
    .thread { display: grid; gap: 10px; margin: 14px 0; }
    .msg { padding: 12px 14px; border: 1px solid var(--border, #e2e8f0); border-radius: 12px; background: #fff; }
    .msg.theirs { background: #f8fafc; }
    .msg.internal { background: #fffbeb; border-color: #fcd34d; }
    .msg .who { font-size: 12px; font-weight: 600; color: #64748b; margin-bottom: 4px; }
    .msg .txt { font-size: 14px; line-height: 1.55; white-space: pre-wrap; word-break: break-word; }
    .issue-meta { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 8px 20px; margin: 10px 0 4px; font-size: 13px; color: #475569; }
    .issue-meta b { display: block; font-size: 12px; color: #94a3b8; font-weight: 600; }
    .chk { display: flex; align-items: center; gap: 8px; font-size: 14px; margin: 0 0 12px; }
    .filters { display: flex; gap: 8px; margin: 0 0 12px; flex-wrap: wrap; }
    .filters a { padding: 6px 14px; border-radius: 999px; border: 1px solid var(--border, #e2e8f0); font-size: 13px; font-weight: 600; color: #475569; text-decoration: none; }
    .filters a.on { background: #106b9a; border-color: #106b9a; color: #fff; }
    .tbl td a.ref { font-weight: 700; color: #106b9a; text-decoration: none; }
</style>
<?php }

/** New-report form. Posts to the same URL. */
function tech_report_form(): void { ?>
    <form method="POST" class="tech-form" autocomplete="off">
        <?= CSRF::getInputField() ?>
        <?= CSRF::getSubmitField() ?>
        <input type="hidden" name="action" value="new">

        <div class="field">
            <label for="category">What is it about?</label>
            <select name="category" id="category" required>
                <option value="">Choose one</option>
                <?php foreach (TechIssueModel::CATEGORIES as $k => $label): ?>
                    <option value="<?= htmlspecialchars($k) ?>"><?= htmlspecialchars($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="severity">How badly does it affect you?</label>
            <select name="severity" id="severity" required>
                <option value="">Choose one</option>
                <?php foreach (TechIssueModel::SEVERITIES as $k => $label): ?>
                    <option value="<?= htmlspecialchars($k) ?>"><?= htmlspecialchars($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="title">Short title</label>
            <input type="text" name="title" id="title" required minlength="3" maxlength="160" placeholder="For example: Upload button does nothing">
        </div>
        <div class="field">
            <label for="page_url">Which page? (optional)</label>
            <input type="text" name="page_url" id="page_url" maxlength="255" placeholder="Paste the address from your browser">
        </div>
        <div class="field">
            <label for="description">What happened?</label>
            <textarea name="description" id="description" required minlength="10" maxlength="4000"
                placeholder="What you did, what you expected, and what happened instead. Do not include passwords."></textarea>
        </div>
        <button type="submit" class="send">Send report</button>
    </form>
<?php }

/** $viewer is 'reporter' or 'staff'. Staff also see internal notes and who on the team wrote each reply. */
function tech_thread(array $messages, string $viewer): void { ?>
    <div class="thread">
    <?php foreach ($messages as $m):
        $fromReporter = $m['sender'] === 'reporter';
        if ($viewer === 'reporter') {
            $who = $fromReporter ? 'You' : 'Technical team';
        } elseif ($fromReporter) {
            $who = 'Reporter';
        } else {
            $who = 'Technical team' . (!empty($m['author_email']) ? ' · ' . $m['author_email'] : '');
        }
        $internal = !empty($m['is_internal']); ?>
        <div class="msg <?= $internal ? 'internal' : ($fromReporter ? '' : 'theirs') ?>">
            <div class="who"><?= htmlspecialchars($who) ?><?= $internal ? ' (internal note, the reporter cannot see this)' : '' ?> &middot; <?= htmlspecialchars(tech_date($m['created_at'])) ?></div>
            <div class="txt"><?= htmlspecialchars($m['body']) ?></div>
        </div>
    <?php endforeach; ?>
    </div>
<?php }

/** Reply box. Staff get an internal-note switch and a status picker. */
function tech_reply_form(string $tracking, bool $staff, string $currentStatus = ''): void { ?>
    <form method="POST" class="tech-form">
        <?= CSRF::getInputField() ?>
        <?= CSRF::getSubmitField() ?>
        <input type="hidden" name="action" value="reply">
        <input type="hidden" name="tracking_id" value="<?= htmlspecialchars($tracking) ?>">
        <div class="field">
            <label for="body"><?= $staff ? 'Reply to the reporter' : 'Add a message' ?></label>
            <textarea name="body" id="body" maxlength="4000" <?= $staff ? '' : 'required' ?>></textarea>
        </div>
        <?php if ($staff): ?>
            <label class="chk"><input type="checkbox" name="internal" value="1"> Internal note only (the reporter will not see it)</label>
            <div class="field">
                <label for="status">Status</label>
                <select name="status" id="status">
                    <?php foreach (TechIssueModel::STATUSES as $s): ?>
                        <option value="<?= htmlspecialchars($s) ?>"<?= $s === $currentStatus ? ' selected' : '' ?>><?= htmlspecialchars($s) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>
        <button type="submit" class="send"><?= $staff ? 'Save' : 'Send message' ?></button>
    </form>
<?php }

/**
 * Handles POSTs from the member and team report pages (new report, or a reply on your own report),
 * then redirects. Does nothing on GET. Same CSRF and double-submit rules as the Team page.
 */
function tech_handle_reporter_post(TechIssueModel $model, string $type, string $ref, string $label, string $base): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;

    $go = function (string $suffix = '') use ($base) { header('Location: ' . $base . $suffix); exit; };

    if (!CSRF::validate($_POST['csrf_token'] ?? '')) {
        tech_flash('error', 'Your session expired. Please try again.');
        $go();
    }
    if (!CSRF::consumeSubmitToken($_POST['submit_token'] ?? '')) {
        $go();   // a double click: the first request already did the work
    }

    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'new') {
            [$clean, $error] = TechIssueModel::validate($_POST);
            if ($error) { tech_flash('error', $error); $go(); }
            $tracking = $model->create($type, $ref, $label, $clean);
            tech_flash('success', 'Report sent. Its reference is ' . $tracking . '. Replies will appear on this page.');
            $go('?id=' . rawurlencode($tracking));
        }
        if ($action === 'reply') {
            $tracking = (string) ($_POST['tracking_id'] ?? '');
            $issue = $model->getOwned($tracking, $type, $ref);
            $body  = trim((string) ($_POST['body'] ?? ''));
            if (!$issue) {
                tech_flash('error', 'That report was not found.');
                $go();
            }
            if (mb_strlen($body) < 2 || mb_strlen($body) > 4000) {
                tech_flash('error', 'Write a message of up to 4000 characters.');
            } else {
                $model->reporterReply($issue, $body);
                tech_flash('success', 'Message sent.');
            }
            $go('?id=' . rawurlencode($tracking));
        }
    } catch (Throwable $e) {
        error_log('Tech issue post failed: ' . $e->getMessage());
        tech_flash('error', $e instanceof RuntimeException ? $e->getMessage() : 'Something went wrong on our side. Please try again in a moment.');
        $go();
    }
    $go();
}
