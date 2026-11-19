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
            $partial = (int) ($r['refund_id'] ?? 0) > 0;      // a refund of named units, not an old whole-order refund
            $v['title'] = $partial ? 'Refund: ' . ((string) ($r['goody_name'] ?? '') !== '' ? $r['goody_name'] : 'item') : 'Order cancelled: points refunded';
            $v['sub']   = $partial ? (string) ($r['order_tracking'] ?? '') : trim(($r['goody_name'] ?? '') . ' ' . ($r['order_tracking'] ?? ''));
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

/** The styles live in assets/css/engage.css. Kept (and empty) so existing calls keep working. */
function points_history_css(): void
{
}
