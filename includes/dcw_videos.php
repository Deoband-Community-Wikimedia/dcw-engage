<?php
// includes/dcw_videos.php
// Latest videos from the DCW YouTube channel, for the "Latest videos" section of the Engage home page.
//
// HOW IT WORKS: YouTube publishes a public RSS feed for every channel. No API key, no login and no quota.
// Engage reads the channel's uploads-only playlist feed (the playlist id is the channel id with "UC"
// replaced by "UULF"), which leaves Shorts out. If that feed is unavailable, the plain channel feed is read
// instead and any entry whose link points at /shorts/ is skipped.
//
// Nothing from YouTube runs in the visitor's browser: the home page shows plain links with a thumbnail image
// from i.ytimg.com (no cookies, no player, no scripts).
//
// Never throws. If YouTube can't be reached, dcw_latest_videos() returns the last good copy (kept up to
// 30 days), or [] and the home page simply leaves the section out.
//
// CONFIGURATION (all optional): includes/videos_db.php, an array like includes/events_db.php.
//   Keys: channel (the UC... channel id; default below)
//         pinned  (an 11-character video id to always show first, as long as it is in the feed)
//   There are no secrets in it.

if (!defined('DCW_YT_CHANNEL')) define('DCW_YT_CHANNEL', 'UCeg3RQvoLmzMs0o6W7lGNLQ');

/** A value from includes/videos_db.php, else the default. */
function dcw_videos_setting(string $key, string $default = ''): string {
    static $cfg = null;
    if ($cfg === null) {
        $cfg = [];
        $file = __DIR__ . '/videos_db.php';
        if (is_file($file)) {
            $loaded = (static function (string $f) { return include $f; })($file);
            if (is_array($loaded)) $cfg = array_change_key_case($loaded, CASE_LOWER);
        }
    }
    $v = $cfg[strtolower($key)] ?? '';
    return ($v !== '' && $v !== null) ? (string) $v : $default;
}

/** The channel id, checked before it is used in a URL. */
function dcw_videos_channel(): string {
    $c = dcw_videos_setting('channel', DCW_YT_CHANNEL);
    return preg_match('/^UC[A-Za-z0-9_-]{22}$/', $c) ? $c : DCW_YT_CHANNEL;
}

/** Plain read-only GET. Returns the body, or null on any failure. */
function dcw_videos_http(string $url): ?string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT        => 4,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_USERAGENT      => 'DCW-Engage/1.0 (videos widget)',
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return ($body !== false && $code === 200) ? (string) $body : null;
}

/**
 * Reads a YouTube Atom feed. Returns [['id','title','ts'], ...] newest first, Shorts left out, or null if the
 * text is not a usable feed.
 */
function dcw_videos_parse(string $xml): ?array {
    if (!function_exists('simplexml_load_string')) return null;
    $prev = libxml_use_internal_errors(true);
    $feed = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    if (!$feed) return null;

    $out = [];
    foreach ($feed->entry as $e) {
        $id = trim((string) $e->children('http://www.youtube.com/xml/schemas/2015')->videoId);
        if (!preg_match('/^[A-Za-z0-9_-]{11}$/', $id)) continue;
        $href = (string) ($e->link['href'] ?? '');
        if (stripos($href, '/shorts/') !== false) continue;
        $title = trim((string) preg_replace('/\s+/u', ' ', (string) $e->title));
        $ts    = strtotime((string) $e->published);
        if ($title === '' || !$ts) continue;
        $out[] = ['id' => $id, 'title' => $title, 'ts' => (int) $ts];
    }
    usort($out, fn($a, $b) => $b['ts'] <=> $a['ts']);
    return $out;
}

/** The feed, uploads-only first, then the plain channel feed. Null if neither gives any video. */
function dcw_videos_fetch(): ?array {
    try {
        $channel = dcw_videos_channel();
        $base = 'https://www.youtube.com/feeds/videos.xml?';
        foreach (['playlist_id=UULF' . substr($channel, 2), 'channel_id=' . $channel] as $qs) {
            $body = dcw_videos_http($base . $qs);
            if ($body === null) continue;
            $list = dcw_videos_parse($body);
            if (!empty($list)) return array_slice($list, 0, 15);
        }
        return null;
    } catch (Throwable $e) {
        return null;
    }
}

/** "3 days ago", "2 months ago". */
function dcw_videos_ago(int $ts, int $now): string {
    $d = max(0, $now - $ts);
    $fmt = fn(int $n, string $unit) => $n . ' ' . $unit . ($n === 1 ? '' : 's') . ' ago';
    if ($d < 3600)       return $fmt(max(1, intdiv($d, 60)), 'minute');
    if ($d < 86400)      return $fmt(intdiv($d, 3600), 'hour');
    if ($d < 86400 * 30) return $fmt(intdiv($d, 86400), 'day');
    if ($d < 86400 * 365) return $fmt(intdiv($d, 86400 * 30), 'month');
    return $fmt(intdiv($d, 86400 * 365), 'year');
}

/**
 * Latest videos, ready to print. Cache: a good answer is reused for 3 hours; after a failure YouTube is not
 * asked again for 10 minutes; on failure the last good copy is kept for up to 30 days.
 *
 * Each returned video: id, title, url, thumb (480x360 image, cropped to 16:9 by CSS), ago ("3 days ago"), new (true if under 7 days old),
 * label (sentence for screen readers).
 */
function dcw_latest_videos(int $limit = 3): array {
    try {
        $now   = time();
        $file  = sys_get_temp_dir() . '/dcw_engage_videos1_' . md5(dcw_videos_channel()) . '.json';
        $cache = null;
        if (is_file($file)) {
            $c = json_decode((string) @file_get_contents($file), true);
            if (is_array($c)) $cache = $c;
        }

        $age   = $cache ? $now - (int) ($cache['tried'] ?? 0) : PHP_INT_MAX;
        $fresh = $cache && $age < (!empty($cache['ok']) ? 10800 : 600);

        if (!$fresh) {
            $videos = dcw_videos_fetch();
            if ($videos !== null) {
                $cache = ['tried' => $now, 'fetched' => $now, 'ok' => true, 'videos' => $videos];
            } else {
                $cache = [
                    'tried'   => $now,
                    'fetched' => (int) ($cache['fetched'] ?? 0),
                    'ok'      => false,
                    'videos'  => (array) ($cache['videos'] ?? []),
                ];
            }
            $tmp = $file . '.' . getmypid() . '.tmp';
            if (@file_put_contents($tmp, json_encode($cache)) !== false) @rename($tmp, $file);
        }

        if (empty($cache['ok']) && $now - (int) ($cache['fetched'] ?? 0) > 30 * 86400) return [];

        $list = array_values(array_filter((array) ($cache['videos'] ?? []),
            fn($v) => is_array($v) && !empty($v['id']) && !empty($v['title']) && !empty($v['ts'])));

        // A pinned video goes first, if it is in the feed.
        $pinned = dcw_videos_setting('pinned');
        if (preg_match('/^[A-Za-z0-9_-]{11}$/', $pinned)) {
            foreach ($list as $i => $v) {
                if ($v['id'] === $pinned) {
                    array_splice($list, $i, 1);
                    array_unshift($list, $v);
                    break;
                }
            }
        }

        $out = [];
        foreach ($list as $v) {
            $ts  = (int) $v['ts'];
            $ago = dcw_videos_ago($ts, $now);
            $out[] = [
                'id'    => (string) $v['id'],
                'title' => (string) $v['title'],
                'url'   => 'https://www.youtube.com/watch?v=' . $v['id'],
                'thumb' => 'https://i.ytimg.com/vi/' . $v['id'] . '/hqdefault.jpg',
                'ago'   => $ago,
                'new'   => ($now - $ts) < 7 * 86400,
                'label' => 'Watch on YouTube: ' . $v['title'] . ', uploaded ' . $ago,
            ];
            if (count($out) >= $limit) break;
        }
        return $out;
    } catch (Throwable $e) {
        return [];
    }
}
