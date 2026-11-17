<?php
/**
 * Shared top of the member sign-in pages (login, forgot, set password): the shared page frame, then an .auth-card.
 * Set before including: $pageTitle, $heading, $sub (optional). Uses $notice / $error if set.
 * Pair with _foot.php, which closes the card and prints the footer.
 */
header('Cache-Control: no-store');
require_once __DIR__ . '/../../includes/engage_page.php';

engage_header([
    'title'    => $pageTitle ?? 'Member sign in',
    'heading'  => $heading ?? 'DCW Engage',
    'lead'     => $sub ?? '',
    'auth'     => true,
    'noindex'  => true,
    'referrer' => 'no-referrer',   // these URLs can carry one-time tokens
]);
?>
<div class="auth-card">
    <?php if (!empty($notice)): ?><div class="alert ok" role="status"><?= htmlspecialchars($notice) ?></div><?php endif; ?>
    <?php if (!empty($error)): ?><div class="alert error" role="alert"><?= htmlspecialchars($error) ?></div><?php endif; ?>
