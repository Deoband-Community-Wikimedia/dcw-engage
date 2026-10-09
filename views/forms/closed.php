<?php
/**
 * Shown when a form exists but has been closed (by an organizer, or automatically
 * by its deadline).
 * Expects $closedTitle to be set by the caller (renderer.php). $closedDeadline is
 * set only when the form closed because its deadline passed.
 *
 * engage_page.php is already loaded by renderer.php before this file is
 * included, so it is not required again here.
 */

$closedTitle = $closedTitle ?? 'This form';
$closedDeadline = $closedDeadline ?? null;   // set by renderer.php when the form closed by deadline

// The old page had <meta name="robots" content="noindex">; the shared header has no such
// option, so send the equivalent as an HTTP header instead.
if (!headers_sent()) {
    header('X-Robots-Tag: noindex');
}

engage_header([
    'title'   => 'Applications Closed',
    'heading' => 'Applications are closed',
    'crumbs'  => [['Home', '/'], ['Applications closed']],
]);
?>
<div class="fcard" style="max-width:520px; text-align:center; padding:40px 34px 32px;">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"
        aria-hidden="true" style="width:56px; height:56px; margin:0 auto 20px; display:block; color:var(--primary);">
        <rect x="3" y="11" width="18" height="11" rx="2"></rect>
        <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
    </svg>
    <?php if (!empty($closedDeadline)): ?>
        <p style="margin:0 0 8px; font-size:16px;"><strong><?= htmlspecialchars($closedTitle) ?></strong> stopped accepting applications on
            <strong><?= htmlspecialchars(FormModel::formatIst($closedDeadline)) ?></strong>.</p>
    <?php else: ?>
        <p style="margin:0 0 8px; font-size:16px;"><strong><?= htmlspecialchars($closedTitle) ?></strong> is no longer accepting submissions.</p>
    <?php endif; ?>
    <p style="margin:0; color:var(--muted); font-size:15px;">If you have already applied, the magic link sent to your email still lets you view your application.</p>
    <a class="back-link" href="/" style="margin:24px 0 0;">&larr; Back to DCW Engage</a>
</div>
<?php engage_footer(); ?>
