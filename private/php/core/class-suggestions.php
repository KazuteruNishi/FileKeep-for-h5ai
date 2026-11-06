<?php

/** Incremental private search catalogue, scoped to the current account's ACL. */
class Suggestions {
    private static function fold($value) {
        // Explicit Latin folding is stable across PHP/iconv locales and OSes.
        static $map = ['À'=>'a','Á'=>'a','Â'=>'a','Ã'=>'a','Ä'=>'a','Å'=>'a','à'=>'a','á'=>'a','â'=>'a','ã'=>'a','ä'=>'a','å'=>'a',
            'Ç'=>'c','ç'=>'c','È'=>'e','É'=>'e','Ê'=>'e','Ë'=>'e','è'=>'e','é'=>'e','ê'=>'e','ë'=>'e',
            'Ì'=>'i','Í'=>'i','Î'=>'i','Ï'=>'i','ì'=>'i','í'=>'i','î'=>'i','ï'=>'i','Ñ'=>'n','ñ'=>'n',
            'Ò'=>'o','Ó'=>'o','Ô'=>'o','Õ'=>'o','Ö'=>'o','ò'=>'o','ó'=>'o','ô'=>'o','õ'=>'o','ö'=>'o',
            'Ù'=>'u','Ú'=>'u','Û'=>'u','Ü'=>'u','ù'=>'u','ú'=>'u','û'=>'u','ü'=>'u','Ý'=>'y','Ÿ'=>'y','ý'=>'y','ÿ'=>'y',
            'Œ'=>'oe','œ'=>'oe','Æ'=>'ae','æ'=>'ae','ß'=>'ss'];
        $value = preg_replace('/\p{Mn}/u', '', strtr($value, $map));
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }

    private static function index() {
        $base = str_replace('\\', '/', realpath(dirname(__DIR__, 4)));
        $directory = Users::directory() . '/search';
        if (is_link($directory) || (!is_dir($directory) && !@mkdir($directory, 0700) && !is_dir($directory))) {
            throw new RuntimeException('Recherche indisponible.');
        }
        $key = hash('sha256', ($_SESSION['access']['user'] ?? '') . ':' . Users::accountRevision($_SESSION['access']['user'] ?? ''));
        $file = $directory . '/' . $key . '.json';
        if (is_link($file) || is_link($file . '.lock')) { throw new RuntimeException('Index invalide.'); }
        $lock = @fopen($file . '.lock', 'c+');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { if ($lock) fclose($lock); return null; }
        @chmod($file . '.lock', 0600);
        try {
            $state = is_file($file) && filesize($file) <= 33554432 ? json_decode(file_get_contents($file), true) : null;
            if (!is_array($state) || ($state['version'] ?? null) !== 2 || time() - ($state['created'] ?? 0) > 300) {
                $state = ['version' => 2, 'created' => time(), 'entries' => [], 'pending' => [['path' => '', 'offset' => 0]], 'limited' => false];
            }
            if (!$state['pending']) { return $state; }
            $until = microtime(true) + .3; $visited = 0;
            while ($state['pending'] && $visited < 3000 && microtime(true) < $until) {
                $cursor = array_shift($state['pending']);
                $path = $base . '/' . $cursor['path'];
                if (!is_dir($path) || !Auth::allowedPath($path)) { continue; }
                try {
                    $iterator = new DirectoryIterator($path);
                    $iterator->seek($cursor['offset']);
                    while ($iterator->valid() && $visited < 3000 && microtime(true) < $until) {
                        $name = $iterator->getFilename(); $child = $iterator->getPathname();
                        $isDirectory = $iterator->isDir(); $isFile = $iterator->isFile(); $link = $iterator->isLink();
                        $cursor['offset'] = $iterator->key() + 1; $iterator->next(); $visited++;
                        if ($name === '' || $name[0] === '.' || $link || (!$isDirectory && !$isFile) || !Auth::allowedPath($child)) { continue; }
                        $relative = ltrim($cursor['path'] . '/' . $name, '/');
                        $state['entries'][] = [$relative, $isDirectory, self::fold($relative)];
                        if ($isDirectory) { $state['pending'][] = ['path' => $relative, 'offset' => 0]; }
                        if (count($state['entries']) >= 100000) { $state['limited'] = true; $state['pending'] = []; break 2; }
                    }
                    if ($iterator->valid()) { array_unshift($state['pending'], $cursor); }
                } catch (UnexpectedValueException $e) { /* Unreadable or removed directory. */ }
                catch (OutOfBoundsException $e) { /* Directory shrank between two batches. */ }
            }
            $temporary = $file . '.' . bin2hex(random_bytes(8)) . '.tmp';
            $json = json_encode($state, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
            $mask = umask(0077);
            try {
                if (strlen($json) > 33554432 || file_put_contents($temporary, $json) !== strlen($json) || !rename($temporary, $file)) {
                    @unlink($temporary); throw new RuntimeException('Index indisponible.');
                }
            } finally { umask($mask); }
            // Retire obsolete account revisions and expired catalogue files.
            foreach (glob($directory . '/*.json') ?: [] as $old) {
                if ($old !== $file && !is_link($old) && @filemtime($old) < time() - 86400) { @unlink($old); }
            }
            return $state;
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    public static function apply() {
        if (!Routes::matches('suggestions')) { return; }
        if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD'], true)) { Auth::stop(405, 'Méthode non autorisée.'); }
        $query = $_GET['q'] ?? '';
        if (!is_string($query) || strlen($query) > 256 || !preg_match('//u', $query)) { Auth::stop(400, 'Recherche invalide.'); }
        $query = trim($query);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: private, no-store'); header('X-Content-Type-Options: nosniff');
        if (strlen($query) < 2) { echo '{"results":[],"indexing":false}'; exit; }
        // Capture the identity before releasing the session lock for concurrent playback.
        session_write_close();
        try {
            $state = self::index();
            $tokens = preg_split('/\s+/', self::fold($query), -1, PREG_SPLIT_NO_EMPTY);
            $matches = []; $base = str_replace('\\', '/', realpath(dirname(__DIR__, 4)));
            $compare = function ($a, $b) { return ($a['score'] <=> $b['score']) ?: strnatcasecmp($a['path'], $b['path']); };
            foreach ($state['entries'] ?? [] as [$path, $folder, $folded]) {
                foreach ($tokens as $token) { if (strpos($folded, $token) === false) { continue 2; } }
                $name = basename($path); $foldedName = self::fold($name);
                $kind = $folder ? 'folder' : (preg_match('/\.(mp4|m4v|mkv|webm|mov)$/i', $name) ? 'video'
                    : (preg_match('/\.(mp3|flac|ogg|oga|opus|wav|m4a|aac)$/i', $name) ? 'audio' : 'file'));
                $href = Routes::url('home') . implode('/', array_map('rawurlencode', explode('/', $path)));
                if ($folder) { $href .= '/'; }
                elseif ($kind === 'video') { $href = substr($href, 0, strrpos($href, '/') + 1) . '?lecture=' . rawurlencode($name); }
                $matches[] = ['name' => $name, 'path' => $path, 'parent' => dirname($path) === '.' ? '' : dirname($path),
                    'kind' => $kind, 'href' => $href, 'score' => (strpos($foldedName, self::fold($query)) === 0 ? 0 : 2) + ($folder ? 0 : 1)];
                if (count($matches) > 96) { usort($matches, $compare); $matches = array_slice($matches, 0, 48); }
            }
            usort($matches, $compare);
            $authorized = [];
            foreach ($matches as $match) {
                $path = $base . '/' . $match['path'];
                // Recheck current permissions and existence before disclosing any cached match.
                if (!Auth::allowedPath($path) || ($match['kind'] === 'folder' ? !is_dir($path) : !is_file($path))) { continue; }
                $authorized[] = $match;
                if (count($authorized) === 12) break;
            }
            $matches = $authorized;
            foreach ($matches as &$match) { unset($match['score']); } unset($match);
            if ($_SERVER['REQUEST_METHOD'] !== 'HEAD') { echo json_encode(['results' => $matches, 'indexing' => $state === null || !!$state['pending'], 'limited' => $state['limited'] ?? false], JSON_INVALID_UTF8_SUBSTITUTE); }
        } catch (RuntimeException $e) { Auth::stop(503, 'Recherche momentanément indisponible.', false); }
        exit;
    }
}
