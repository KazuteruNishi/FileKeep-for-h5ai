<?php

/** Global counters kept outside the public share. */
class Videoviews {
    public static function source($href) {
        if (!is_string($href) || strlen($href) > 8192 || substr($href, 0, 2) === '//'
            || preg_match('/[\x00-\x20\\\\?#]/', $href)) { return null; }
        $root = Routes::url('home');
        if (strpos($href, $root) !== 0) { return null; }
        $path = dirname(__DIR__, 4) . '/' . rawurldecode(substr($href, strlen($root)));
        if (!preg_match('/\.(mp4|m4v|mkv|webm|mov|avi|flv|mpg|mpeg)$/i', $path)
            || !is_file($path) || !Auth::allowedPath($path)) { return null; }
        return realpath($path) ?: null;
    }

    public static function apply() {
        if (!Routes::matches('views')) { return; }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { Auth::stop(405, 'Méthode non autorisée.'); }
        Auth::requireCsrf($_POST['_csrf'] ?? null);
        $increment = ($_POST['action'] ?? '') === 'view';
        $files = $increment ? [$_POST['file'] ?? null] : ($_POST['files'] ?? []);
        if (!is_array($files) || count($files) > 200) { Auth::stop(400, 'Liste invalide.'); }
        $token = $_POST['token'] ?? '';
        if ($increment && (!is_string($token) || !preg_match('/^[a-f0-9]{32}$/D', $token))) {
            Auth::stop(400, 'Lecture invalide.');
        }
        $directory = Users::directory() . '/video-views';
        if (is_link($directory) || (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory))) {
            Auth::stop(503, 'Compteur indisponible.');
        }
        $result = [];
        foreach ($files as $href) {
            $path = self::source($href);
            if ($path === null) { continue; }
            $key = hash('sha256', $path);
            $file = $directory . '/' . $key . '.json';
            if (is_link($file)) { Auth::stop(503, 'Compteur indisponible.'); }
            $handle = @fopen($file, 'c+');
            if (!$handle || !flock($handle, LOCK_EX)) { Auth::stop(503, 'Compteur indisponible.'); }
            @chmod($file, 0600);
            try {
                $data = json_decode(stream_get_contents($handle), true);
                $count = max(0, (int) ($data['views'] ?? 0));
                $seen = $_SESSION['video_views'][$key] ?? [];
                if ($increment && !in_array($token, $seen, true)) {
                    $count++;
                    $json = json_encode(['views' => $count]);
                    rewind($handle);
                    if (fwrite($handle, $json) !== strlen($json) || !ftruncate($handle, strlen($json)) || !fflush($handle)) {
                        Auth::stop(503, 'Compteur indisponible.');
                    }
                    $seen[] = $token;
                    $_SESSION['video_views'][$key] = array_slice($seen, -20);
                    if (count($_SESSION['video_views']) > 200) { array_shift($_SESSION['video_views']); }
                }
                $result[$href] = $count;
            } finally { flock($handle, LOCK_UN); fclose($handle); }
        }
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        echo json_encode(['views' => $result], JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }
}
