<?php

class Gateway {
    public static function apply() {
        $method = $_SERVER['REQUEST_METHOD'];
        if (!in_array($method, ['GET', 'HEAD', 'POST'], true)) {
            header('Allow: GET, HEAD, POST');
            Auth::stop(405, 'Méthode non autorisée.');
        }
        $uri = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
        $rootHref = rtrim(str_replace('\\', '/', dirname(dirname(dirname($_SERVER['SCRIPT_NAME'])))), '/');
        if ($rootHref !== '' && strpos($uri, $rootHref . '/') !== 0) {
            Auth::stop(404, 'Fichier introuvable.');
        }
        $relative = substr($uri, strlen($rootHref));
        if ($relative === '/_h5ai/public/index.php') { return; }
        $path = dirname(__DIR__, 4) . $relative;
        if (!Auth::allowedPath($path, true)) {
            Auth::stop(404, 'Fichier introuvable.');
        }
        if (is_dir($path)) { return; }
        if ($method === 'POST') {
            Auth::stop(405, 'Méthode non autorisée pour un fichier.');
        }
        self::send($path);
    }

    private static function send($path) {
        // Never execute shared HTML or SVG in the authenticated origin.
        // PHP sources are rejected by the shared path policy.
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $types = [
            'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
            'gif' => 'image/gif', 'webp' => 'image/webp', 'avif' => 'image/avif',
            'mp4' => 'video/mp4', 'webm' => 'video/webm', 'm4v' => 'video/mp4',
            'mp3' => 'audio/mpeg', 'ogg' => 'audio/ogg', 'wav' => 'audio/wav',
            'flac' => 'audio/flac', 'pdf' => 'application/pdf',
            'txt' => 'text/plain; charset=utf-8', 'md' => 'text/plain; charset=utf-8',
            'json' => 'text/plain; charset=utf-8', 'csv' => 'text/plain; charset=utf-8'
        ];
        $type = $types[$ext] ?? 'application/octet-stream';
        $disposition = isset($types[$ext]) ? 'inline' : 'attachment';
        header('Content-Type: ' . $type);
        header("Content-Security-Policy: sandbox; default-src 'none'");
        header('Content-Disposition: ' . $disposition . '; filename="download"; filename*=UTF-8\'\'' . rawurlencode(basename($path)));
        $fp = @fopen($path, 'rb');
        if (!$fp) { Auth::stop(404, 'Fichier introuvable.'); }
        $size = fstat($fp)['size'];
        $start = 0;
        $end = $size - 1;
        header('Accept-Ranges: bytes');
        // Single ranges support video seeking and resumable downloads.
        if (isset($_SERVER['HTTP_RANGE'])) {
            if (!preg_match('/^bytes=(\d*)-(\d*)$/D', $_SERVER['HTTP_RANGE'], $match) || ($match[1] === '' && $match[2] === '')) {
                header('Content-Range: bytes */' . $size);
                Auth::stop(416, 'Plage invalide.');
            }
            if ($match[1] === '') {
                if ((int) $match[2] <= 0) {
                    header('Content-Range: bytes */' . $size);
                    Auth::stop(416, 'Plage invalide.');
                }
                $start = max(0, $size - (int) $match[2]);
            } else {
                $start = (int) $match[1];
            }
            if ($match[1] !== '' && $match[2] !== '') {
                $end = min($end, (int) $match[2]);
            }
            if ($start > $end || $start >= $size) {
                header('Content-Range: bytes */' . $size);
                Auth::stop(416, 'Plage invalide.');
            }
            http_response_code(206);
            header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
        }
        $length = max(0, $end - $start + 1);
        header('Content-Length: ' . $length);
        session_write_close();
        if ($_SERVER['REQUEST_METHOD'] === 'HEAD') { fclose($fp); exit; }
        set_time_limit(0);
        fseek($fp, $start);
        while ($length > 0 && !feof($fp) && !connection_aborted()) {
            $chunk = fread($fp, min(1048576, $length));
            if ($chunk === false || $chunk === '') { break; }
            echo $chunk;
            $length -= strlen($chunk);
        }
        fclose($fp);
        exit;
    }
}
