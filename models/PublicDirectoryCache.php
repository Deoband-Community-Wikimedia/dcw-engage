<?php
/**
 * DCW Engage - file cache for the public members directory.
 *
 * Deliberately has no dependencies, so MemberStrikeModel, MemberProfileModel and MemberModel can call
 * PublicDirectoryCache::clear() without creating a circular require.
 * The cache only ever holds what /members shows publicly (usernames and club keys).
 */
class PublicDirectoryCache
{
    public static function file(): string
    {
        $dir = __DIR__ . '/../cache';
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        if (!is_dir($dir) || !is_writable($dir)) {
            $dir = sys_get_temp_dir();
        }
        return $dir . '/dcw_public_directory.json';
    }

    /** Drop the cached list so the next visit rebuilds it. Safe to call anywhere; never throws. */
    public static function clear(): void
    {
        try {
            @unlink(self::file());
        } catch (Throwable $e) {
            // Nothing to do: the cache simply expires on its own.
        }
    }

    /** The cached directory if it is younger than $ttl seconds, else null. */
    public static function read(int $ttl): ?array
    {
        $f = self::file();
        if (!is_file($f) || filemtime($f) < time() - $ttl) {
            return null;
        }
        $d = json_decode((string) @file_get_contents($f), true);
        return (is_array($d) && isset($d['current'], $d['alumni'])) ? $d : null;
    }

    public static function write(array $directory): void
    {
        $f   = self::file();
        $tmp = $f . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, json_encode($directory, JSON_UNESCAPED_UNICODE), LOCK_EX) !== false) {
            @rename($tmp, $f);   // atomic, so a visitor never reads half a file
        }
        @unlink($tmp);
    }
}
