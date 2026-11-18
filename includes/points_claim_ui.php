<?php
// includes/points_claim_ui.php
// The member's side of claiming points, shown inside /member/goodies:
//   /member/goodies?view=claim        the claim form, the member's claims, and the ways to earn that are not claimable
//   /member/goodies?claim=PC-XXXXXXXX one claim: status, the conversation, reply / withdraw
// Needs includes/points_ui.php (points_h, points_cat_hue, points_cat_emoji) and models/PointClaimModel.php.
// Styles: the .pc-* rules live in assets/css/engage.css (section "Claim points").
//
// Members never see who the reviewer is. Every reviewer message is labelled "DCW reviewer", and the model does not
// hand reviewer emails (decided_by, message author) to this page at all. Keep it that way: never print them here.

/** [label, colour token] for a claim as the member sees it. */
function points_claim_status(array $c): array
{
    switch ((string) $c['status']) {
        case 'Submitted':  return ['In review', 'var(--primary)'];
        case 'Needs info': return ['Reply needed', 'var(--amber)'];
        case 'Approved':   return ($c['award_status'] ?? '') === 'Needs Second Approval'
                                ? ['Approved, one more check', 'var(--leaf-dark)'] : ['Approved', 'var(--leaf-dark)'];
        case 'Rejected':   return ['Not approved', 'var(--accent)'];
        case 'Withdrawn':  return ['Withdrawn', 'var(--slate)'];
    }
    return [(string) $c['status'], 'var(--primary)'];
}

/** The styles live in engage.css. Kept (and empty) so existing calls keep working. */
function points_claim_css(): void
{
}

/**
 * The claim form. $old is what the member typed last time (kept when a check fails).
 * $cooldowns is [activity id => "date it can be claimed again"] for activities still on cooldown; those tiles
 * are greyed out and cannot be picked.
 */
function points_claim_render_form(array $activities, array $old = [], array $cooldowns = []): void
{
    $ist      = new DateTimeZone('Asia/Kolkata');
    $today    = new DateTimeImmutable('today', $ist);
    $earliest = PointClaimModel::windowStart($today);
    $min      = $earliest->format('Y-m-d');
    $max      = $today->format('Y-m-d');
    $minLabel = $earliest->format('j M Y');
    $maxLabel = $today->format('j M Y');
    $days     = (int) PointClaimModel::WINDOW_DAYS;
    $sel      = (int) ($old['activity_type_id'] ?? 0);
    ?>
    <section class="pc-card">
        <h2>Claim points</h2>
        <p class="sub">For something you did that DCW did not log for you. A reviewer checks it, usually within a few days. Claims must be made within <?= $days ?> days of the activity, so you can claim things that happened from <strong><?= points_h($minLabel) ?></strong> to <strong><?= points_h($maxLabel) ?></strong>.</p>
        <?php if (!$activities): ?>
            <div class="pc-empty">Nothing can be claimed right now. Points for Conversation Hours and other logged sessions are added for you.</div>
        <?php else: ?>
        <form class="pc-form" method="POST" action="/member/goodies?view=claim" id="pc-form">
            <?= CSRF::getInputField() ?>
            <input type="hidden" name="action" value="claim_submit">
            <span class="pc-lab">What did you do?</span>
            <div class="pc-tiles" role="radiogroup" aria-label="Activity">
                <?php foreach ($activities as $a): $id = (int) $a['id']; $h = points_cat_hue((string) $a['category']);
                    $wait = (string) ($cooldowns[$id] ?? ''); ?>
                    <label class="pc-tile<?= $wait !== '' ? ' wait' : '' ?>" style="--h:<?= $h ?>">
                        <input type="radio" name="activity_type_id" value="<?= $id ?>" data-evidence="<?= (int) $a['needs_evidence'] ?>" required<?= $sel === $id && $wait === '' ? ' checked' : '' ?><?= $wait !== '' ? ' disabled' : '' ?>>
                        <span class="e" aria-hidden="true"><?= points_cat_emoji((string) $a['category'], (string) $a['code']) ?></span>
                        <span class="t"><?= points_h($a['name']) ?>
                            <small><?= (int) $a['needs_evidence'] ? 'Link needed' : 'Link optional' ?><?= (int) ($a['one_time'] ?? 0) ? ' · once only' : '' ?><?= $wait !== '' ? ' · again from ' . points_h($wait) : '' ?></small></span>
                        <span class="p">+<?= number_format((int) $a['points']) ?></span>
                    </label>
                <?php endforeach; ?>
            </div>

            <label class="l" for="pc-date">When did it happen?</label>
            <input type="date" id="pc-date" name="event_date" required min="<?= $min ?>" max="<?= $max ?>"
                   data-min-label="<?= points_h($minLabel) ?>" data-max-label="<?= points_h($maxLabel) ?>"
                   value="<?= points_h($old['event_date'] ?? '') ?>">
            <span class="hint">Pick a date from <?= points_h($minLabel) ?> to <?= points_h($maxLabel) ?>. Older activity can't be claimed here; if there is a good reason, write to DCW Support.</span>

            <label class="l" for="pc-ev">Evidence link <span id="pc-ev-star" class="pc-star" hidden>*</span></label>
            <input type="url" id="pc-ev" name="evidence_url" maxlength="500" placeholder="https://" value="<?= points_h($old['evidence_url'] ?? '') ?>">
            <span class="hint">A diff, a page or a post that shows it. Each link can be used for one claim only.</span>

            <label class="l" for="pc-note">Anything the reviewer should know <span class="pc-opt">(optional)</span></label>
            <textarea id="pc-note" name="note" maxlength="<?= (int) PointClaimModel::MAX_NOTE ?>"><?= points_h($old['note'] ?? '') ?></textarea>

            <button type="submit" class="go">Send claim</button>
        </form>
        <script>
        (function () {
            var f = document.getElementById('pc-form'); if (!f) return;
            var ev = document.getElementById('pc-ev'), star = document.getElementById('pc-ev-star');
            function sync() {
                var r = f.querySelector('input[name=activity_type_id]:checked');
                var need = r && r.dataset.evidence === '1';
                ev.required = !!need; star.hidden = !need;
            }
            f.addEventListener('change', sync); sync();

            var d = document.getElementById('pc-date');
            function dateCheck() {
                d.setCustomValidity('');
                if (d.validity.rangeUnderflow) d.setCustomValidity('Claims can only be made for ' + d.dataset.minLabel + ' or later (<?= $days ?> days).');
                else if (d.validity.rangeOverflow) d.setCustomValidity('The date cannot be after ' + d.dataset.maxLabel + '.');
            }
            d.addEventListener('input', dateCheck); d.addEventListener('change', dateCheck); dateCheck();

            f.addEventListener('submit', function () { setTimeout(function () { f.querySelector('.go').disabled = true; }, 0); });
        })();
        </script>
        <?php endif; ?>
    </section>
<?php }

/** The member's claims as rows (same look as requests). */
function points_claim_render_list(array $claims): void
{
    if (!$claims): ?>
        <div class="pc-empty">You have not made any claims yet.</div>
    <?php return; endif; ?>
    <div class="reqs pc-list">
        <?php foreach ($claims as $c):
            [$label, $tone] = points_claim_status($c);
            $act = $c['status'] === 'Needs info';
        ?>
            <div class="req<?= $act ? ' act' : '' ?>" style="--tone: <?= points_h($tone) ?>;">
                <span class="tick pc-emoji" aria-hidden="true"><?= points_cat_emoji((string) $c['category'], (string) $c['activity_code']) ?></span>
                <div>
                    <h3><?= points_h($c['activity_name']) ?> <span class="pc-pts">+<?= number_format((int) $c['activity_points']) ?></span></h3>
                    <p class="meta">
                        Claim &middot; <?= points_h(date('j M Y', strtotime((string) $c['event_date']))) ?>
                        &middot; <code><?= points_h($c['tracking_id']) ?></code>
                    </p>
                </div>
                <div class="side">
                    <span class="pill"><?= points_h($label) ?></span>
                    <a href="/member/goodies?claim=<?= points_h(rawurlencode((string) $c['tracking_id'])) ?>"><?= $act ? 'Reply' : 'View' ?> &rarr;</a>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php }

/** Activities members cannot claim, so they know how else points arrive. */
function points_claim_render_other(array $others): void
{
    if (!$others) return; ?>
    <section class="pc-card">
        <h2>Other ways to earn</h2>
        <p class="sub">DCW adds these for you, so there is nothing to claim.</p>
        <ul class="pc-more">
            <?php foreach ($others as $o): ?>
                <li><span><?= points_cat_emoji((string) $o['category'], (string) $o['code']) ?> <?= points_h($o['name']) ?></span><b>+<?= number_format((int) $o['points']) ?></b></li>
            <?php endforeach; ?>
        </ul>
    </section>
<?php }

/** One claim: facts, the decision, the conversation, and what the member can do next. */
function points_claim_render_detail(array $c, array $messages): void
{
    [$label, $tone] = points_claim_status($c);
    $ist = fn($ts, $f = 'j M Y, g:i a') => $ts ? (new DateTimeImmutable((string) $ts, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Asia/Kolkata'))->format($f) : '';
    $open = in_array($c['status'], PointClaimModel::OPEN, true);
    ?>
    <section class="pc-card">
        <h2><?= points_h($c['activity_name']) ?> <span class="pill pc-status" style="--tone:<?= points_h($tone) ?>"><?= points_h($label) ?></span></h2>
        <p class="sub">Claim <code><?= points_h($c['tracking_id']) ?></code></p>

        <?php if ($c['status'] === 'Needs info'): ?>
            <div class="pc-banner wait">A reviewer needs a little more information. Read their message below and reply.</div>
        <?php elseif ($c['status'] === 'Approved'): ?>
            <div class="pc-banner ok"><?= ($c['award_status'] ?? '') === 'Needs Second Approval'
                ? 'A reviewer approved this. A second reviewer has to confirm it before the points are added to your balance.'
                : '+' . number_format((int) ($c['activity_points'] ?? 0)) . ' points have been added to your balance.' ?></div>
        <?php elseif ($c['status'] === 'Rejected'): ?>
            <div class="pc-banner bad"><strong>Not approved.</strong> <?= points_h($c['decision_note'] ?? '') ?></div>
        <?php elseif ($c['status'] === 'Withdrawn'): ?>
            <div class="pc-banner wait">You withdrew this claim.</div>
        <?php endif; ?>

        <div class="pc-facts">
            <div><span>Points</span>+<?= number_format((int) $c['activity_points']) ?></div>
            <div><span>It happened on</span><?= points_h(date('j M Y', strtotime((string) $c['event_date']))) ?></div>
            <div><span>Sent</span><?= points_h($ist($c['created_at'])) ?></div>
            <?php if (!empty($c['evidence_url'])): ?><div><span>Evidence</span><a href="<?= points_h($c['evidence_url']) ?>" target="_blank" rel="noopener noreferrer"><?= points_h($c['evidence_url']) ?></a></div><?php endif; ?>
            <?php if (!empty($c['note'])): ?><div class="pc-wide"><span>Your note</span><?= nl2br(points_h($c['note'])) ?></div><?php endif; ?>
        </div>

        <?php if ($messages): ?>
            <h3 class="pc-conv">Conversation</h3>
            <?php foreach ($messages as $m): ?>
                <div class="pc-msg<?= $m['sender'] === 'member' ? ' mine' : '' ?>">
                    <small><?= $m['sender'] === 'member' ? 'You' : 'DCW reviewer' ?> &middot; <?= points_h($ist($m['created_at'])) ?></small>
                    <?= points_h($m['body']) ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <?php if ($c['status'] === 'Needs info'): ?>
            <form class="pc-form" method="POST" action="/member/goodies?claim=<?= points_h(rawurlencode((string) $c['tracking_id'])) ?>">
                <?= CSRF::getInputField() ?>
                <input type="hidden" name="action" value="claim_reply">
                <input type="hidden" name="tracking" value="<?= points_h($c['tracking_id']) ?>">
                <label class="l" for="pc-reply">Your reply</label>
                <textarea id="pc-reply" name="body" required maxlength="<?= (int) PointClaimModel::MAX_NOTE ?>"></textarea>
                <button type="submit" class="go">Send reply</button>
            </form>
        <?php endif; ?>

        <?php if ($open): ?>
            <div class="pc-actions">
                <form method="POST" action="/member/goodies?claim=<?= points_h(rawurlencode((string) $c['tracking_id'])) ?>" onsubmit="return confirm('Withdraw this claim?')">
                    <?= CSRF::getInputField() ?>
                    <input type="hidden" name="action" value="claim_withdraw">
                    <input type="hidden" name="tracking" value="<?= points_h($c['tracking_id']) ?>">
                    <button type="submit" class="d-btn line">Withdraw claim</button>
                </form>
            </div>
        <?php endif; ?>
    </section>
<?php }
