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
 * One person, one Member ID. The Generic Community ID is shared with any club:
 *   - Renewing: Member ID given and well formed (letter + 8 digits, e.g. A48213977) -> renewal form
 *   - Joining a club while already a Generic Community member: Member ID given
 *     (starts with D) -> the same form, which asks which club and verifies the ID,
 *     so approval reuses it instead of creating a new one
 *   - ID left blank -> a normal application for the chosen membership
 */
const MEMBERSHIP_NEW = [
    'generic'       => ['DCW Generic Community', 'membership-generic'],
    'amu'           => ['Wiki Club AMU', 'membership-amu'],
    'jamia'         => ['Wiki Club Jamia', 'membership-jamia'],
    'photographers' => ['DCW Photographers Club', 'membership-photographers'],
];
const MEMBERSHIP_RENEWAL_SLUG = 'membership-renewal-2026';

$type = (string) ($_GET['type'] ?? '');
$chapter = (string) ($_GET['chapter'] ?? '');
$memberId = strtoupper(trim((string) ($_GET['member_id'] ?? '')));
$error = '';

$badFormat = 'That Member ID is not valid. It is one letter ('
    . implode(', ', array_values(MemberModel::ID_PREFIX)) . ') followed by '
    . MemberModel::MEMBER_ID_DIGITS . ' digits, for example A48213977.';

// Whitelisted slugs only, so this can never redirect anywhere else.
$slug = null;
if ($type === 'renewal') {
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
    if ($chapter !== 'generic' && $memberId !== '') {
        // Already a Generic Community member joining a club: the ID must be a Generic one.
        if (MemberModel::chapterFromMemberId($memberId) === 'generic') {
            $slug = MEMBERSHIP_RENEWAL_SLUG;
        } else {
            $error = MemberModel::chapterFromMemberId($memberId) === null
                ? $badFormat
                : 'To join a club with an existing ID, use your DCW Generic Community Member ID '
                    . '(it starts with ' . MemberModel::ID_PREFIX['generic'] . '). Otherwise leave the box empty.';
        }
    } else {
        $slug = MEMBERSHIP_NEW[$chapter][1];
    }
}
if ($slug !== null) {
    header('Location: /' . rawurlencode($slug));
    exit;
}
if ($error === '' && isset($_GET['go'])) {
    $error = $type === '' ? 'Choose whether you are a new applicant or renewing.'
                          : 'Choose the membership you want to join.';
}

// Public page; a signed-in member just gets their name in the top bar.
$member = null;
try {
    require_once __DIR__ . '/../includes/member_session.php';
    $member = MemberSession::current();
} catch (Throwable $ex) {
    $member = null;
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
            <span class="hint" id="hint-club">Already a DCW Generic Community member? Enter your Member ID
                (it starts with <?= htmlspecialchars(MemberModel::ID_PREFIX['generic']) ?>) and your club membership will share it,
                so you keep one ID. You will pick the club again on the next page. Not a member yet? Leave this empty.</span>
        </div>

        <div class="field" id="chapter-row">
            <label for="chapter">Membership <span class="req-star">*</span></label>
            <select name="chapter" id="chapter">
                <option value="">-- Select --</option>
                <?php foreach (MEMBERSHIP_NEW as $key => [$label]): ?>
                    <option value="<?= htmlspecialchars($key) ?>" <?= $chapter === $key ? 'selected' : '' ?>>
                        <?= htmlspecialchars($label) ?></option>
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
    // Two reasons to ask for a Member ID:
    //  - Renewal: the ID is needed, and with it the chapter dropdown is hidden (the form asks inside).
    //  - New applicant picking a club: optional, for someone who is already a Generic Community member.
    // For a new Generic Community applicant there is nothing to ask, so the box is hidden.
    const type = document.getElementById('type');
    const idRow = document.getElementById('member-id-row');
    const idInput = document.getElementById('member_id');
    const hintRenewal = document.getElementById('hint-renewal');
    const hintClub = document.getElementById('hint-club');
    const row = document.getElementById('chapter-row');
    const chapter = document.getElementById('chapter');
    function sync() {
        const renewal = type.value === 'renewal';
        const club = type.value === 'new' && chapter.value !== '' && chapter.value !== 'generic';
        const showId = renewal || club;
        const hasId = renewal && idInput.value.trim() !== '';
        idRow.style.display = showId ? '' : 'none';
        idInput.disabled = !showId;
        hintRenewal.style.display = renewal ? '' : 'none';
        hintClub.style.display = club ? '' : 'none';
        row.style.display = hasId ? 'none' : '';
        chapter.disabled = hasId;
    }
    type.addEventListener('change', sync);
    chapter.addEventListener('change', sync);
    idInput.addEventListener('input', sync);
    sync();
</script>
<?php engage_footer(); ?>
