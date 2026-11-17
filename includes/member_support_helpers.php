<?php
/**
 * DCW Engage - shared helpers for the member support views
 * (views/member/ticket_new.php, views/member/ticket.php, views/admin/member_support.php).
 *
 * Uses the project's own CSRF class (validate / getInputField / getSubmitField / consumeSubmitToken).
 *
 * Styles: the .ms-* rules live in assets/css/engage.css (section "Member support"), written with the
 * shared colour tokens, so the same markup is blue on member pages and teal on staff pages.
 * ms_styles() is kept (and returns nothing) so pages that still call it keep working.
 */
require_once __DIR__ . '/engage_page.php';

function ms_e($v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }

/** UTC from the database, shown in IST. */
function ms_date(?string $utc, bool $withTime = false): string
{
    if (!$utc) return '';
    $d = new DateTimeImmutable($utc, new DateTimeZone('UTC'));
    return $d->setTimezone(new DateTimeZone('Asia/Kolkata'))->format($withTime ? 'j M Y, g:i a' : 'j M Y');
}

/**
 * Colour for a status pill, as a CSS value for the --tone variable. Uses the page's own tokens, with the old
 * colour as the fallback for tokens a page does not define (the staff scheme defines --amber, --violet, --slate).
 */
function ms_tone(string $status): string
{
    switch ($status) {
        case 'Awaiting member':
        case 'Under consideration': return 'var(--amber, #b45309)';
        case 'Resolved':
        case 'Done':                return 'var(--leaf-dark, #15803d)';
        case 'Planned':             return 'var(--violet, #0f766e)';
        case 'Not planned':         return 'var(--slate, #6b7280)';
        default:                    return 'var(--primary, #106b9a)';
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
/** The styles live in engage.css. Kept so existing calls (echo ms_styles()) still work. */
function ms_styles(): string
{
    return '';
}

// ---------------------------------------------------------------- member page chrome
/**
 * Member support pages (/member/talk and its conversations) use the shared page frame: the member menu,
 * the chapter colours and the shared footer. One back link: a conversation goes back to the list, the list
 * goes back to the dashboard. $links and $kicker are accepted for old callers and ignored.
 * Opens <main>; ms_foot() closes it.
 */
function ms_head(string $title, string $h1, string $lead, array $links = [], string $kicker = ''): void
{
    $member = class_exists('MemberSession') ? MemberSession::current() : null;
    $path   = (string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);

    $back = $path !== '/member/talk'
        ? ['Talk to DCW', '/member/talk']
        : ['My dashboard', '/member/dashboard'];

    engage_header([
        'title'   => $title,
        'heading' => $h1,
        'lead'    => $lead,
        'member'  => $member,
        'tools'   => $member ? engage_member_menu($member, ['current' => '/member/talk']) : null,
        'back'    => $back,
        'wide'    => true,
    ]);
    echo ms_flash_html();
}

function ms_foot(): void
{
    engage_footer();
}
