<?php

/** Private history of explicit file openings, separate from security events. */
class Filejournal {
    const RETENTION = 60;
    const DAILY_LIMIT = 2000;

    public static function source($href) {
        if (!is_string($href) || strlen($href) > 8192 || substr($href, 0, 2) === '//'
            || preg_match('/[\x00-\x20\\\\?#]/', $href)) { return null; }
        $root = Routes::url('home');
        if (strpos($href, $root) !== 0) { return null; }
        $path = dirname(__DIR__, 4) . '/' . rawurldecode(substr($href, strlen($root)));
        return is_file($path) && Auth::allowedPath($path) ? realpath($path) : null;
    }

    private static function directory() {
        $directory = Users::directory() . '/file-journal';
        if (is_link($directory) || (!is_dir($directory) && !@mkdir($directory, 0700) && !is_dir($directory))) {
            throw new RuntimeException('Stockage des journaux indisponible.');
        }
        return $directory;
    }

    private static function kind($path) {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (in_array($ext, ['mp4', 'm4v', 'mkv', 'webm', 'mov', 'avi', 'flv', 'mpg', 'mpeg'], true)) { return 'video'; }
        if (in_array($ext, ['mp3', 'ogg', 'oga', 'wav', 'flac', 'm4a', 'aac', 'opus', 'wma', 'aiff'], true)) { return 'audio'; }
        return 'file';
    }

    private static function write($file, $data) {
        $temporary = $file . '.' . bin2hex(random_bytes(8)) . '.tmp';
        $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        $mask = umask(0077);
        try {
            if (file_put_contents($temporary, $json) !== strlen($json) || !rename($temporary, $file)) {
                @unlink($temporary); throw new RuntimeException('Écriture du journal impossible.');
            }
        } finally { umask($mask); }
    }

    private static function record() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { Auth::stop(405, 'Méthode non autorisée.'); }
        Auth::requireCsrf($_POST['_csrf'] ?? null);
        $user = $_SESSION['access']['user'] ?? '';
        $token = $_POST['token'] ?? '';
        $action = $_POST['action'] ?? '';
        if (!is_string($token) || !preg_match('/^[a-f0-9]{32}$/D', $token)
            || !in_array($action, ['open', 'update'], true) || $user === '') { Auth::stop(400, 'Événement invalide.'); }
        $path = self::source($_POST['file'] ?? null);
        if (!$path) { Auth::stop(404, 'Fichier introuvable.'); }
        $binding = substr(hash('sha256', session_id() . ':' . $user . ':' . $token), 0, 40);
        $id = $action === 'open' ? gmdate('Y-m-d') . '-' . $binding : ($_POST['id'] ?? '');
        if (!is_string($id) || !preg_match('/^\d{4}-\d{2}-\d{2}-[a-f0-9]{40}$/D', $id)
            || substr($id, 11) !== $binding) { Auth::stop(400, 'Lecture invalide.'); }
        $directory = self::directory();
        $lockPath = $directory . '/journal.lock';
        if (is_link($lockPath)) { throw new RuntimeException('Verrou invalide.'); }
        $lock = @fopen($lockPath, 'c+');
        if (!$lock || !flock($lock, LOCK_EX)) { throw new RuntimeException('Verrou indisponible.'); }
        @chmod($lockPath, 0600);
        try {
            $today = gmdate('Y-m-d');
            $marker = $directory . '/maintenance';
            if (is_link($marker)) { throw new RuntimeException('Stockage invalide.'); }
            if (@file_get_contents($marker) !== $today) {
                $oldest = gmdate('Y-m-d', time() - self::RETENTION * 86400);
                foreach (glob($directory . '/????-??-??-*.json') ?: [] as $old) {
                    if (!is_link($old) && substr(basename($old), 0, 10) < $oldest) { @unlink($old); }
                }
                file_put_contents($marker, $today); @chmod($marker, 0600);
            }
            $file = $directory . '/' . $id . '.json';
            if (is_link($file)) { throw new RuntimeException('Journal invalide.'); }
            $row = is_file($file) ? json_decode(file_get_contents($file), true) : null;
            if (!$row) {
                if ($action !== 'open') { Auth::stop(404, 'Ouverture introuvable.'); }
                if (count(glob($directory . '/' . $today . '-*.json') ?: []) >= self::DAILY_LIMIT) {
                    Auth::stop(429, 'Limite quotidienne du journal atteinte.');
                }
                $href = $_POST['file'];
                $row = ['id' => $id, 'user' => $user, 'file' => rawurldecode($href),
                    'source' => hash('sha256', $path), 'kind' => self::kind($path), 'openedAt' => microtime(true),
                    'updatedAt' => time(), 'watched' => 0, 'position' => null, 'duration' => null, 'state' => 'opened'];
            }
            if ($row['user'] !== $user || $row['source'] !== hash('sha256', $path)) { Auth::stop(403, 'Lecture invalide.'); }
            if ($action === 'update' && $row['kind'] !== 'file') {
                $watched = filter_var($_POST['watched'] ?? null, FILTER_VALIDATE_FLOAT);
                $position = filter_var($_POST['position'] ?? null, FILTER_VALIDATE_FLOAT);
                $duration = filter_var($_POST['duration'] ?? null, FILTER_VALIDATE_FLOAT);
                $state = $_POST['state'] ?? '';
                $sequence = filter_var($_POST['sequence'] ?? null, FILTER_VALIDATE_INT);
                if ($watched === false || $position === false || $duration === false || $sequence === false
                    || !is_finite($watched) || !is_finite($position) || !is_finite($duration)
                    || $watched < 0 || $watched > microtime(true) - $row['openedAt'] + 5
                    || $position < 0 || $duration < 0 || ($duration > 0 && $position > $duration + 1)
                    || !in_array($state, ['playing', 'paused', 'ended', 'closed'], true)) { Auth::stop(400, 'Temps de lecture invalide.'); }
                if ($sequence > ($row['sequence'] ?? -1)) {
                    $row['watched'] = max($row['watched'], $watched);
                    $row['position'] = $position; $row['duration'] = $duration;
                    $row['state'] = $state; $row['sequence'] = $sequence; $row['updatedAt'] = time();
                }
            }
            self::write($file, $row);
        } finally { flock($lock, LOCK_UN); fclose($lock); }
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        echo json_encode(['id' => $id]); exit;
    }

    public static function clock($seconds) {
        $seconds = max(0, (int) $seconds);
        return sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
    }

    public static function pageUrl($filter, $page) {
        $filter['page'] = $page;
        return Routes::url('filelogs') . '?' . http_build_query($filter, '', '&', PHP_QUERY_RFC3986);
    }

    private static function page() {
        if (!Users::isAdmin()) { Auth::stop(403, 'Cette page est réservée aux administrateurs.'); }
        if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD'], true)) { Auth::stop(405, 'Méthode non autorisée.'); }
        $timezone = new DateTimeZone('Europe/Paris');
        $now = new DateTimeImmutable('now', $timezone);
        $filter = ['from' => $now->modify('-60 days')->format('Y-m-d'), 'to' => $now->format('Y-m-d'),
            'user' => '', 'search' => '', 'kind' => '', 'page' => 1];
        $rows = []; $total = 0; $error = '';
        try {
            foreach (['from', 'to', 'user', 'search', 'kind'] as $key) {
                if (isset($_GET[$key])) {
                    if (!is_string($_GET[$key]) || strlen($_GET[$key]) > 512) { throw new InvalidArgumentException('Filtre invalide.'); }
                    $filter[$key] = trim($_GET[$key]);
                }
            }
            $dates = [];
            foreach (['from', 'to'] as $key) {
                $date = DateTimeImmutable::createFromFormat('!Y-m-d', $filter[$key], $timezone);
                if (!$date || $date->format('Y-m-d') !== $filter[$key]) { throw new InvalidArgumentException('Date invalide.'); }
                $dates[$key] = ($key === 'to' ? $date->setTime(23, 59, 59) : $date)->getTimestamp();
            }
            $filter['page'] = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
            if (!$filter['page'] || $dates['from'] > $dates['to'] || !in_array($filter['kind'], ['', 'file', 'video', 'audio'], true)) {
                throw new InvalidArgumentException('Filtre invalide.');
            }
            $limit = $filter['page'] * 50 + 1;
            $compare = function ($a, $b) { return ($b['openedAt'] <=> $a['openedAt']) ?: strcmp($b['id'], $a['id']); };
            foreach (glob(self::directory() . '/????-??-??-*.json') ?: [] as $file) {
                if (is_link($file) || filesize($file) > 32768) { continue; }
                $row = json_decode(file_get_contents($file), true);
                if (!is_array($row) || !isset($row['openedAt'], $row['user'], $row['file'], $row['kind'])) { continue; }
                if ($row['openedAt'] < max($dates['from'], time() - self::RETENTION * 86400) || $row['openedAt'] >= $dates['to'] + 1
                    || ($filter['user'] !== '' && stripos($row['user'], $filter['user']) === false)
                    || ($filter['search'] !== '' && !preg_match('/' . preg_quote($filter['search'], '/') . '/iu', $row['file']))
                    || ($filter['kind'] !== '' && $filter['kind'] !== $row['kind'])) { continue; }
                $total++; $rows[] = $row;
                if (count($rows) > $limit * 2) { usort($rows, $compare); $rows = array_slice($rows, 0, $limit); }
            }
            usort($rows, $compare);
            $rows = array_slice($rows, ($filter['page'] - 1) * 50, 50);
        } catch (InvalidArgumentException $e) { http_response_code(400); $error = $e->getMessage(); }
        catch (RuntimeException $e) { http_response_code(503); $error = 'Les journaux des fichiers sont indisponibles.'; }
        $home = Routes::url('home');
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: private, no-store');
        header("Content-Security-Policy: default-src 'none'; style-src 'self'; script-src 'self'; img-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
        require __DIR__ . '/../pages/file-journal.php'; exit;
    }

    public static function apply() {
        if (Routes::matches('filelogs')) { self::page(); }
        if (!Routes::matches('fileevents')) { return; }
        try { self::record(); }
        catch (RuntimeException $e) { error_log('h5ai file journal: ' . $e->getMessage()); Auth::stop(503, 'Journal indisponible.'); }
    }
}
