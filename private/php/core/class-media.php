<?php

/** Private, permission-checked subtitle and font delivery. */
class Media {
    public static function apply() {
        if (!Routes::matches('subtitles')) { return; }
        $method = $_SERVER['REQUEST_METHOD'];
        if (!in_array($method, ['GET', 'HEAD', 'POST'], true)) { Auth::stop(405, 'Méthode non autorisée.'); }
        if ($method === 'POST') { Auth::requireCsrf($_POST['_csrf'] ?? null); }
        $href = $method === 'POST' ? ($_POST['file'] ?? null) : ($_GET['file'] ?? null);
        $path = self::source($href);
        if ($path === null) { Auth::stop(404, 'Vidéo introuvable.'); }
        $key = self::key($path);
        $directory = Users::directory() . '/media';
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            Auth::stop(503, 'Stockage des sous-titres indisponible.');
        }
        if (is_link($directory)) { Auth::stop(503, 'Stockage des sous-titres invalide.'); }
        $cache = $directory . '/' . $key . '.json';
        if (is_link($cache)) { Auth::stop(503, 'Cache de sous-titres invalide.'); }
        $data = is_file($cache) ? json_decode(file_get_contents($cache), true) : null;
        if (!is_array($data) || ($data['version'] ?? null) !== 2) {
            if ($method !== 'POST') { Auth::stop(404, 'Sous-titres non préparés.'); }
            Auth::limitMediaExtraction();
            $lockPath = $directory . '/' . $key . '.lock';
            if (is_link($lockPath)) { Auth::stop(503, 'Verrou invalide.'); }
            $lock = @fopen($lockPath, 'c+');
            if (!$lock) { Auth::stop(503, 'Cache de sous-titres indisponible.'); }
            @chmod($lockPath, 0600);
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                fclose($lock); header('Retry-After: 5');
                Auth::stop(409, 'Analyse déjà en cours. Réessayez dans quelques instants.');
            }
            // Release the session so playback and other tabs can keep working.
            session_write_close();
            try {
                self::clean($directory);
                set_time_limit(35);
                $data = (new Matroska())->extract($path);
                clearstatcache(true, $path);
                if (self::key($path) !== $key) { throw new RuntimeException('La vidéo a changé pendant sa lecture. Réessayez.'); }
                $data['version'] = 2;
                $json = json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
                $temporary = $cache . '.' . bin2hex(random_bytes(8)) . '.tmp';
                $oldMask = umask(0077);
                try {
                    if (file_put_contents($temporary, $json, LOCK_EX) !== strlen($json) || !rename($temporary, $cache)) {
                        @unlink($temporary); throw new RuntimeException('Impossible de conserver les sous-titres.');
                    }
                } finally { umask($oldMask); }
            } catch (RuntimeException $error) {
                error_log('h5ai subtitles: ' . $error->getMessage());
                Auth::stop(422, $error->getMessage(), false);
            } finally { flock($lock, LOCK_UN); fclose($lock); }
        }
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; sandbox");
        if (isset($_GET['track'])) {
            $id = filter_var($_GET['track'], FILTER_VALIDATE_INT);
            foreach ($data['tracks'] as $track) {
                if ($id !== false && $track['id'] === $id) {
                    header('Content-Type: text/plain; charset=utf-8');
                    if ($method !== 'HEAD') { echo $track['ass']; } exit;
                }
            }
            Auth::stop(404, 'Piste introuvable.');
        }
        if (isset($_GET['font'])) {
            $id = filter_var($_GET['font'], FILTER_VALIDATE_INT);
            if ($id === false || !isset($data['fonts'][$id])) { Auth::stop(404, 'Police introuvable.'); }
            header('Content-Type: application/octet-stream');
            if ($method !== 'HEAD') { echo base64_decode($data['fonts'][$id], true); } exit;
        }
        $result = ['tracks' => [], 'fonts' => [], 'chapters' => $data['chapters'] ?? [], 'warnings' => $data['warnings']];
        $base = Routes::url('subtitles') . '?file=' . rawurlencode($href);
        foreach ($data['tracks'] as $track) {
            $result['tracks'][] = ['id' => $track['id'], 'label' => $track['label'], 'language' => $track['language'],
                'url' => $base . '&track=' . $track['id']];
        }
        foreach ($data['fonts'] as $id => $_) { $result['fonts'][] = $base . '&font=' . $id; }
        header('Content-Type: application/json; charset=utf-8');
        if ($method !== 'HEAD') { echo json_encode($result, JSON_INVALID_UTF8_SUBSTITUTE); } exit;
    }

    private static function source($href) {
        if (!is_string($href) || strlen($href) > 8192 || preg_match('/[\x00-\x20\\\\?#]/', $href)
            || substr($href, 0, 2) === '//') { return null; }
        $root = Routes::url('home');
        if (strpos($href, $root) !== 0) { return null; }
        $path = dirname(__DIR__, 4) . '/' . rawurldecode(substr($href, strlen($root)));
        return preg_match('/\.mkv$/i', $path) && is_file($path) && Auth::allowedPath($path) ? (realpath($path) ?: null) : null;
    }

    private static function key($path) {
        $stat = @stat($path);
        if (!$stat) { throw new RuntimeException('Vidéo indisponible.'); }
        return hash('sha256', 'ass-v2:' . $path . ':' . $stat['size'] . ':' . $stat['mtime'] . ':' . $stat['ctime'] . ':' . $stat['ino']);
    }

    private static function clean($directory) {
        $files = glob($directory . '/*.json') ?: [];
        $total = 0;
        foreach ($files as $file) {
            if (is_link($file)) { continue; }
            if (filemtime($file) < time() - 7 * 86400) { @unlink($file); }
            else { $total += filesize($file); }
        }
        if ($total > 536870912) { throw new RuntimeException('Le cache de sous-titres est plein.'); }
    }
}
