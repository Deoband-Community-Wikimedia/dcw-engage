<?php
/**
 * Social preview tags (Open Graph + Twitter/X) and Wikimedia Commons image credits.
 *
 * engage_social_meta([...])   prints the tags; called by engage_header() and by home.php
 * engage_resolve_image($v)    turns a stored banner value into a public image URL
 * engage_commons_caption($v)  credit line (author, licence, Commons link) or '' if not from Commons
 *
 * An "image" value can be any of:
 *   - a Commons file name:      Group photo from DCW 5th Anniversary.jpg   (File: prefix is fine)
 *   - a Commons page link:      https://commons.wikimedia.org/wiki/File:Something.jpg
 *   - a direct Commons file:    https://upload.wikimedia.org/wikipedia/commons/a/ab/Something.jpg
 *   - any other full URL, or a local path such as /assets/img/x.png or uploads/x.png
 */

const ENGAGE_BASE_URL      = 'https://engage.dcwwiki.org';   // no trailing slash
const ENGAGE_DEFAULT_IMAGE = 'Group photo from DCW 5th Anniversary.jpg'; // Commons file used when a page has none
const ENGAGE_USER_AGENT    = 'DCW-Engage/1.0 (https://engage.dcwwiki.org; dcwhelper@dcwwiki.org)'; // Wikimedia asks for a contact

/** Relative path or URL -> absolute URL on this site. */
function engage_abs_url(string $u): string {
    if (preg_match('#^https?://#i', $u)) return $u;
    return ENGAGE_BASE_URL . '/' . ltrim($u, '/');
}

/**
 * Commons file name (spaces, no "File:" prefix) from a bare name or any Commons link.
 * Returns null when the value is not a Commons image (other URL, or a local path).
 */
function engage_commons_file(?string $v): ?string {
    $v = trim((string) $v);
    if ($v === '') return null;

    if (preg_match('#^https?://#i', $v)) {
        if (preg_match('#commons\.wikimedia\.org/wiki/(?:Special:FilePath/|(?:File|Image):)([^?\#]+)#i', $v, $m)) {
            $v = $m[1];
        } elseif (preg_match('#upload\.wikimedia\.org/wikipedia/commons/(?:thumb/)?[0-9a-f]/[0-9a-f]{2}/([^/?\#]+)#i', $v, $m)) {
            $v = $m[1];
        } else {
            return null;
        }
        $v = urldecode($v);
    } elseif (strpos($v, '/') !== false) {
        return null;   // a local path; Commons file names never contain a slash
    }

    $v = preg_replace('/^(File|Image):/i', '', $v);
    $v = trim(str_replace('_', ' ', $v));
    return $v === '' ? null : $v;
}

/** Public image URL for a stored value. Commons files are served at $width px wide. */
function engage_resolve_image(?string $v, int $width = 1200): ?string {
    $v = trim((string) $v);
    if ($v === '') return null;

    $file = engage_commons_file($v);
    if ($file !== null) {
        return 'https://commons.wikimedia.org/wiki/Special:FilePath/'
            . rawurlencode(str_replace(' ', '_', $file)) . '?width=' . $width;
    }
    return engage_abs_url($v);
}

/** ['author','license','license_url','page'] for a Commons image, cached on disk. Null if unavailable. */
function engage_commons_credit(?string $input): ?array {
    $file = engage_commons_file($input);
    if ($file === null) return null;
    $title = 'File:' . $file;

    $dir = __DIR__ . '/../cache/commons';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    if (!is_dir($dir) || !is_writable($dir)) {
        $dir = sys_get_temp_dir() . '/dcw-engage-commons';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
    }
    $cache = $dir . '/' . md5($title) . '.json';

    if (is_file($cache)) {
        $c = json_decode((string) file_get_contents($cache), true);
        if (is_array($c)) {
            $ttl = !empty($c['data']) ? 7 * 86400 : 3600;   // retry a failed lookup after an hour
            if (time() - (int) ($c['at'] ?? 0) < $ttl) return $c['data'] ?? null;
        }
    }

    $url = 'https://commons.wikimedia.org/w/api.php?' . http_build_query([
        'action' => 'query', 'format' => 'json', 'titles' => $title,
        'prop' => 'imageinfo', 'iiprop' => 'extmetadata',
        'iiextmetadatafilter' => 'Artist|Credit|LicenseShortName|LicenseUrl',
    ]);
    $ctx = stream_context_create(['http' => [
        'timeout' => 3,
        'header'  => 'User-Agent: ' . ENGAGE_USER_AGENT . "\r\n",
    ]]);
    $json = @file_get_contents($url, false, $ctx);
    $data = null;

    if ($json) {
        $decoded = json_decode($json, true);
        $pages = is_array($decoded) ? ($decoded['query']['pages'] ?? []) : [];
        $page  = $pages ? (current($pages) ?: []) : [];
        $meta  = $page['imageinfo'][0]['extmetadata'] ?? [];
        $clean = fn($k) => trim(html_entity_decode(strip_tags((string) ($meta[$k]['value'] ?? '')), ENT_QUOTES, 'UTF-8'));
        $author  = $clean('Artist') ?: $clean('Credit');
        $license = $clean('LicenseShortName');
        if ($author !== '' || $license !== '') {
            $data = [
                'author'      => mb_strimwidth($author, 0, 120, '…'),
                'license'     => $license,
                'license_url' => $clean('LicenseUrl'),
                'page'        => 'https://commons.wikimedia.org/wiki/' . rawurlencode(str_replace(' ', '_', $title)),
            ];
        }
    }
    @file_put_contents($cache, json_encode(['at' => time(), 'data' => $data]));
    return $data;
}

/** Caption for under a banner: "Photo: Author · CC BY-SA 4.0 · Wikimedia Commons". '' if not a Commons image. */
function engage_commons_caption(?string $input): string {
    $c = engage_commons_credit($input);
    if (!$c) return '';
    $e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

    $parts = [];
    if (!empty($c['author'])) $parts[] = 'Photo: ' . $e($c['author']);
    if (!empty($c['license'])) {
        $parts[] = !empty($c['license_url']) && preg_match('#^https?://#i', $c['license_url'])
            ? '<a href="' . $e($c['license_url']) . '" target="_blank" rel="noopener license">' . $e($c['license']) . '</a>'
            : $e($c['license']);
    }
    $parts[] = '<a href="' . $e($c['page']) . '" target="_blank" rel="noopener">Wikimedia Commons</a>';
    return '<figcaption class="banner-credit">' . implode(' &middot; ', $parts) . '</figcaption>';
}

/**
 * Prints the social preview tags. Call once inside <head>.
 * Options: title, description, image (see top of file), image_alt, path
 */
function engage_social_meta(array $o = []): void {
    $e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

    $title = trim((string) ($o['title'] ?? '')) ?: 'DCW Engage';
    $desc  = trim((string) ($o['description'] ?? ''));
    if ($desc === '') $desc = 'Applications and forms for Deoband Community Wikimedia: membership, scholarships, fellowships, support and more.';
    $desc  = mb_strimwidth(preg_replace('/\s+/u', ' ', $desc), 0, 200, '…');

    $image = engage_resolve_image($o['image'] ?? null) ?? engage_resolve_image(ENGAGE_DEFAULT_IMAGE);
    $alt   = trim((string) ($o['image_alt'] ?? '')) ?: $title;

    // Path only: drops ?fbclid=... and one-time ?verify=... tokens from the shared address.
    $path = $o['path'] ?? (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $url  = ENGAGE_BASE_URL . '/' . ltrim((string) $path, '/');
    ?>
    <link rel="canonical" href="<?= $e($url) ?>">
    <meta property="og:site_name" content="DCW Engage">
    <meta property="og:type" content="website">
    <meta property="og:title" content="<?= $e($title) ?>">
    <meta property="og:description" content="<?= $e($desc) ?>">
    <meta property="og:url" content="<?= $e($url) ?>">
    <meta property="og:image" content="<?= $e($image) ?>">
    <meta property="og:image:alt" content="<?= $e($alt) ?>">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= $e($title) ?>">
    <meta name="twitter:description" content="<?= $e($desc) ?>">
    <meta name="twitter:image" content="<?= $e($image) ?>">
    <meta name="description" content="<?= $e($desc) ?>">
    <?php
}
