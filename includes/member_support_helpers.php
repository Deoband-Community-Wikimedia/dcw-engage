<?php
/**
 * DCW Engage - shared helpers for the member support views
 * (views/member/ticket_new.php, views/member/ticket.php, views/admin/member_support.php).
 *
 * Uses the project's own CSRF class (validate / getInputField / getSubmitField / consumeSubmitToken).
 */

function ms_e($v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }

/** UTC from the database, shown in IST. */
function ms_date(?string $utc, bool $withTime = false): string
{
    if (!$utc) return '';
    $d = new DateTimeImmutable($utc, new DateTimeZone('UTC'));
    return $d->setTimezone(new DateTimeZone('Asia/Kolkata'))->format($withTime ? 'j M Y, g:i a' : 'j M Y');
}

function ms_tone(string $status): string
{
    switch ($status) {
        case 'Awaiting member':
        case 'Under consideration': return '#b45309';
        case 'Resolved':
        case 'Done':                return '#15803d';
        case 'Planned':             return '#0f766e';
        case 'Not planned':         return '#6b7280';
        default:                    return '#106b9a';
    }
}

// ---------------------------------------------------------------- CSRF
/** Both hidden fields every form needs: the CSRF token and the single-use submit token. */
function ms_csrf_field(): string { return CSRF::getInputField() . CSRF::getSubmitField(); }

function ms_csrf_valid(): bool { return (bool) CSRF::validate($_POST['csrf_token'] ?? ''); }

/**
 * True the first time a rendered form is submitted, false for a double click. Callers drop the
 * duplicate silently (redirect, no flash), so the first submission's result is what the person sees.
 */
function ms_submit_once(): bool { return (bool) CSRF::consumeSubmitToken($_POST['submit_token'] ?? ''); }

// ---------------------------------------------------------------- flash messages (post/redirect/get)
function ms_flash(string $kind, string $msg): void { $_SESSION['ms_flash'] = [$kind, $msg]; }

function ms_flash_html(): string
{
    if (empty($_SESSION['ms_flash'])) return '';
    [$kind, $msg] = $_SESSION['ms_flash'];
    unset($_SESSION['ms_flash']);
    return '<div class="' . ($kind === 'ok' ? 'ms-ok' : 'ms-error') . '" role="status">' . ms_e($msg) . '</div>';
}

// ---------------------------------------------------------------- styles
function ms_styles(): string
{
    return <<<'CSS'
<style>
    .ms-form label { display: block; font-weight: 600; margin: 16px 0 6px; }
    .ms-form input[type=text], .ms-form textarea, .ms-form select { width: 100%; padding: 11px 12px; border: 1px solid #cbd5e1; border-radius: 10px; font: inherit; box-sizing: border-box; background: #fff; }
    .ms-form textarea { min-height: 160px; resize: vertical; }
    .ms-form .hint { color: #64748b; font-size: 13px; margin: 6px 0 0; font-weight: 400; }
    .ms-types { display: grid; gap: 10px; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); }
    .ms-types label { border: 1px solid #cbd5e1; border-radius: 12px; padding: 12px 14px; cursor: pointer; margin: 0; font-weight: 600; background: #fff; }
    .ms-types label span { display: block; font-weight: 400; font-size: 13px; color: #64748b; margin-top: 4px; }
    .ms-check { display: flex; gap: 10px; align-items: flex-start; margin-top: 14px; font-weight: 500; }
    .ms-check input { margin-top: 4px; }
    .ms-actions { margin-top: 20px; display: flex; gap: 12px; align-items: center; flex-wrap: wrap; }
    .ms-thread { display: grid; gap: 12px; margin-bottom: 8px; }
    .ms-msg { border-radius: 14px; padding: 12px 16px; max-width: 760px; white-space: pre-wrap; overflow-wrap: anywhere; border: 1px solid #e2e8f0; background: #fff; }
    .ms-msg.mine { background: #eef6fb; border-color: #cfe3ef; margin-left: auto; }
    .ms-msg.note { background: #fffbeb; border-color: #fde68a; }
    .ms-msg small { display: block; color: #64748b; margin-bottom: 4px; font-size: 12px; font-weight: 600; }
    .ms-error, .ms-ok { padding: 12px 14px; border-radius: 10px; margin-bottom: 14px; }
    .ms-error { background: #fef2f2; border: 1px solid #fecaca; color: #97161b; }
    .ms-ok { background: #f0fdf4; border: 1px solid #bbf7d0; color: #15803d; }
    .ms-meta { color: #64748b; font-size: 14px; margin: 6px 0 0; }
    .ms-filters { display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 14px; align-items: center; }
    .ms-filters label { margin: 0; }
    .ms-filters select { width: auto; padding: 8px 10px; border: 1px solid #cbd5e1; border-radius: 10px; font: inherit; background: #fff; }
    .ms-scroll { overflow-x: auto; }
    table.ms-table { width: 100%; border-collapse: collapse; min-width: 720px; }
    .ms-table th, .ms-table td { text-align: left; padding: 10px 8px; border-bottom: 1px solid #e2e8f0; font-size: 14px; vertical-align: top; }
    .ms-table tr.wait td:first-child { box-shadow: inset 3px 0 0 #b45309; }
    .ms-tag { display: inline-block; font-size: 12px; font-weight: 700; padding: 2px 8px; border-radius: 999px; background: #fef2f2; color: #97161b; margin-left: 6px; }
</style>
CSS;
}

// ---------------------------------------------------------------- member page chrome
/** $links: [[href, label], ...] shown as buttons in the top bar. Opens <main>; ms_foot() closes it. */
function ms_head(string $title, string $h1, string $lead, array $links = [], string $kicker = ''): void
{
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <?php require __DIR__ . '/favicon.php'; ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#0c567a">
    <meta name="robots" content="noindex, nofollow">
    <title><?= ms_e($title) ?> - DCW Engage</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/engage.css?v=2">
    <style>.hero { padding-bottom: 44px; } .wrap.cards-wrap { margin-top: 34px; }</style>
    <?= ms_styles() ?>
</head>
<body>
    <header class="hero">
        <div class="topbar">
            <a class="brand" href="/">
                <img src="https://dcwwiki.org/dcwwiki/images/5/56/DCW_logo.png" alt="">
                <span>DCW Engage</span>
            </a>
            <div class="tools">
                <?php foreach ($links as $l): ?>
                    <a href="<?= ms_e($l[0]) ?>" class="chip-btn"><?= ms_e($l[1]) ?></a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php if ($kicker !== ''): ?><p class="kicker"><?= ms_e($kicker) ?></p><?php endif; ?>
        <h1><?= ms_e($h1) ?></h1>
        <p class="lead"><?= ms_e($lead) ?></p>
    </header>

    <main class="wrap wide cards-wrap">
        <?= ms_flash_html() ?>
<?php
}

function ms_foot(): void
{
    ?>
    </main>

    <footer>
        <div class="org">
            <img src="https://dcwwiki.org/dcwwiki/images/5/56/DCW_logo.png" alt="">
            <span>Deoband Community Wikimedia</span>
        </div>
        <div>&copy; <?= date('Y') ?> · <a href="/">dcwwiki.org</a></div>
    </footer>
</body>
</html>
<?php
}
