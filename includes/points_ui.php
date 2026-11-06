<?php
// includes/points_ui.php
// Shared by /member/goodies (latest activity and the full history) and /member/dashboard (Goodies card).
// Turns the rows from PointsModel::ledgerDetailed() / pendingForMember() into something a member can read:
// a plain title, one line of detail, a month heading with a subtotal, and repeats folded into one row.
//
// Award notes are NOT shown to members: reviewers may write internal remarks there. The only event detail
// shown comes from the Conversation Hour session an award belongs to (its title and date).

function points_h($s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES);
}

/** One hue per category, the same ones the reviewer pages use. */
function points_cat_hue(string $cat): int
{
    return ['learn' => 205, 'contribute' => 165, 'community' => 28, 'organisation' => 275][$cat] ?? 215;
}

function points_cat_emoji(string $cat, string $code = ''): string
{
    $byCode = ['ch_attend' => '🎙️', 'ch_host' => '🎤', 'taw_graduate' => '🎓'];
    $byCat  = ['learn' => '📚', 'contribute' => '✍️', 'community' => '🤝', 'organisation' => '🗂️'];
    return $byCode[$code] ?? $byCat[$cat] ?? '⭐';
}

/** "j M Y" in India time for a UTC timestamp. */
function points_date(?string $utc, string $fmt = 'j M Y'): string
{
    if (!$utc) return '';
    return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Asia/Kolkata'))->format($fmt);
}

/** The Conversation Hour session an award came from ("Title · 30 Oct 2026"), or ''. */
function points_event_detail(array $r): string
{
    $t = trim((string) ($r['session_title'] ?? ''));
    if ($t === '') return '';
    $d = !empty($r['session_date']) ? ' · ' . date('j M Y', strtotime((string) $r['session_date'])) : '';
    return $t . $d;
}

/**
 * How one ledger (or pending) row reads to the member.
 * @return array{title:string, sub:string, kind:string, emoji:string, hue:int, points:int, type:string, collapse:bool}
 *   kind: earn | spend | pending
 */
function points_entry_view(array $r): array
{
    $type   = (string) ($r['entry_type'] ?? 'award');     // award | redemption | refund | reversal | revaluation | pending
    $p      = (int) $r['points'];
    $reason = (string) ($r['reason'] ?? '');
    $v = ['title' => '', 'sub' => '', 'kind' => $p >= 0 ? 'earn' : 'spend', 'emoji' => '⭐', 'hue' => 215,
          'points' => $p, 'type' => $type, 'collapse' => false];

    switch ($type) {
        case 'award':
        case 'pending':
            $cat  = (string) ($r['category'] ?? '');
            $name = trim((string) ($r['activity_name'] ?? ''));
            if ($name === '') $name = trim((string) preg_replace('/^Award:\s*/', '', $reason));
            $v['title'] = $name !== '' ? $name : 'Points awarded';
            $v['sub']   = points_event_detail($r);
            $v['emoji'] = points_cat_emoji($cat, (string) ($r['activity_code'] ?? ''));
            $v['hue']   = points_cat_hue($cat);
            $v['collapse'] = $type === 'award' && $p > 0;
            if ($type === 'pending') $v['kind'] = 'pending';
            break;

        case 'revaluation':
            if (preg_match('/^Points revised:\s*(.+?)\s*\((\d+) to (\d+)\)/u', $reason, $m)) {
                $v['title'] = $m[1];
                $v['sub']   = 'Points value changed from ' . $m[2] . ' to ' . $m[3];
            } else {
                $v['title'] = 'Points adjusted';
            }
            $v['emoji'] = '🔧';
            break;

        case 'reversal':
            $v['title'] = 'Points taken back';
            $v['sub']   = trim((string) preg_replace('/^Reversal:\s*/', '', $reason));
            $v['emoji'] = '↩️';
            break;

        case 'refund':
            $v['title'] = 'Order cancelled: points refunded';
            $v['sub']   = trim(($r['goody_name'] ?? '') . ' ' . ($r['order_tracking'] ?? ''));
            $v['emoji'] = '↩️';
            break;

        case 'redemption':
            $v['title'] = 'Redeemed: ' . ((string) ($r['goody_name'] ?? '') !== '' ? $r['goody_name'] : 'a goody');
            $v['sub']   = (string) ($r['order_tracking'] ?? '');
            $v['emoji'] = '🎁';
            $v['hue']   = 340;
            break;

        default:
            $v['title'] = $reason !== '' ? $reason : 'Points';
    }
    return $v;
}

/**
 * Newest-first ledger rows grouped by month (India time). Inside a month, awards for the same activity and
 * the same points fold into one slot with the individual rows as children.
 * @return array<string, array{label:string, earned:int, spent:int, slots:list<array{v:array, children:list<array>}>}>
 */
function points_months(array $rows): array
{
    $utc = new DateTimeZone('UTC');
    $ist = new DateTimeZone('Asia/Kolkata');
    $months = [];
    foreach ($rows as $r) {
        $dt  = (new DateTimeImmutable((string) $r['created_at'], $utc))->setTimezone($ist);
        $key = $dt->format('Y-m');
        if (!isset($months[$key])) $months[$key] = ['label' => $dt->format('F Y'), 'earned' => 0, 'spent' => 0, 'raw' => []];
        $v = points_entry_view($r);
        $v['day']     = $dt->format('j M');
        $v['balance'] = $r['balance_after'] ?? null;
        $months[$key]['raw'][] = $v;
        if ($v['points'] > 0) $months[$key]['earned'] += $v['points']; else $months[$key]['spent'] += -$v['points'];
    }
    foreach ($months as &$m) {
        $slots = []; $index = [];
        foreach ($m['raw'] as $v) {
            if ($v['collapse']) {
                $k = $v['title'] . '|' . $v['points'];
                if (isset($index[$k])) { $slots[$index[$k]]['children'][] = $v; continue; }
                $index[$k] = count($slots);
            }
            $slots[] = ['v' => $v, 'children' => [$v]];
        }
        $m['slots'] = $slots;
        unset($m['raw']);
    }
    unset($m);
    return $months;
}

/** Earned and spent in a calendar year (India time), and earned per category. */
function points_summary(array $rows, int $year): array
{
    $utc = new DateTimeZone('UTC');
    $ist = new DateTimeZone('Asia/Kolkata');
    $out = ['year' => $year, 'earned' => 0, 'spent' => 0, 'cats' => []];
    foreach ($rows as $r) {
        $y = (int) (new DateTimeImmutable((string) $r['created_at'], $utc))->setTimezone($ist)->format('Y');
        if ($y !== $year) continue;
        $p = (int) $r['points'];
        $t = (string) $r['entry_type'];
        if (in_array($t, ['redemption', 'refund'], true)) { $out['spent'] -= $p; continue; }
        if ($p > 0) {
            $out['earned'] += $p;
            $c = (string) ($r['category'] ?? '');
            if ($c !== '') $out['cats'][$c] = ($out['cats'][$c] ?? 0) + $p;
        }
    }
    return $out;
}

/** Print the month groups. */
function points_render_months(array $months, bool $showBalance = true): void
{
    foreach ($months as $m): ?>
        <section class="ph-month">
            <header>
                <h3><?= points_h($m['label']) ?></h3>
                <span class="tot"><?= number_format($m['earned']) ?> earned<?= $m['spent'] ? ' · ' . number_format($m['spent']) . ' spent' : '' ?></span>
            </header>
            <ul class="ph-list">
                <?php foreach ($m['slots'] as $slot):
                    $v = $slot['v']; $n = count($slot['children']);
                    $total = array_sum(array_column($slot['children'], 'points'));
                    $sign = $v['points'] >= 0 ? '+' : '−';
                ?>
                    <li class="<?= $v['kind'] ?>" style="--h:<?= (int) $v['hue'] ?>">
                    <?php if ($n > 1): ?>
                        <details class="ph-group">
                            <summary>
                                <span class="ph-ic" aria-hidden="true"><?= $v['emoji'] ?></span>
                                <span class="ph-tx"><b><?= points_h($v['title']) ?> <span class="ph-x">×<?= $n ?></span></b>
                                    <small>Latest <?= points_h($slot['children'][0]['day']) ?> · tap to see each one</small></span>
                                <span class="ph-pt"><strong><?= $sign ?><?= number_format(abs($total)) ?></strong></span>
                            </summary>
                            <ul>
                                <?php foreach ($slot['children'] as $c): ?>
                                    <li><span><?= points_h($c['sub'] !== '' ? $c['sub'] : 'Awarded') ?> <em>(<?= points_h($c['day']) ?>)</em></span>
                                        <span><?= $c['points'] >= 0 ? '+' : '−' ?><?= number_format(abs($c['points'])) ?></span></li>
                                <?php endforeach; ?>
                            </ul>
                        </details>
                    <?php else: ?>
                        <div class="ph-row">
                            <span class="ph-ic" aria-hidden="true"><?= $v['emoji'] ?></span>
                            <span class="ph-tx"><b><?= points_h($v['title']) ?></b>
                                <small><?= points_h($v['sub'] !== '' ? $v['sub'] . ' · ' . $v['day'] : $v['day']) ?></small></span>
                            <span class="ph-pt"><strong><?= $sign ?><?= number_format(abs($v['points'])) ?></strong>
                                <?php if ($showBalance && $v['balance'] !== null): ?><small>Balance <?= number_format((int) $v['balance']) ?></small><?php endif; ?></span>
                        </div>
                    <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endforeach;
}

/** Awards that are saved but not counted yet (waiting for a second reviewer). */
function points_render_pending(array $pending): void
{
    if (!$pending) return; ?>
    <section class="ph-pend" aria-label="Waiting for approval">
        <h3>Waiting for approval <span class="ph-x"><?= count($pending) ?></span></h3>
        <p>A second reviewer has to approve these before they are added to your balance.</p>
        <ul class="ph-list">
            <?php foreach ($pending as $r): $v = points_entry_view($r); ?>
                <li class="pending" style="--h:<?= (int) $v['hue'] ?>">
                    <div class="ph-row">
                        <span class="ph-ic" aria-hidden="true"><?= $v['emoji'] ?></span>
                        <span class="ph-tx"><b><?= points_h($v['title']) ?></b>
                            <small><?= points_h($v['sub'] !== '' ? $v['sub'] . ' · ' : '') ?>Saved <?= points_h(points_date((string) $r['created_at'], 'j M')) ?></small></span>
                        <span class="ph-pt"><strong>+<?= number_format($v['points']) ?></strong><small>Pending</small></span>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>
<?php }

/** Styles for the history rows. Uses the tokens from /assets/css/engage.css. Print once per page. */
function points_history_css(): void
{ ?>
<style>
    .ph-month { margin: 0 0 22px; }
    .ph-month > header { display: flex; flex-wrap: wrap; align-items: baseline; justify-content: space-between; gap: 2px 12px; margin: 0 0 4px; padding-bottom: 6px; border-bottom: 2px solid var(--border); }
    .ph-month h3 { margin: 0; font-size: 15px; font-weight: 800; letter-spacing: -.01em; }
    .ph-month .tot { color: var(--muted); font-size: 13px; font-variant-numeric: tabular-nums; }
    .ph-list { list-style: none; margin: 0; padding: 0; }
    .ph-list > li { border-top: 1px solid var(--border); }
    .ph-list > li:first-child { border-top: 0; }
    .ph-row, .ph-group > summary { display: grid; grid-template-columns: 40px minmax(0, 1fr) auto; gap: 12px; align-items: center; padding: 10px 0; }
    .ph-group > summary { cursor: pointer; list-style: none; }
    .ph-group > summary::-webkit-details-marker { display: none; }
    .ph-group > summary:focus-visible { outline: 3px solid var(--focus); outline-offset: 2px; border-radius: 8px; }
    .ph-ic { display: grid; place-items: center; width: 40px; height: 40px; border-radius: 50%; font-size: 18px; background: hsl(var(--h, 215) 75% 92%); }
    .ph-tx b { display: block; font-size: 14.5px; font-weight: 700; line-height: 1.35; overflow-wrap: anywhere; }
    .ph-tx small { display: block; margin-top: 1px; color: var(--muted); font-size: 12.5px; overflow-wrap: anywhere; }
    .ph-pt { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
    .ph-pt strong { font-size: 17px; font-weight: 800; }
    .ph-pt small { display: block; color: var(--muted); font-size: 11.5px; }
    .earn .ph-pt strong { color: var(--leaf-dark); }
    .spend .ph-pt strong { color: var(--accent); }
    .ph-x { margin-left: 4px; padding: 0 8px; border-radius: 999px; background: #eef3f8; color: var(--muted); font-size: 12px; font-weight: 700; }
    .ph-group > ul { list-style: none; margin: 0 0 8px 52px; padding: 0 0 0 14px; border-left: 2px solid var(--border); }
    .ph-group > ul li { display: flex; justify-content: space-between; gap: 12px; padding: 5px 0; color: var(--muted); font-size: 13px; }
    .ph-group > ul li em { font-style: normal; opacity: .8; }
    .ph-pend { margin: 0 0 22px; padding: 14px 16px 6px; background: #fffbeb; border: 1.5px dashed #fcd34d; border-radius: 14px; }
    .ph-pend h3 { margin: 0; font-size: 15px; font-weight: 800; color: #78350f; }
    .ph-pend > p { margin: 2px 0 6px; color: #92400e; font-size: 13px; }
    .ph-pend .ph-list > li { border-top-color: #fde68a; }
    .ph-pend .ph-pt strong { color: #92400e; }
    .ph-sum { display: flex; flex-wrap: wrap; gap: 8px; margin: 0 0 18px; }
    .ph-sum span { padding: 5px 13px; border-radius: 999px; background: #f6f9fb; border: 1px solid var(--border); font-size: 13.5px; font-weight: 600; font-variant-numeric: tabular-nums; }
    .ph-sum b { font-weight: 800; }
    .ph-sum .c { background: hsl(var(--h) 80% 94%); border-color: transparent; }
</style>
<?php }
