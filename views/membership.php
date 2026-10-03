<?php
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../models/MemberModel.php';   // static helpers only; no DB connection is opened here

/**
 * /membership: picks the right membership form. No data is stored here;
 * each target is an ordinary builder form served by views/forms/renderer.php,
 * so email verification, drafts, uploads and tracking all come for free.
 *
 * Renewal runs on a per-term slug (UNIQUE(form_id, email) allows one
 * application per email per form). Change it once per cycle.
 *
 * Renewal needs a Member ID:
 *   - ID given and well formed (letter + 8 digits, e.g. A48213977) -> renewal form
 *   - ID given but malformed                                       -> error, stay here
 *   - ID left blank -> the person is treated as a new applicant, so they must pick
 *     the membership they want and go to that normal application form
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

// Whitelisted slugs only, so this can never redirect anywhere else.
$slug = null;
if ($type === 'renewal') {
    if ($memberId !== '') {
        if (MemberModel::chapterFromMemberId($memberId) !== null) {
            $slug = MEMBERSHIP_RENEWAL_SLUG;
        } else {
            $error = 'That Member ID is not valid. It is one letter ('
                . implode(', ', array_values(MemberModel::ID_PREFIX)) . ') followed by '
                . MemberModel::MEMBER_ID_DIGITS . ' digits, for example A48213977.';
        }
    } elseif (isset(MEMBERSHIP_NEW[$chapter])) {
        $slug = MEMBERSHIP_NEW[$chapter][1];   // no Member ID: normal application
    } elseif (isset($_GET['go'])) {
        $error = 'Enter your Member ID to renew, or choose a membership to apply as a new member.';
    }
} elseif ($type === 'new' && isset(MEMBERSHIP_NEW[$chapter])) {
    $slug = MEMBERSHIP_NEW[$chapter][1];
}
if ($slug !== null) {
    header('Location: /' . rawurlencode($slug));
    exit;
}
if ($error === '' && isset($_GET['go'])) {
    $error = $type === '' ? 'Choose whether you are a new applicant or renewing.'
                          : 'Choose the membership you want to join.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Membership - DCW Engage</title>
    <?php require __DIR__ . '/../includes/favicon.php'; ?>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/forms.css?v=2">
</head>
<body>
<div class="container">
    <h1 style="margin-top:0;">Become a DCW member</h1>
    <p style="color:#475569; font-size:15px; line-height:1.6; margin-bottom:30px;">
        Membership is ongoing, with no deadlines or selection rounds. Tell us where you are starting from.
    </p>

    <?php if ($error): ?>
        <div class="alert-error"><strong>Notice:</strong> <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="GET" action="/membership">
        <div class="form-group">
            <label for="type">I am a <span style="color:#ef4444">*</span></label>
            <select name="type" id="type" required>
                <option value="">-- Select --</option>
                <option value="new" <?= $type === 'new' ? 'selected' : '' ?>>New applicant</option>
                <option value="renewal" <?= $type === 'renewal' ? 'selected' : '' ?>>Existing member (renewal)</option>
            </select>
        </div>

        <div class="form-group" id="member-id-row">
            <label for="member_id">Member ID</label>
            <input type="text" name="member_id" id="member_id" maxlength="<?= MemberModel::MEMBER_ID_DIGITS + 1 ?>"
                   pattern="[A-Za-z][0-9]{<?= MemberModel::MEMBER_ID_DIGITS ?>}" autocomplete="off"
                   title="One letter followed by <?= MemberModel::MEMBER_ID_DIGITS ?> digits, for example A48213977"
                   placeholder="e.g. A48213977" value="<?= htmlspecialchars($memberId) ?>">
            <small style="color:#64748b;">It is in your membership confirmation email.
                No Member ID? Leave this empty and choose a membership below to apply as a new member.</small>
        </div>

        <div class="form-group" id="chapter-row">
            <label for="chapter">Membership <span style="color:#ef4444">*</span></label>
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

    <p style="font-size:14px; color:#64748b; margin-top:24px;">
        Already applied? <a href="/track">Check your application status</a>.
    </p>
</div>
<script>
    // Renewal with a Member ID uses one form for every chapter (it asks which chapter inside),
    // so the chapter dropdown is hidden then. With no Member ID the person is a new applicant
    // and must choose the membership.
    const type = document.getElementById('type');
    const idRow = document.getElementById('member-id-row');
    const idInput = document.getElementById('member_id');
    const row = document.getElementById('chapter-row');
    const chapter = document.getElementById('chapter');
    function sync() {
        const renewal = type.value === 'renewal';
        const hasId = renewal && idInput.value.trim() !== '';
        idRow.style.display = renewal ? '' : 'none';
        idInput.disabled = !renewal;
        row.style.display = hasId ? 'none' : '';
        chapter.disabled = hasId;
    }
    type.addEventListener('change', sync);
    idInput.addEventListener('input', sync);
    sync();
</script>
</body>
</html>
