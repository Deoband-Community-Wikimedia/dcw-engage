<?php
// includes/dcw_events.php
// Upcoming events for the "What's on" section of the Engage home page.
//
// HOW IT WORKS: Engage asks the wiki's public API (api.php?action=cargoquery) for upcoming events.
// No database login is involved and nothing on dcwwiki.org is changed: it is a plain read-only HTTP request,
// the same one a browser could make.
//
// Never throws. If the wiki can't be reached, dcw_upcoming_events() returns [] (or the last good copy)
// and the home page simply omits the section.
//
// CONFIGURATION (all optional): includes/events_db.php, copied from includes/events_db.example.php.
//   Keys: api   (default https://dcwwiki.org/api.php)
//         table (default 'events', the Cargo table name as listed on Special:CargoTables)
//   There are no secrets in it any more, but it can stay out of git like the other *_db.php files.

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

/** Wiki short descriptions are plain text, but strip any markup that slips in, and keep it short. */
function dcw_events_clean_text(string $s, int $max = 110): string {
    $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $s = preg_replace('/\[\[(?:[^\]|]*\|)?([^\]]*)\]\]/u', '$1', $s);   // [[Page|label]] -> label
    $s = str_replace("''", '', $s);                                      // wiki bold/italic quotes
    $s = trim(preg_replace('/\s+/u', ' ', strip_tags($s)));
    return mb_strimwidth($s, 0, $max, '…');
}

/**
 * Read upcoming events from the wiki's Cargo API. Returns a list of ['page','start','end','desc']
 * (dates as Y-m-d, in Indian time), or null on any failure.
 */
function dcw_events_fetch(int $rows = 10): ?array {
    try {
        $api   = dcw_events_setting('API', DCW_WIKI_BASE . '/api.php');
        $table = dcw_events_setting('TABLE', 'events');
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) return null;
        if (!preg_match('#^https://#i', $api)) return null;

        // Loose lower bound (three days of slack, so an event still running "today" in India is never
        // lost); the exact "has it finished?" test is done in dcw_upcoming_events(), in India time.
        $since = gmdate('Y-m-d', time() - 3 * 86400);

        // Cargo does not allow field aliases that start with an underscore, so every alias is plain.
        $qs = http_build_query([
            'action'   => 'cargoquery',
            'format'   => 'json',
            'tables'   => $table,
            'fields'   => '_pageID=pid,_pageName=page,'
                        . 'start_date=sdate,start_date__precision=sprec,'
                        . 'end_date=edate,end_date__precision=eprec,'
                        . 'short_description=descr',
            'where'    => "_pageNamespace=0 AND _pageName NOT LIKE '%/%' AND start_date IS NOT NULL "
                        . "AND (end_date >= '$since' OR (end_date IS NULL AND start_date >= '$since'))",
            'order_by' => 'start_date',
            'limit'    => $rows * 3,
        ]);

        $ch = curl_init($api . '?' . $qs);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT        => 3,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_USERAGENT      => 'DCW-Engage/1.0 (events widget)',
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($body === false || $code !== 200) return null;

        $json = json_decode((string) $body, true);
        if (!is_array($json) || !isset($json['cargoquery']) || !is_array($json['cargoquery'])) return null;

        // Times on the wiki are stored in UTC. A value with a time is shifted to India time.
        // Cargo precision 1 to 3 means year, month or day only: there is no time, so nothing is shifted.
        // (Precision 0 is reported for full date-and-time values.)
        $ist = new DateTimeZone('Asia/Kolkata');
        $utc = new DateTimeZone('UTC');
        $toDay = function ($v, $prec) use ($ist, $utc): string {
            $v = trim((string) $v);
            if ($v === '') return '';
            $p = (int) $prec;
            if ($p >= 1 && $p <= 3) return substr($v, 0, 10);
            $d = date_create_immutable($v, $utc);
            return $d ? $d->setTimezone($ist)->format('Y-m-d') : substr($v, 0, 10);
        };

        $out  = [];
        $seen = [];
        foreach ($json['cargoquery'] as $row) {
            $r     = is_array($row['title'] ?? null) ? $row['title'] : [];
            $page  = trim((string) ($r['page'] ?? ''));
            $pid   = (string) ($r['pid'] ?? $page);
            $start = $toDay($r['sdate'] ?? '', $r['sprec'] ?? 0);
            $end   = (($r['edate'] ?? '') !== '') ? $toDay($r['edate'], $r['eprec'] ?? 0) : $start;
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
 * Upcoming events, ready to print. The request is cheap, so the cache is short and only saves repeating it
 * on every page view:
 *   - a good answer is reused for 5 minutes
 *   - after a failure the wiki is not asked again for 1 minute (a hiccup never slows the home page)
 *   - on failure the last good copy is kept for up to 1 day
 * Events that have already finished (in India time) are filtered out every time, even from the cache.
 */
function dcw_upcoming_events(int $limit = 3): array {
    try {
        $now   = time();
        $file  = sys_get_temp_dir() . '/dcw_engage_events_'
               . md5(dcw_events_setting('API', DCW_WIKI_BASE . '/api.php') . '|' . dcw_events_setting('TABLE', 'events'))
               . '.json';
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

            $title = str_replace('_', ' ', (string) $e['page']);
            $desc  = (string) ($e['desc'] ?? '');
            if (strcasecmp(trim($desc), $title) === 0) $desc = '';   // a description that just repeats the title adds nothing

            $out[] = [
                'title' => $title,
                'url'   => dcw_events_page_url((string) $e['page']),
                'desc'  => $desc,
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
