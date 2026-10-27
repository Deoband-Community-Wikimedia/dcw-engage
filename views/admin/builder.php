<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/require_role.php';
require_once __DIR__ . '/../../includes/engage_page.php';
require_once __DIR__ . '/../../models/FormModel.php';

// Creating and editing forms (and their schemas): organizers and owners for ordinary forms.
// Membership coordinators get in too, but only for membership forms of their OWN chapters
// (FormModel::userCanEdit decides, for the form being edited and for the slug being saved).
// Before this, any signed-in account could open the builder.
Auth::requireLogin();
requireRole(['owner', 'organizer', 'membership_coordinator']);

$formModel = new FormModel();

$success = '';
$error = '';

$existingSchema = null;
$existingFormType = '';
$existingNotifyEmails = '';
$existingDeadlineInput = '';   // the saved deadline, in IST, in datetime-local format
$existingUnlisted = false;     // "link only": live, but not listed on the home page
$existingKind = 'application'; // 'application' or 'feedback'; fixed once the form exists
if (isset($_GET['edit'])) {
    $form = $formModel->getFormById($_GET['edit']);
    // Same response for "missing" and "not yours to open", so nobody can tell
    // whether a form they can't edit exists. Membership forms: owners, and
    // coordinators of that form's chapter.
    if (!$form || !FormModel::userCanEdit($form)) {
        http_response_code(404);
        die("Form not found.");
    }
    $existingSchema = $form['schema'];
    $existingFormType = $form['form_type'];
    $existingNotifyEmails = $form['notify_emails'] ?? '';
    $existingDeadlineInput = FormModel::utcToIstInput($form['deadline_at'] ?? null);
    $existingUnlisted = !empty($form['schema']['unlisted']);
    $existingKind = (($form['schema']['kind'] ?? '') === 'feedback') ? 'feedback' : 'application';
}

// What the deadline box shows: what was just posted (so a failed save does not lose it), else the saved value.
$deadlineValue = $existingDeadlineInput;
// Same for the "link only" checkbox.
$unlistedValue = $existingUnlisted;
// And for the form type.
$kindValue = $existingKind;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CSRF::validate($_POST['csrf_token'])) {
        die("Invalid CSRF token.");
    }
    
    $formType = trim($_POST['form_type']);
    $schemaJson = $_POST['schema_json'];
    $notifyEmailsRaw = trim($_POST['notify_emails'] ?? '');
    $deadlineRaw = trim($_POST['deadline_at'] ?? '');
    $deadlineValue = $deadlineRaw;
    $unlistedChecked = !empty($_POST['unlisted']);
    $unlistedValue = $unlistedChecked;

    // Editing an existing form is identified by its stable form_id,
    // not by matching form_type — matching on form_type meant that
    // renaming a form's slug found no existing row to match against
    // and silently INSERTed a brand new form instead of updating the
    // one being edited (see #56).
    $formId = !empty($_POST['form_id']) ? (int)$_POST['form_id'] : null;

    // The form being edited must be one this user may edit. This is checked
    // on the POST itself, not just on page load, since a crafted POST skips
    // the page.
    $savedDeadlineInput = '';
    $savedKind = null;
    if ($formId) {
        $target = $formModel->getFormById($formId);
        if (!$target || !FormModel::userCanEdit($target)) {
            http_response_code(404);
            die("Form not found.");
        }
        $savedDeadlineInput = FormModel::utcToIstInput($target['deadline_at'] ?? null);
        $savedKind = (($target['schema']['kind'] ?? '') === 'feedback') ? 'feedback' : 'application';
    }

    // Form type: an existing form keeps the type it was created with (its responses live in a table
    // that depends on it); only a new form takes the type from the select. The select is disabled
    // when editing, so it is not even posted then.
    $kind = $savedKind ?? ((($_POST['form_kind'] ?? '') === 'feedback') ? 'feedback' : 'application');
    $kindValue = $kind;

    // Normalise the comma-separated recipients and validate each one.
    // Empty is allowed — a form with no recipients simply sends no alerts.
    $notifyEmails = '';
    $badEmail = null;
    if ($notifyEmailsRaw !== '') {
        $parts = array_filter(array_map('trim', explode(',', $notifyEmailsRaw)));
        foreach ($parts as $addr) {
            if (!filter_var($addr, FILTER_VALIDATE_EMAIL)) {
                $badEmail = $addr;
                break;
            }
        }
        $notifyEmails = implode(', ', $parts);
    }

    // Deadline: optional. Typed in IST, stored in UTC. A new deadline must be in the future;
    // an unchanged one is left alone even if it has already passed (the form is just closed).
    $deadlineUtc = null;
    $deadlineError = null;
    if ($deadlineRaw !== '') {
        $deadlineTs = FormModel::istInputToTs($deadlineRaw);
        if ($deadlineTs === null) {
            $deadlineError = "The deadline is not a valid date and time.";
        } elseif ($deadlineRaw !== $savedDeadlineInput && $deadlineTs <= time()) {
            $deadlineError = "The deadline must be in the future.";
        } else {
            $deadlineUtc = gmdate('Y-m-d H:i:s', $deadlineTs);
        }
    }

    if (empty($formType) || empty($schemaJson)) {
        $error = "Form type and schema are required.";
    } elseif (!preg_match('/^[a-z0-9_-]+$/', $formType)) {
        $error = "Invalid URL Slug. Use only lowercase letters, numbers, hyphens, and underscores.";
    } elseif (!FormModel::userCanEdit(['form_type' => $formType])) {
        // The NEW slug counts too: otherwise an organizer could rename a form
        // into a membership slug (or a coordinator into another chapter's), or
        // create one, and bypass the rule above.
        $error = htmlspecialchars(FormModel::editDeniedMessage($formType));
    } elseif ($badEmail !== null) {
        $error = "Notification email '" . htmlspecialchars($badEmail) . "' is not a valid address.";
    } elseif ($deadlineError !== null) {
        $error = htmlspecialchars($deadlineError);
    } else {
        // Validate JSON
        $decodedSchema = json_decode($schemaJson, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decodedSchema)) {
            $error = "Invalid JSON schema format generated.";
        } elseif (!FormModel::hasUniqueFieldNames($decodedSchema)) {
            $error = "Every question must have a unique, non-empty field name. Please use distinct question labels and try again.";
        } elseif ($kind === 'feedback' && in_array('file', array_column(FormModel::schemaFields($decodedSchema), 'type'), true)) {
            // Feedback responses are stored without any upload handling, so a file question could never work.
            $error = "Feedback forms cannot include file upload questions.";
        } else {
            // "Link only" is set here, on the server, from the checkbox, rather than by builder.js.
            // That way the flag is always exactly what the checkbox says, and a builder that only
            // rebuilds the known keys can never silently drop it.
            if ($unlistedChecked) {
                $decodedSchema['unlisted'] = true;
            } else {
                unset($decodedSchema['unlisted']);
            }

            // Same for the form type: "kind": "feedback" is written here, not by builder.js.
            // Applications carry no "kind" key at all, so every existing form stays an application.
            if ($kind === 'feedback') {
                $decodedSchema['kind'] = 'feedback';
            } else {
                unset($decodedSchema['kind']);
            }
            // Preserve the legacy flat field list for review pages and filters that still read schema['fields'];
            // sections remains available for layout and display order.
            $decodedSchema['fields'] = FormModel::schemaFields($decodedSchema);
            $schemaJson = json_encode($decodedSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            global $db;

            try {
                if ($formId) {
                    $stmt = $db->prepare("UPDATE forms SET form_type = ?, schema_json = ?, notify_emails = ?, deadline_at = ? WHERE id = ?");
                    $stmt->execute([$formType, $schemaJson, $notifyEmails !== '' ? $notifyEmails : null, $deadlineUtc, $formId]);
                } else {
                    $stmt = $db->prepare("INSERT INTO forms (form_type, schema_json, notify_emails, deadline_at, is_active) VALUES (?, ?, ?, ?, 1)");
                    $stmt->execute([$formType, $schemaJson, $notifyEmails !== '' ? $notifyEmails : null, $deadlineUtc]);
                }
                // Back to the workspace dashboard on success (see #47) — a
                // standard Post/Redirect/Get, same pattern already used by every
                // other admin page's POST handler in this codebase.
                header('Location: /admin/dashboard');
                exit;
            } catch (PDOException $e) {
                // form_type is UNIQUE — this fires if the new/renamed slug
                // collides with a different, already-existing form.
                if ($e->getCode() === '23000') {
                    $error = "That URL slug is already in use by another form. Please choose a different one.";
                } else {
                    $error = "Something went wrong while saving the form. Please try again.";
                }
            }
        }
    }
}

// Earliest time the date picker allows (now, in IST).
$deadlineMin = (new DateTimeImmutable('now', new DateTimeZone(FormModel::DISPLAY_TZ)))->format('Y-m-d\TH:i');

// Slug examples for a membership coordinator, built from the chapters they are assigned to.
$slugHint = '';
$coordChapters = FormModel::coordinatorChapters();
if ($coordChapters) {
    $examples = array_map(fn($c) => 'membership-' . $c . ' or membership-renewal-' . $c . '-2027', $coordChapters);
    $slugHint = 'Membership forms for your chapters must start with: ' . implode('; ', $examples) . '.';
}

// Page shell: header band, staff stylesheet, noindex and the shared footer all come from engage_header()/engage_footer().
engage_header([
    'title'   => 'Visual form builder',
    'heading' => isset($_GET['edit']) ? 'Edit form' : 'New form',
    'kicker'  => 'Organizer workspace',
    'lead'    => 'Build the questions, then save and publish.',
    'tools'   => '',
    'crumbs'  => [['Workspace', '/admin/dashboard'], ['Form builder']],
]);
?>
    <div class="builder">
        <?php if ($success): ?><div class="alert ok"><?= $success ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert error"><?= $error ?></div><?php endif; ?>

        <form id="builderForm" method="POST">
            <?= CSRF::getInputField() ?>
            <input type="hidden" name="schema_json" id="schema_json_input">
            <input type="hidden" name="form_id" value="<?= isset($_GET['edit']) ? (int)$_GET['edit'] : '' ?>">

            <!-- Global Form Settings -->
            <div class="header-card">
                <input type="text" class="field-title-input" id="form_title" placeholder="Form Title (e.g. Untitled Form)" required style="font-size: 28px !important; width: 100%;">
                
                <div id="description_toolbar" style="display:flex; gap:6px; margin-bottom:6px; flex-wrap:wrap;">
                    <button type="button" class="btn-outline btn-sm" title="Bold" data-wiki-wrap="'''" style="font-weight:700;">B</button>
                    <button type="button" class="btn-outline btn-sm" title="Italic" data-wiki-wrap="''" style="font-style:italic;">I</button>
                    <button type="button" class="btn-outline btn-sm" title="Link" data-wiki-link="1">Link</button>
                    <button type="button" class="btn-outline btn-sm" title="Large heading" data-wiki-heading="==">H1</button>
                    <button type="button" class="btn-outline btn-sm" title="Medium heading" data-wiki-heading="===">H2</button>
                    <button type="button" class="btn-outline btn-sm" title="Increase indent (up to 3 levels)" data-wiki-indent="1">Indent</button>
                    <button type="button" class="btn-outline btn-sm" title="Bullet list" data-wiki-list="*">&bull; List</button>
                    <button type="button" class="btn-outline btn-sm" title="Numbered list" data-wiki-list="#">1. List</button>
                </div>
                <textarea id="form_description" placeholder="Form Description (Optional)" style="width: 100%; padding: 12px; margin-bottom: 5px; border: 1.5px solid var(--border); border-radius: 10px; font-family: 'Inter', sans-serif; font-size: 14px; min-height: 140px; overflow-y: hidden; resize: vertical;"></textarea>
                <span class="hint" style="margin-bottom: 20px;">Formatting supported: '''bold''', ''italic'', [https://example.com link text], == Large heading ==, === Medium heading === (each heading must start and end its own line), :/::/::: for indenting up to 3 levels, and */# for bullet/numbered lists (consecutive lines of the same marker group into one list) — use the buttons above or type wikitext directly.</span>

                <input type="url" id="banner_image" placeholder="Banner Image URL (Optional, e.g. https://example.com/banner.jpg)" style="width: 100%; padding: 12px; margin-bottom: 20px; border: 1.5px solid var(--border); border-radius: 10px; font-family: 'Inter', sans-serif; font-size: 14px;">
                
                <label>Form type</label>
                <?php /* Disabled when editing: a disabled select is not posted, and the server keeps the saved type anyway. */ ?>
                <select name="form_kind" id="form_kind" <?= isset($_GET['edit']) ? 'disabled' : '' ?> style="margin-bottom: 0;">
                    <option value="application" <?= $kindValue === 'application' ? 'selected' : '' ?>>Application (tracked, drafts, review queue)</option>
                    <option value="feedback" <?= $kindValue === 'feedback' ? 'selected' : '' ?>>Feedback (receipt email only, no tracking)</option>
                </select>
                <span class="hint">
                    <?= isset($_GET['edit']) ? 'The type cannot be changed after a form is created.' : 'Choose carefully: the type cannot be changed after the form is saved.' ?>
                </span>
                <div style="height: 20px;"></div>

                <label>URL Slug (Identifier)</label>
                <input type="text" name="form_type" id="form_type" required placeholder="e.g. fellowship-2026" style="margin-bottom: 0;">
                <span class="hint">Users will access this form at: /&lt;slug&gt;</span>
                <?php if ($slugHint): ?>
                    <span class="hint b-slug-hint"><?= htmlspecialchars($slugHint) ?></span>
                <?php endif; ?>

                <label style="margin-top: 20px;">Alert Emails (Optional)</label>
                <input type="text" name="notify_emails" id="notify_emails" placeholder="e.g. clublead@dcwwiki.org, coordinator@dcwwiki.org" value="<?= htmlspecialchars($existingNotifyEmails, ENT_QUOTES) ?>" style="margin-bottom: 0;">
                <span class="hint">Organizers notified when someone submits this form. Comma-separate multiple addresses. Leave blank for none.</span>

                <label style="margin-top: 20px;">Deadline (Optional)</label>
                <?php /* No min when the saved deadline has already passed: the browser would refuse to save any other edit to a closed form. The server checks a CHANGED deadline is in the future. */ ?>
                <input type="datetime-local" name="deadline_at" id="deadline_at" value="<?= htmlspecialchars($deadlineValue, ENT_QUOTES) ?>"<?= ($deadlineValue === '' || $deadlineValue >= $deadlineMin) ? ' min="' . htmlspecialchars($deadlineMin, ENT_QUOTES) . '"' : '' ?> style="margin-bottom: 0;">
                <span class="hint">The form closes automatically at this time (IST, India Standard Time). Leave blank for no deadline. You can extend it later from the form's manager page.</span>

                <label style="margin-top: 20px; display:flex; align-items:center; gap:8px; cursor:pointer;">
                    <input type="checkbox" name="unlisted" id="unlisted" value="1" <?= $unlistedValue ? 'checked' : '' ?> style="margin:0;">
                    <span>Link only (do not list on the home page)</span>
                </label>
                <span class="hint">The form stays live and anyone with its link can open it, but it is left out of the public "Open programs" list and asked not to be indexed by search engines. Share the link only with the people who should fill it in.</span>
            </div>

            <div class="b-notice" id="notice_application">
                <strong>Notice:</strong> Applicants give their <em>Name</em> and <em>Email Address</em> when they verify their email, before the form opens, and both are carried into the application automatically (the email is also used for Magic Links). You do not need to add questions for them below. A question that only asks for the applicant's name is hidden from applicants and filled in from that verified name. A combined "Name and age" question is kept, since it also collects the age.
            </div>
            <div class="b-notice" id="notice_feedback" style="display:none;">
                <strong>Notice:</strong> <em>Name</em> and <em>Email Address</em> fields are added automatically to the top of a feedback form. The email is used only to send a receipt, and organizers can write back from the Feedback page if they need to. Feedback forms cannot have file uploads.
            </div>
            <script>
                (function () {
                    var sel = document.getElementById('form_kind');
                    function sync() {
                        var fb = sel.value === 'feedback';
                        document.getElementById('notice_application').style.display = fb ? 'none' : '';
                        document.getElementById('notice_feedback').style.display = fb ? '' : 'none';
                    }
                    sel.addEventListener('change', sync);
                    sync();
                })();
            </script>

            <!-- Sections contain their own title, optional introduction, and ordered questions. -->
            <div id="sections_container"></div>

            <div style="text-align: center; margin-top: 24px;">
                <button type="button" class="btn-outline" id="add_section_btn" style="border-radius: 50px; padding: 12px 30px;">+ Add Section</button>
            </div>

            <!-- Floating Save Actions -->
            <div class="floating-action">
                <button type="button" class="btn-outline" id="preview_form_btn">Preview</button>
                <button type="submit" class="btn-primary">Save Form &amp; Publish</button>
            </div>
        </form>

        <!-- Opens the live public-form template in a new tab against the
             builder's current (unsaved) schema — see #47. Submitted via JS
             once the schema is built, same as the main form. -->
        <form id="previewForm" method="POST" action="/admin/preview_form" target="_blank" style="display:none;">
            <?= CSRF::getInputField() ?>
            <input type="hidden" name="schema_json" id="preview_schema_json_input">
        </form>
    </div>

    <script>
        // Load existing schema if editing
        const existingSchema = <?= isset($existingSchema) && $existingSchema ? json_encode($existingSchema) : 'null' ?>;
        const existingFormType = <?= isset($existingFormType) ? json_encode($existingFormType) : 'null' ?>;
    </script>
    <script>
        // Preview: builder.js builds the schema, but "kind" and "unlisted" are written by the SERVER on
        // save, so the preview payload would not carry them and a feedback form would preview with the
        // application layout. Add them from the form-type select and the "Link only" checkbox just
        // before the preview form is sent. Works whether builder.js calls submit() or requestSubmit().
        (function () {
            var pf = document.getElementById('previewForm');
            var out = document.getElementById('preview_schema_json_input');
            var kindSel = document.getElementById('form_kind');
            var unl = document.getElementById('unlisted');
            if (!pf || !out || !kindSel) return;

            function patch() {
                try {
                    var s = JSON.parse(out.value);
                    if (!s || typeof s !== 'object') return;
                    if (kindSel.value === 'feedback') { s.kind = 'feedback'; } else { delete s.kind; }
                    if (unl && unl.checked) { s.unlisted = true; } else { delete s.unlisted; }
                    out.value = JSON.stringify(s);
                } catch (e) { /* leave the value as builder.js set it */ }
            }

            var nativeSubmit = HTMLFormElement.prototype.submit;
            pf.submit = function () { patch(); nativeSubmit.call(pf); };
            pf.addEventListener('submit', patch);
        })();
    </script>
    <script src="/assets/js/builder.js?v=<?= filemtime(__DIR__ . '/../../assets/js/builder.js') ?>"></script>
<?php engage_footer(); ?>
