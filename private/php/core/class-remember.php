<?php

/** Persistent login: only random selectors/secrets leave the server. */
class Remember {
    const COOKIE = 'H5AI_REMEMBER';
    const LIFETIME = 2592000; // 30 days, absolute (not extended by rotation).

    private static function directory() {
        return Users::directory() . '/remember';
    }

    private static function cookie($value, $expires) {
        $params = session_get_cookie_params();
        setcookie(self::COOKIE, $value, [
            'expires' => $expires, 'path' => '/', 'secure' => $params['secure'],
            'httponly' => true, 'samesite' => 'Lax'
        ]);
    }

    private static function parsed() {
        $cookie = $_COOKIE[self::COOKIE] ?? null;
        if (!is_string($cookie) || !preg_match('/^([a-f0-9]{32}):([a-f0-9]{64})$/D', $cookie, $parts)) { return null; }
        return ['selector' => $parts[1], 'secret' => $parts[2]];
    }

    private static function write($handle, $record) {
        $json = json_encode($record);
        if ($json === false || !ftruncate($handle, 0) || !rewind($handle)
            || fwrite($handle, $json) !== strlen($json) || !fflush($handle)) {
            throw new RuntimeException('Écriture du jeton persistant impossible.');
        }
    }

    public static function issue($user) {
        $directory = self::directory();
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Stockage de connexion persistante indisponible.');
        }
        $selector = bin2hex(random_bytes(16));
        $secret = bin2hex(random_bytes(32));
        $expires = time() + self::LIFETIME;
        $handle = @fopen($directory . '/' . $selector . '.json', 'x+');
        if (!$handle) { throw new RuntimeException('Création du jeton persistant impossible.'); }
        try {
            chmod($directory . '/' . $selector . '.json', 0600);
            if (!flock($handle, LOCK_EX)) { throw new RuntimeException('Verrouillage du jeton impossible.'); }
            self::write($handle, ['user' => $user, 'revision' => Users::accountRevision($user),
                'hash' => hash('sha256', $secret), 'expires' => $expires]);
        } finally { fclose($handle); }
        self::cookie($selector . ':' . $secret, $expires);
        $_SESSION['remember_selector'] = $selector;
        // Bound stale storage without touching a recently used/locked token.
        $files = glob($directory . '/*.json') ?: [];
        foreach (array_slice($files, 0, 100) as $file) {
            if (@filemtime($file) < time() - self::LIFETIME - 86400) { @unlink($file); }
        }
    }

    private static function matches($record, $secret) {
        $hash = hash('sha256', $secret);
        if (is_string($record['hash'] ?? null) && hash_equals($record['hash'], $hash)) { return 'current'; }
        if (($record['previous_until'] ?? 0) >= time() && is_string($record['previous'] ?? null)
            && hash_equals($record['previous'], $hash)) { return 'previous'; }
        return false;
    }

    public static function restore() {
        if (!isset($_COOKIE[self::COOKIE])) { return null; }
        $token = self::parsed();
        $restored = null;
        if ($token !== null) {
            $handle = @fopen(self::directory() . '/' . $token['selector'] . '.json', 'r+');
            if ($handle) {
                try {
                    if (!flock($handle, LOCK_EX)) { throw new RuntimeException('Verrouillage du jeton impossible.'); }
                    $record = json_decode(stream_get_contents($handle, 4096), true);
                    if (is_array($record) && empty($record['revoked']) && is_int($record['expires'] ?? null) && $record['expires'] > time()
                        && is_string($record['user'] ?? null) && Users::get($record['user']) !== null
                        && empty(Users::get($record['user'])['must_change_password'])
                        && is_string($record['revision'] ?? null) && hash_equals(Users::accountRevision($record['user']), $record['revision'])) {
                        $match = self::matches($record, $token['secret']);
                        if ($match) {
                            if ($match === 'current') {
                                $secret = bin2hex(random_bytes(32));
                                $record['previous'] = $record['hash'];
                                $record['previous_until'] = time() + 30;
                                $record['hash'] = hash('sha256', $secret);
                                self::write($handle, $record);
                                self::cookie($token['selector'] . ':' . $secret, $record['expires']);
                            }
                            // A parallel request can finish using the old token for
                            // 30 seconds, without overwriting the newly issued cookie.
                            $restored = ['user' => $record['user'], 'selector' => $token['selector']];
                        }
                    }
                } finally { fclose($handle); }
            }
        }
        if ($restored === null) {
            Audit::record('auth.remember_failed', 'failure');
            self::cookie('', time() - 3600);
        }
        return $restored;
    }

    public static function forget() {
        $token = self::parsed();
        $selector = $_SESSION['remember_selector'] ?? ($token['selector'] ?? null);
        if (is_string($selector) && preg_match('/^[a-f0-9]{32}$/D', $selector)) {
            $path = self::directory() . '/' . $selector . '.json';
            $handle = @fopen($path, 'r+');
            if (!$handle && is_file($path)) { throw new RuntimeException('Révocation du jeton persistant impossible.'); }
            if ($handle) {
                try {
                    if (!flock($handle, LOCK_EX)) { throw new RuntimeException('Verrouillage du jeton impossible.'); }
                    $record = json_decode(stream_get_contents($handle, 4096), true);
                    $owned = ($_SESSION['remember_selector'] ?? null) === $selector;
                    $verified = $token !== null && $token['selector'] === $selector && is_array($record)
                        && self::matches($record, $token['secret']);
                    if ($owned || $verified) { self::write($handle, ['revoked' => true, 'expires' => 0]); }
                } finally { fclose($handle); }
            }
        }
        unset($_SESSION['remember_selector']);
        self::cookie('', time() - 3600);
    }
}
