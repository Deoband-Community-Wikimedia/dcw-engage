<?php
/**
 * DCW Engage - portal diagnostics.
 *
 * Read-only health checks for the technical manager. By design this reports PASS / WARN / FAIL
 * and system facts only. It never returns row contents, row counts of membership tables, config
 * values, secrets, file paths or raw exception messages (a PDO message can contain the DB host).
 * Details of a failure go to the server log instead.
 */
class PortalDiagnostics {
    /** Tables the portal needs. Add any others your install relies on. */
    const EXPECTED_TABLES = [
        'admin_users', 'admin_user_roles', 'applications', 'forms',
        'members', 'membership_scopes', 'membership_decisions',
        'tech_issues', 'tech_issue_messages',
    ];

    const REQUIRED_EXTENSIONS = ['pdo_mysql', 'mbstring', 'openssl', 'json', 'curl', 'fileinfo'];

    /** @return array<string, array<int, array{label:string,state:string,detail:string}>> */
    public static function run(): array {
        $out = [];
        $groups = ['Server' => 'server', 'Database' => 'database', 'Security settings' => 'security', 'Storage' => 'storage'];
        foreach ($groups as $title => $method) {
            try {
                $out[$title] = self::$method();
            } catch (Throwable $e) {
                error_log('Diagnostics group "' . $title . '" failed: ' . $e->getMessage());
                $out[$title] = [self::c('This group could not run', 'fail', 'Unexpected error (' . get_class($e) . '). See the server log.')];
            }
        }
        return $out;
    }

    /** Count of checks per state, for the summary line. */
    public static function tally(array $groups): array {
        $t = ['ok' => 0, 'warn' => 0, 'fail' => 0];
        foreach ($groups as $checks) foreach ($checks as $c) $t[$c['state']]++;
        return $t;
    }

    private static function c(string $label, string $state, string $detail = ''): array {
        return ['label' => $label, 'state' => $state, 'detail' => $detail];
    }

    private static function server(): array {
        $r = [];
        $r[] = self::c('PHP version', version_compare(PHP_VERSION, '8.1.0', '>=') ? 'ok' : 'warn',
            PHP_VERSION . (version_compare(PHP_VERSION, '8.1.0', '>=') ? '' : ' (older than 8.1; plan an upgrade)'));
        foreach (self::REQUIRED_EXTENSIONS as $ext) {
            $r[] = self::c('Extension: ' . $ext, extension_loaded($ext) ? 'ok' : 'fail', extension_loaded($ext) ? 'Loaded' : 'Missing');
        }
        $r[] = self::c('Memory limit', 'ok', (string) ini_get('memory_limit'));
        $r[] = self::c('Upload limits', 'ok', 'Files up to ' . ini_get('upload_max_filesize') . ', requests up to ' . ini_get('post_max_size'));
        $r[] = self::c('PHP time zone', 'ok', date_default_timezone_get());
        return $r;
    }

    private static function database(): array {
        $r = [];
        $t0  = microtime(true);
        $pdo = DB::getInstance()->getConnection();
        $dbNow = (string) $pdo->query('SELECT UTC_TIMESTAMP()')->fetchColumn();
        $ms = (int) round((microtime(true) - $t0) * 1000);
        $r[] = self::c('Connection', $ms > 500 ? 'warn' : 'ok', 'Answered in ' . $ms . ' ms' . ($ms > 500 ? ' (slow)' : ''));
        $r[] = self::c('Server version', 'ok', (string) $pdo->query('SELECT VERSION()')->fetchColumn());

        // Dates are stored in UTC, so a drifting clock shifts expiry and reset times.
        $drift = abs(strtotime($dbNow . ' UTC') - time());
        $r[] = self::c('Clock agreement (database vs web server)', $drift > 120 ? 'warn' : 'ok',
            $drift > 120 ? 'Off by about ' . round($drift / 60) . ' minutes. Expiry and link times may be wrong.' : 'Within ' . max($drift, 1) . ' s');

        $have = array_map('strtolower', $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN));
        $missing = array_values(array_diff(self::EXPECTED_TABLES, $have));
        $r[] = self::c('Required tables', $missing ? 'fail' : 'ok',
            $missing ? 'Missing: ' . implode(', ', $missing) . '. Run the pending migration.' : 'All ' . count(self::EXPECTED_TABLES) . ' present');

        // The check this rollout needs: an ENUM role column silently rejects a new role.
        foreach (['admin_users', 'admin_user_roles'] as $tbl) {
            try {
                $row = $pdo->query("SHOW COLUMNS FROM `$tbl` LIKE 'role'")->fetch();
                $type = strtolower((string) ($row['Type'] ?? ''));
                if (!$row) {
                    $r[] = self::c("$tbl.role accepts technical_manager", 'warn', 'No role column found.');
                } elseif (str_starts_with($type, 'enum(') && !str_contains($type, 'technical_manager')) {
                    $r[] = self::c("$tbl.role accepts technical_manager", 'fail', 'The column is an ENUM without technical_manager. Run the ALTER in the migration file.');
                } else {
                    $r[] = self::c("$tbl.role accepts technical_manager", 'ok', 'Yes');
                }
            } catch (Throwable $e) {
                $r[] = self::c("$tbl.role accepts technical_manager", 'warn', 'Could not check (table missing?).');
            }
        }
        return $r;
    }

    private static function security(): array {
        $r = [];
        $off = in_array(strtolower((string) ini_get('display_errors')), ['', '0', 'off', 'false', 'stderr'], true);
        $r[] = self::c('PHP errors hidden from visitors', $off ? 'ok' : 'fail',
            $off ? 'display_errors is off' : 'display_errors is on. Errors can leak file paths and queries. Turn it off in production.');

        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
              || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
        $r[] = self::c('This request used HTTPS', $https ? 'ok' : 'warn', $https ? 'Yes' : 'No. Sign-in cookies and links should only travel over HTTPS.');

        $config = require __DIR__ . '/config.php';
        $scheme = (string) parse_url((string) ($config['app']['url'] ?? ''), PHP_URL_SCHEME);
        $r[] = self::c('Configured app URL uses HTTPS', $scheme === 'https' ? 'ok' : 'warn',
            $scheme === 'https' ? 'Yes' : 'It does not. Invitation and magic links are built from it.');

        $p = session_get_cookie_params();
        $r[] = self::c('Session cookie is HttpOnly', !empty($p['httponly']) ? 'ok' : 'warn', !empty($p['httponly']) ? 'Yes' : 'No');
        $r[] = self::c('Session cookie is Secure', !empty($p['secure']) ? 'ok' : 'warn', !empty($p['secure']) ? 'Yes' : 'No. Set it once the site is HTTPS-only.');
        $strict = (string) ini_get('session.use_strict_mode') === '1';
        $r[] = self::c('Strict session mode', $strict ? 'ok' : 'warn', $strict ? 'On' : 'Off');
        return $r;
    }

    private static function storage(): array {
        $r = [];
        $dir   = dirname(__DIR__);
        $free  = @disk_free_space($dir);
        $total = @disk_total_space($dir);
        if ($free !== false && $total) {
            $pct = (int) round($free / $total * 100);
            $r[] = self::c('Free disk space', $pct < 10 ? 'fail' : ($pct < 20 ? 'warn' : 'ok'), $pct . '% free');
        } else {
            $r[] = self::c('Free disk space', 'warn', 'Could not be read on this host.');
        }
        $sessPath = session_save_path() ?: sys_get_temp_dir();
        $r[] = self::c('Session storage writable', is_writable($sessPath) ? 'ok' : 'fail', is_writable($sessPath) ? 'Yes' : 'No. Nobody can stay signed in.');
        $r[] = self::c('Temporary folder writable', is_writable(sys_get_temp_dir()) ? 'ok' : 'warn', is_writable(sys_get_temp_dir()) ? 'Yes' : 'No');
        return $r;
    }
}
