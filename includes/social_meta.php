<?php
/**
 * Social preview tags (Open Graph + Twitter/X) and Wikimedia Commons images and credits.
 *
 * engage_social_meta([...])   prints the tags; called by engage_header() and by home.php
 * engage_resolve_image($v,$w) turns a stored banner value into a public image URL ($w px wide for Commons)
 * engage_commons_caption($v)  credit line (author, licence, Commons link) or '' if not from Commons
 * engage_commons_credit($v)   the same credit as data
 *
 * An "image" value can be any of:
 *   - a Commons file name:      Group photo from DCW 5th Anniversary.jpg   (File: prefix is fine)
 *   - a Commons page link:      https://commons.wikimedia.org/wiki/File:Something.jpg
 *   - a direct Commons file:    https://upload.wikimedia.org/wikipedia/commons/a/ab/Something.jpg
 *   - any other full URL, or a local path such as /assets/img/x.png or uploads/x.png
 *
 * Commons images are resolved through the Commons API to their direct upload.wikimedia.org
 * thumbnail address (cached for a week). Facebook and other crawlers cannot read the redirecting
 * Special:FilePath address, which is why the direct address is used.
 */

const ENGAGE_BASE_URL      = 'https://engage.dcwwiki.org';   // no trailing slash
const ENGAGE_DEFAULT_IMAGE = 'Group photo from DCW 5th Anniversary.jpg'; // Commons file used when a page has none
const ENGAGE_USER_AGENT    = 'DCW-Engage/1.0 (https://engage.dcwwiki.org; CHANGE-ME@dcwwiki.org)'; // Wikimedia asks for a contact

/**
 * Removes any og:image / twitter:image tags from a chunk of head HTML. Used around includes/favicon.php,
 * so a leftover logo image tag there can never come before (and beat) the page's real social image.
 */
function engage_strip_social_images(string $html): string {
    return preg_replace('#<meta\b[^>]*\b(?:property|name)\s*=\s*["\'](?:og:image|twitter:image)[^>]*>\s*#i', '', $html);
}

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

/** GET a URL with a short timeout and the Wikimedia-friendly user agent. Null on failure. */
function engage_http_get(string $url): ?string {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 4, CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_USERAGENT => ENGAGE_USER_AGENT,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($body !== false && $code === 200) ? (string) $body : null;
    }
    $ctx = stream_context_create(['http' => [
        'timeout' => 4,
        'header'  => 'User-Agent: ' . ENGAGE_USER_AGENT . "\r\n",
    ]]);
    $body = @file_get_contents($url, false, $ctx);
    return $body === false ? null : (string) $body;
}

/**
 * Everything we know about a Commons image, cached on disk (7 days; a failed lookup is retried after an hour):
 *   author, license, license_url, page   for the credit line
 *   thumb, width, height                  the direct thumbnail address (1280 px) and the original size
 * Null if the value is not a Commons image or Commons could not be reached.
 */
function engage_commons_info(?string $input): ?array {
    $file = engage_commons_file($input);
    if ($file === null) return null;
    $title = 'File:' . $file;

    $dir = __DIR__ . '/../cache/commons';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    if (!is_dir($dir) || !is_writable($dir)) {
        $dir = sys_get_temp_dir() . '/dcw-engage-commons';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
    }
    $cache = $dir . '/' . md5($title) . '.v2.json';

    if (is_file($cache)) {
        $c = json_decode((string) file_get_contents($cache), true);
        if (is_array($c)) {
            $ttl = !empty($c['data']['thumb']) ? 7 * 86400 : 3600;
            if (time() - (int) ($c['at'] ?? 0) < $ttl) return $c['data'] ?: null;
        }
    }

    $url = 'https://commons.wikimedia.org/w/api.php?' . http_build_query([
        'action' => 'query', 'format' => 'json', 'titles' => $title,
        'prop' => 'imageinfo', 'iiprop' => 'extmetadata|url|size', 'iiurlwidth' => 1280,
        'iiextmetadatafilter' => 'Artist|Credit|LicenseShortName|LicenseUrl',
    ]);
    $json = engage_http_get($url);
    $data = null;

    if ($json) {
        $decoded = json_decode($json, true);
        $pages = is_array($decoded) ? ($decoded['query']['pages'] ?? []) : [];
        $page  = $pages ? (current($pages) ?: []) : [];
        $ii    = $page['imageinfo'][0] ?? [];
        $meta  = $ii['extmetadata'] ?? [];
        $clean = fn($k) => trim(html_entity_decode(strip_tags((string) ($meta[$k]['value'] ?? '')), ENT_QUOTES, 'UTF-8'));
        $thumb = (string) ($ii['thumburl'] ?? $ii['url'] ?? '');
        if (!preg_match('#^https://upload\.wikimedia\.org/#', $thumb)) $thumb = '';
        $author  = $clean('Artist') ?: $clean('Credit');
        $license = $clean('LicenseShortName');
        if ($thumb !== '' || $author !== '' || $license !== '') {
            $data = [
                'author'      => mb_strimwidth($author, 0, 120, '…'),
                'license'     => $license,
                'license_url' => $clean('LicenseUrl'),
                'page'        => 'https://commons.wikimedia.org/wiki/' . rawurlencode(str_replace(' ', '_', $title)),
                'thumb'       => $thumb,
                'width'       => (int) ($ii['width'] ?? 0),
                'height'      => (int) ($ii['height'] ?? 0),
            ];
        }
    }
    @file_put_contents($cache, json_encode(['at' => time(), 'data' => $data]));
    return $data;
}

/** Credit data for a Commons image (author, license, license_url, page), or null. */
function engage_commons_credit(?string $input): ?array {
    $i = engage_commons_info($input);
    if (!$i || ($i['author'] === '' && $i['license'] === '')) return null;
    return $i;
}

/**
 * Public image address plus size when known: ['url' => ..., 'w' => int|null, 'h' => int|null].
 * Commons images come back as direct upload.wikimedia.org thumbnails $width px wide. Use one of
 * Wikimedia's standard widths (500, 960, 1280, 1920) so the thumbnail already exists and loads fast.
 */
function engage_image_info(?string $v, int $width = 1200): ?array {
    $v = trim((string) $v);
    if ($v === '') return null;

    $file = engage_commons_file($v);
    if ($file === null) return ['url' => engage_abs_url($v), 'w' => null, 'h' => null];

    $i = engage_commons_info($v);
    if ($i && !empty($i['thumb'])) {
        $url = $i['thumb'];
        $ow = (int) $i['width']; $oh = (int) $i['height'];
        $w = $ow; $h = $oh;
        if (strpos($url, '/thumb/') !== false) {
            $url = preg_replace('#/\d+px-(?=[^/]*$)#', '/' . $width . 'px-', $url, 1);
            if ($ow > 0 && $oh > 0) { $w = min($width, $ow); $h = (int) round($oh * $w / $ow); }
        }
        return ['url' => $url, 'w' => $w ?: null, 'h' => $h ?: null];
    }

    $name = str_replace(' ', '_', $file);
    $md5  = md5($name);
    $path = $md5[0] . '/' . substr($md5, 0, 2) . '/' . rawurlencode($name);
    $base = 'https://upload.wikimedia.org/wikipedia/commons/';
    $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $url  = in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)
        ? $base . 'thumb/' . $path . '/' . $width . 'px-' . rawurlencode($name)
        : $base . $path;
    return ['url' => $url, 'w' => null, 'h' => null];
}

/** Just the image address (see engage_image_info). */
function engage_resolve_image(?string $v, int $width = 1200): ?string {
    $i = engage_image_info($v, $width);
    return $i ? $i['url'] : null;
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

    // 1280 px is a standard Wikimedia thumbnail width, so it already exists and is served fast.
    $img   = engage_image_info($o['image'] ?? null, 1280) ?? engage_image_info(ENGAGE_DEFAULT_IMAGE, 1280);
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
    <meta property="og:image" content="<?= $e($img['url']) ?>">
    <?php if (!empty($img['w']) && !empty($img['h'])): ?>
    <meta property="og:image:width" content="<?= (int) $img['w'] ?>">
    <meta property="og:image:height" content="<?= (int) $img['h'] ?>">
    <?php endif; ?>
    <meta property="og:image:alt" content="<?= $e($alt) ?>">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= $e($title) ?>">
    <meta name="twitter:description" content="<?= $e($desc) ?>">
    <meta name="twitter:image" content="<?= $e($img['url']) ?>">
    <meta name="description" content="<?= $e($desc) ?>">
    <?php
}
