<?php

/** Shared access policy for the UI, API and Apache file gateway. */
class Auth {
    private static $userFile;

    public static function start() {
        if (PHP_VERSION_ID < 70300) {
            self::stop(503, 'PHP 7.3 ou plus récent est requis.');
        }
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        session_name('H5AI_SESSION');
        session_set_cookie_params([
            'lifetime' => 0, 'path' => '/',
            // Behind Traefik, set H5AI_HTTPS=1 in the container. Do not trust client headers.
            'secure' => getenv('H5AI_HTTPS') === '1' || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
            'httponly' => true, 'samesite' => 'Lax'
        ]);
        if (!session_start()) {
            self::stop(503, 'Stockage des sessions indisponible.');
        }
        header('Cache-Control: private, no-store, max-age=0');
        header('Pragma: no-cache');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: same-origin');
        self::$userFile = getenv('H5AI_AUTH_FILE') ?: __DIR__ . '/../../conf/users.htpasswd';
        try {
            Users::initialize(self::$userFile);
        } catch (RuntimeException $error) {
            error_log('h5ai users: ' . $error->getMessage());
            self::stop(503, 'Comptes indisponibles. Consultez INSTALL-AUTH.md et les journaux du serveur.');
        }
        $now = time();
        if (isset($_SESSION['access']) && (
            $now - $_SESSION['access']['last'] > 1800 ||
            $now - $_SESSION['access']['created'] > 28800 ||
            !hash_equals($_SESSION['access']['revision'], self::revision()) ||
            Users::get($_SESSION['access']['user']) === null
        )) {
            $expired = $now - $_SESSION['access']['last'] > 1800 || $now - $_SESSION['access']['created'] > 28800;
            Audit::record($expired ? 'auth.expired' : 'auth.revoked', 'denied', $_SESSION['access']['user'],
                ['reason' => $expired ? 'expired' : 'account_changed']);
            $_SESSION = [];
            session_regenerate_id(true);
        }
        if (empty($_SESSION['access']) && in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD'], true)) {
            try {
                $remembered = Remember::restore();
                if ($remembered !== null) {
                    self::authenticate($remembered['user']);
                    $_SESSION['remember_selector'] = $remembered['selector'];
                    Audit::record('auth.remember', 'success', $remembered['user']);
                }
            } catch (RuntimeException $error) {
                error_log('h5ai remember: ' . $error->getMessage());
                self::stop(503, 'Connexion persistante indisponible. Réessayez ultérieurement.');
            }
        }
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        $action = $_POST['h5ai_auth'] ?? null;
        if ($action !== null) {
            self::requireCsrf($_POST['_csrf'] ?? null);
            if ($action === 'logout') {
                try { Remember::forget(); } catch (RuntimeException $error) {
                    Audit::record('auth.logout', 'failure', $_SESSION['access']['user'] ?? null, ['reason' => 'storage_unavailable']);
                    error_log('h5ai remember logout: ' . $error->getMessage());
                    self::stop(503, 'Déconnexion impossible. Réessayez ultérieurement.');
                }
                Audit::record('auth.logout', 'success', $_SESSION['access']['user'] ?? null);
                $_SESSION = [];
                $cookie = session_get_cookie_params();
                setcookie(session_name(), '', [
                    'expires' => time() - 3600, 'path' => $cookie['path'],
                    'secure' => $cookie['secure'], 'httponly' => true, 'samesite' => 'Lax'
                ]);
                session_destroy();
                header('Location: ' . Routes::url('home'), true, 303);
                exit;
            }
            if ($action === 'login') {
                self::login();
            }
            self::stop(400, 'Action incorrecte.');
        }
        if (empty($_SESSION['access'])) {
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                self::stop(401, 'Connexion requise.');
            }
            self::page();
        }
        $_SESSION['access']['last'] = $now;
        if (Users::mustChangePassword() && !Routes::matches('account') && !Routes::matches('photo')) {
            if (in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD'], true)) {
                header('Location: ' . Routes::url('account'), true, 303);
                exit;
            }
            self::stop(403, 'Vous devez changer votre mot de passe temporaire avant de continuer.');
        }
    }

    private static function revision($user = null) {
        return Users::accountRevision($user ?? ($_SESSION['access']['user'] ?? '')) ?? '';
    }

    public static function endpoint() {
        return rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/') . '/index.php';
    }

    public static function pageTitle($label) {
        return $label . ' - ' . self::domain();
    }

    public static function domain() {
        $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
        $domain = parse_url('http://' . $host, PHP_URL_HOST);
        return is_string($domain) && $domain !== '' ? $domain : 'localhost';
    }

    public static function requireCsrf($token) {
        if (!is_string($token) || !hash_equals($_SESSION['csrf'], $token)) {
            Audit::record('security.csrf', 'denied');
            self::stop(403, 'Jeton de sécurité invalide. Rechargez la page.', false);
        }
    }

    private static function login() {
        $user = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';
        if (!is_string($user) || !is_string($password) || strlen($user) > 128 || strlen($password) > 1024) {
            Audit::record('auth.failed', 'failure', is_string($user) ? $user : null, ['reason' => 'invalid_format']);
            self::page('Identifiant ou mot de passe incorrect.', 401);
        }
        // Persistent per-account bucket, independent of sessions and shared IPs.
        self::limit('account:' . $user, 10, true, $user);
        // bcrypt only: older Apache apr1/SHA1 hashes must be migrated.
        $hash = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
        $found = false;
        $account = Users::get($user);
        if ($account !== null) {
            $hash = $account['hash'];
            $found = true;
        }
        $valid = password_verify($password, $hash);
        if (!$valid || !$found) {
            Audit::record('auth.failed', 'failure', $user, ['reason' => 'invalid_credentials']);
            self::page('Identifiant ou mot de passe incorrect.', 401);
        }
        try {
            Remember::forget();
            self::authenticate($user);
            if (($_POST['remember'] ?? null) === '1') {
                if (Users::mustChangePassword()) { $_SESSION['remember_after_password_change'] = true; }
                else { Remember::issue($user); }
            }
        } catch (RuntimeException $error) {
            error_log('h5ai remember login: ' . $error->getMessage());
            Audit::record('auth.failed', 'failure', $user, ['reason' => 'storage_unavailable']);
            $_SESSION = [];
            session_regenerate_id(true);
            self::stop(503, 'Connexion persistante indisponible. Réessayez sans « Rester connecté ».');
        }
        Audit::record('auth.login', 'success', $user);
        // Return to the requested local path; no user-provided redirect URL.
        $path = Users::mustChangePassword() ? Routes::url('account') : Routes::loginTarget();
        if (!is_string($path) || substr($path, 0, 1) !== '/' || substr($path, 0, 2) === '//' || preg_match('/[\x00-\x20\\\\]/', $path)) {
            $path = Routes::url('home');
        }
        header('Location: ' . $path, true, 303);
        exit;
    }

    private static function authenticate($user) {
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        $_SESSION['access'] = ['user' => $user, 'created' => time(), 'last' => time(), 'revision' => self::revision($user)];
    }

    public static function limitAccountChanges() {
        self::limit('account-settings:' . $_SESSION['access']['user'], 10, false, $_SESSION['access']['user']);
    }

    private static function limit($key, $max, $login = true, $target = null) {
        $dir = getenv('H5AI_AUTH_STATE') ?: __DIR__ . '/../../cache/auth';
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            self::stop(503, 'Stockage de sécurité indisponible.');
        }
        $file = $dir . '/' . hash('sha256', $key) . '.json';
        $fp = @fopen($file, 'c+');
        if (!$fp || !flock($fp, LOCK_EX)) {
            self::stop(503, 'Stockage de sécurité indisponible.');
        }
        $state = json_decode(stream_get_contents($fp), true);
        if (!is_array($state) || ($state['since'] ?? 0) < time() - 900) {
            $state = ['since' => time(), 'attempts' => 0];
        }
        if ($state['attempts'] >= $max) {
            fclose($fp);
            Audit::record('security.rate_limited', 'denied', $target, ['reason' => 'too_many_attempts', 'action' => $login ? 'login' : 'profile']);
            header('Retry-After: ' . max(1, 900 - (time() - $state['since'])));
            if (!$login) { self::stop(429, 'Trop de tentatives. Réessayez dans 15 minutes.'); }
            self::page('Trop de tentatives. Réessayez dans 15 minutes.', 429);
        }
        $state['attempts']++;
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($state));
        fflush($fp);
        fclose($fp);
    }

    public static function escape($value) {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function page($error = '', $status = 200) {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        require __DIR__ . '/../pages/login.php';
        exit;
    }

    public static function stop($status, $message, $audit = true) {
        if ($audit && in_array($status, [401, 403, 404, 405], true)) {
            Audit::record('access.denied', 'denied', null, ['status' => $status, 'path' => parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH)]);
        }
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['err' => $status, 'message' => $message]);
        exit;
    }

    /** Canonical containment; deny symlinks, dot files and application internals. */
    public static function allowedPath($path, $allowCache = false) {
        if (!is_string($path) || strpos($path, "\0") !== false) {
            return false;
        }
        $root = str_replace('\\', '/', realpath(__DIR__ . '/../../../..'));
        $path = str_replace('\\', '/', $path);
        if (strpos($path, $root . '/') !== 0 && $path !== $root) {
            return false;
        }
        $relative = substr($path, strlen($root));
        $cursor = $root;
        foreach (explode('/', trim($relative, '/')) as $part) {
            if ($part === '') { continue; }
            if ($part[0] === '.' || strpos($part, ':') !== false) { return false; }
            $cursor .= '/' . $part;
            if (is_link($cursor)) { return false; }
        }
        $real = realpath($path);
        if ($real === false) { return false; }
        $real = str_replace('\\', '/', $real);
        // Secrets remain private even if an operator mounts them under the share.
        foreach ([self::$userFile, Users::directory(), getenv('H5AI_AUTH_STATE') ?: __DIR__ . '/../../cache/auth'] as $secret) {
            $secret = $secret ? realpath($secret) : false;
            if ($secret !== false) {
                $secret = str_replace('\\', '/', $secret);
                if ($real === $secret || strpos($real, $secret . '/') === 0) { return false; }
            }
        }
        if (is_file($real) && preg_match('/\.(?:php\d*|phtml|phar)$/i', $real)) {
            return false;
        }
        $app = str_replace('\\', '/', realpath(__DIR__ . '/../../..'));
        if ($real === $app || strpos($real, $app . '/') === 0) {
            $cache = $app . '/public/cache/';
            if (!$allowCache || strpos($real, $cache) !== 0 || !is_file($real)
                || !preg_match('/\.jpg$/i', $real)) { return false; }
            $metadata = $real . '.source.json';
            if (is_link($metadata)) { return false; }
            $source = is_file($metadata) ? json_decode(file_get_contents($metadata), true) : null;
            return is_string($source) && self::allowedPath($source);
        }
        return ($real === $root || strpos($real, $root . '/') === 0)
            && Users::permits(substr($real, strlen($root)), is_dir($real));
    }
}
