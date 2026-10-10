<?php
// includes/dcw_events.php
// Upcoming events for the "What's on" section of the Engage home page.
//
// Never throws. If the database can't be reached, dcw_upcoming_events() returns [] (or the last good copy)
// and the home page simply omits the section.
//
// CONFIGURATION: same pattern as the certificates database.
//   Copy includes/events_db.example.php to includes/events_db.php and fill in the real values.
//   events_db.php must be listed in .gitignore. NEVER commit it.
//   Keys: host, name, user, pass, and optionally port and view (default 'engage_events_v').

if (!defined('DCW_WIKI_BASE')) define('DCW_WIKI_BASE', 'https://dcwwiki.org');

/** A value from includes/events_db.php (an array, like includes/certs_db.php), else the default. */
function dcw_events_setting(string $key, string $default = ''): string {
    static $cfg = null;
    if ($cfg === null) {
        $cfg = [];
        $file = __DIR__ . '/events_db.php';
        if (is_file($file)) {
            $loaded = (static function (string $f) { return include $f; })($file);
            if (is_array($loaded)) $cfg = array_change_key_case($loaded, CASE_LOWER);
        }
    }
    $v = $cfg[strtolower($key)] ?? '';
    return ($v !== '' && $v !== null) ? (string) $v : $default;
}

/** Read-only connection to the wiki's database. Throws if not configured; callers catch it. */
function dcw_events_pdo(): PDO {
    $host = dcw_events_setting('HOST', 'localhost');
    $port = dcw_events_setting('PORT');
    $name = dcw_events_setting('NAME');
    $user = dcw_events_setting('USER');
    $pass = dcw_events_setting('PASS');
    if ($name === '' || $user === '') throw new RuntimeException('Wiki database is not configured.');
    $dsn = 'mysql:host=' . $host . ($port !== '' ? ';port=' . $port : '') . ';dbname=' . $name . ';charset=utf8mb4';
    return new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_TIMEOUT            => 3,
    ]);
}

/** Wiki short descriptions are plain text, but strip any markup that slips in, and keep it short. */
function dcw_events_clean_text(string $s, int $max = 110): string {
    $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $s = preg_replace('/\[\[(?:[^\]|]*\|)?([^\]]*)\]\]/u', '$1', $s);   // [[Page|label]] -> label
    $s = str_replace("''", '', $s);                                      // wiki bold/italic quotes
    $s = trim(preg_replace('/\s+/u', ' ', strip_tags($s)));
    return mb_strimwidth($s, 0, $max, '…');
}

/**
 * Read upcoming events from the wiki database. Returns a list of ['page','start','end','desc']
 * (dates as Y-m-d, in Indian time), or null on any failure.
 */
function dcw_events_fetch(int $rows = 10): ?array {
    try {
        $view = dcw_events_setting('VIEW', 'engage_events_v');
        if (!preg_match('/^[A-Za-z0-9_]+$/', $view)) return null;

        // The view already holds the days in Indian time (IST) and drops subpages. The SQL only needs a loose
        // lower bound (two days of slack, so an event still running "today" in India is never lost to the
        // database server's own clock); the exact "has it finished?" test is done in PHP, in India time.
        $sql = "SELECT pid, page_name AS page, ist_start AS local_start, ist_end AS local_end, descr
                  FROM `$view`
                 WHERE sort_end > DATE_SUB(UTC_DATE(), INTERVAL 2 DAY)
                 ORDER BY sort_start, pid
                 LIMIT " . (int) ($rows * 3);

        $out = [];
        $seen = [];
        foreach (dcw_events_pdo()->query($sql) as $r) {
            $page  = trim((string) ($r['page'] ?? ''));
            $start = substr((string) ($r['local_start'] ?? ''), 0, 10);
            $end   = substr((string) ($r['local_end'] ?? ''), 0, 10);
            $pid   = (string) ($r['pid'] ?? $page);
            if ($page === '' || isset($seen[$pid]) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) continue;
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end) || $end < $start) $end = $start;
            $seen[$pid] = true;
            $out[] = [
                'page'  => $page,
                'start' => $start,
                'end'   => $end,
                'desc'  => dcw_events_clean_text((string) ($r['descr'] ?? '')),
            ];
            if (count($out) >= $rows) break;
        }
        return $out;
    } catch (Throwable $e) {
        return null;
    }
}

/** Link to the event's page on dcwwiki.org: spaces become underscores, commas and colons stay readable. */
function dcw_events_page_url(string $page): string {
    $slug = rawurlencode(str_replace(' ', '_', $page));
    return DCW_WIKI_BASE . '/' . str_replace(['%2C', '%3A'], [',', ':'], $slug);
}

/**
 * Upcoming events, ready to print. The query is cheap, so the cache is short and only saves repeating it
 * on every page view:
 *   - a good answer is reused for 5 minutes
 *   - after a failure the database is not tried again for 1 minute (a hiccup never slows the home page)
 *   - on failure the last good copy is kept for up to 1 day
 * Events that have already finished (in India time) are filtered out every time, even from the cache.
 */
function dcw_upcoming_events(int $limit = 3): array {
    try {
        $now   = time();
        $file  = sys_get_temp_dir() . '/dcw_engage_events_' . md5(dcw_events_setting('NAME') . dcw_events_setting('VIEW')) . '.json';
        $cache = null;
        if (is_file($file)) {
            $c = json_decode((string) @file_get_contents($file), true);
            if (is_array($c)) $cache = $c;
        }

        $age   = $cache ? $now - (int) ($cache['tried'] ?? 0) : PHP_INT_MAX;
        $fresh = $cache && $age < (!empty($cache['ok']) ? 300 : 60);

        if (!$fresh) {
            $events = dcw_events_fetch();
            if ($events !== null) {
                $cache = ['tried' => $now, 'fetched' => $now, 'ok' => true, 'events' => $events];
            } else {
                $cache = [
                    'tried'   => $now,
                    'fetched' => (int) ($cache['fetched'] ?? 0),
                    'ok'      => false,
                    'events'  => (array) ($cache['events'] ?? []),
                ];
            }
            $tmp = $file . '.' . getmypid() . '.tmp';
            if (@file_put_contents($tmp, json_encode($cache)) !== false) @rename($tmp, $file);
        }

        if (empty($cache['ok']) && $now - (int) ($cache['fetched'] ?? 0) > 86400) return [];

        $ist   = new DateTimeZone('Asia/Kolkata');
        $today = (new DateTimeImmutable('now', $ist))->format('Y-m-d');
        $utc   = new DateTimeZone('UTC');   // dates are plain calendar days: no shifting

        $out = [];
        foreach ((array) ($cache['events'] ?? []) as $e) {
            if (empty($e['start']) || empty($e['page'])) continue;
            $end = $e['end'] ?: $e['start'];
            if ($end < $today) continue;   // already over

            $s = DateTimeImmutable::createFromFormat('!Y-m-d', $e['start'], $utc);
            $f = DateTimeImmutable::createFromFormat('!Y-m-d', $end, $utc);
            if (!$s || !$f) continue;

            // "Friday 30 October 2026", or "30 Oct – 1 Nov 2026" for events over several days.
            $when = $e['start'] === $end
                ? $s->format('l j F Y')
                : ($s->format('Y') === $f->format('Y')
                    ? $s->format('j M') . ' – ' . $f->format('j M Y')
                    : $s->format('j M Y') . ' – ' . $f->format('j M Y'));

            $out[] = [
                'title' => str_replace('_', ' ', (string) $e['page']),
                'url'   => dcw_events_page_url((string) $e['page']),
                'desc'  => (string) ($e['desc'] ?? ''),
                'month' => strtoupper($s->format('M')),
                'day'   => $s->format('j'),
                'wday'  => $s->format('D'),
                'when'  => $when,
            ];
            if (count($out) >= $limit) break;
        }
        return $out;
    } catch (Throwable $e) {
        return [];
    }
}
