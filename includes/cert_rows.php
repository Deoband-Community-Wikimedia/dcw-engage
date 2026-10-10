<?php
/**
 * DCW Engage - shared certificate row, used by /member/dashboard and /member/certificates
 * so both pages look and behave the same.
 *
 * Needs dash_date() from includes/member_requests.php (already loaded by both pages).
 * Call cert_rows_css() once inside the page, cert_row() per certificate, cert_rows_js() before </body>.
 */

/** Small inline icon. */
function cert_icon(string $key): string
{
    static $paths = [
        'award'    => '<circle cx="12" cy="8" r="7"/><polyline points="8.21 13.89 7 23 12 20 17 23 15.79 13.88"/>',
        'download' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>',
        'link'     => '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
        'verified' => '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>',
        'copy'     => '<rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>',
        'more'     => '<circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/><circle cx="5" cy="12" r="1"/>',
    ];
    return '<svg class="i" viewBox="0 0 24 24" aria-hidden="true">' . ($paths[$key] ?? '') . '</svg>';
}

/**
 * One certificate row: title and details on the left, Download plus a "more" menu on the right.
 * Verify, Copy link and Copy ID live in the menu so each row stays a single line on desktop.
 */
function cert_row(array $c, string $defaultBase): void
{
    $h    = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $base = rtrim((string) (!empty($c['base_url']) ? $c['base_url'] : $defaultBase), '/');
    $cid  = rawurlencode((string) $c['certificate_id']);
    $verifyUrl = $base . '/verify/' . $cid;

    // "New" for certificates issued in the last 14 days.
    $ts    = !empty($c['issued_at']) ? strtotime((string) $c['issued_at']) : false;
    $isNew = $ts && $ts > time() - 14 * 86400;

    $parts = [];
    if (!empty($c['org_name'])) $parts[] = $h($c['org_name']);
    $parts[] = $h($c['role_name'] ?: 'Participant');
    $parts[] = $h(dash_date($c['issued_at']));
    ?>
    <article class="c-row">
        <span class="c-seal"><?= cert_icon('award') ?></span>
        <div class="c-body">
            <h3><?= $h($c['event_name']) ?><?php if ($isNew): ?><span class="c-new">New</span><?php endif; ?></h3>
            <p><?= implode(' &middot; ', $parts) ?> &middot; <code><?= $h($c['certificate_id']) ?></code></p>
        </div>
        <div class="c-acts">
            <a class="d-btn soft" href="<?= $h($base) ?>/download.php?id=<?= $cid ?>"><?= cert_icon('download') ?>Download</a>
            <details class="c-menu">
                <summary aria-label="More actions for <?= $h($c['event_name']) ?>"><?= cert_icon('more') ?></summary>
                <div class="c-pop">
                    <a href="<?= $h($verifyUrl) ?>" target="_blank" rel="noopener"><?= cert_icon('verified') ?>Verify</a>
                    <button type="button" data-copy="<?= $h($verifyUrl) ?>"><?= cert_icon('link') ?><span>Copy link</span></button>
                    <button type="button" data-copy="<?= $h($c['certificate_id']) ?>"><?= cert_icon('copy') ?><span>Copy ID</span></button>
                </div>
            </details>
        </div>
    </article>
    <?php
}

/** Styles shared by both pages: icons, buttons, certificate rows. Scoped under .dash. */
function cert_rows_css(): void
{
    ?>
    <style>
        .dash svg.i { width: 18px; height: 18px; flex: none; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }

        /* Buttons: pills in the same family as .btn-primary / .btn-ghost */
        .d-btn { display: inline-flex; align-items: center; justify-content: center; gap: 7px; padding: 9px 18px; border-radius: 999px; border: 1px solid transparent; font: inherit; font-size: 14px; font-weight: 700; line-height: 1.2; text-decoration: none; cursor: pointer; transition: background .15s, border-color .15s; }
        .d-btn.fill { color: #fff; background: linear-gradient(135deg, var(--primary-dark), var(--primary)); box-shadow: 0 5px 14px rgba(46,101,153,.3); }
        .d-btn.fill:hover { box-shadow: 0 9px 20px rgba(46,101,153,.38); }
        .d-btn.line { color: var(--primary); background: #fff; border-color: var(--primary); }
        .d-btn.line:hover { background: var(--primary-tint); }
        .d-btn.soft { padding: 7px 14px; font-size: 13.5px; color: var(--primary-dark); background: var(--primary-tint); }
        .d-btn.soft:hover { background: color-mix(in srgb, var(--primary) 16%, #fff); }
        .d-btn.amber { color: #fff; background: #92400e; padding: 8px 16px; }
        .d-btn.amber:hover { background: #78350f; }
        .d-btn:focus-visible, .c-menu summary:focus-visible, .c-pop a:focus-visible, .c-pop button:focus-visible { outline: 2px solid var(--primary); outline-offset: 2px; }

        /* Certificate list: one panel, hairline dividers, no card per item */
        .c-list { background: var(--card); border: 1px solid var(--border); border-radius: 14px; }
        .c-row { display: grid; grid-template-columns: auto minmax(0, 1fr) auto; align-items: center; gap: 14px; padding: 13px 18px; }
        .c-row + .c-row { border-top: 1px solid var(--border); }
        .c-seal { width: 36px; height: 36px; border-radius: 50%; display: grid; place-items: center; color: var(--leaf-dark); background: var(--leaf-tint); }
        .c-body h3 { margin: 0; font-size: 15.5px; font-weight: 600; line-height: 1.35; overflow-wrap: anywhere; }
        .c-body p { margin: 2px 0 0; font-size: 13px; color: var(--muted); line-height: 1.5; overflow-wrap: anywhere; }
        .c-body code { font-size: 12px; background: #f1f5f9; padding: 1px 6px; border-radius: 5px; color: var(--ink); }
        .c-new { margin-left: 8px; padding: 1px 8px; border-radius: 999px; font-size: 11.5px; font-weight: 700; vertical-align: 2px; color: var(--leaf-dark); background: var(--leaf-tint); }
        .c-acts { display: flex; align-items: center; gap: 6px; }

        /* "More" menu (native <details>, no script needed to open) */
        .c-menu { position: relative; }
        .c-menu summary { list-style: none; width: 34px; height: 34px; display: grid; place-items: center; border-radius: 50%; color: var(--muted); cursor: pointer; }
        .c-menu summary::-webkit-details-marker { display: none; }
        .c-menu summary:hover, .c-menu[open] summary { background: #eef3f8; color: var(--ink); }
        .c-pop { position: absolute; right: 0; top: calc(100% + 6px); z-index: 20; min-width: 170px; padding: 6px; background: #fff; border: 1px solid var(--border); border-radius: 12px; box-shadow: 0 10px 24px rgba(15,23,42,.12); }
        .c-pop a, .c-pop button { display: flex; align-items: center; gap: 10px; width: 100%; padding: 9px 12px; border: 0; border-radius: 8px; background: none; font: inherit; font-size: 14px; font-weight: 600; color: var(--ink); text-align: left; text-decoration: none; cursor: pointer; }
        .c-pop a:hover, .c-pop button:hover { background: var(--primary-tint); }
        .c-pop svg.i { width: 16px; height: 16px; color: var(--muted); }

        @media (max-width: 560px) {
            .c-row { grid-template-columns: auto minmax(0, 1fr); padding: 13px 14px; }
            .c-acts { grid-column: 2; justify-content: space-between; }
        }
    </style>
    <?php
}

/** Copy buttons ([data-copy]) and closing the "more" menus when clicking elsewhere or pressing Esc. */
function cert_rows_js(): void
{
    ?>
    <script>
        document.querySelectorAll('[data-copy]').forEach(function (b) {
            b.addEventListener('click', function () {
                if (!navigator.clipboard) return;
                var label = b.querySelector('span') || b;
                var original = label.textContent;
                navigator.clipboard.writeText(b.dataset.copy).then(function () {
                    label.textContent = 'Copied';
                    setTimeout(function () { label.textContent = original; }, 1500);
                });
            });
        });
        function closeMenus(except) {
            document.querySelectorAll('.c-menu[open]').forEach(function (d) { if (d !== except) d.removeAttribute('open'); });
        }
        document.addEventListener('click', function (e) { closeMenus(e.target.closest('.c-menu')); });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeMenus(null); });
    </script>
    <?php
}
