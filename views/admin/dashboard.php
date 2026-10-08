<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../models/FormModel.php';
require_once __DIR__ . '/../../models/ApplicationModel.php';
require_once __DIR__ . '/../../models/MemberModel.php';
require_once __DIR__ . '/../../models/TechIssueModel.php';

Auth::requireLogin();

// Each flag mirrors the exact requireRole() call on the page it links to, so
// a link only ever appears for someone who can actually get past its gate:
//   application_review.php   -> requireRole(['organizer', 'owner'])   (program forms; membership forms are left out)
//   reimbursement_review.php -> requireRole(['support_reviewer', 'owner'])
//   internet_review.php      -> requireRole(['support_reviewer', 'owner'])
//   finance/queue.php        -> requireRole(['finance', 'owner'])   (combined: reimbursements + internet support)
//   finance/closed.php       -> requireRole(['finance', 'owner'])
//   membership_review.php    -> requireRole(['membership_coordinator', 'membership_reviewer', 'owner'])   (organizers: forms only)
//   team.php                 -> Auth::requireOwner()   (invites, roles and coordinator chapters)
//   member_support.php       -> requireRole(['member_support', 'owner'])   (shown as "DCW Support")
//   tech-issues.php, tech-diagnostics.php -> tech_require_staff()   (technical_manager, owner)
//   report-problem.php       -> any signed-in team account
// Auth::role() is only the PRIMARY role. An account can hold several, and
// requireRole() grants access when ANY matches, so use hasAnyRole() here too.
$canReviewApplications   = Auth::hasAnyRole(['organizer', 'owner']);
$canReviewReimbursements = Auth::hasAnyRole(['support_reviewer', 'owner']);
$canReviewInternet       = Auth::hasAnyRole(['support_reviewer', 'owner']);
$canProcessFinance       = Auth::hasAnyRole(['finance', 'owner']);
$canReviewMembership     = Auth::hasAnyRole(['membership_coordinator', 'membership_reviewer', 'owner']);
$canManageTeam           = Auth::isOwner();
$canWorkMemberSupport    = Auth::hasAnyRole(['member_support', 'owner']);
$canWorkTech             = Auth::hasAnyRole(TechIssueModel::STAFF_ROLES);

// Technical: open count for the queue tile, and this person's own reports that have a new reply.
$techOpen = 0; $techWaiting = [];
try {
    $techModel = new TechIssueModel();
    if ($canWorkTech) $techOpen = $techModel->openCount();
    $techWaiting = $techModel->awaitingReporter('team', strtolower((string) Auth::email()));
} catch (Throwable $e) { /* tables not created yet */ }

// Who gets the forms grid, and which forms they see. Mirrors
// FormModel::userCanOpen(), which form_manager.php enforces:
//   membership forms -> membership_reviewer, owner (all chapters);
//                       membership_coordinator (their own chapters only)
//   other forms      -> organizer, owner
// Hiding tiles is tidiness, not security.
$canManageForms        = Auth::hasAnyRole(['organizer', 'owner']);
$isCoordinator         = Auth::hasAnyRole(['membership_coordinator']);
$canSeeMembershipForms = Auth::hasAnyRole(['membership_reviewer', 'owner']) || $isCoordinator;
$canCreateForms        = $canManageForms || $isCoordinator;   // gates "Create a blank form"; the builder limits a coordinator to their chapters
$showFormsGrid         = $canCreateForms || $canSeeMembershipForms;

// Only a pure coordinator is limited to chapters; a reviewer sees them all.
$chapterLimited = Auth::hasAnyRole(['membership_coordinator'])
    && !Auth::hasAnyRole(['membership_reviewer', 'owner']);

$canReviewAny  = $canReviewReimbursements || $canReviewInternet;
$canSeeSupport = $canReviewAny || $canProcessFinance;

// Forms grid. Fetched after the role flags; each user only gets the forms
// they could actually open (FormModel::userCanOpen, same rule as form_manager.php).
$formModel = new FormModel();
$forms = array_filter($formModel->getAllForms(), fn($f) => FormModel::userCanOpen($f));

// Active forms show by default; closed ones sit behind a toggle.
$activeForms = array_filter($forms, fn($f) => !empty($f['is_active']));
$closedForms = array_filter($forms, fn($f) => empty($f['is_active']));

// Applications waiting for a first decision (New or Submitted), across the program forms this person can open.
// Shown as a badge on the "Application review" tile. Uses ApplicationModel::countAwaitingByForm();
// if that method is not added yet, or the query fails, the badge is simply left out.
$appsWaiting = 0;
if ($canReviewApplications) {
    try {
        $reviewable = [];
        foreach ($forms as $f) {
            if (!FormModel::isMembershipType($f['form_type'])) $reviewable[(int) $f['id']] = true;
        }
        foreach ((new ApplicationModel())->countAwaitingByForm() as $formId => $n) {
            if (isset($reviewable[(int) $formId])) $appsWaiting += (int) $n;
        }
    } catch (Throwable $e) { $appsWaiting = null; }
}

// Membership applications waiting for a first decision (New or Submitted), limited to the chapters this person
// may see (a coordinator sees only their own). Same idea as the badge above; left out if the query fails.
$membershipWaiting = 0;
if ($canReviewMembership) {
    try {
        $memberModel = new MemberModel();
        $memberScope = $memberModel->scopeFor(Auth::roles(), (string) Auth::email());   // null = every chapter
        foreach ($memberModel->listApplications('', '', $memberScope) as $r) {
            if (in_array($r['status'], ['New', 'Submitted'], true)) $membershipWaiting++;
        }
    } catch (Throwable $e) { $membershipWaiting = null; }
}

// The "Review" panel holds the review queues for applications and for membership.
$showReviewPanel = $canReviewApplications || $canReviewMembership;
$reviewIntro = ($canReviewApplications && $canReviewMembership)
    ? 'Decide on applications to our programs and on membership requests' . ($chapterLimited ? ' for your chapters' : '') . '.'
    : ($canReviewApplications
        ? 'Decide on applications to our programs, across every form in one list.'
        : 'Review new applications and renewals' . ($chapterLimited ? ' for your chapters' : '') . '.');

// Inner SVG markup for the card icons (24x24 viewBox, stroke icons).
$icons = [
    'doc'    => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/>',
    'plus'   => '<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>',
    'people' => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
    'key'    => '<circle cx="8" cy="15" r="4"/><line x1="10.85" y1="12.15" x2="19" y2="4"/><line x1="18" y1="5" x2="20" y2="7"/><line x1="15" y1="8" x2="17" y2="10"/>',
    'check'  => '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>',
    'inbox'  => '<polyline points="22 12 16 12 14 15 10 15 8 12 2 12"/><path d="M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>',
    'wifi'   => '<path d="M5 12.55a11 11 0 0 1 14.08 0"/><path d="M1.42 9a16 16 0 0 1 21.16 0"/><path d="M8.53 16.11a6 6 0 0 1 6.95 0"/><line x1="12" y1="20" x2="12.01" y2="20"/>',
    'card'   => '<rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/>',
    'archive'=> '<polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5"/><line x1="10" y1="12" x2="14" y2="12"/>',
    'chat'   => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
    'alert'  => '<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>',
    'pulse'  => '<polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>',
];

/**
 * Renders one compact tool tile. $badge (optional) replaces the arrow with a small pill, for a count that
 * should catch the eye, e.g. "5 waiting".
 */
function workspace_tile(array $icons, string $tone, string $icon, string $title, string $desc, string $href, string $badge = ''): void
{
    ?>
    <a class="tile" href="<?= htmlspecialchars($href) ?>" style="--tone: <?= htmlspecialchars($tone) ?>;">
        <span class="tick"><svg viewBox="0 0 24 24" aria-hidden="true"><?= $icons[$icon] ?></svg></span>
        <span><h3><?= htmlspecialchars($title) ?></h3><p><?= htmlspecialchars($desc) ?></p></span>
        <?php if ($badge !== ''): ?>
            <span class="pill badge-attn"><?= htmlspecialchars($badge) ?></span>
        <?php else: ?>
            <span class="arrow" aria-hidden="true">→</span>
        <?php endif; ?>
    </a>
    <?php
}

/**
 * Renders one large review card (the "Review" panel). $count: a number shows as a big figure
 * ("7 waiting"), 0 shows "All clear", null shows nothing (e.g. the Team card, or a count that could not be read).
 */
function review_card(array $icons, string $tone, string $icon, string $title, string $desc, string $href, ?int $count = null, string $cta = 'Open'): void
{
    ?>
    <a class="rcard" href="<?= htmlspecialchars($href) ?>" style="--tone: <?= htmlspecialchars($tone) ?>;">
        <span class="top">
            <span class="tick"><svg viewBox="0 0 24 24" aria-hidden="true"><?= $icons[$icon] ?></svg></span>
            <?php if ($count !== null && $count > 0): ?>
                <span class="count"><strong><?= (int) $count ?></strong><span>waiting</span></span>
            <?php elseif ($count === 0): ?>
                <span class="count clear"><strong>&#10003; All clear</strong><span>nothing waiting</span></span>
            <?php endif; ?>
        </span>
        <h3><?= htmlspecialchars($title) ?></h3>
        <p><?= htmlspecialchars($desc) ?></p>
        <span class="go"><?= htmlspecialchars($cta) ?> <span aria-hidden="true">&rarr;</span></span>
    </a>
    <?php
}

/** Renders one form tile (active or closed). */
function form_tile(array $icons, array $form): void
{
    $active = !empty($form['is_active']);
    $count  = (int) $form['applicant_count'];
    $tone   = $active ? 'var(--leaf-dark, #3E7A53)' : '#94a3b8';
    ?>
    <a class="tile" href="/admin/form_manager?id=<?= (int) $form['id'] ?>" style="--tone: <?= $tone ?>;">
        <span class="tick"><svg viewBox="0 0 24 24" aria-hidden="true"><?= $icons['doc'] ?></svg></span>
        <span>
            <h3><?= htmlspecialchars($form['title']) ?></h3>
            <p>/<?= htmlspecialchars($form['form_type']) ?> &middot; <?= $count ?> response<?= $count !== 1 ? 's' : '' ?></p>
        </span>
        <span class="pill"><?= $active ? 'Active' : 'Closed' ?></span>
    </a>
    <?php
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <?php require __DIR__ . '/../../includes/favicon.php'; ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#2E6599">
    <meta name="robots" content="noindex, nofollow">
    <title>Organizer workspace - DCW Engage</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/engage.css?v=2">
    <style>
        .hero { padding-bottom: 44px; }
        /* Wider tiles so titles and paths are not squeezed */
        .tiles { grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); }
        @media (max-width: 640px) { .tiles { grid-template-columns: 1fr; } }

        /* Large review cards */
        .rcards { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 18px; }
        .rcard {
            --tone: var(--primary); position: relative; overflow: hidden; display: flex; flex-direction: column; gap: 6px;
            padding: 24px 24px 20px; text-decoration: none; color: inherit;
            background: var(--card); border: 1px solid var(--border); border-radius: 18px;
            box-shadow: 0 6px 18px rgba(15,23,42,.06);
            transition: transform .18s, box-shadow .18s, border-color .18s;
        }
        .rcard::before { content: ''; position: absolute; inset: 0 0 auto 0; height: 5px; background: var(--tone); }
        .rcard:hover { transform: translateY(-4px); box-shadow: 0 16px 34px rgba(15,23,42,.14); border-color: var(--tone); }
        .rcard .top { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; margin-bottom: 10px; }
        .rcard .tick { width: 50px; height: 50px; border-radius: 14px; display: grid; place-items: center; background: color-mix(in srgb, var(--tone) 14%, #fff); }
        .rcard .tick svg { width: 24px; height: 24px; stroke: var(--tone); fill: none; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
        .rcard .count { text-align: right; line-height: 1.1; }
        .rcard .count strong { display: block; font-size: 38px; font-weight: 800; letter-spacing: -1px; color: var(--accent); }
        .rcard .count span { display: block; margin-top: 2px; font-size: 11.5px; font-weight: 700; letter-spacing: .1em; text-transform: uppercase; color: var(--muted); }
        .rcard .count.clear strong { font-size: 16px; letter-spacing: 0; padding-top: 10px; color: var(--leaf-dark, #3E7A53); }
        .rcard h3 { margin: 0; font-size: 20px; font-weight: 800; letter-spacing: -.01em; }
        .rcard p { margin: 0; flex: 1; color: var(--muted); font-size: 14.5px; }
        .rcard .go { margin-top: 14px; color: var(--tone); font-weight: 700; font-size: 14.5px; }
        .rcard:hover .go span { display: inline-block; transform: translateX(4px); transition: transform .15s; }
        .wrap.cards-wrap { margin-top: 34px; }
        .closed-forms { margin-top: 18px; }
        .closed-forms summary { cursor: pointer; font-weight: 600; color: var(--muted); padding: 6px 0; }
        .closed-forms .tiles { margin-top: 12px; }
        /* Count badge on a tile: crimson from the logo, the one color that should catch the eye */
        .pill.badge-attn { color: #fff; background: var(--accent); padding: 3px 12px; font-size: 12px; }
    </style>
</head>
<body>
    <header class="hero">
        <div class="topbar">
            <a class="brand" href="/admin/dashboard">
                <img src="https://dcwwiki.org/dcwwiki/images/5/56/DCW_logo.png" alt="">
                <span>DCW Engage</span>
            </a>
            <div class="tools">
                <span class="who"><?= htmlspecialchars(Auth::email()) ?></span>
                <?php if (Auth::isOwner()): ?>
                    <a href="/admin/audit" class="chip-btn">Audit log</a>
                <?php endif; ?>
                <form method="POST" action="/admin/logout">
                    <?= CSRF::getInputField() ?>
                    <button type="submit" class="chip-btn">Sign out</button>
                </form>
            </div>
        </div>
        <p class="kicker">Organizer workspace</p>
        <h1>Workspace</h1>
        <p class="lead">Everything you can review, approve and manage, in one place.</p>
    </header>

    <main class="wrap wide cards-wrap">

        <?php foreach ($techWaiting as $t): ?>
            <div class="action-banner" role="status">
                <strong>Technical team:</strong>
                <span>New reply on &ldquo;<?= htmlspecialchars($t['title']) ?>&rdquo;.</span>
                <a href="/admin/report-problem?id=<?= htmlspecialchars(rawurlencode($t['tracking_id'])) ?>">Read it</a>
            </div>
        <?php endforeach; ?>

        <?php if ($showReviewPanel): ?>
            <section class="panel">
                <div class="panel-head">
                    <h2>Review</h2>
                    <p><?= htmlspecialchars($reviewIntro) ?></p>
                </div>
                <div class="rcards">
                    <?php
                    if ($canReviewApplications) {
                        review_card($icons, 'var(--primary)', 'inbox', 'Application review',
                            'Responses to every program form, grouped by program. Accept, reject or send back.',
                            '/admin/application-review', $appsWaiting, 'Open the queue');
                    }
                    if ($canReviewMembership) {
                        review_card($icons, 'var(--accent)', 'people', 'Membership review',
                            'New applications and renewals. Approve, reject, or ask for more information.',
                            '/admin/membership-review', $membershipWaiting, 'Open the queue');
                    }
                    if ($canManageTeam) {
                        review_card($icons, 'var(--leaf-dark, #3E7A53)', 'key', 'Team',
                            'Invite people, change roles, and choose which chapters each coordinator sees.',
                            '/admin/team', null, 'Manage team');
                    }
                    ?>
                </div>
            </section>
        <?php endif; ?>

        <?php if ($showFormsGrid): ?>
        <section class="panel">
            <div class="panel-head">
                <h2>Application forms</h2>
                <p><?= $canCreateForms
                    ? 'Create, open and close the forms volunteers apply through, and review responses.'
                    : 'Review responses to membership forms.' ?></p>
            </div>
            <div class="tiles">
                <?php if ($canCreateForms): ?>
                <a href="/admin/builder" class="tile new" style="--tone: var(--primary);">
                    <span class="tick"><svg viewBox="0 0 24 24" aria-hidden="true"><?= $icons['plus'] ?></svg></span>
                    <span><h3>Create a blank form</h3></span>
                </a>
                <?php endif; ?>
                <?php foreach ($activeForms as $form) form_tile($icons, $form); ?>
            </div>

            <?php if ($closedForms): ?>
                <details class="closed-forms">
                    <summary>Closed forms (<?= count($closedForms) ?>)</summary>
                    <div class="tiles">
                        <?php foreach ($closedForms as $form) form_tile($icons, $form); ?>
                    </div>
                </details>
            <?php endif; ?>
        </section>
        <?php endif; ?>

        <?php if ($canSeeSupport): ?>
            <section class="panel">
                <div class="panel-head">
                    <h2>Volunteer support</h2>
                    <p>Reimbursements and internet support, from review to payment.</p>
                    <div class="legend">
                        <?php if ($canReviewAny): ?><span style="--tone:var(--leaf-dark, #3E7A53)"><i></i>Review</span><?php endif; ?>
                        <?php if ($canProcessFinance): ?><span style="--tone:#b45309"><i></i>Finance</span><?php endif; ?>
                    </div>
                </div>
                <div class="tiles">
                    <?php
                    if ($canReviewReimbursements) {
                        workspace_tile($icons, 'var(--leaf-dark, #3E7A53)', 'check', 'Reimbursement review',
                            'Approve or reject claims. Line items and receipts only, no payment details.', '/admin/reimbursements/review');
                    }
                    if ($canReviewInternet) {
                        workspace_tile($icons, 'var(--leaf-dark, #3E7A53)', 'wifi', 'Internet support review',
                            'Judge reasons and packages. No phone numbers shown.', '/admin/internet-review');
                    }
                    if ($canProcessFinance) {
                        workspace_tile($icons, '#b45309', 'card', 'Finance queue',
                            'Pay claims, recharge numbers and check receipts, one tab for each.', '/finance');
                        workspace_tile($icons, '#b45309', 'archive', 'Closed requests',
                            'Paid and closed requests, with a receipt PDF for each.', '/finance/closed');
                    }
                    ?>
                </div>
            </section>
        <?php endif; ?>

        <?php if ($canWorkMemberSupport): ?>
            <section class="panel">
                <div class="panel-head">
                    <h2>Member conversations</h2>
                    <p>Complaints, suggestions and questions that members send from their dashboard.</p>
                </div>
                <div class="tiles">
                    <?php
                    workspace_tile($icons, 'var(--primary)', 'chat', 'DCW Support',
                        'Read and answer member conversations. Replies are emailed as a notification only.', '/admin/member-support');
                    ?>
                </div>
            </section>
        <?php endif; ?>

        <?php if ($canWorkTech): ?>
            <section class="panel">
                <div class="panel-head">
                    <h2>Technical</h2>
                    <p>Problems reported by members and team people, and portal health checks.</p>
                </div>
                <div class="tiles">
                    <?php
                    workspace_tile($icons, 'var(--primary-dark)', 'alert', 'Reported problems',
                        $techOpen > 0 ? $techOpen . ' open. Reporters show only as "Member" or by team email.' : 'Nothing open right now.',
                        '/admin/tech-issues');
                    workspace_tile($icons, 'var(--primary-dark)', 'pulse', 'Diagnostics',
                        'Read-only health checks: server, database, security and storage.', '/admin/tech-diagnostics');
                    ?>
                </div>
            </section>
        <?php endif; ?>

        <section class="panel">
            <div class="panel-head">
                <h2>Something not working?</h2>
                <p>Tell the technical team about a problem with this workspace.</p>
            </div>
            <div class="tiles">
                <?php
                workspace_tile($icons, 'var(--primary-dark)', 'alert', 'Report a problem',
                    'Send a report and follow the replies here.', '/admin/report-problem');
                ?>
            </div>
        </section>

    </main>

    <footer>
        <div class="org">
            <img src="https://dcwwiki.org/dcwwiki/images/5/56/DCW_logo.png" alt="">
            <span>Deoband Community Wikimedia</span>
        </div>
        <div>&copy; <?= date('Y') ?> · <a href="/">Public home page</a></div>
    </footer>
</body>
</html>
