<?php

/** Private, bounded JSONL audit trail. Never accept arbitrary request payloads. */
class Audit {
    const RETENTION = 60;
    const SEGMENT_BYTES = 5242880;
    const SEGMENTS_PER_DAY = 2;
    const MAX_LINE = 16384;
    private static $reported = false;

    public static function directory() { return Users::directory() . '/audit'; }

    public static function deniedPath($path) {
        self::record('access.denied', 'denied', $_SESSION['access']['user'] ?? null,
            ['path' => is_string($path) ? parse_url($path, PHP_URL_PATH) : null]);
    }

    public static function events() {
        return [
            'auth.login' => 'Connexion réussie', 'auth.failed' => 'Échec de connexion',
            'auth.remember' => 'Reconnexion automatique', 'auth.remember_failed' => 'Reconnexion automatique refusée', 'auth.logout' => 'Déconnexion',
            'auth.expired' => 'Session expirée', 'auth.revoked' => 'Session invalidée',
            'security.rate_limited' => 'Tentatives limitées', 'security.csrf' => 'Jeton de formulaire refusé',
            'access.denied' => 'Accès refusé', 'user.created' => 'Compte créé',
            'user.updated' => 'Compte modifié', 'user.deleted' => 'Compte supprimé',
            'user.renamed' => 'Identifiant modifié', 'user.role' => 'Rôle modifié',
            'user.folders' => 'Dossiers autorisés modifiés', 'user.photo' => 'Photo de profil modifiée',
            'password.changed' => 'Mot de passe changé', 'password.initial' => 'Mot de passe temporaire remplacé',
            'password.reset' => 'Mot de passe réinitialisé', 'user.failed' => 'Modification de compte refusée',
            'audit.view' => 'Journaux consultés'
        ];
    }

    private static function clean($value, $length = 128) {
        if (!is_string($value)) { return null; }
        $value = preg_replace('/[\x00-\x1f\x7f]/u', ' ', $value);
        if ($value === null || !preg_match('/^.{0,' . $length . '}/us', $value, $match)) { return ''; }
        return $match[0];
    }

    private static function details($values) {
        $safe = [];
        foreach (['reason', 'action', 'old_name', 'new_name', 'from_role', 'to_role', 'path'] as $key) {
            if (isset($values[$key]) && is_string($values[$key])) { $safe[$key] = self::clean($values[$key], $key === 'path' ? 256 : 128); }
        }
        foreach (['fields', 'added_folders', 'removed_folders'] as $key) {
            if (isset($values[$key]) && is_array($values[$key])) {
                $safe[$key] = [];
                foreach (array_slice($values[$key], 0, 5) as $value) {
                    if (is_string($value)) { $safe[$key][] = self::clean($value, 128); }
                }
            }
        }
        foreach (['status', 'folder_count'] as $key) {
            if (isset($values[$key]) && is_int($values[$key])) { $safe[$key] = $values[$key]; }
        }
        return $safe;
    }

    private static function inNetwork($ip, $network) {
        $parts = explode('/', trim($network), 2);
        $address = @inet_pton($ip);
        $base = @inet_pton($parts[0]);
        if ($address === false || $base === false || strlen($address) !== strlen($base)) { return false; }
        if (isset($parts[1]) && !preg_match('/^\d{1,3}$/D', $parts[1])) { return false; }
        $bits = isset($parts[1]) ? (int) $parts[1] : strlen($base) * 8;
        if ($bits < 0 || $bits > strlen($base) * 8) { return false; }
        $bytes = intdiv($bits, 8);
        $rest = $bits % 8;
        return substr($address, 0, $bytes) === substr($base, 0, $bytes)
            && ($rest === 0 || ((ord($address[$bytes]) ^ ord($base[$bytes])) & (255 << (8 - $rest))) === 0);
    }

    public static function clientAddress() {
        $peer = $_SERVER['REMOTE_ADDR'] ?? '';
        if (!filter_var($peer, FILTER_VALIDATE_IP)) { return ['ip' => null, 'source' => 'network']; }
        $networks = array_filter(array_map('trim', explode(',', getenv('H5AI_TRUSTED_PROXIES') ?: '')));
        $trusted = function ($ip) use ($networks) {
            foreach ($networks as $network) { if (self::inNetwork($ip, $network)) { return true; } }
            return false;
        };
        $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if (!$trusted($peer) || !is_string($forwarded) || strlen($forwarded) > 2048 || $forwarded === '') {
            return ['ip' => $peer, 'source' => 'network'];
        }
        $chain = array_map('trim', explode(',', $forwarded));
        if (count($chain) > 20) { return ['ip' => $peer, 'source' => 'network']; }
        foreach ($chain as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP)) { return ['ip' => $peer, 'source' => 'network']; }
        }
        // Walk from the trusted peer toward the client; ignore spoofed left prefixes.
        for ($index = count($chain) - 1; $index >= 0; $index--) {
            if (!$trusted($chain[$index]) || $index === 0) { return ['ip' => $chain[$index], 'source' => 'proxy']; }
        }
        return ['ip' => $peer, 'source' => 'network'];
    }

    public static function record($event, $result = 'success', $target = null, $details = []) {
        if (!isset(self::events()[$event]) || !in_array($result, ['success', 'failure', 'denied'], true)) { return false; }
        $address = self::clientAddress();
        $record = ['id' => '', 'time' => 0, 'event' => $event, 'result' => $result,
            'actor' => self::clean($_SESSION['access']['user'] ?? null), 'target' => self::clean($target),
            'ip' => $address['ip'], 'ip_source' => $address['source'], 'details' => self::details($details)];
        $lock = null;
        $handle = null;
        try {
            $dir = self::directory();
            if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) { throw new RuntimeException(); }
            $lock = @fopen($dir . '/audit.lock', 'c');
            if (!$lock || !flock($lock, LOCK_EX)) { throw new RuntimeException(); }
            @chmod($dir . '/audit.lock', 0600);
            $id = sprintf('%020.0f', microtime(true) * 1000000) . '-' . bin2hex(random_bytes(4));
            $record['id'] = $id;
            $record['time'] = time();
            $line = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($line === false || strlen($line) >= self::MAX_LINE) { throw new RuntimeException(); }
            $today = gmdate('Y-m-d');
            $maintenance = $today . ':' . self::RETENTION;
            if (@file_get_contents($dir . '/maintenance') !== $maintenance) {
                $oldest = gmdate('Y-m-d', time() - self::RETENTION * 86400);
                foreach (glob($dir . '/*') ?: [] as $file) {
                    if (preg_match('/^(\d{4}-\d{2}-\d{2})(?:\.\d{2}\.jsonl|\.limit)$/D', basename($file), $match)
                        && $match[1] < $oldest && !is_link($file)) { @unlink($file); }
                }
                @file_put_contents($dir . '/maintenance', $maintenance);
                @chmod($dir . '/maintenance', 0600);
            }
            for ($part = 0; $part < self::SEGMENTS_PER_DAY; $part++) {
                $file = $dir . '/' . $today . '.' . sprintf('%02d', $part) . '.jsonl';
                clearstatcache(true, $file);
                if (is_link($file)) { throw new RuntimeException(); }
                if (!is_file($file) || filesize($file) + strlen($line) + 1 <= self::SEGMENT_BYTES) {
                    $handle = @fopen($file, 'ab');
                    if (!$handle || !flock($handle, LOCK_EX)) { throw new RuntimeException(); }
                    @chmod($file, 0600);
                    if (fwrite($handle, $line . "\n") !== strlen($line) + 1 || !fflush($handle)) { throw new RuntimeException(); }
                    return $id;
                }
            }
            $marker = @fopen($dir . '/' . $today . '.limit', 'x');
            if ($marker) {
                fwrite($marker, 'Daily audit quota reached'); fclose($marker);
                @chmod($dir . '/' . $today . '.limit', 0600);
                error_log('h5ai audit: daily quota reached');
            }
            self::$reported = true;
            return false;
        } catch (Throwable $error) {
            if (!self::$reported) { error_log('h5ai audit: storage unavailable'); self::$reported = true; }
            return false;
        } finally {
            if (is_resource($handle)) { fclose($handle); }
            if (is_resource($lock)) { fclose($lock); }
        }
    }

    private static function reverseLines($path) {
        $handle = @fopen($path, 'rb');
        if (!$handle || !flock($handle, LOCK_SH)) {
            if ($handle) { fclose($handle); }
            throw new RuntimeException('Journaux non lisibles.');
        }
        try {
            $position = fstat($handle)['size'];
            $buffer = '';
            $discard = false;
            while ($position > 0) {
                $length = min(16384, $position);
                $position -= $length;
                fseek($handle, $position);
                $chunk = fread($handle, $length);
                if ($chunk === false) { throw new RuntimeException('Lecture du journal impossible.'); }
                $buffer = $chunk . $buffer;
                while (($newline = strrpos($buffer, "\n")) !== false) {
                    $line = substr($buffer, $newline + 1);
                    $buffer = substr($buffer, 0, $newline);
                    if (!$discard && $line !== '' && strlen($line) <= self::MAX_LINE) { yield $line; }
                    $discard = false;
                }
                if (strlen($buffer) > self::MAX_LINE) { $buffer = ''; $discard = true; }
            }
            if (!$discard && $buffer !== '') { yield $buffer; }
        } finally { fclose($handle); }
    }

    public static function query($filter) {
        $dir = self::directory();
        if (!is_dir($dir) || !is_readable($dir)) { throw new RuntimeException('Journaux indisponibles.'); }
        $files = glob($dir . '/*.jsonl') ?: [];
        rsort($files, SORT_STRING);
        $rows = [];
        $skip = ($filter['page'] - 1) * 50;
        $oldest = max($filter['from'], time() - self::RETENTION * 86400);
        $warnings = false;
        foreach ($files as $file) {
            if (is_link($file) || !preg_match('/^(\d{4}-\d{2}-\d{2})\.\d{2}\.jsonl$/D', basename($file), $date)
                || $date[1] < gmdate('Y-m-d', $oldest) || $date[1] > gmdate('Y-m-d', $filter['to'])) { continue; }
            foreach (self::reverseLines($file) as $line) {
                $row = json_decode($line, true);
                if (!is_array($row) || !is_int($row['time'] ?? null) || !is_string($row['id'] ?? null)
                    || !preg_match('/^\d{20}-[a-f0-9]{8}$/D', $row['id']) || !is_string($row['event'] ?? null) || !isset(self::events()[$row['event']])
                    || !in_array($row['result'] ?? '', ['success', 'failure', 'denied'], true)) { $warnings = true; continue; }
                if ($row['time'] < $oldest || $row['time'] > $filter['to'] || strcmp($row['id'], $filter['before']) > 0
                    || ($filter['event'] !== '' && $filter['event'] !== $row['event'])
                    || ($filter['result'] !== '' && $filter['result'] !== $row['result'])
                    || ($filter['user'] !== '' && $filter['user'] !== ($row['actor'] ?? null) && $filter['user'] !== ($row['target'] ?? null))) { continue; }
                if ($skip > 0) { $skip--; continue; }
                $row['actor'] = self::clean($row['actor'] ?? null);
                $row['target'] = self::clean($row['target'] ?? null);
                $row['ip'] = filter_var($row['ip'] ?? '', FILTER_VALIDATE_IP) ?: null;
                $row['ip_source'] = ($row['ip_source'] ?? '') === 'proxy' ? 'proxy' : 'network';
                $row['details'] = self::details(is_array($row['details'] ?? null) ? $row['details'] : []);
                $rows[] = $row;
                if (count($rows) > 50) { break 2; }
            }
        }
        $next = count($rows) > 50;
        return ['rows' => array_slice($rows, 0, 50), 'next' => $next, 'warnings' => $warnings,
            'quota' => (bool) glob($dir . '/*.limit')];
    }
}
