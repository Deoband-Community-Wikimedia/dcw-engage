<?php
/**
 * Shown when a requested form slug does not exist at all.
 *
 * engage_page.php is already loaded by renderer.php before this file is
 * included, so it is not required again here.
 */

// The old page had <meta name="robots" content="noindex">; the shared header has no such
// option, so send the equivalent as an HTTP header instead. The 404 status is also set here
// (harmless if the caller already did) so search engines do not treat this as a real page.
if (!headers_sent()) {
    http_response_code(404);
    header('X-Robots-Tag: noindex');
}

engage_header([
    'title'   => 'Form Not Found',
    'heading' => 'Form not found',
    'crumbs'  => [['Home', '/'], ['Form not found']],
]);
?>
<div class="fcard" style="max-width:520px; text-align:center; padding:40px 34px 32px;">
    <p style="margin:0 0 8px; font-size:56px; font-weight:800; letter-spacing:-1px; line-height:1.1; color:var(--primary);">404</p>
    <p style="margin:0; color:var(--muted); font-size:15px;">The form you are looking for does not exist. Please double-check the link you were given.</p>
    <a class="back-link" href="/" style="margin:24px 0 0;">&larr; Back to DCW Engage</a>
</div>
<?php engage_footer(); ?>
