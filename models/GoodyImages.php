<?php
/**
 * DCW Engage - pictures of goodies.
 * Pictures live in goodies.images_json as a list, in display order (the first one is the cover):
 *   [{"file":"7f3a9c1e5b2d4a60.jpg","alt":"Front of the DCW mug"}, ...]
 * and as files in uploads/goodies/ under the web root, so a member's browser can load them from
 * /uploads/goodies/<file>.
 *
 * What an upload goes through (store()):
 *   - the PHP upload must have succeeded, be a real uploaded file and be at most MAX_BYTES;
 *   - its real type is read from the file (finfo + getimagesize), never from its name: JPEG, PNG or WebP only;
 *   - it is decoded and written out again with GD, scaled down to MAX_EDGE pixels on the longest side.
 *     Re-encoding drops EXIF data (location, camera) and anything hidden in the file. JPEG rotation from
 *     EXIF is applied first so the picture does not turn sideways;
 *   - it is saved under a random name. The uploaded name is never kept or used.
 *
 * Where the folder is: $_SERVER['DOCUMENT_ROOT'] . '/uploads/goodies', unless config defines
 * GOODY_IMAGES_DIR (an absolute path) and GOODY_IMAGES_URL (the matching public URL path, ending in /).
 * The folder and a locked-down .htaccess are created on first use. Keep uploads/ out of git and out of
 * anything a deploy overwrites.
 */
class GoodyImages
{
    public const MAX_IMAGES = 6;
    public const MAX_BYTES  = 5242880;      // 5 MB per upload
    public const MAX_EDGE   = 1600;         // longest side after resizing, in pixels
    public const MAX_PIXELS = 40000000;     // refuse very large images (they can exhaust memory when decoded)
    public const ALT_MAX    = 160;

    private const TYPES   = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    private const NAME_RE = '/^[a-f0-9]{16}\.(?:jpg|png|webp)$/';

    /** Written into the folder: nothing but the pictures themselves can be fetched, and nothing runs. */
    private const HTACCESS = <<<'HT'
# DCW Engage: goody pictures only. Everything else is refused, and no script can run here.
Options -Indexes -ExecCGI
<IfModule mod_authz_core.c>
    Require all denied
    <FilesMatch "^[a-f0-9]{16}\.(jpg|png|webp)$">
        Require all granted
    </FilesMatch>
</IfModule>
<IfModule mod_headers.c>
    Header set X-Content-Type-Options "nosniff"
    Header set Cache-Control "public, max-age=31536000, immutable"
</IfModule>
HT;

    // ---- Reading -------------------------------------------------------------------------------

    public static function dir(): string
    {
        if (defined('GOODY_IMAGES_DIR')) return rtrim((string) GOODY_IMAGES_DIR, '/\\');
        $root = rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), '/\\');
        return $root === '' ? '' : $root . '/uploads/goodies';
    }

    public static function url(string $file): string
    {
        $base = defined('GOODY_IMAGES_URL') ? (string) GOODY_IMAGES_URL : '/uploads/goodies/';
        return $base . rawurlencode($file);
    }

    /** goodies.images_json as a clean list of ['file' => ..., 'alt' => ...]. Bad entries are dropped. */
    public static function decode($json): array
    {
        if ($json === null || $json === '' || $json === false) return [];
        $a = json_decode((string) $json, true);
        if (!is_array($a)) return [];
        $out = [];
        foreach ($a as $i) {
            $f = is_array($i) ? (string) ($i['file'] ?? '') : '';
            if (!preg_match(self::NAME_RE, $f)) continue;
            $out[] = ['file' => $f, 'alt' => mb_substr(trim((string) ($i['alt'] ?? '')), 0, self::ALT_MAX)];
        }
        return array_slice($out, 0, self::MAX_IMAGES);
    }

    public static function encode(array $images): ?string
    {
        return $images ? json_encode(array_values($images), JSON_UNESCAPED_UNICODE) : null;
    }

    /** The description to show for a picture: its own, or the goody's name. */
    public static function alt(string $goodyName, array $image): string
    {
        $a = trim((string) ($image['alt'] ?? ''));
        return $a !== '' ? $a : $goodyName;
    }

    // ---- Saving --------------------------------------------------------------------------------

    /**
     * Works out the new picture list from the goody form. Writes the new files to disk, but changes
     * nothing in the database and deletes nothing: the caller saves $images, then calls remove($drop).
     *
     * The form sends the pictures that are kept, in the order wanted (img_file[], img_alt[]), and the new
     * uploads (img_new[]). Anything not sent back is dropped. Only files already saved for this goody are
     * accepted back, so the form cannot point a goody at some other file.
     *
     * @param array $existing  decode() of what is saved now
     * @param array $post      $_POST
     * @param array $files     $_FILES['img_new'] (or [])
     * @return array{ok:bool, error?:string, images?:list<array>, add?:list<string>, drop?:list<string>}
     */
    public static function process(array $existing, array $post, array $files): array
    {
        $known = [];
        foreach ($existing as $e) $known[$e['file']] = true;

        $keep = [];
        $seen = [];
        $names = (array) ($post['img_file'] ?? []);
        $alts  = (array) ($post['img_alt'] ?? []);
        foreach ($names as $i => $f) {
            $f = is_scalar($f) ? (string) $f : '';
            if (!isset($known[$f]) || isset($seen[$f])) continue;
            $seen[$f] = true;
            $a = is_scalar($alts[$i] ?? null) ? (string) $alts[$i] : '';
            $keep[] = ['file' => $f, 'alt' => mb_substr(trim((string) preg_replace('/\s+/u', ' ', $a)), 0, self::ALT_MAX)];
        }
        $drop = array_values(array_diff(array_keys($known), array_keys($seen)));

        $uploads = self::uploads($files);
        if (count($keep) + count($uploads) > self::MAX_IMAGES) {
            return ['ok' => false, 'error' => 'A goody can have up to ' . self::MAX_IMAGES . ' pictures. Remove some first.'];
        }

        $added = [];
        foreach ($uploads as $u) {
            $r = self::store($u);
            if (!$r['ok']) {
                self::remove($added);   // none of this batch is kept
                return ['ok' => false, 'error' => $r['error']];
            }
            $added[] = $r['file'];
            $keep[] = ['file' => $r['file'], 'alt' => ''];
        }
        return ['ok' => true, 'images' => $keep, 'add' => $added, 'drop' => $drop];
    }

    /** Deletes picture files. Only names this class could have made are touched. */
    public static function remove(array $files): void
    {
        $dir = self::dir();
        if ($dir === '') return;
        foreach ($files as $f) {
            $f = (string) $f;
            if (preg_match(self::NAME_RE, $f) && is_file($dir . '/' . $f)) @unlink($dir . '/' . $f);
        }
    }

    /** Creates the folder (and its .htaccess) when needed. Null when it cannot be created or written to. */
    public static function ensureDir(): ?string
    {
        $dir = self::dir();
        if ($dir === '') return null;
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) return null;
        if (!is_writable($dir)) return null;
        if (!is_file($dir . '/.htaccess')) @file_put_contents($dir . '/.htaccess', self::HTACCESS . "\n");
        if (!is_file($dir . '/index.html')) @file_put_contents($dir . '/index.html', '');
        return $dir;
    }

    // ---- Internals -----------------------------------------------------------------------------

    /** $_FILES['img_new'] (multiple) as a list of single files, skipping empty slots. */
    private static function uploads(array $f): array
    {
        if (!isset($f['name'])) return [];
        $out = [];
        foreach ((array) $f['name'] as $i => $name) {
            $err = (int) ($f['error'][$i] ?? UPLOAD_ERR_NO_FILE);
            if ($err === UPLOAD_ERR_NO_FILE) continue;
            $out[] = ['name' => (string) $name, 'tmp_name' => (string) ($f['tmp_name'][$i] ?? ''), 'size' => (int) ($f['size'][$i] ?? 0), 'error' => $err];
        }
        return $out;
    }

    /** @return array{ok:bool, file?:string, error?:string} */
    private static function store(array $u): array
    {
        $label = '"' . mb_substr(preg_replace('/[^\p{L}\p{N} ._-]+/u', '', basename($u['name'])) ?: 'picture', 0, 40) . '"';
        $fail = fn(string $why) => ['ok' => false, 'error' => $label . ': ' . $why];
        $mb = (int) (self::MAX_BYTES / 1048576);

        if ($u['error'] === UPLOAD_ERR_INI_SIZE || $u['error'] === UPLOAD_ERR_FORM_SIZE) return $fail('this picture is too large. The limit is ' . $mb . ' MB.');
        if ($u['error'] !== UPLOAD_ERR_OK) return $fail('the upload did not finish. Please try again.');
        if ($u['size'] > self::MAX_BYTES) return $fail('this picture is too large. The limit is ' . $mb . ' MB.');
        if (!is_uploaded_file($u['tmp_name'])) return $fail('not a valid upload.');

        $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($u['tmp_name']);
        if (!isset(self::TYPES[$mime])) return $fail('only JPG, PNG and WebP pictures are accepted.');
        $info = @getimagesize($u['tmp_name']);
        if (!$info || $info[0] < 1 || $info[1] < 1) return $fail('this is not a readable picture.');
        if ($info[0] * $info[1] > self::MAX_PIXELS) return $fail('this picture has too many pixels. Please shrink it first.');
        if (!extension_loaded('gd')) return $fail('picture processing is not available on this server.');

        $dir = self::ensureDir();
        if ($dir === null) return ['ok' => false, 'error' => 'The pictures folder cannot be written to. Check that uploads/goodies exists and PHP may write to it.'];

        $tmp = $u['tmp_name'];
        $img = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($tmp),
            'image/png'  => @imagecreatefrompng($tmp),
            default      => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($tmp) : false,
        };
        if (!$img) return $fail('this is not a readable picture.');

        // Phones store JPEGs sideways and set an EXIF flag; apply it, because the flag is dropped below.
        if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
            $ex = @exif_read_data($tmp);
            $rot = [3 => 180, 6 => -90, 8 => 90][(int) ($ex['Orientation'] ?? 1)] ?? 0;
            if ($rot) { $r = @imagerotate($img, $rot, 0); if ($r) $img = $r; }
        }
        if ($mime !== 'image/jpeg') {
            imagepalettetotruecolor($img);
            imagealphablending($img, false);
            imagesavealpha($img, true);
        }

        $w = imagesx($img); $h = imagesy($img);
        $scale = min(1, self::MAX_EDGE / max($w, $h));
        if ($scale < 1) {
            $nw = max(1, (int) round($w * $scale)); $nh = max(1, (int) round($h * $scale));
            $dst = imagecreatetruecolor($nw, $nh);
            if ($mime !== 'image/jpeg') {
                imagealphablending($dst, false);
                imagesavealpha($dst, true);
                imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
            }
            imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
            $img = $dst;
        }

        $ext  = self::TYPES[$mime];
        $name = bin2hex(random_bytes(8)) . '.' . $ext;
        $path = $dir . '/' . $name;
        $ok = match ($ext) {
            'jpg'   => imagejpeg($img, $path, 85),
            'png'   => imagepng($img, $path, 6),
            default => imagewebp($img, $path, 82),
        };
        if (!$ok || !is_file($path)) return $fail('could not be saved.');
        @chmod($path, 0644);
        return ['ok' => true, 'file' => $name];
    }
}
