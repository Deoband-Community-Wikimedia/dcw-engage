<?php
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/engage_page.php';
require_once __DIR__ . '/../models/MemberModel.php';   // static helpers only; no DB connection is opened here

/**
 * /membership: picks the right membership form. No data is stored here;
 * each target is an ordinary builder form served by views/forms/renderer.php,
 * so email verification, drafts, uploads and tracking all come for free.
 *
 * Renewal runs on a per-term slug (UNIQUE(form_id, email) allows one
 * application per email per form). Change it once per cycle.
 *
 * One person, one Member ID, whichever chapter it was first issued for:
 *   - Renewing: Member ID given and well formed (letter + 8 digits, e.g. A48213977) -> renewal form
 *   - Joining another chapter (Generic -> club, club -> Generic, club -> club): always the
 *     chapter's OWN form. A signed-in member needs to type nothing: the renderer uses their
 *     session. A guest may type their Member ID; it is parked in the session here and checked
 *     against their verified email on submit, so approval reuses the ID instead of making a new one.
 *   - ID left blank -> a normal application for the chosen membership
 *
 * Signed-in members never get an application form for a membership they already hold
 * (any status except rejected). They see a notice and a renewal link instead. The renderer
 * sends them back here with ?held=<chapter> if they reach the form URL directly.
 */
const MEMBERSHIP_NEW = [
    'generic'       => ['DCW Generic Community', 'membership-generic'],
    'amu'           => ['Wiki Club AMU', 'membership-amu'],
    'jamia'         => ['Wiki Club Jamia', 'membership-jamia'],
    'photographers' => ['DCW Photographers Club', 'membership-photographers'],
];
const MEMBERSHIP_RENEWAL_SLUG = 'membership-renewal-2026';

// Public page; a signed-in member gets their name in the top bar and is never asked for an ID.
// Loaded first because the routing below depends on it.
$member = null;
try {
    require_once __DIR__ . '/../includes/member_session.php';
    $member = MemberSession::current();
} catch (Throwable $ex) {
    $member = null;
}
$sessionId = (string) ($member['member_id'] ?? '');
$loggedIn = $sessionId !== '';

// Memberships this signed-in member already holds: [chapter key => ['status'=>..., 'expires_at'=>...]]
$held = [];
if ($loggedIn) {
    try {
        $held = MemberSession::heldChapters();
    } catch (Throwable $ex) {
        $held = [];
    }
}

$type = (string) ($_GET['type'] ?? '');
$chapter = (string) ($_GET['chapter'] ?? '');
$memberId = strtoupper(trim((string) ($_GET['member_id'] ?? '')));
$error = '';
$alreadyKey = '';   // chapter key to show the "already a member" notice for

$badFormat = 'That Member ID is not valid. It is one letter ('
    . implode(', ', array_values(MemberModel::ID_PREFIX)) . ') followed by '
    . MemberModel::MEMBER_ID_DIGITS . ' digits, for example A48213977.';

// Sent back by the form renderer when a signed-in member opened a form they already hold.
$heldParam = (string) ($_GET['held'] ?? '');
if ($loggedIn && isset(MEMBERSHIP_NEW[$heldParam]) && isset($held[$heldParam])) {
    $alreadyKey = $heldParam;
}

// Whitelisted slugs only, so this can never redirect anywhere else.
$slug = null;
if ($type === 'renewal') {
    if ($memberId === '' && $loggedIn) $memberId = $sessionId;   // signed in: use their own ID
    if ($memberId !== '') {
        if (MemberModel::chapterFromMemberId($memberId) !== null) {
            $slug = MEMBERSHIP_RENEWAL_SLUG;
        } else {
            $error = $badFormat;
        }
    } elseif (isset(MEMBERSHIP_NEW[$chapter])) {
        $slug = MEMBERSHIP_NEW[$chapter][1];   // no Member ID: normal application
    } elseif (isset($_GET['go'])) {
        $error = 'Enter your Member ID to renew, or choose a membership to apply as a new member.';
    }
} elseif ($type === 'new' && isset(MEMBERSHIP_NEW[$chapter])) {
    $target = MEMBERSHIP_NEW[$chapter][1];     // always the chapter's own form, never the renewal form
    if ($loggedIn && isset($held[$chapter])) { // signed in and already holds it: no form, renew instead
        $alreadyKey = $chapter;
    } elseif (!$loggedIn && $memberId !== '') {    // guest who typed an ID
        if (MemberModel::chapterFromMemberId($memberId) === null) {
            $error = $badFormat;
        } else {
            // Parked server-side (not in the URL). The renderer checks it against the verified email.
            $_SESSION['join_member_id'][$target] = $memberId;
            $slug = $target;
        }
    } else {
        unset($_SESSION['join_member_id'][$target]);   // signed in, or no ID: nothing to carry over
        $slug = $target;
    }
}
if ($slug !== null) {
    header('Location: /' . rawurlencode($slug));
    exit;
}
if ($error === '' && $alreadyKey === '' && isset($_GET['go'])) {
    $error = $type === '' ? 'Choose whether you are a new applicant or renewing.'
                          : 'Choose the membership you want to join.';
}

// Wording of the "already a member" notice.
$alreadyLabel = '';
$alreadyPending = false;
if ($alreadyKey !== '') {
    $alreadyLabel = MEMBERSHIP_NEW[$alreadyKey][0];
    $alreadyPending = ($held[$alreadyKey]['status'] ?? '') !== 'approved';
}

engage_header([
    'title'   => 'Membership',
    'heading' => 'Become a DCW member',
    'kicker'  => 'Membership',
    'lead'    => 'Membership is ongoing, with no deadlines or selection rounds. Tell us where you are starting from.',
    'member'  => $member,
    'crumbs'  => [['Home', '/'], ['Membership']],
]);
?>
<div class="fcard">
    <?php if ($error): ?>
        <div class="alert error"><strong>Notice:</strong> <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($alreadyKey !== ''): ?>
        <div class="alert">
            <?php if ($alreadyPending): ?>
                <strong>You have already applied.</strong>
                Your membership of <?= htmlspecialchars($alreadyLabel) ?> is on record
                (Member ID <?= htmlspecialchars($sessionId) ?>), so there is no need to apply again.
                <a href="/track">Check your application status</a>.
            <?php else: ?>
                <strong>You are already a member.</strong>
                You hold a <?= htmlspecialchars($alreadyLabel) ?> membership under Member ID
                <?= htmlspecialchars($sessionId) ?>.
                To extend it, <a href="/membership?type=renewal&amp;go=1">renew your membership</a>.
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <form method="GET" action="/membership">
        <div class="field">
            <label for="type">I am a <span class="req-star">*</span></label>
            <select name="type" id="type" required>
                <option value="">-- Select --</option>
                <option value="new" <?= $type === 'new' ? 'selected' : '' ?>>New applicant</option>
                <option value="renewal" <?= $type === 'renewal' ? 'selected' : '' ?>>Existing member (renewal)</option>
            </select>
        </div>

        <div class="field" id="member-id-row">
            <label for="member_id" id="member-id-label">Member ID</label>
            <input type="text" name="member_id" id="member_id" maxlength="<?= MemberModel::MEMBER_ID_DIGITS + 1 ?>"
                   pattern="[A-Za-z][0-9]{<?= MemberModel::MEMBER_ID_DIGITS ?>}" autocomplete="off"
                   title="One letter followed by <?= MemberModel::MEMBER_ID_DIGITS ?> digits, for example A48213977"
                   placeholder="e.g. D48213977" value="<?= htmlspecialchars($memberId) ?>">
            <span class="hint" id="hint-renewal">It is in your membership confirmation email.
                No Member ID? Leave this empty and choose a membership below to apply as a new member.</span>
            <span class="hint" id="hint-club">Already a DCW member of any chapter? Enter your Member ID and
                you will keep one ID for all your memberships. Not a member yet? Leave this empty.</span>
        </div>

        <div class="field" id="chapter-row">
            <label for="chapter">Membership <span class="req-star">*</span></label>
            <select name="chapter" id="chapter">
                <option value="">-- Select --</option>
                <?php foreach (MEMBERSHIP_NEW as $key => [$label]):
                    $isHeld = isset($held[$key]); ?>
                    <option value="<?= htmlspecialchars($key) ?>"
                        <?= ($chapter === $key && !$isHeld) ? 'selected' : '' ?>
                        <?= $isHeld ? 'disabled' : '' ?>>
                        <?= htmlspecialchars($label) ?><?= $isHeld ? ' (already a member)' : '' ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <button type="submit" name="go" value="1">Continue</button>
    </form>

    <p style="margin:24px 0 0; font-size:14px; color:var(--muted);">
        Already applied? <a href="/track">Check your application status</a>.
    </p>
</div>

<script>
    // When to ask for a Member ID (never when signed in: the session already knows it):
    //  - Renewal: the ID is needed, and with it the chapter dropdown is hidden (the form asks inside).
    //  - New applicant, any membership: optional, for someone who already holds a membership in another chapter.
    const loggedIn = <?= $loggedIn ? 'true' : 'false' ?>;
    const type = document.getElementById('type');
    const idRow = document.getElementById('member-id-row');
    const idInput = document.getElementById('member_id');
    const hintRenewal = document.getElementById('hint-renewal');
    const hintClub = document.getElementById('hint-club');
    const row = document.getElementById('chapter-row');
    const chapter = document.getElementById('chapter');
    function sync() {
        const renewal = type.value === 'renewal';
        const joining = type.value === 'new' && chapter.value !== '' && !loggedIn;
        const showId = (renewal && !loggedIn) || joining;
        const hasId = renewal && (loggedIn || idInput.value.trim() !== '');
        idRow.style.display = showId ? '' : 'none';
        idInput.disabled = !showId;
        hintRenewal.style.display = (renewal && !loggedIn) ? '' : 'none';
        hintClub.style.display = joining ? '' : 'none';
        row.style.display = hasId ? 'none' : '';
        chapter.disabled = hasId;
    }
    type.addEventListener('change', sync);
    chapter.addEventListener('change', sync);
    idInput.addEventListener('input', sync);
    sync();
</script>
<?php engage_footer(); ?>
