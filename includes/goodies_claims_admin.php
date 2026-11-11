<?php
// includes/goodies_claims_admin.php
// The "Claims" tab of /admin/goodies (support_reviewer and owner). Kept out of goodies.php because that page is
// already long. goodies.php calls goodies_claims_post() for any POST whose "do" starts with "claim_", and
// goodies_claims_render() for ?tab=claims. Needs goodies_flash() and gd_h() from goodies.php, and PointClaimModel.
//
// Rules the page relies on (all enforced in PointClaimModel, not here):
//  - a reviewer never decides a claim from their own Member ID (owners included)
//  - approving makes an ordinary award: awards at PointsModel::SECOND_APPROVAL_AT or more by a non-owner still wait
//    for a second reviewer
//  - rejecting and asking for more information need words; the member reads them
//  - members only claim activity from the last PointClaimModel::WINDOW_DAYS days; approving does not re-check it
//  - members never see who the reviewer is: they see "DCW reviewer". Reviewer names and emails are shown on THIS
//    page only. Reviewers should not sign their messages or put their name in a rejection reason.

/**
 * Handle one claim action. Returns the tab to go back to.
 * @param list<string> $ownIds the signed-in reviewer's own Member IDs
 */
function goodies_claims_post(string $do, PointClaimModel $claims, string $actor, bool $isOwner, array $ownIds): string
{
    $id = (int) ($_POST['claim_id'] ?? 0);

    if ($do === 'claim_approve') {
        $r = $claims->approve($id, $actor, $isOwner, $ownIds);
        goodies_flash($r['ok'] ? 'ok' : 'error', $r['ok']
            ? ($r['awaiting_second'] ? 'Approved. The award is ' . (int) PointsModel::SECOND_APPROVAL_AT . ' points or more, so a different reviewer must confirm it under "Waiting for a second reviewer" before it counts.' : 'Approved. The points are now in the member’s balance.')
            : $r['error']);
    } elseif ($do === 'claim_reject') {
        $r = $claims->reject($id, (string) ($_POST['reason'] ?? ''), $actor);
        goodies_flash($r['ok'] ? 'ok' : 'error', $r['ok'] ? 'Claim rejected. The member can read your reason.' : $r['error']);
    } elseif ($do === 'claim_info') {
        $r = $claims->requestInfo($id, (string) ($_POST['message'] ?? ''), $actor);
        goodies_flash($r['ok'] ? 'ok' : 'error', $r['ok'] ? 'Sent. The claim now waits for the member’s reply.' : $r['error']);
    } elseif ($do === 'claim_bulk_approve') {
        $ids = array_values(array_filter(array_map('intval', (array) ($_POST['claim_ids'] ?? []))));
        if (!$ids) {
            goodies_flash('error', 'Tick at least one claim first.');
        } else {
            $r = $claims->approveMany($ids, $actor, $isOwner, $ownIds);
            goodies_flash($r['approved'] > 0 ? 'ok' : 'error',
                $r['approved'] . ' approved.' . ($r['skipped'] ? ' ' . $r['skipped'] . ' skipped (your own, already decided, or not allowed).' : ''));
        }
    }
    return 'claims';
}

/** Print the tab. $status: open | Approved | Rejected | Withdrawn | all. */
function goodies_claims_render(PointClaimModel $claims, string $status, array $ownIds, bool $isOwner): void
{
    $filters = ['open' => 'Open', 'Approved' => 'Approved', 'Rejected' => 'Rejected', 'Withdrawn' => 'Withdrawn', 'all' => 'All'];
    if (!isset($filters[$status])) $status = 'open';

    $counts = []; $rows = []; $failed = false;
    try {
        $counts = $claims->counts();
        $rows   = $claims->queue($status === 'all' ? '' : $status);
    } catch (Throwable $e) {
        app_log_ignored('goodies_claims_render', $e);
        $failed = true;
    }
    $own  = array_fill_keys(array_map(fn($m) => strtoupper(trim((string) $m)), $ownIds), true);
    $ist  = new DateTimeZone('Asia/Kolkata');
    $when = fn($ts) => $ts ? (new DateTimeImmutable((string) $ts, new DateTimeZone('UTC')))->setTimezone($ist)->format('j M Y, g:i a') : '';
    $hues = ['learn' => 205, 'contribute' => 165, 'community' => 28, 'organisation' => 275];
    $open = ($counts['Submitted'] ?? 0) + ($counts['Needs info'] ?? 0);
    $bulkable = array_filter($rows, fn($r) => in_array($r['status'], PointClaimModel::OPEN, true) && !isset($own[strtoupper((string) $r['member_id'])]));
    ?>
    <style>
        .cl-nav { display: flex; flex-wrap: wrap; gap: 8px; margin: 0 0 16px; }
        .cl-nav a { padding: 7px 16px; border-radius: 999px; border: 1px solid var(--border); background: var(--card); color: var(--ink); font-size: 14px; font-weight: 600; text-decoration: none; }
        .cl-nav a.on { background: var(--ink); border-color: var(--ink); color: #fff; }
        .cl-nav small { margin-left: 4px; opacity: .75; }
        .cl-card { --h: 205; margin: 0 0 14px; padding: 16px 18px; background: var(--card); border: 1px solid var(--border); border-left: 6px solid hsl(var(--h) 60% 50%); border-radius: 14px; }
        .cl-card.info { background: #fffbeb; border-color: #fcd34d; border-left-color: #d97706; }
        .cl-top { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: flex-start; gap: 6px 16px; }
        .cl-top h4 { margin: 0; font-size: 16px; }
        .cl-top .who { margin: 2px 0 0; color: var(--muted); font-size: 13.5px; }
        .cl-pts { padding: 5px 14px; border-radius: 12px; font-weight: 800; font-size: 20px; color: hsl(var(--h) 65% 24%); background: hsl(var(--h) 85% 93%); font-variant-numeric: tabular-nums; }
        .cl-facts { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 8px 18px; margin: 12px 0; padding: 10px 12px; background: #F3F8FC; border-radius: 10px; font-size: 13.5px; }
        .cl-facts span { display: block; font-size: 11.5px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: var(--muted); }
        .cl-facts div { min-width: 0; overflow-wrap: anywhere; }
        .cl-thread .m { margin: 0 0 6px; padding: 8px 12px; background: #fff; border: 1px solid var(--border); border-radius: 10px; font-size: 13.5px; white-space: pre-wrap; overflow-wrap: anywhere; }
        .cl-thread .m.member { background: var(--primary-tint); }
        .cl-thread .m small { display: block; margin-bottom: 2px; color: var(--muted); font-size: 12px; font-weight: 600; }
        .cl-acts { display: flex; flex-wrap: wrap; gap: 8px 10px; align-items: flex-start; margin: 12px 0 0; }
        .cl-acts form { margin: 0; display: flex; flex-wrap: wrap; gap: 6px; align-items: center; }
        .cl-acts input[type=text] { width: 15em; max-width: 100%; padding: 7px 10px; font: inherit; font-size: 13.5px; border: 1.5px solid #BCCFDD; border-radius: 10px; }
        .cl-acts details { flex: 1 1 100%; }
        .cl-acts summary { cursor: pointer; font-size: 13.5px; font-weight: 700; color: var(--primary); }
        .cl-acts details form { margin-top: 8px; }
        .cl-acts details textarea { flex: 1 1 280px; min-height: 60px; padding: 8px 10px; font: inherit; font-size: 13.5px; border: 1.5px solid #BCCFDD; border-radius: 10px; box-sizing: border-box; }
        .cl-acts .cl-hint { flex: 1 1 100%; margin: 0; color: var(--muted); font-size: 12.5px; }
        .cl-tick { display: inline-flex; gap: 6px; align-items: center; font-size: 13px; font-weight: 600; color: var(--muted); margin: 0 0 8px; }
        .cl-tag { display: inline-block; margin-left: 6px; padding: 1px 9px; border-radius: 999px; background: #f1f5f9; color: #475569; font-size: 12px; font-weight: 700; }
        .cl-tag.warn { background: #fef3c7; color: #92400e; }
    </style>

    <nav class="cl-nav" aria-label="Filter claims">
        <?php foreach ($filters as $key => $label):
            $n = $key === 'open' ? $open : ($key === 'all' ? array_sum($counts) : ($counts[$key] ?? 0)); ?>
            <a href="/admin/goodies?tab=claims&amp;status=<?= gd_h($key) ?>"<?= $status === $key ? ' class="on" aria-current="page"' : '' ?>><?= gd_h($label) ?><small><?= (int) $n ?></small></a>
        <?php endforeach; ?>
    </nav>

    <p class="gd-note">Members claim points for activities you have switched on under <a href="/admin/goodies?tab=activities">Activities and points</a> (“Members can claim it”). Approving a claim awards the activity’s points. Members can only claim activity from the last <?= (int) PointClaimModel::WINDOW_DAYS ?> days; for anything older, a reviewer can still award the points on the <a href="/admin/goodies?tab=award">Award tab</a>. You cannot decide a claim from your own Member ID<?= $isOwner ? ', even as an owner' : '' ?>. Members see you only as “DCW reviewer”: keep your name out of your messages and reasons.</p>

    <?php if ($failed): ?>
        <div class="gd-empty gd-card">Claims are not set up yet. Run migrations/2026_11_goodies_claims.sql first.</div>
    <?php elseif (!$rows): ?>
        <div class="gd-empty gd-card"><?= $status === 'open' ? 'No claims are waiting. 🎉' : 'No claims here.' ?></div>
    <?php else: ?>

        <?php if ($bulkable): ?>
            <form method="POST" action="/admin/goodies?tab=claims" id="bulk-claims"><?= CSRF::getInputField() ?></form>
            <div class="gd-wait-bar">
                <label><input type="checkbox" id="claim-all"> Select all</label>
                <button type="submit" form="bulk-claims" name="do" value="claim_bulk_approve" class="gd-btn">Approve selected</button>
            </div>
        <?php endif; ?>

        <?php foreach ($rows as $c):
            $id = (int) $c['id'];
            $isOpen = in_array($c['status'], PointClaimModel::OPEN, true);
            $mine = isset($own[strtoupper((string) $c['member_id'])]);
            $h = $hues[(string) $c['category']] ?? 215;
            $waitMember = $c['status'] === 'Needs info';
        ?>
            <article class="cl-card<?= $waitMember ? ' info' : '' ?>" style="--h:<?= $h ?>">
                <div class="cl-top">
                    <div>
                        <h4><?php if ($isOpen && !$mine): ?><label class="gd-tick"><input type="checkbox" class="claim-pick" form="bulk-claims" name="claim_ids[]" value="<?= $id ?>" aria-label="Select claim <?= gd_h($c['tracking_id']) ?>"></label><?php endif; ?><?= gd_h($c['activity_name']) ?>
                            <span class="cl-tag"><?= gd_h($c['status']) ?></span>
                            <?php if ($mine): ?><span class="cl-tag warn">Your own claim</span><?php endif; ?>
                            <?php if ((int) $c['activity_points'] >= PointsModel::SECOND_APPROVAL_AT && !$isOwner): ?><span class="cl-tag warn">Needs 2nd approval</span><?php endif; ?></h4>
                        <p class="who"><?= gd_h($c['member_name'] ?: 'Member') ?> · <?= gd_h($c['member_id']) ?> · <code><?= gd_h($c['tracking_id']) ?></code> · sent <?= gd_h($when($c['created_at'])) ?></p>
                    </div>
                    <span class="cl-pts">+<?= number_format((int) $c['activity_points']) ?></span>
                </div>

                <div class="cl-facts">
                    <div><span>It happened on</span><?= gd_h(date('j M Y', strtotime((string) $c['event_date']))) ?></div>
                    <div><span>Evidence</span><?php if (!empty($c['evidence_url'])): ?><a href="<?= gd_h($c['evidence_url']) ?>" target="_blank" rel="noopener noreferrer"><?= gd_h($c['evidence_url']) ?></a><?php else: ?><?= (int) $c['needs_evidence'] ? '<strong style="color:#97161b">Missing</strong>' : 'None given' ?><?php endif; ?></div>
                    <?php if (!empty($c['wm_username'])): ?><div><span>Wikimedia username</span><?= gd_h($c['wm_username']) ?> (verified)</div><?php endif; ?>
                    <?php if (!empty($c['prev_claim_at'])): ?><div><span>Previous claim for this</span><?= gd_h($when($c['prev_claim_at'])) ?><?= !empty($c['cooldown_days']) ? ' (can be claimed every ' . (int) $c['cooldown_days'] . ' days)' : '' ?></div><?php endif; ?>
                    <?php if (!empty($c['note'])): ?><div style="grid-column:1/-1"><span>Member’s note</span><?= nl2br(gd_h($c['note'])) ?></div><?php endif; ?>
                    <?php if ($c['status'] === 'Rejected' && !empty($c['decision_note'])): ?><div style="grid-column:1/-1"><span>Reason given</span><?= gd_h($c['decision_note']) ?></div><?php endif; ?>
                    <?php if (!empty($c['decided_by'])): ?><div><span>Decided by (reviewers only)</span><?= gd_h($c['decided_by']) ?> · <?= gd_h($when($c['decided_at'])) ?></div><?php endif; ?>
                    <?php if ($c['status'] === 'Approved' && ($c['award_status'] ?? '') === 'Needs Second Approval'): ?><div><span>Award</span>Waiting for a second reviewer</div><?php endif; ?>
                </div>

                <?php if ($c['messages']): ?>
                    <div class="cl-thread">
                        <?php foreach ($c['messages'] as $m): ?>
                            <div class="m<?= $m['sender'] === 'member' ? ' member' : '' ?>"><small><?= $m['sender'] === 'member' ? 'Member' : gd_h((string) $m['author']) ?> · <?= gd_h($when($m['created_at'])) ?></small><?= gd_h($m['body']) ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if ($isOpen): ?>
                    <div class="cl-acts">
                        <form method="POST" action="/admin/goodies?tab=claims"><?= CSRF::getInputField() ?>
                            <input type="hidden" name="do" value="claim_approve"><input type="hidden" name="claim_id" value="<?= $id ?>">
                            <button type="submit" class="gd-btn"<?= $mine ? ' disabled title="You cannot decide your own claim"' : '' ?>>Approve</button>
                        </form>
                        <form method="POST" action="/admin/goodies?tab=claims"><?= CSRF::getInputField() ?>
                            <input type="hidden" name="do" value="claim_reject"><input type="hidden" name="claim_id" value="<?= $id ?>">
                            <input type="text" name="reason" required maxlength="<?= (int) PointClaimModel::MAX_NOTE ?>" placeholder="Reason (the member reads this, no names)" aria-label="Reason for rejecting <?= gd_h($c['tracking_id']) ?>. The member reads it; do not include your name.">
                            <button type="submit" class="gd-btn red">Reject</button>
                        </form>
                        <details>
                            <summary><?= $waitMember ? 'Send another message' : 'Ask for more information' ?></summary>
                            <form method="POST" action="/admin/goodies?tab=claims"><?= CSRF::getInputField() ?>
                                <input type="hidden" name="do" value="claim_info"><input type="hidden" name="claim_id" value="<?= $id ?>">
                                <textarea name="message" required maxlength="<?= (int) PointClaimModel::MAX_NOTE ?>" placeholder="What do you need to know? The member reads this and replies." aria-label="Message about <?= gd_h($c['tracking_id']) ?>. The member reads it; do not include your name."></textarea>
                                <button type="submit" class="gd-btn line">Send</button>
                                <p class="cl-hint">The member sees this as “DCW reviewer”. Do not sign it or include your name.</p>
                            </form>
                        </details>
                    </div>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>

        <script>
        (function () {
            var all = document.getElementById('claim-all');
            if (all) all.addEventListener('change', function () {
                [].slice.call(document.querySelectorAll('.claim-pick')).forEach(function (c) { c.checked = all.checked; });
            });
        })();
        </script>
    <?php endif; ?>
<?php }
