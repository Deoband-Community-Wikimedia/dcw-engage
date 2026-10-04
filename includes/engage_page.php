<?php
/**
 * Compact DCW Engage page shell.
 * Usage: engage_header([...]); ...content...; engage_footer();
 *
 * Options:
 *   title    <title> text (" - DCW Engage" is added)
 *   heading  big heading in the hero (defaults to title)
 *   kicker   small pill above the heading
 *   lead     optional sentence under the heading
 *   member   MemberSession::current() row, or null
 *   tools    raw, already-escaped HTML for the top-bar buttons (overrides the member links)
 *   wide     true for wide layouts (finance queue and tables)
 */
function engage_header(array $o) {
    $title   = $o['title'];
    $heading = $o['heading'] ?? $title;
    $kicker  = $o['kicker'] ?? 'Deoband Community Wikimedia';
    $lead    = $o['lead'] ?? '';
    $member  = $o['member'] ?? null;
    $tools   = $o['tools'] ?? null;
    $wide    = !empty($o['wide']);
    $e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#0c567a">
    <title><?= $e($title) ?> - DCW Engage</title>
    <?php require __DIR__ . '/favicon.php'; ?>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/engage.css">
</head>
<body>
<header class="hero">
    <div class="topbar">
        <a class="brand" href="/"><img src="https://dcwwiki.org/dcwwiki/images/5/56/DCW_logo.png" alt="">DCW Engage</a>
        <div class="tools">
            <?php if ($tools !== null): ?>
                <?= $tools ?>
            <?php elseif ($member): ?>
                <span class="who"><?= $e($member['full_name'] ?: $member['member_id']) ?> (<?= $e($member['member_id']) ?>)</span>
                <a class="chip-btn" href="/member/dashboard">My dashboard</a>
                <a class="chip-btn" href="/member/logout">Sign out</a>
            <?php else: ?>
                <a class="chip-btn" href="/">All programs</a>
            <?php endif; ?>
        </div>
    </div>
    <p class="kicker"><?= $e($kicker) ?></p>
    <h1><?= $e($heading) ?></h1>
    <?php if ($lead): ?><p class="lead"><?= $e($lead) ?></p><?php endif; ?>
</header>
<main class="wrap cards-wrap<?= $wide ? ' wide' : '' ?>">
<?php
}

function engage_footer() { ?>
</main>
<footer>
    <div class="org">
        <img src="https://dcwwiki.org/dcwwiki/images/5/56/DCW_logo.png" alt="">
        <span>Deoband Community Wikimedia</span>
    </div>
    <div>&copy; <?= date('Y') ?> · <a href="https://dcwwiki.org">dcwwiki.org</a></div>
</footer>
</body>
</html>
<?php }
