<?php
// includes/internal_notes_ui.php
// The "Internal notes" box shown under a request card on the staff pages
// (finance queue, internet review, reimbursement review).
//
// Call it OUTSIDE any other <form> (HTML does not allow nested forms):
//
//   internal_notes_block('internet', (int) $req['id'], $noteModel->forRequest('internet', (int) $req['id']), 'decision', 'internet');
//
// $buttonName is the name of the submit button the page's POST handler reads:
// 'result' on the finance page, 'decision' on the two review pages. The value is always 'internal_note'.
// $tab is only used by the finance page, so the page stays on the same tab afterwards.

function internal_notes_block(string $queue, int $id, array $notes, string $buttonName = 'result', string $tab = ''): void
{
    static $styled = false;
    $h = fn($s) => htmlspecialchars((string) $s);

    if (!$styled) {
        $styled = true;
        echo '<style>
            .inotes { margin: 0 0 14px; padding: 10px 12px; background: #fffbeb; border: 1px solid #fde68a; border-radius: 10px; font-size: 14px; }
            .inotes summary { cursor: pointer; font-weight: 700; color: #92400e; }
            .inotes .inote { margin: 10px 0 0; padding: 8px 10px; background: #fff; border: 1px solid #fde68a; border-radius: 8px; }
            .inotes .inote-meta { font-size: 12px; color: #78716c; }
            .inotes form { margin-top: 10px; }
            .inotes textarea { width: 100%; box-sizing: border-box; min-height: 60px; }
            .inotes .inote-btn { margin-top: 6px; padding: 8px 18px; border: none; border-radius: 999px; cursor: pointer; font: inherit; font-size: 13.5px; font-weight: 700; color: #fff; background: linear-gradient(135deg, #475569, #64748b); }
        </style>';
    }
    ?>
    <details class="inotes"<?= $notes ? ' open' : '' ?>>
        <summary>Internal notes (<?= count($notes) ?>): staff only, never shown to the applicant</summary>
        <?php foreach ($notes as $n): ?>
            <div class="inote">
                <div class="inote-meta"><?= $h($n['author_email']) ?> &middot; <?= $h($n['created_at']) ?> UTC</div>
                <?= nl2br($h($n['body'])) ?>
            </div>
        <?php endforeach; ?>
        <form method="POST">
            <?= CSRF::getInputField() ?>
            <?= CSRF::getSubmitField() ?>
            <input type="hidden" name="queue" value="<?= $h($queue) ?>">
            <input type="hidden" name="tab" value="<?= $h($tab) ?>">
            <input type="hidden" name="request_id" value="<?= (int) $id ?>">
            <textarea name="internal_note" maxlength="2000" required placeholder="Add an internal note (colleagues can see it; the applicant cannot)"></textarea>
            <button type="submit" name="<?= $h($buttonName) ?>" value="internal_note" class="inote-btn">Add note</button>
        </form>
    </details>
    <?php
}
