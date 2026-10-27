<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/engage_page.php';
require_once __DIR__ . '/../../models/FormModel.php';
require_once __DIR__ . '/../../models/FeedbackModel.php';
require_once __DIR__ . '/../../models/ApplicationModel.php';
require_once __DIR__ . '/../../models/MemberModel.php';
require_once __DIR__ . '/../../models/TechIssueModel.php';
require_once __DIR__ . '/../../models/PointsModel.php';

Auth::requireLogin();

// Each flag mirrors the exact requireRole() call on the page it links to, so
// a link only ever appears for someone who can actually get past its gate:
//   application_review.php   -> requireRole(['organizer', 'owner'])   (program forms; membership and feedback forms are left out)
//   feedback_review.php      -> requireRole(['owner', 'organizer'])   (responses to feedback forms; has its own Feedback tab here)
//   reimbursement_review.php -> requireRole(['support_reviewer', 'owner'])
//   internet_review.php      -> requireRole(['support_reviewer', 'owner'])
//   goodies.php              -> support_reviewer or owner (checked with Auth::hasAnyRole inside the view)
//   finance/queue.php        -> requireRole(['finance', 'owner'])   (combined: reimbursements + internet support)
//   finance/closed.php       -> requireRole(['finance', 'owner'])
//   membership_review.php    -> requireRole(['membership_coordinator', 'membership_reviewer', 'owner'])   (organizers: forms only)
//                               Member card PDFs are reached from there (Members tab, or inside an application).
//   community_records.php    -> requireRole(['membership_reviewer', 'owner'])   (Conversation Hour attendance, technical contributors)
//   team.php                 -> Auth::requireOwner()   (invites, roles and coordinator chapters)
//   member_support.php       -> requireRole(['member_support', 'owner'])   (shown as "DCW Support")
//   tech-issues.php, tech-diagnostics.php -> tech_require_staff()   (technical_manager, owner)
//   report-problem.php       -> any signed-in team account
// Auth::role() is only the PRIMARY role. An account can hold several, and
// requireRole() grants access when ANY matches, so use hasAnyRole() here too.
$canReviewApplications   = Auth::hasAnyRole(['organizer', 'owner']);
$canReadFeedback         = Auth::hasAnyRole(['organizer', 'owner']);   // mirrors feedback_review.php
$canReviewReimbursements = Auth::hasAnyRole(['support_reviewer', 'owner']);
$canReviewInternet       = Auth::hasAnyRole(['support_reviewer', 'owner']);
$canManageGoodies        = Auth::hasAnyRole(['support_reviewer', 'owner']);   // mirrors goodies.php
$canProcessFinance       = Auth::hasAnyRole(['finance', 'owner']);
$canReviewMembership     = Auth::hasAnyRole(['membership_coordinator', 'membership_reviewer', 'owner']);
$canManageTeam           = Auth::isOwner();
$canWorkMemberSupport    = Auth::hasAnyRole(['member_support', 'owner']);
$canWorkTech             = Auth::hasAnyRole(TechIssueModel::STAFF_ROLES);
$canManageRecords        = Auth::hasAnyRole(['membership_reviewer', 'owner']);   // mirrors community_records.php

// Technical: open count for the queue tile, and this person's own reports that have a new reply.
$techOpen = 0; $techWaiting = [];
try {
    $techModel = new TechIssueModel();
    if ($canWorkTech) $techOpen = $techModel->openCount();
    $techWaiting = $techModel->awaitingReporter('team', strtolower((string) Auth::email()));
} catch (Throwable $e) { app_log_ignored('dashboard.php:47', $e); /* tables not created yet */ }

// Goodies: awards waiting for a second reviewer plus new orders, shown as the badge on the Goodies tab.
// If the goodies tables have not been created yet, the badge is simply left out.
$goodiesWaiting = 0;
if ($canManageGoodies) {
    try {
        $goodiesTotals  = (new PointsModel())->totals();
        $goodiesWaiting = (int) $goodiesTotals['awaiting_second'] + (int) $goodiesTotals['requested'];
    } catch (Throwable $e) { app_log_ignored('dashboard.php:goodies', $e); /* tables not created yet */ }
}

// Who gets the forms grid, and which forms they see. Mirrors
// FormModel::userCanOpen(), which form_manager.php enforces:
//   membership forms -> membership_reviewer, owner (all chapters);
//                       membership_coordinator (their own chapters only)
//   other forms      -> organizer, owner
// Hiding tiles is tidiness, not security.
$canManageForms        = Auth::hasAnyRole(['organizer', 'owner']);
$isCoordinator         = Auth::hasAnyRole(['membership_coordinator']);
$canSeeMembershipForms = Auth::hasAnyRole(['membership_reviewer', 'owner']) || $isCoordinator;
$canCreateForms        = $canManageForms || $isCoordinator;   // gates "New form"; the builder limits a coordinator to their chapters
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

// Feedback forms keep their responses in feedback_responses, not applications, so getAllForms()'s
// applicant_count is always 0 for them. Fetch the real counts here: form id => response count.
// If the feedback table has not been created yet, the counts are simply left empty.
$feedbackCounts = [];
try {
    foreach ((new FeedbackModel())->feedbackForms() as $fbForm) {
        $feedbackCounts[(int) $fbForm['id']] = (int) $fbForm['response_count'];
    }
} catch (Throwable $e) { app_log_ignored('dashboard.php:81', $e); /* migration not run yet */ }

// Application forms and feedback forms are shown separately: feedback has responses, not applications.
$isFeedbackForm = fn($f) => FeedbackModel::isFeedbackSchema(json_decode((string) ($f['schema_json'] ?? ''), true));
$feedbackForms  = array_filter($forms, $isFeedbackForm);
$appForms       = array_filter($forms, fn($f) => !$isFeedbackForm($f));

// Open forms show by default; closed ones sit behind a toggle. "Open" means switched on AND not past its
// deadline (FormModel::isOpen), so a form that closed by deadline moves to the closed group on its own.
$activeForms = array_filter($appForms, fn($f) => FormModel::isOpen($f));
$closedForms = array_filter($appForms, fn($f) => !FormModel::isOpen($f));

$fbActive = array_filter($feedbackForms, fn($f) => FormModel::isOpen($f));
$fbClosed = array_filter($feedbackForms, fn($f) => !FormModel::isOpen($f));
$fbTotal  = 0;
foreach ($feedbackForms as $f) $fbTotal += (int) ($feedbackCounts[(int) $f['id']] ?? 0);

// Programs that are not shown in the review queue: closed forms, and test forms.
// A form counts as a test form when "test"/"testing"/"dummy" is a whole word in its title or URL slug
// (so "Test form" and "scholarship-test" match, "Contest 2026" does not). Add exact slugs to $extra to hide others.
// KEEP IN SYNC with the same function in views/admin/dashboard.php and application_review.php.
// Note: this looks at is_active only, NOT the deadline, on purpose. A form that closed by deadline is
// exactly the one whose responses still need reviewing, so it stays in the review queue.
if (!function_exists('review_hidden_form')) {
    function review_hidden_form(array $form, array $extra = []): bool {
        if (empty($form['is_active'])) return true;
        if (in_array((string) $form['form_type'], $extra, true)) return true;
        return (bool) preg_match('/(^|[^a-z])(test|testing|dummy)([^a-z]|$)/i',
            (string) ($form['title'] ?? '') . ' ' . (string) $form['form_type']);
    }
}

// Applications waiting for a first decision (New or Submitted), across the program forms this person can open
// (closed, test, membership and feedback forms are left out, the same as in the review queue).
// Shown as a count on the "Application review" row. Uses ApplicationModel::countAwaitingByForm();
// if that method is not added yet, or the query fails, the count is simply left out.
$appsWaiting = 0;
if ($canReviewApplications) {
    try {
        $reviewable = [];
        foreach ($forms as $f) {
            if (FormModel::isMembershipType($f['form_type'])) continue;   // reviewed in Membership review
            if ($isFeedbackForm($f)) continue;                            // read in the Feedback tab
            if (review_hidden_form($f)) continue;
            $reviewable[(int) $f['id']] = true;
        }
        foreach ((new ApplicationModel())->countAwaitingByForm() as $formId => $n) {
            if (isset($reviewable[(int) $formId])) $appsWaiting += (int) $n;
        }
    } catch (Throwable $e) { $appsWaiting = null; }
}

// Membership applications waiting for a first decision (New or Submitted), limited to the chapters this person
// may see (a coordinator sees only their own). Same idea as the count above; left out if the query fails.
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

// The "Review" tab holds the review queues: applications, membership and community records. Feedback is read,
// not decided on, so it has its own tab. Everything else is its own tab too.
$showReviewPanel = $canReviewApplications || $canReviewMembership || $canManageRecords;
$reviewIntro = ($canReviewApplications && $canReviewMembership)
    ? 'Decide on applications to our programs and on membership requests' . ($chapterLimited ? ' for your chapters' : '') . '.'
    : ($canReviewApplications
        ? 'Decide on applications to our programs, across every form in one list.'
        : 'Review new applications and renewals' . ($chapterLimited ? ' for your chapters' : '') . '.');

// One tab for member conversations and the technical tools.
$showSupportTech = $canWorkMemberSupport || $canWorkTech;

// Inner SVG markup for the card icons (24x24 viewBox, stroke icons).
$icons = [
    'doc'    => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/>',
    'people' => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
    'check'  => '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>',
    'inbox'  => '<polyline points="22 12 16 12 14 15 10 15 8 12 2 12"/><path d="M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>',
    'wifi'   => '<path d="M5 12.55a11 11 0 0 1 14.08 0"/><path d="M1.42 9a16 16 0 0 1 21.16 0"/><path d="M8.53 16.11a6 6 0 0 1 6.95 0"/><line x1="12" y1="20" x2="12.01" y2="20"/>',
    'card'   => '<rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/>',
    'archive'=> '<polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5"/><line x1="10" y1="12" x2="14" y2="12"/>',
    'chat'   => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
    'alert'  => '<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>',
    'pulse'  => '<polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>',
    'star'   => '<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>',
];

// The vertical tabs, in order: [id, label, icon, number shown in a coral badge (0 = none)].
$vtabs = [];
if ($showReviewPanel) $vtabs[] = ['review',   'Review',               'inbox', (int) $appsWaiting + (int) $membershipWaiting];
if ($canSeeSupport)   $vtabs[] = ['support',  'Volunteer support',    'card',  0];
if ($canManageGoodies) $vtabs[] = ['goodies', 'Goodies',              'star',  (int) $goodiesWaiting];
if ($showFormsGrid)   $vtabs[] = ['forms',    'Forms',                'doc',   0];
if ($canReadFeedback) $vtabs[] = ['feedback', 'Feedback',             'star',  0];   // no badge: feedback has nothing "waiting"
if ($showSupportTech) $vtabs[] = ['tech',     'Support & technical',  'chat',  (int) $techOpen];

/**
 * Renders one list row (one per line). $count: a number > 0 shows a coral "7 waiting" chip, 0 shows a green
 * "All clear" chip, null shows nothing. $badge (optional text, e.g. "5 open") shows a coral chip instead.
 */
function lrow(array $icons, string $tone, string $icon, string $title, string $desc, string $href, ?int $count = null, string $badge = ''): void
{
    ?>
    <a class="lrow" href="<?= htmlspecialchars($href) ?>" style="--tone: <?= htmlspecialchars($tone) ?>;">
        <span class="tick"><svg viewBox="0 0 24 24" aria-hidden="true"><?= $icons[$icon] ?></svg></span>
        <span>
            <h3><?= htmlspecialchars($title) ?></h3>
            <p><?= htmlspecialchars($desc) ?></p>
        </span>
        <span class="lside">
            <?php if ($badge !== ''): ?>
                <span class="chip attn"><?= htmlspecialchars($badge) ?></span>
            <?php elseif ($count !== null && $count > 0): ?>
                <span class="chip attn"><?= (int) $count ?> waiting</span>
            <?php elseif ($count === 0): ?>
                <span class="chip clear">&#10003; All clear</span>
            <?php endif; ?>
            <span class="arrow" aria-hidden="true">&rarr;</span>
        </span>
    </a>
    <?php
}

/**
 * Renders one form tile (open or closed). Shows when an open form closes, and
 * "Closed (deadline)" for a form that has run past its deadline.
 *
 * Feedback forms (schema "kind": "feedback") differ in three ways: the response count comes from
 * $feedbackCounts (their responses are not in `applications`), the tile says "Feedback", and it opens
 * the feedback page (/admin/feedback-review), which is also where they are edited, closed and reopened.
 */
function form_tile(array $icons, array $form, array $feedbackCounts = []): void
{
    $open       = FormModel::isOpen($form);
    $byDeadline = FormModel::closedByDeadline($form);
    $hasDeadline = !empty($form['deadline_at']);
    $isFeedback = FeedbackModel::isFeedbackSchema(json_decode((string) ($form['schema_json'] ?? ''), true));
    $count      = $isFeedback ? (int) ($feedbackCounts[(int) $form['id']] ?? 0) : (int) $form['applicant_count'];
    $tone       = $open ? 'var(--leaf-dark, #3E7A53)' : '#94a3b8';

    $pill = $open ? 'Active' : ($byDeadline ? 'Closed (deadline)' : 'Closed');

    $sub = '/' . $form['form_type'] . ($isFeedback ? ' · Feedback' : '') . ' · ' . $count . ' response' . ($count !== 1 ? 's' : '');
    if ($open && $hasDeadline) {
        $sub .= ' · closes ' . FormModel::formatIst($form['deadline_at']);
    } elseif ($byDeadline) {
        $sub .= ' · closed ' . FormModel::formatIst($form['deadline_at']);
    }

    $href = $isFeedback
        ? '/admin/feedback-review?form=' . (int) $form['id']
        : '/admin/form_manager?id=' . (int) $form['id'];
    ?>
    <a class="tile" href="<?= htmlspecialchars($href) ?>" style="--tone: <?= $tone ?>;">
        <span class="tick"><svg viewBox="0 0 24 24" aria-hidden="true"><?= $icons[$isFeedback ? 'star' : 'doc'] ?></svg></span>
        <span>
            <h3><?= htmlspecialchars($form['title']) ?></h3>
            <p><?= htmlspecialchars($sub) ?></p>
        </span>
        <span class="pill"><?= htmlspecialchars($pill) ?></span>
    </a>
    <?php
}

// The top bar (email, Team, Audit log, Chapter themes, Sign out, and the phone hamburger) comes from
// engage_staff_menu(), which engage_header() adds on every staff page, so 'tools' is left empty here.
// engage_header() adds the staff stylesheet, the "admin" body class, noindex and the shared footer for /admin pages.
engage_header([
    'title'   => 'Organizer workspace',
    'heading' => 'Workspace',
    'lead'    => 'Everything you can review, approve and manage, in one place.',
    'slim'    => true,
    'wide'    => true,
    'crumbs'  => [],
    'tools'   => '',
]);
?>

        <?php foreach ($techWaiting as $t): ?>
            <div class="action-banner" role="status">
                <strong>Technical team:</strong>
                <span>New reply on &ldquo;<?= htmlspecialchars($t['title']) ?>&rdquo;.</span>
                <a href="/admin/report-problem?id=<?= htmlspecialchars(rawurlencode($t['tracking_id'])) ?>">Read it</a>
            </div>
        <?php endforeach; ?>

        <?php if (!$vtabs): ?>
            <div class="empty-note">Nothing is assigned to your account yet. Ask an owner to give you a role.</div>
        <?php else: ?>
        <div class="adm-shell">

            <nav class="vtabs" role="tablist" aria-label="Workspace sections" aria-orientation="vertical">
                <?php foreach ($vtabs as $i => [$id, $label, $icon, $num]): ?>
                    <button type="button" class="vtab" role="tab" id="t-<?= $id ?>" data-tab="<?= $id ?>"
                            aria-controls="p-<?= $id ?>" aria-selected="<?= $i === 0 ? 'true' : 'false' ?>" tabindex="<?= $i === 0 ? '0' : '-1' ?>">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><?= $icons[$icon] ?></svg>
                        <span><?= htmlspecialchars($label) ?></span>
                        <?php if ($num > 0): ?><span class="vcount"><?= (int) $num ?></span><?php endif; ?>
                    </button>
                <?php endforeach; ?>
            </nav>

            <div class="adm-main">

                <?php if ($showReviewPanel): ?>
                <section class="vpanel" id="p-review" role="tabpanel" aria-labelledby="t-review">
                    <div class="panel-head">
                        <h2>Review</h2>
                        <p><?= htmlspecialchars($reviewIntro) ?></p>
                    </div>
                    <div class="lrows">
                        <?php
                        if ($canReviewApplications) {
                            lrow($icons, 'var(--primary)', 'inbox', 'Application review',
                                'Responses to every program form, grouped by program. Accept, reject or send back.',
                                '/admin/application-review', $appsWaiting);
                        }
                        if ($canReviewMembership) {
                            lrow($icons, 'var(--accent)', 'people', 'Membership review',
                                'New applications and renewals, the member list and membership cards.',
                                '/admin/membership-review', $membershipWaiting);
                        }
                        if ($canManageRecords) {
                            lrow($icons, 'var(--leaf-dark, #3E7A53)', 'check', 'Community records',
                                'Log Conversation Hour attendance and keep the technical contributors list. Internet support reads from these.',
                                '/admin/community-records');
                        }
                        ?>
                    </div>
                </section>
                <?php endif; ?>

                <?php if ($canSeeSupport): ?>
                <section class="vpanel" id="p-support" role="tabpanel" aria-labelledby="t-support" hidden>
                    <div class="panel-head">
                        <h2>Volunteer support</h2>
                        <p>Reimbursements and internet support, from review to payment.</p>
                        <div class="legend">
                            <?php if ($canReviewAny): ?><span style="--tone:var(--leaf-dark, #3E7A53)"><i></i>Review</span><?php endif; ?>
                            <?php if ($canProcessFinance): ?><span style="--tone:#C96A12"><i></i>Finance</span><?php endif; ?>
                        </div>
                    </div>
                    <div class="lrows">
                        <?php
                        if ($canReviewReimbursements) {
                            lrow($icons, 'var(--leaf-dark, #3E7A53)', 'check', 'Reimbursement review',
                                'Approve or reject claims. Line items and receipts only, no payment details.', '/admin/reimbursements/review');
                        }
                        if ($canReviewInternet) {
                            lrow($icons, 'var(--leaf-dark, #3E7A53)', 'wifi', 'Internet support review',
                                'Judge reasons and packages. No phone numbers shown.', '/admin/internet-review');
                        }
                        if ($canProcessFinance) {
                            lrow($icons, '#C96A12', 'card', 'Finance queue',
                                'Pay claims, recharge numbers and check receipts, one tab for each.', '/finance');
                            lrow($icons, '#C96A12', 'archive', 'Closed requests',
                                'Paid and closed requests, with a receipt PDF for each.', '/finance/closed');
                        }
                        ?>
                    </div>
                </section>
                <?php endif; ?>

                <?php if ($canManageGoodies): ?>
                <section class="vpanel" id="p-goodies" role="tabpanel" aria-labelledby="t-goodies" hidden>
                    <div class="panel-head">
                        <h2>Goodies</h2>
                        <p>Award points to members, manage the catalogue and fulfil orders.</p>
                    </div>
                    <div class="lrows">
                        <?php
                        lrow($icons, 'var(--primary)', 'star', 'Award points',
                            'Award points for activities, approve large awards, or take points back.', '/admin/goodies?tab=award');
                        lrow($icons, 'var(--primary)', 'doc', 'Catalogue',
                            'The goodies members can redeem, and what each costs in points.', '/admin/goodies?tab=catalogue');
                        lrow($icons, 'var(--leaf-dark, #3E7A53)', 'inbox', 'Orders',
                            'Order, ship and deliver what members have redeemed.', '/admin/goodies?tab=redemptions');
                        ?>
                    </div>
                </section>
                <?php endif; ?>

                <?php if ($showFormsGrid): ?>
                <section class="vpanel" id="p-forms" role="tabpanel" aria-labelledby="t-forms" hidden>
                    <div class="panel-head">
                        <h2>Forms</h2>
                        <p><?= $canCreateForms
                            ? 'Open and close the application forms people fill in, and review responses.'
                            : 'Review responses to membership forms.' ?></p>
                        <?php if ($canCreateForms): ?>
                            <a class="btn-ghost head-act" href="/admin/builder">+ New form</a>
                        <?php endif; ?>
                    </div>
                    <?php if ($activeForms): ?>
                        <div class="tiles">
                            <?php foreach ($activeForms as $form) form_tile($icons, $form, $feedbackCounts); ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-note">No forms are open right now.</div>
                    <?php endif; ?>

                    <?php if ($closedForms): ?>
                        <details class="closed-forms">
                            <summary>Closed forms (<?= count($closedForms) ?>)</summary>
                            <div class="tiles">
                                <?php foreach ($closedForms as $form) form_tile($icons, $form, $feedbackCounts); ?>
                            </div>
                        </details>
                    <?php endif; ?>
                </section>
                <?php endif; ?>

                <?php if ($canReadFeedback): ?>
                <section class="vpanel" id="p-feedback" role="tabpanel" aria-labelledby="t-feedback" hidden>
                    <div class="panel-head">
                        <h2>Feedback</h2>
                        <p>Read what people told us, email a respondent, or delete a response.
                            <?= (int) $fbTotal ?> response<?= $fbTotal === 1 ? '' : 's' ?> across <?= count($feedbackForms) ?> form<?= count($feedbackForms) === 1 ? '' : 's' ?>.</p>
                        <a class="btn-ghost head-act" href="/admin/builder?kind=feedback">+ New feedback form</a>
                    </div>
                    <?php if ($fbActive): ?>
                        <div class="tiles">
                            <?php foreach ($fbActive as $form) form_tile($icons, $form, $feedbackCounts); ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-note"><?= $feedbackForms ? 'No feedback forms are open right now.' : 'No feedback forms yet. Create one with the button above.' ?></div>
                    <?php endif; ?>

                    <?php if ($fbClosed): ?>
                        <details class="closed-forms">
                            <summary>Closed feedback forms (<?= count($fbClosed) ?>)</summary>
                            <div class="tiles">
                                <?php foreach ($fbClosed as $form) form_tile($icons, $form, $feedbackCounts); ?>
                            </div>
                        </details>
                    <?php endif; ?>
                </section>
                <?php endif; ?>

                <?php if ($showSupportTech): ?>
                <section class="vpanel" id="p-tech" role="tabpanel" aria-labelledby="t-tech" hidden>
                    <div class="panel-head">
                        <h2>Member support &amp; technical</h2>
                        <p>Conversations from members, reported problems and health checks.</p>
                    </div>
                    <div class="lrows">
                        <?php
                        if ($canWorkMemberSupport) {
                            lrow($icons, 'var(--primary)', 'chat', 'DCW Support',
                                'Read and answer member conversations. Replies are emailed as a notification only.', '/admin/member-support');
                        }
                        if ($canWorkTech) {
                            lrow($icons, 'var(--primary-dark)', 'alert', 'Reported problems',
                                'Problems from members and the team. Reporters show only as "Member" or by team email.',
                                '/admin/tech-issues', null, $techOpen > 0 ? $techOpen . ' open' : '');
                            lrow($icons, 'var(--primary-dark)', 'pulse', 'Diagnostics',
                                'Read-only health checks: server, database, security and storage.', '/admin/tech-diagnostics');
                        }
                        ?>
                    </div>
                </section>
                <?php endif; ?>

            </div>
        </div>
        <?php endif; ?>

<script>
// Vertical tabs: show one section at a time. The section is remembered in the address (#forms) so a
// refresh or a shared link opens the same tab. Arrow keys move between tabs.
(function () {
    var tabs = [].slice.call(document.querySelectorAll('.vtab'));
    var panels = [].slice.call(document.querySelectorAll('.vpanel'));
    if (!tabs.length) return;
    function show(id, focus) {
        tabs.forEach(function (t) {
            var on = t.getAttribute('data-tab') === id;
            t.setAttribute('aria-selected', on ? 'true' : 'false');
            t.tabIndex = on ? 0 : -1;
            if (on && focus) t.focus();
        });
        panels.forEach(function (p) { p.hidden = p.id !== 'p-' + id; });
        try { history.replaceState(null, '', '#' + id); } catch (e) {}
    }
    tabs.forEach(function (t, i) {
        t.addEventListener('click', function () { show(t.getAttribute('data-tab')); });
        t.addEventListener('keydown', function (e) {
            var d = (e.key === 'ArrowDown' || e.key === 'ArrowRight') ? 1 : (e.key === 'ArrowUp' || e.key === 'ArrowLeft') ? -1 : 0;
            if (!d) return;
            e.preventDefault();
            show(tabs[(i + d + tabs.length) % tabs.length].getAttribute('data-tab'), true);
        });
    });
    var start = (location.hash || '').replace('#', '');
    var ids = tabs.map(function (t) { return t.getAttribute('data-tab'); });
    show(ids.indexOf(start) > -1 ? start : ids[0]);
})();
</script>
<?php engage_footer(); ?>
