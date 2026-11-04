<?php

/** Authenticated, per-account playback positions in private storage. */
class Playbackprogress {
    public static function apply() {
        if (!Routes::matches('progress')) { return; }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { Auth::stop(405, 'Méthode non autorisée.'); }
        Auth::requireCsrf($_POST['_csrf'] ?? null);
        $user = $_SESSION['access']['user'] ?? null;
        if (!is_string($user) || $user === '') { Auth::stop(403, 'Connexion requise.'); }
        $path = Videoviews::source($_POST['file'] ?? null);
        if ($path === null) { Auth::stop(404, 'Vidéo introuvable.'); }
        $action = $_POST['action'] ?? '';
        if (!in_array($action, ['load', 'save'], true)) { Auth::stop(400, 'Action invalide.'); }
        $position = filter_var($_POST['position'] ?? null, FILTER_VALIDATE_FLOAT);
        $duration = filter_var($_POST['duration'] ?? null, FILTER_VALIDATE_FLOAT);
        $sentAt = filter_var($_POST['sentAt'] ?? null, FILTER_VALIDATE_FLOAT);
        if ($action === 'save' && ($position === false || $duration === false || $sentAt === false
            || !is_finite($position) || !is_finite($duration) || !is_finite($sentAt)
            || $position < 0 || $duration <= 0 || $position > $duration + 1
            || $sentAt < 0 || $sentAt > microtime(true) * 1000 + 300000)) {
            Auth::stop(400, 'Progression invalide.');
        }
        $base = Users::directory() . '/playback-progress';
        $directory = $base . '/' . hash('sha256', $user);
        foreach ([$base, $directory] as $folder) {
            if (is_link($folder) || (!is_dir($folder) && !@mkdir($folder, 0700) && !is_dir($folder))) {
                Auth::stop(503, 'Progression indisponible.');
            }
        }
        $file = $directory . '/' . hash('sha256', $path) . '.json';
        if (is_link($file) || is_link($file . '.lock')) { Auth::stop(503, 'Progression indisponible.'); }
        $lock = @fopen($file . '.lock', 'c+');
        if (!$lock || !flock($lock, LOCK_EX)) { Auth::stop(503, 'Progression indisponible.'); }
        @chmod($file . '.lock', 0600);
        try {
            $data = is_file($file) ? json_decode(file_get_contents($file), true) : null;
            // A replacement at the same path starts a new viewing progress.
            $stat = stat($path);
            $signature = hash('sha256', $stat['size'] . ':' . $stat['mtime']);
            if (!is_array($data) || ($data['signature'] ?? '') !== $signature) { $data = null; }
            if ($action === 'save' && $sentAt >= ($data['sentAt'] ?? 0)) {
                $finished = ($_POST['finished'] ?? '') === '1';
                $data = ['position' => min($position, $duration), 'duration' => $duration,
                    'finished' => $finished, 'updatedAt' => time(), 'sentAt' => $sentAt, 'signature' => $signature];
                $temporary = $file . '.' . bin2hex(random_bytes(8)) . '.tmp';
                $json = json_encode($data, JSON_THROW_ON_ERROR);
                $mask = umask(0077);
                try {
                    if (file_put_contents($temporary, $json) !== strlen($json) || !rename($temporary, $file)) {
                        @unlink($temporary); Auth::stop(503, 'Progression indisponible.');
                    }
                } finally { umask($mask); }
            }
        } finally { flock($lock, LOCK_UN); fclose($lock); }
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        echo json_encode(['progress' => $data ? array_intersect_key($data, array_flip(['position', 'duration', 'finished', 'updatedAt'])) : null]);
        exit;
    }
}
