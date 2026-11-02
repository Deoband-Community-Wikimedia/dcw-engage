<?php
/**
 * Generic 404 page for the whole Engage site: a form slug that does not exist, a mistyped address,
 * a removed page, anything. Include it (or call it from the router's fallback) and exit.
 *
 * Expects engage_page.php to be loaded already. The require_once below is only a safety net.
 *
 * Optional: $notFoundWhat (string) is a short note for the server log, e.g. "form slug: abc".
 * It is never printed on the page.
 */

if (!function_exists('engage_header')) {
    require_once __DIR__ . '/engage_page.php';
}

// Status and robots headers must go out before any output. If output has already started they cannot be
// changed, so skip them quietly rather than raise a warning on top of the page.
if (!headers_sent()) {
    http_response_code(404);
    header('X-Robots-Tag: noindex, nofollow');
    header('Cache-Control: no-store');   // something may exist at this address later
}

// One log line makes dead links easy to find. Path only (no query string, which could hold tokens),
// stripped of control characters and cut short so it cannot forge log lines.
$nfPath = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$nfNote = (isset($notFoundWhat) && is_string($notFoundWhat)) ? ' (' . $notFoundWhat . ')' : '';
app_log('404 ' . substr(preg_replace('/[^\x20-\x7E]/', '?', $nfPath . $nfNote), 0, 200));

// Signed-in members see their chapter colours and menu, like on every other member page. The member
// session is read only if a session is already running (this file must never start one), and any failure
// falls back to the default DCW look, so this page cannot break because of the session.
$nfMember = null;
if (class_exists('MemberSession') && session_status() === PHP_SESSION_ACTIVE) {
    try {
        $nfMember = MemberSession::current() ?: null;
    } catch (Throwable $ex) {
        $nfMember = null;
    }
}

$nfOptions = [
    'title'   => 'Page Not Found',
    'heading' => 'Page not found',
    'crumbs'  => [['Home', '/'], ['Page not found']],
];
if ($nfMember) {
    $nfOptions['member'] = $nfMember;
    try {
        $nfOptions['tools'] = engage_member_menu($nfMember);
    } catch (Throwable $ex) {
        unset($nfOptions['tools']);
    }
}

engage_header($nfOptions);
?>
<div class="fcard" style="max-width:520px; text-align:center; padding:40px 34px 32px;">
    <p style="margin:0 0 8px; font-size:56px; font-weight:800; letter-spacing:-1px; line-height:1.1; color:var(--primary);">404</p>
    <p style="margin:0 0 8px; font-size:16px;"><strong>We couldn't find that page.</strong></p>
    <p style="margin:0; color:var(--muted); font-size:15px;">The link may be mistyped or out of date, or the page may have been removed. Please double-check the address you were given.</p>
    <p style="margin:18px 0 0; color:var(--muted); font-size:14px;">Already applied? <a href="/track">Track your application</a>.</p>
    <a class="back-link" href="/" style="margin:24px 0 0;">&larr; Back to DCW Engage</a>
</div>
<?php engage_footer(); ?>
