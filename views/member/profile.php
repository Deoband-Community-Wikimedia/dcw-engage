<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/app_log.php';
require_once __DIR__ . '/../../includes/engage_page.php';
require_once __DIR__ . '/../../includes/member_session.php';
require_once __DIR__ . '/../../includes/cert_rows.php';          // .d-btn buttons and icons, shared with the dashboard
require_once __DIR__ . '/../../models/MemberAuthModel.php';
require_once __DIR__ . '/../../models/MemberModel.php';
require_once __DIR__ . '/../../models/MemberProfileModel.php';
require_once __DIR__ . '/../../includes/mail/membership_mailer.php';

/**
 * DCW Engage - member profile (/member/profile).
 * Signed-in members only. This page is about WHO the member is: identity, memberships (names and join
 * dates only; status and Renew live on the dashboard), the Wikimedia username and its verification,
 * optional "About you" details, and account actions. Anything time-sensitive stays on the dashboard.
 *
 * Name and email are read-only here: they appear on certificates and are the key requests and
 * certificates are matched on, so they are changed through DCW Support.
 *
 * Everything on this page is read with the signed-in member's own Member ID. Nothing here is shown to
 * other members.
 */
MemberSession::requireLogin();
$member   = MemberSession::current();
$memberId = (string) $member['member_id'];
$email    = (string) $member['email'];
$fullName = trim((string) ($member['full_name'] ?? ''));
$profiles = new MemberProfileModel();
$by       = 'member:' . $memberId;

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CSRF::validate($_POST['csrf_token'] ?? '')) {
        die('Invalid CSRF token.');
    }
    $act = (string) ($_POST['action'] ?? '');
    try {
        if ($act === 'save_username') {
            $profiles->saveByMember($memberId, (string) ($_POST['wikimedia_username'] ?? ''), $by);
            header('Location: /member/profile?saved=username#wikimedia');
            exit;
        } elseif ($act === 'save_about') {
            $profiles->saveAbout(
                $memberId,
                (string) ($_POST['bio'] ?? ''),
                (string) ($_POST['languages'] ?? ''),
                (array) ($_POST['areas'] ?? []),
                $by
            );
            header('Location: /member/profile?saved=about#about');
            exit;
        } elseif ($act === 'password_link') {
            // Same flow as /member/forgot, without retyping anything: the member is already signed in.
            // requestLink() sends nothing if a link went out a moment ago; the message below covers both.
            try {
                $link = (new MemberAuthModel())->requestLink($memberId, $email);
                if ($link) {
                    $m = $link['member'];
                    MembershipMailer::sendPasswordLink($m['email'], $m['full_name'], $m['member_id'], $link['token'], $link['purpose'], $m['chapter']);
                }
            } catch (Throwable $e) {
                app_log("Profile password link failed for $memberId: " . $e->getMessage());
            }
            header('Location: /member/profile?saved=link#account');
            exit;
        }
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        app_log('Member profile save failed: ' . $e->getMessage());
        $error = 'We could not save that just now. Please try again in a moment.';
    }
}

// ---- Read ------------------------------------------------------------------------------------------
$profile = null;
try { $profile = $profiles->get($memberId); } catch (Throwable $e) { app_log('Member profile read failed: ' . $e->getMessage()); }

$memberships = [];
try { $memberships = (new MemberModel())->membershipsFor($memberId); } catch (Throwable $e) { app_log_ignored('profile.php:82', $e); /* fall back below */ }
if (!$memberships) $memberships = [$member];

$h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

/** A stored UTC datetime as a plain date in India time, e.g. "14 Oct 2026". */
function pf_date(?string $utc): string
{
    if ($utc === null || $utc === '') return '';
    return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))
        ->setTimezone(new DateTimeZone('Asia/Kolkata'))->format('j M Y');
}

$username   = (string) ($profile['wikimedia_username'] ?? '');
$verified   = $username !== '' && !empty($profile['username_verified_at']);
$hasName    = $username !== '';
$areas      = MemberProfileModel::areasOf($profile);
$languages  = MemberProfileModel::languagesOf($profile);

// After a failed save, keep what the member typed.
$posted = $_SERVER['REQUEST_METHOD'] === 'POST' && $error !== '';
$typedUser  = ($posted && ($_POST['action'] ?? '') === 'save_username') ? (string) ($_POST['wikimedia_username'] ?? '') : $username;
$typedBio   = ($posted && ($_POST['action'] ?? '') === 'save_about') ? (string) ($_POST['bio'] ?? '') : (string) ($profile['bio'] ?? '');
$typedLangs = ($posted && ($_POST['action'] ?? '') === 'save_about') ? (string) ($_POST['languages'] ?? '') : implode(', ', $languages);
$typedAreas = ($posted && ($_POST['action'] ?? '') === 'save_about') ? array_map('strval', (array) ($_POST['areas'] ?? [])) : $areas;

// Joined: the earliest membership start we know of (hidden if the members table has no such column).
$joined = null;
foreach ($memberships as $m) {
    $c = (string) ($m['created_at'] ?? '');
    if ($c !== '' && ($joined === null || $c < $joined)) $joined = $c;
}

$initials = '';
foreach (array_slice(preg_split('/\s+/', $fullName) ?: [], 0, 2) as $w) {
    if ($w !== '') $initials .= mb_strtoupper(mb_substr($w, 0, 1));
}
if ($initials === '') $initials = 'M';

$saved = (string) ($_GET['saved'] ?? '');

engage_header([
    'title'   => 'My profile',
    'heading' => 'My profile',
    'lead'    => 'Who you are on DCW Engage.',
    'back'    => ['Dashboard', '/member/dashboard'],
    'compact' => true,
    'member'  => $member,
    'tools'   => engage_member_menu($member, ['current' => '/member/profile']),
    'wide'    => true,
]);
cert_rows_css();   // .d-btn buttons and icons
?>
<style>
    /* Member profile only. Colors, radii and fonts come from the tokens in /assets/css/engage.css. */
    .pf { display: grid; gap: 0 22px; }
    @media (min-width: 1000px) {
        .pf { grid-template-columns: 340px minmax(0, 1fr); align-items: start; }
        .pf-side { position: sticky; top: 14px; }
    }
    .pf-card { position: relative; margin: 0 0 20px; padding: 22px 24px; background: var(--card); border: 1px solid var(--border); border-radius: 16px; box-shadow: 0 6px 18px rgba(15,23,42,.06); }
    .pf-head { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 8px 14px; margin: 0 0 14px; }
    .pf-head h2 { margin: 0; font-size: 18px; font-weight: 800; letter-spacing: -.01em; }
    .pf-lead { margin: 0 0 14px; color: var(--muted); font-size: 14.5px; }
    .pf-note { margin: 12px 0 0; color: var(--muted); font-size: 13px; line-height: 1.55; }
    .pf a:not(.d-btn) { color: var(--primary); font-weight: 600; }

    /* Identity card: the logo's three-colour stripe on top, same as the dashboard */
    .pf-id { padding-top: 30px; overflow: hidden; text-align: center; }
    .pf-id::before { content: ""; position: absolute; inset: 0 0 auto 0; height: 5px; background: linear-gradient(90deg, var(--primary) 0 40%, var(--accent) 40% 70%, var(--leaf) 70% 100%); }
    .pf-av { width: 76px; height: 76px; margin: 0 auto 12px; border-radius: 50%; display: grid; place-items: center; font-size: 26px; font-weight: 800; color: #fff; background: linear-gradient(135deg, var(--primary), var(--leaf-dark)); box-shadow: 0 0 0 4px color-mix(in srgb, var(--leaf) 22%, #fff); }
    .pf-id h2 { margin: 0; font-size: 20px; font-weight: 800; letter-spacing: -.01em; overflow-wrap: anywhere; }
    .pf-mid { display: inline-flex; align-items: center; gap: 8px; margin: 8px 0 0; }
    .pf-mid code { font-size: 14px; padding: 3px 10px; border-radius: 8px; background: #eef3f8; letter-spacing: .04em; }
    .pf-cardbtn { margin: 14px 0 0; }
    .pf-facts { margin: 18px 0 0; padding-top: 14px; border-top: 1px solid var(--border); text-align: left; display: grid; grid-template-columns: auto 1fr; gap: 8px 14px; font-size: 14px; }
    .pf-facts dt { color: var(--muted); font-weight: 600; }
    .pf-facts dd { margin: 0; overflow-wrap: anywhere; }

    /* Memberships: names and join dates only */
    .pf-ms { list-style: none; margin: 0; padding: 0; }
    .pf-ms li { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 2px 12px; padding: 10px 0; border-top: 1px solid var(--border); font-size: 14.5px; }
    .pf-ms li:first-child { border-top: 0; padding-top: 0; }
    .pf-ms .n { font-weight: 600; }
    .pf-ms .v { color: var(--muted); font-size: 13px; }

    /* Wikimedia card */
    .pf-wm-name { margin: 0; font-size: 22px; font-weight: 800; letter-spacing: -.01em; overflow-wrap: anywhere; }
    .pf-wm-sub { margin: 4px 0 14px; color: var(--muted); font-size: 14px; }
    .pf-actions { display: flex; flex-wrap: wrap; gap: 8px; margin: 0 0 4px; }
    .pf-fold { margin-top: 16px; padding-top: 14px; border-top: 1px solid var(--border); }
    .pf-fold > summary { cursor: pointer; list-style: none; font-size: 14.5px; font-weight: 700; color: var(--primary); }
    .pf-fold > summary::-webkit-details-marker { display: none; }
    .pf-fold > summary::before { content: '\25B8'; margin-right: 8px; color: var(--muted); }
    .pf-fold[open] > summary::before { content: '\25BE'; }
    .pf-fold form { margin-top: 14px; }
    .pf-warn { margin: 0 0 14px; padding: 10px 12px; background: #fffbeb; border: 1px solid #fcd34d; border-radius: 10px; font-size: 13.5px; color: #92400e; }

    /* About you */
    .pf-areas { display: grid; grid-template-columns: repeat(auto-fill, minmax(190px, 1fr)); gap: 8px 14px; }
    .pf-areas label { display: flex; align-items: center; gap: 8px; margin: 0; font-size: 14.5px; font-weight: 400; cursor: pointer; }
    .pf-areas input { width: 17px; height: 17px; accent-color: var(--primary); }
    .pf textarea { min-height: 96px; resize: vertical; }
    .pf-count { float: right; font-weight: 400; color: var(--muted); font-variant-numeric: tabular-nums; }

    /* History */
    .pf-hist { list-style: none; margin: 0; padding: 0; font-size: 14px; }
    .pf-hist li { display: flex; justify-content: space-between; gap: 12px; padding: 8px 0; border-top: 1px solid var(--border); }
    .pf-hist li:first-child { border-top: 0; padding-top: 0; }
    .pf-hist span:last-child { color: var(--muted); }

    .pf-acct { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; }
    .pf-acct form { margin: 0; }
</style>

<div class="dash">

<?php if ($saved === 'username'): ?>
    <div class="alert ok">Username saved.<?= $verified ? '' : ' A DCW reviewer or coordinator will verify it.' ?></div>
<?php elseif ($saved === 'about'): ?>
    <div class="alert ok">Your details are saved.</div>
<?php elseif ($saved === 'link'): ?>
    <div class="alert ok">We have emailed a link to <strong><?= $h($email) ?></strong>. If you asked a moment ago, please use that one. It can take a minute to arrive, so check your spam folder too.</div>
<?php endif; ?>
<?php if ($error !== ''): ?>
    <div class="alert error"><strong>Notice:</strong> <?= $h($error) ?></div>
<?php endif; ?>

<div class="pf">

    <!-- Left: who you are -->
    <div class="pf-side">
        <section class="pf-card pf-id" aria-label="Identity">
            <div class="pf-av" aria-hidden="true"><?= $h($initials) ?></div>
            <h2><?= $h($fullName !== '' ? $fullName : $memberId) ?></h2>
            <p class="pf-mid">
                <code><?= $h($memberId) ?></code>
                <button type="button" class="d-btn soft" data-copy="<?= $h($memberId) ?>" aria-label="Copy Member ID"><span>Copy</span></button>
            </p>
            <p class="pf-cardbtn"><a class="d-btn fill" href="/member/card">Membership card</a></p>
            <dl class="pf-facts">
                <dt>Email</dt><dd><?= $h($email) ?></dd>
                <?php if ($joined !== null): ?><dt>Member since</dt><dd><?= $h(pf_date($joined)) ?></dd><?php endif; ?>
            </dl>
            <p class="pf-note">Your name and email appear on certificates and are how your requests are matched to you. To change either, <a href="/member/talk">contact DCW Support</a>.</p>
        </section>

        <section class="pf-card" aria-label="Memberships">
            <div class="pf-head"><h2>Memberships</h2></div>
            <ul class="pf-ms">
                <?php foreach ($memberships as $m):
                    $key  = trim((string) ($m['chapter'] ?? ''));
                    $name = MemberModel::CHAPTER_NAMES[$key] ?? $key;
                    $since = pf_date((string) ($m['created_at'] ?? '')); ?>
                    <li>
                        <span class="n"><?= $h($name !== '' ? $name : 'DCW membership') ?></span>
                        <?php if ($since !== ''): ?><span class="v">Since <?= $h($since) ?></span><?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <p class="pf-note">Status and renewal are on your <a href="/member/dashboard">dashboard</a>.</p>
        </section>
    </div>

    <!-- Right: details and settings -->
    <div class="pf-main">

        <section class="pf-card" id="wikimedia" aria-label="Wikimedia identity">
            <div class="pf-head">
                <h2>Wikimedia identity</h2>
                <?php if (!$hasName): ?><span class="pill" style="--tone:#64748b;">Not added</span>
                <?php elseif ($verified): ?><span class="pill" style="--tone:#047857;">Verified</span>
                <?php else: ?><span class="pill" style="--tone:#b45309;">Awaiting verification</span><?php endif; ?>
            </div>

            <?php if (!$hasName): ?>
                <p class="pf-lead">Add your Wikimedia username. It is needed to request internet support, because reviewers check your contributions against it.</p>
            <?php else: ?>
                <p class="pf-wm-name"><?= $h($username) ?></p>
                <p class="pf-wm-sub">
                    <?php if ($verified): ?>
                        Verified on <?= $h(pf_date((string) $profile['username_verified_at'])) ?>. You can request internet support when it is open.
                    <?php else: ?>
                        A DCW reviewer or coordinator will check that this account exists and is yours. Internet support opens once it is verified.
                        If it is taking long, write to us from <a href="/member/talk">Talk to DCW Support</a>.
                    <?php endif; ?>
                </p>
                <div class="pf-actions">
                    <a class="d-btn line" href="<?= $h(MemberProfileModel::globalUrl($username)) ?>" target="_blank" rel="noopener">Global account</a>
                    <a class="d-btn line" href="<?= $h(MemberProfileModel::contribsUrl($username)) ?>" target="_blank" rel="noopener">Contributions</a>
                </div>
            <?php endif; ?>

            <details class="pf-fold"<?= (!$hasName || ($posted && ($_POST['action'] ?? '') === 'save_username')) ? ' open' : '' ?>>
                <summary><?= $hasName ? 'Change username' : 'Add username' ?></summary>
                <form method="POST" action="/member/profile">
                    <?= CSRF::getInputField() ?>
                    <input type="hidden" name="action" value="save_username">
                    <?php if ($verified): ?>
                        <p class="pf-warn">Switching to a different account means it has to be verified again, and internet support stays closed to you until it is.</p>
                    <?php endif; ?>
                    <div class="field">
                        <label for="wikimedia_username">Your Wikimedia username <span class="req-star">*</span></label>
                        <input type="text" id="wikimedia_username" name="wikimedia_username" required maxlength="255"
                               autocomplete="off" value="<?= $h($typedUser) ?>">
                        <span class="hint">The username only, without "User:" or a link.</span>
                    </div>
                    <button type="submit" class="d-btn fill"><?= $hasName ? 'Save username' : 'Add username' ?></button>
                </form>
            </details>
        </section>

        <section class="pf-card" id="about" aria-label="About you">
            <div class="pf-head"><h2>About you</h2></div>
            <p class="pf-lead">Optional. Only you and DCW reviewers and coordinators can see this.</p>
            <form method="POST" action="/member/profile">
                <?= CSRF::getInputField() ?>
                <input type="hidden" name="action" value="save_about">

                <div class="field">
                    <label for="bio">Short bio <span class="pf-count" id="bio-count"></span></label>
                    <textarea id="bio" name="bio" maxlength="<?= (int) MemberProfileModel::BIO_MAX ?>" rows="3"
                              placeholder="A line or two about you and your work with Wikimedia."><?= $h($typedBio) ?></textarea>
                </div>

                <div class="field">
                    <label for="languages">Languages</label>
                    <input type="text" id="languages" name="languages" maxlength="500" autocomplete="off"
                           placeholder="e.g. Urdu, Hindi, English" value="<?= $h($typedLangs) ?>">
                    <span class="hint">Separate with commas. Up to <?= (int) MemberProfileModel::LANGUAGES_MAX ?>.</span>
                </div>

                <div class="field">
                    <label>Where you contribute</label>
                    <div class="pf-areas">
                        <?php foreach (MemberProfileModel::AREAS as $key => $text): ?>
                            <label><input type="checkbox" name="areas[]" value="<?= $h($key) ?>"<?= in_array($key, $typedAreas, true) ? ' checked' : '' ?>> <?= $h($text) ?></label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <button type="submit" class="d-btn fill">Save details</button>
            </form>
        </section>

        <section class="pf-card" id="account" aria-label="Account">
            <div class="pf-head"><h2>Account</h2></div>
            <p class="pf-lead">Signed in as <strong><?= $h($memberId) ?></strong>.</p>
            <div class="pf-acct">
                <form method="POST" action="/member/profile">
                    <?= CSRF::getInputField() ?>
                    <input type="hidden" name="action" value="password_link">
                    <button type="submit" class="d-btn line">Email me a link to change my password</button>
                </form>
                <a class="d-btn soft" href="/member/logout">Sign out</a>
            </div>
            <p class="pf-note">The link goes to <?= $h($email) ?>. Choosing a new password signs you out on every device, including this one.</p>
        </section>

        <?php if ($verified || !empty($profile['updated_at'])): ?>
        <section class="pf-card" aria-label="Profile history">
            <div class="pf-head"><h2>Profile history</h2></div>
            <ul class="pf-hist">
                <?php if ($verified): ?>
                    <li><span>Wikimedia username verified</span><span><?= $h(pf_date((string) $profile['username_verified_at'])) ?></span></li>
                <?php endif; ?>
                <?php if (!empty($profile['updated_at'])): ?>
                    <li><span>Profile last updated</span><span><?= $h(pf_date((string) $profile['updated_at'])) ?></span></li>
                <?php endif; ?>
            </ul>
        </section>
        <?php endif; ?>

    </div>
</div>

</div>

<script>
    // Live character count for the bio.
    (function () {
        var bio = document.getElementById('bio'), out = document.getElementById('bio-count');
        if (!bio || !out) return;
        var max = <?= (int) MemberProfileModel::BIO_MAX ?>;
        function update() { out.textContent = bio.value.length + ' / ' + max; }
        bio.addEventListener('input', update);
        update();
    })();
</script>
<?php cert_rows_js(); /* copy buttons */ ?>
<?php engage_footer(); ?>
