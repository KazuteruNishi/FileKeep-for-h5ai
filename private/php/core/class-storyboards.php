<?php

/** Permanent, content-addressed contact sheets; all delivery is source-ACL checked. */
class Storyboards {
    private static $workEpoch;
    private static $workAlias;
    private static $workerVersion;
    const VERSION = 1;
    const WIDTH = 192;
    const HEIGHT = 108;
    const COLUMNS = 5;
    const ROWS = 5;

    public static function directory() {
        return Users::directory() . '/storyboards';
    }

    private static function codeVersion() {
        return hash('sha256', hash_file('sha256', __FILE__) . hash_file('sha256', dirname(__DIR__) . '/storyboard-worker.php'));
    }

    public static function initializeWorker() {
        self::$workerVersion = self::codeVersion();
        self::mkdir(self::directory());
        self::write(self::directory() . '/worker-version.json', ['code' => self::$workerVersion,
            'concurrency' => in_array('--watch', $_SERVER['argv'] ?? [], true) ? self::concurrency() : 1]);
    }

    public static function concurrency() {
        $value = filter_var(getenv('H5AI_STORYBOARD_CONCURRENCY') ?: 10, FILTER_VALIDATE_INT);
        return max(1, min(32, $value === false ? 10 : $value));
    }

    public static function checkWorkerVersion() {
        if (self::$workerVersion !== null && self::$workerVersion !== self::codeVersion()) {
            throw new Storyboardreload('Mise à jour du générateur : redémarrage entre deux vidéos.');
        }
    }

    public static function requestScan($paths = null, $priority = 0) {
        $root = self::directory(); self::mkdir($root);
        if (is_link($root . '/scan-request.lock')) { throw new RuntimeException('Verrou invalide.'); }
        $lock = fopen($root . '/scan-request.lock', 'c+');
        if (!$lock || !flock($lock, LOCK_EX)) { throw new RuntimeException('Recherche indisponible.'); }
        try {
            $pending = self::read($root . '/scan-request.json');
            if ($pending) {
                $paths = ($paths === null || ($pending['paths'] ?? null) === null) ? null
                    : array_values(array_unique(array_merge($pending['paths'], $paths)));
            }
            self::write($root . '/scan-request.json', ['requested' => time(), 'paths' => $paths, 'priority' => $priority]);
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    public static function control() {
        return self::read(self::directory() . '/control.json') ?: ['mode' => 'running', 'epoch' => 'initial'];
    }

    public static function setControl($mode) {
        if (!in_array($mode, ['running', 'paused', 'stopped'], true)) { throw new InvalidArgumentException('Action invalide.'); }
        self::mkdir(self::directory());
        $control = self::control(); $control['mode'] = $mode;
        if ($mode === 'stopped') { $control['epoch'] = bin2hex(random_bytes(12)); }
        self::write(self::directory() . '/control.json', $control);
        if ($mode === 'stopped') {
            @unlink(self::directory() . '/queue-order.json');
            @unlink(self::directory() . '/scan-request.json');
            foreach (glob(self::directory() . '/queue/*.json') ?: [] as $queued) {
                if (!preg_match('/^[a-f0-9]{64}\.json$/D', basename($queued))) { continue; }
                $path = self::directory() . '/index/' . basename($queued);
                $job = self::read($path);
                if ($job) { $job['status'] = 'stopped'; self::write($path, $job); }
                @unlink(self::directory() . '/job-controls/' . basename($queued));
                @unlink($queued);
            }
        }
    }

    private static function checkpoint() {
        $control = self::control();
        if ($control['mode'] !== 'running' || (self::$workEpoch !== null && self::$workEpoch !== $control['epoch'])) {
            throw new Storyboardinterrupted($control['mode'] === 'paused' ? 'paused' : 'stopped');
        }
        if (self::$workAlias !== null && self::jobPaused(self::$workAlias)) { throw new Storyboardinterrupted('job-paused'); }
        if (self::$workAlias !== null && (self::read(self::directory() . '/job-controls/' . self::$workAlias . '.json')['mode'] ?? null) === 'cancelled') {
            throw new Storyboardinterrupted('stopped');
        }
    }

    private static function jobPaused($alias) {
        return (self::read(self::directory() . '/job-controls/' . $alias . '.json')['mode'] ?? null) === 'paused';
    }

    public static function controlJob($relative, $pause) {
        $source = realpath(self::selectedPath($relative)); self::checkSource($source);
        $alias = self::alias($source, self::signature($source));
        $path = self::directory() . '/index/' . $alias . '.json'; $job = self::read($path);
        if (!$job || !in_array($job['status'], ['hashing', 'generating', 'queued', 'paused', 'resuming'], true)
            || !self::read(self::directory() . '/queue/' . $alias . '.json')) {
            throw new InvalidArgumentException('Ce traitement n’est plus actif. Actualisez la liste.');
        }
        $root = self::directory() . '/job-controls'; self::mkdir($root);
        if ($pause) { self::write($root . '/' . $alias . '.json', ['mode' => 'paused']); }
        else {
            if (!self::jobPaused($alias) && !in_array($job['status'], ['paused', 'resuming'], true)) { return; }
            self::write($root . '/' . $alias . '.json', ['mode' => 'resume']);
            $job['status'] = 'resuming'; self::write($path, $job);
        }
    }

    public static function selectedPath($relative) {
        if (!is_string($relative) || strlen($relative) > 8192 || strpos($relative, "\0") !== false
            || strpos($relative, '\\') !== false) { throw new InvalidArgumentException('Chemin invalide.'); }
        $base = str_replace('\\', '/', realpath(dirname(__DIR__, 4)));
        $cursor = $base;
        foreach (explode('/', $relative) as $part) {
            if ($part === '' && $relative === '') { continue; }
            if ($part === '' || $part[0] === '.' || strpos($part, ':') !== false) { throw new InvalidArgumentException('Chemin invalide.'); }
            $cursor .= '/' . $part;
            if (is_link($cursor)) { throw new InvalidArgumentException('Lien symbolique interdit.'); }
        }
        $app = str_replace('\\', '/', realpath(dirname(__DIR__, 3)));
        $private = str_replace('\\', '/', realpath(Users::directory()) ?: Users::directory());
        if ($cursor === $app || strpos($cursor, $app . '/') === 0 || $cursor === $private || strpos($cursor, $private . '/') === 0
            || (!is_dir($cursor) && !is_file($cursor))) { throw new InvalidArgumentException('Chemin indisponible.'); }
        return $cursor;
    }

    public static function completion($source, &$memo) {
        $path = realpath($source);
        if (!$path) { return ['complete' => false, 'total' => 0, 'ready' => 0, 'unknown' => true]; }
        if (isset($memo[$path])) { return $memo[$path]; }
        $result = ['complete' => false, 'total' => 0, 'ready' => 0, 'unknown' => false];
        if (is_file($path)) {
            if (!preg_match('/\.(mp4|m4v|mkv|webm|mov)$/i', $path)) { return $memo[$path] = $result; }
            $result['total'] = 1;
            try { $job = self::read(self::directory() . '/index/' . self::alias($path, self::signature($path)) . '.json'); }
            catch (Throwable $error) { $result['unknown'] = true; return $memo[$path] = $result; }
            if ($job && preg_match('/^[a-f0-9]{64}$/D', $job['content'] ?? '')) {
                $manifest = self::read(self::directory() . '/assets/' . $job['content'] . '/manifest.json');
                $result['complete'] = $manifest && ($manifest['status'] ?? null) === 'ready'
                    && !empty($manifest['count']) && !empty($manifest['sheets']);
                $result['ready'] = $result['complete'] ? 1 : 0;
            }
        } elseif (is_dir($path)) {
            $base = str_replace('\\', '/', realpath(dirname(__DIR__, 4)));
            try {
                foreach (new DirectoryIterator($path) as $entry) {
                    if ($entry->isDot() || $entry->isLink() || $entry->getFilename()[0] === '.') { continue; }
                    $relative = substr(str_replace('\\', '/', $entry->getPathname()), strlen($base) + 1);
                    try { self::selectedPath($relative); } catch (InvalidArgumentException $error) { continue; }
                    if (!Auth::allowedPath($entry->getPathname())) { continue; }
                    $child = self::completion($entry->getPathname(), $memo);
                    $result['total'] += $child['total']; $result['ready'] += $child['ready'];
                    $result['unknown'] = $result['unknown'] || $child['unknown'];
                }
                $result['complete'] = !$result['unknown'] && $result['total'] > 0 && $result['total'] === $result['ready'];
            } catch (Throwable $error) { $result['complete'] = false; $result['unknown'] = true; }
        }
        return $memo[$path] = $result;
    }

    /** Split selected ancestors into sibling branches without enumerating every video. */
    public static function selectionWithout($paths, $excluded) {
        self::selectedPath($excluded);
        $result = []; $memo = [];
        $visit = function ($relative) use (&$visit, &$result, &$memo, $excluded) {
            if ($relative === $excluded || strpos($relative, $excluded . '/') === 0) { return; }
            $source = self::selectedPath($relative);
            if (!Auth::allowedPath($source)) { throw new InvalidArgumentException('Chemin non autorisé.'); }
            if (strpos($excluded, $relative . '/') === 0 && is_dir($source)) {
                foreach (new DirectoryIterator($source) as $entry) {
                    if ($entry->isDot() || $entry->isLink() || $entry->getFilename()[0] === '.') { continue; }
                    if (!$entry->isDir() && !preg_match('/\.(mp4|m4v|mkv|webm|mov)$/i', $entry->getFilename())) { continue; }
                    $visit($relative . '/' . $entry->getFilename());
                }
            } else {
                $completion = self::completion($source, $memo);
                if ($completion['complete'] || (is_dir($source) && !$completion['total'] && !$completion['unknown'])) { return; }
                $result[$relative] = true;
                if (count($result) > 5000) { throw new InvalidArgumentException('La sélection dépasse 5 000 branches.'); }
            }
        };
        foreach ($paths as $path) { $visit($path); }
        $paths = array_keys($result); usort($paths, 'strnatcasecmp');
        return $paths;
    }

    public static function catalog($relative = '', $search = '') {
        $folder = self::selectedPath($relative);
        if (!is_dir($folder) || !Auth::allowedPath($folder)) { throw new InvalidArgumentException('Dossier indisponible.'); }
        $base = str_replace('\\', '/', realpath(dirname(__DIR__, 4))); $entries = []; $truncated = false;
        $iterator = $search === '' ? new DirectoryIterator($folder) : new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS), function ($entry) use ($base) {
                try { return (bool) self::selectedPath(substr(str_replace('\\', '/', $entry->getPathname()), strlen($base) + 1)); }
                catch (Throwable $error) { return false; }
            }), RecursiveIteratorIterator::SELF_FIRST, RecursiveIteratorIterator::CATCH_GET_CHILD);
        foreach ($iterator as $entry) {
            $path = substr(str_replace('\\', '/', $entry->getPathname()), strlen($base) + 1);
            try { self::selectedPath($path); } catch (Throwable $error) { continue; }
            if (!Auth::allowedPath($entry->getPathname())) { continue; }
            if (!$entry->isDir() && !preg_match('/\.(mp4|m4v|mkv|webm|mov)$/i', $entry->getFilename())) { continue; }
            if ($search !== '' && !preg_match('/' . preg_quote($search, '/') . '/iu', $path)) { continue; }
            if (count($entries) >= 300) { $truncated = true; break; }
            $entries[] = ['path' => $path, 'folder' => $entry->isDir(), 'name' => $entry->getFilename(), 'parent' => dirname($path) === '.' ? 'Racine' : dirname($path)];
        }
        $memo = [];
        foreach ($entries as &$entry) { $entry += self::completion($base . '/' . $entry['path'], $memo); }
        unset($entry);
        usort($entries, function ($a, $b) { return ($b['folder'] <=> $a['folder']) ?: strnatcasecmp($a['path'], $b['path']); });
        return ['entries' => $entries, 'truncated' => $truncated];
    }

    public static function prioritizeQueued($relative, $priority) {
        if (!in_array($priority, [-100, 0, 100, 1000], true)) { throw new InvalidArgumentException('Priorité invalide.'); }
        $source = realpath(self::selectedPath($relative)); self::checkSource($source);
        $alias = self::alias($source, self::signature($source));
        $job = self::read(self::directory() . '/index/' . $alias . '.json');
        if (!$job || $job['status'] !== 'queued' || !self::read(self::directory() . '/queue/' . $alias . '.json')) {
            throw new InvalidArgumentException('Cette vidéo n’est plus en attente. Actualisez la liste.');
        }
        self::prioritize([$relative], $priority);
    }

    public static function prioritize($paths, $priority) {
        self::mkdir(self::directory());
        $lockPath = self::directory() . '/priorities.lock';
        if (is_link($lockPath)) { throw new RuntimeException('Verrou invalide.'); }
        $lock = fopen($lockPath, 'c+');
        if (!$lock || !flock($lock, LOCK_EX)) { throw new RuntimeException('Priorités indisponibles.'); }
        try {
            $values = self::read(self::directory() . '/priorities.json') ?: [];
            foreach ($paths as $relative) {
                $source = self::selectedPath($relative);
                // Directory priorities apply recursively without touching job files.
                $values[$source] = $priority;
            }
            self::write(self::directory() . '/priorities.json', $values);
            self::resetOrder();
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    private static function priority($job, $values) {
        $path = str_replace('\\', '/', $job['source'] ?? '');
        while ($path !== '' && $path !== '.' && $path !== '/') {
            if (isset($values[$path])) { return (int) $values[$path]; }
            $parent = dirname($path); if ($parent === $path) { break; } $path = $parent;
        }
        return 0;
    }

    private static function manualOrder() {
        $paths = self::read(self::directory() . '/queue-order.json')['paths'] ?? [];
        return array_flip(array_values(array_filter($paths, 'is_string')));
    }

    public static function resetOrder() {
        @unlink(self::directory() . '/queue-order.json');
    }

    public static function reorder($source, $target, $placement) {
        if (!in_array($placement, ['before', 'after'], true)) { throw new InvalidArgumentException('Position invalide.'); }
        self::selectedPath($source); self::selectedPath($target);
        $root = self::directory(); self::mkdir($root);
        if (is_link($root . '/queue-order.lock')) { throw new RuntimeException('Verrou invalide.'); }
        $lock = fopen($root . '/queue-order.lock', 'c+');
        if (!$lock || !flock($lock, LOCK_EX)) { throw new RuntimeException('Ordre indisponible.'); }
        try {
            $rows = array_values(array_filter(self::overview()['videos'], function ($row) { return $row['status'] === 'queued'; }));
            usort($rows, function ($a, $b) { return $a['queuePosition'] <=> $b['queuePosition']; });
            $paths = array_column($rows, 'path');
            if (!in_array($source, $paths, true) || !in_array($target, $paths, true)) {
                throw new InvalidArgumentException('La file a changé. Actualisez puis réessayez.');
            }
            if ($source === $target) { return; }
            $paths = array_values(array_filter($paths, function ($path) use ($source) { return $path !== $source; }));
            $position = array_search($target, $paths, true) + ($placement === 'after' ? 1 : 0);
            array_splice($paths, $position, 0, [$source]);
            $base = str_replace('\\', '/', realpath(dirname(__DIR__, 4)));
            self::write($root . '/queue-order.json', ['paths' => array_map(function ($path) use ($base) { return $base . '/' . $path; }, $paths)]);
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    private static function compareQueue($a, $b) {
        return (($b['resume'] ?? 0) <=> ($a['resume'] ?? 0))
            ?: (($a['manualRank'] ?? PHP_INT_MAX) <=> ($b['manualRank'] ?? PHP_INT_MAX))
            ?: ($b['priority'] <=> $a['priority'])
            ?: strnatcasecmp($a['path'], $b['path']) ?: strcmp($a['path'], $b['path']);
    }

    public static function scanRequested() {
        if (self::control()['mode'] !== 'running') { return; }
        $root = self::directory(); self::mkdir($root);
        $request = $root . '/scan-request.json';
        if (!self::read($request)) { return; }
        if (is_link($root . '/scan.lock')) { throw new RuntimeException('Verrou de recherche invalide.'); }
        $lock = fopen($root . '/scan.lock', 'c+');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { if ($lock) fclose($lock); return; }
        try {
            if (is_link($root . '/scan-request.lock')) { throw new RuntimeException('Verrou invalide.'); }
            $requestLock = fopen($root . '/scan-request.lock', 'c+');
            if (!$requestLock || !flock($requestLock, LOCK_EX)) { throw new RuntimeException('Recherche indisponible.'); }
            try { $scan = self::read($request); if ($scan) { @unlink($request); } }
            finally { flock($requestLock, LOCK_UN); fclose($requestLock); }
            if (!$scan) { return; }
            self::write($root . '/scan-status.json', ['status' => 'scanning', 'updated' => time()]);
            try { $ok = self::enqueueAll($scan['paths'] ?? null); }
            catch (Storyboardinterrupted $interrupted) {
                if ($interrupted->getMessage() === 'paused') { self::requestScan($scan['paths'] ?? null); }
                self::write($root . '/scan-status.json', ['status' => $interrupted->getMessage(), 'updated' => time()]);
                return;
            }
            self::write($root . '/scan-status.json', ['status' => $ok ? 'done' : 'error', 'updated' => time()]);
        } catch (Throwable $error) {
            self::write($root . '/scan-status.json', ['status' => 'error', 'updated' => time()]);
            error_log('h5ai storyboards scan: ' . $error->getMessage());
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    public static function cancelQueued($relative) {
        $source = realpath(self::selectedPath($relative)); self::checkSource($source);
        $alias = self::alias($source, self::signature($source)); $root = self::directory();
        $path = $root . '/index/' . $alias . '.json'; $job = self::read($path);
        if (!$job || $job['status'] !== 'queued' || !self::read($root . '/queue/' . $alias . '.json')) {
            throw new InvalidArgumentException('Cette vidéo n’est plus en attente. Actualisez la liste.');
        }
        self::mkdir($root . '/job-controls');
        self::write($root . '/job-controls/' . $alias . '.json', ['mode' => 'cancelled']);
        $job['status'] = 'stopped'; $job['updated'] = time(); self::write($path, $job);
        @unlink($root . '/queue/' . $alias . '.json');
    }

    public static function clearCancelledHistory($relative = null) {
        self::clearHistory('stopped', $relative);
    }

    private static function historyStatus($job) {
        if (preg_match('/^[a-f0-9]{64}$/D', $job['content'] ?? '')) {
            $manifest = self::read(self::directory() . '/assets/' . $job['content'] . '/manifest.json');
            if (($manifest['status'] ?? null) === 'ready') { return 'ready'; }
        }
        return $job['status'];
    }

    public static function clearHistory($status, $relative = null) {
        if (!in_array($status, ['stopped', 'ready', 'error'], true)) { throw new InvalidArgumentException('Catégorie invalide.'); }
        $root = self::directory(); self::mkdir($root);
        $onlyAlias = null;
        if ($relative !== null) {
            $source = realpath(self::selectedPath($relative)); self::checkSource($source);
            $onlyAlias = self::alias($source, self::signature($source));
            $job = self::read($root . '/index/' . $onlyAlias . '.json');
            if (!$job || self::historyStatus($job) !== $status) { throw new InvalidArgumentException('Cette entrée a changé de catégorie. Actualisez la liste.'); }
        }
        if (is_link($root . '/history.lock')) { throw new RuntimeException('Verrou invalide.'); }
        $lock = fopen($root . '/history.lock', 'c+');
        if (!$lock || !flock($lock, LOCK_EX)) { throw new RuntimeException('Historique indisponible.'); }
        try {
            $cleared = self::read($root . '/cleared-history.json', 16777216) ?: [];
            foreach (glob($root . '/index/*.json') ?: [] as $path) {
                if (!preg_match('/^[a-f0-9]{64}\.json$/D', basename($path))) { continue; }
                if ($onlyAlias !== null && basename($path, '.json') !== $onlyAlias) { continue; }
                $job = self::read($path);
                if ($job && self::historyStatus($job) === $status) {
                    // Keep the alias and assets available for protected previews.
                    // A fresh enqueue has a new runId and can appear here again.
                    $cleared[$status . ':' . basename($path, '.json')] = $job['runId'] ?? 'legacy';
                }
            }
            self::write($root . '/cleared-history.json', $cleared);
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    public static function overview() {
        $root = self::directory(); $rows = [];
        $clearedHistory = self::read($root . '/cleared-history.json', 16777216) ?: [];
        $priorities = self::read($root . '/priorities.json') ?: [];
        $manualOrder = self::manualOrder();
        foreach (glob($root . '/index/*.json') ?: [] as $path) {
            $job = self::read($path);
            if (!$job || empty($job['source'])) { continue; }
            $source = $job['source'];
            // Hide stale aliases after replacement/move and never expose an
            // obsolete ready result as the state of a different file.
            if (!is_file($source) || !Auth::allowedPath($source)) { continue; }
            try { if (self::signature($source) !== $job['signature']) { continue; } }
            catch (Throwable $error) { continue; }
            $manifest = null;
            if (preg_match('/^[a-f0-9]{64}$/D', $job['content'] ?? '')) {
                $manifest = self::read($root . '/assets/' . $job['content'] . '/manifest.json');
            }
            $status = $job['status']; $percent = null;
            if (in_array($status, ['queued', 'hashing', 'generating', 'paused', 'resuming'], true)) {
                $mode = self::read($root . '/job-controls/' . basename($path))['mode'] ?? null;
                if ($mode === 'paused') { $status = 'paused'; }
                elseif ($mode === 'resume') { $status = 'resuming'; }
                elseif ($mode === 'cancelled') { $status = 'stopped'; }
            }
            if ($manifest && $manifest['status'] === 'ready') { $status = 'ready'; }
            $hiddenRun = $clearedHistory[$status . ':' . basename($path, '.json')]
                ?? ($status === 'stopped' ? ($clearedHistory[basename($path, '.json')] ?? null) : null);
            if (in_array($status, ['stopped', 'ready', 'error'], true) && $hiddenRun === ($job['runId'] ?? 'legacy')) { continue; }
            if ($status === 'ready') { $percent = 100; }
            elseif (in_array($status, ['generating', 'paused', 'resuming'], true) && !empty($manifest['count'])) {
                $percent = min(99, (int) floor(100 * ($manifest['observed'] ?? count($manifest['sheets'] ?? []) * 25) / $manifest['count']));
            }
            $base = str_replace('\\', '/', dirname(__DIR__, 4));
            $rows[] = ['path' => substr(str_replace('\\', '/', $source), strlen($base) + 1),
                'status' => $status, 'percent' => $percent, 'priority' => self::priority($job, $priorities),
                'manualRank' => $manualOrder[str_replace('\\', '/', $source)] ?? PHP_INT_MAX,
                'historyAt' => $job['historyAt'] ?? (@filemtime($path) ?: ($job['updated'] ?? 0)),
                'queuedAt' => @filemtime($root . '/queue/' . basename($path)) ?: ($job['updated'] ?? 0)];
        }
        usort($rows, function ($a, $b) { return strnatcasecmp($a['path'], $b['path']); });
        $waiting = array_values(array_filter($rows, function ($row) { return $row['status'] === 'queued'; }));
        usort($waiting, [self::class, 'compareQueue']);
        $positions = [];
        foreach ($waiting as $position => $row) { $positions[$row['path']] = $position + 1; }
        foreach ($rows as &$row) { $row['queuePosition'] = $positions[$row['path']] ?? null; }
        unset($row);
        $storageBytes = 0;
        if (is_dir($root) && !is_link($root)) {
            try {
                $files = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
                    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                    function ($entry) { return !$entry->isLink(); }
                ));
                foreach ($files as $file) {
                    // Temporary diagnostic files may disappear during polling.
                    if ($file->isFile()) {
                        clearstatcache(true, $file->getPathname());
                        $size = @filesize($file->getPathname());
                        if ($size !== false) { $storageBytes += $size; }
                    }
                }
            } catch (Throwable $error) { $storageBytes = null; }
        }
        $workerVersion = self::read($root . '/worker-version.json');
        return ['videos' => $rows, 'workerConcurrency' => $workerVersion['concurrency'] ?? 1,
            'workerNeedsRestart' => $workerVersion ? ($workerVersion['code'] !== self::codeVersion()) : null,
            'manualOrder' => (bool) $manualOrder, 'control' => self::control()['mode'], 'storageBytes' => $storageBytes, 'scan' => self::read($root . '/scan-request.json') ? 'queued'
            : (self::read($root . '/scan-status.json')['status'] ?? null)];
    }

    /** Local CLI administration only; the worker still handles one video at a time. */
    public static function enqueueAll($selected = null) {
        if (PHP_SAPI !== 'cli') { throw new RuntimeException('Commande CLI uniquement.'); }
        $base = dirname(__DIR__, 4);
        $chosenPaths = $selected === null ? null : array_map([self::class, 'selectedPath'], $selected);
        $app = str_replace('\\', '/', realpath(dirname(__DIR__, 3)));
        $private = str_replace('\\', '/', realpath(Users::directory()) ?: Users::directory());
        $files = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
            function ($entry) use ($app, $private, $chosenPaths) {
                $path = str_replace('\\', '/', $entry->getPathname());
                if ($entry->isLink() || $entry->getFilename()[0] === '.' || $path === $app || $path === $private) { return false; }
                if ($chosenPaths === null) { return true; }
                foreach ($chosenPaths as $chosen) {
                    if ($path === $chosen || strpos($path, $chosen . '/') === 0 || strpos($chosen, $path . '/') === 0) { return true; }
                }
                return false;
            }
        ), RecursiveIteratorIterator::LEAVES_ONLY, RecursiveIteratorIterator::CATCH_GET_CHILD);
        $candidates = [];
        foreach ($files as $entry) {
            $mode = self::control()['mode'];
            if ($mode !== 'running') { throw new Storyboardinterrupted($mode); }
            if (!$entry->isFile() || !preg_match('/\.(mp4|m4v|mkv|webm|mov)$/i', $entry->getFilename())) { continue; }
            $candidates[] = $entry->getPathname();
        }
        usort($candidates, function ($a, $b) { return strnatcasecmp($a, $b) ?: strcmp($a, $b); });
        $added = 0; $existing = 0; $failed = 0;
        foreach ($candidates as $candidate) {
            $mode = self::control()['mode'];
            if ($mode !== 'running') { throw new Storyboardinterrupted($mode); }
            try {
                $path = realpath($candidate);
                self::checkSource($path);
                if ($chosenPaths !== null) {
                    $match = false;
                    foreach ($chosenPaths as $chosen) {
                        $normalized = str_replace('\\', '/', $path);
                        if ($normalized === $chosen || strpos($normalized, $chosen . '/') === 0) { $match = true; break; }
                    }
                    if (!$match) { continue; }
                }
                if (self::enqueue($path)) { $added++; echo 'Ajout : ' . $path . PHP_EOL; }
                else { $existing++; }
            } catch (Throwable $error) {
                $failed++; fwrite(STDERR, $candidate . ' : ' . $error->getMessage() . PHP_EOL);
            }
        }
        echo "Ajoutées : $added ; déjà connues : $existing ; erreurs : $failed" . PHP_EOL;
        return $failed === 0;
    }

    private static function enqueue($source) {
        $root = self::directory();
        foreach (['', '/index', '/queue', '/assets'] as $suffix) { self::mkdir($root . $suffix); }
        $signature = self::signature($source);
        $alias = self::alias($source, $signature);
        $path = $root . '/index/' . $alias . '.json';
        if (is_link($path . '.lock')) { throw new RuntimeException('Verrou de vidéo invalide.'); }
        $lock = fopen($path . '.lock', 'c+');
        if (!$lock || !flock($lock, LOCK_EX)) { throw new RuntimeException('Verrou de vidéo indisponible.'); }
        @chmod($path . '.lock', 0600);
        try {
            $job = self::read($path);
            if ($job && $job['status'] !== 'stopped' && !($job['status'] === 'error' && time() - ($job['updated'] ?? 0) > 60)) { return false; }
            self::write($path, ['version' => self::VERSION, 'source' => $source, 'runId' => bin2hex(random_bytes(12)),
                'signature' => $signature, 'status' => 'queued', 'updated' => time()]);
            @unlink($root . '/job-controls/' . $alias . '.json');
            self::write($root . '/queue/' . $alias . '.json', ['alias' => $alias]);
            return true;
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    private static function mkdir($path) {
        if (is_link($path) || (!is_dir($path) && !@mkdir($path, 0700, true) && !is_dir($path))) {
            throw new RuntimeException('Stockage des planches indisponible.');
        }
    }

    private static function read($path, $maxBytes = 1048576) {
        if (is_link($path) || !is_file($path) || filesize($path) > $maxBytes) { return null; }
        $data = json_decode(file_get_contents($path), true);
        return is_array($data) ? $data : null;
    }

    private static function write($path, $data) {
        if (is_link($path)) { throw new RuntimeException('Stockage des planches invalide.'); }
        $index = str_replace('\\', '/', self::directory()) . '/index/';
        if (strpos(str_replace('\\', '/', $path), $index) === 0 && preg_match('/^[a-f0-9]{64}\.json$/D', basename($path))) {
            if (in_array($data['status'] ?? null, ['ready', 'stopped', 'error'], true)) {
                $previous = self::read($path);
                $data['historyAt'] = ($previous['status'] ?? null) === $data['status']
                    ? ($previous['historyAt'] ?? (@filemtime($path) ?: microtime(true))) : microtime(true);
            } else { unset($data['historyAt']); }
        }
        $temporary = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        $mask = umask(0077);
        try {
            $written = file_put_contents($temporary, $json, LOCK_EX) === strlen($json);
            $renamed = false;
            if ($written) {
                // On Windows a concurrent reader can briefly prevent an atomic
                // replacement. Keep the old JSON intact and retry the rename.
                for ($attempt = 0; $attempt < (PHP_OS_FAMILY === 'Windows' ? 10 : 1); $attempt++) {
                    if (@rename($temporary, $path)) { $renamed = true; break; }
                    usleep(20000);
                }
            }
            if (!$written || !$renamed) {
                @unlink($temporary); throw new RuntimeException('Enregistrement des planches impossible.');
            }
        } finally { umask($mask); }
    }

    private static function signature($path) {
        clearstatcache(true, $path);
        $stat = @stat($path);
        if (!$stat) { throw new RuntimeException('Vidéo indisponible.'); }
        return array_intersect_key($stat, array_flip(['dev', 'ino', 'size', 'mtime', 'ctime']));
    }

    private static function alias($path, $signature) {
        return hash('sha256', 'storyboards-v1:' . $path . ':' . json_encode($signature));
    }

    private static function source($href) {
        if (!is_string($href) || strlen($href) > 8192 || preg_match('/[\x00-\x20\\\\?#]/', $href) || substr($href, 0, 2) === '//') { return null; }
        $root = Routes::url('home');
        if (strpos($href, $root) !== 0) { return null; }
        $path = dirname(__DIR__, 4) . '/' . rawurldecode(substr($href, strlen($root)));
        if (!preg_match('/\.(?:mp4|m4v|webm|mkv|mov)$/i', $path) || !is_file($path) || !Auth::allowedPath($path)) { return null; }
        return realpath($path) ?: null;
    }

    public static function apply() {
        if (!Routes::matches('storyboards')) { return; }
        ignore_user_abort(true);
        $method = $_SERVER['REQUEST_METHOD'];
        if (!in_array($method, ['GET', 'HEAD', 'POST'], true)) { Auth::stop(405, 'Méthode non autorisée.'); }
        if ($method === 'POST') { Auth::requireCsrf($_POST['_csrf'] ?? null); }
        $href = $method === 'POST' ? ($_POST['file'] ?? null) : ($_GET['file'] ?? null);
        $source = self::source($href);
        if ($source === null) { Auth::stop(404, 'Vidéo introuvable.'); }
        try {
            $root = self::directory();
            self::mkdir($root); self::mkdir($root . '/index'); self::mkdir($root . '/queue'); self::mkdir($root . '/assets');
            $signature = self::signature($source);
            $alias = self::alias($source, $signature);
            $jobPath = $root . '/index/' . $alias . '.json';
            $job = self::read($jobPath);
            if ($method === 'POST') {
                if (is_link($jobPath . '.lock')) { throw new RuntimeException('Verrou de vidéo invalide.'); }
                $jobLock = fopen($jobPath . '.lock', 'c+');
                if (!$jobLock || !flock($jobLock, LOCK_EX)) { throw new RuntimeException('Verrou de vidéo indisponible.'); }
                @chmod($jobPath . '.lock', 0600);
                try {
                    $job = self::read($jobPath);
                    if (!$job || ($job['status'] === 'error' && time() - ($job['updated'] ?? 0) > 60)) {
                        $job = ['version' => self::VERSION, 'source' => $source, 'signature' => $signature, 'runId' => bin2hex(random_bytes(12)),
                            'status' => 'queued', 'updated' => time()];
                        self::write($jobPath, $job);
                        @unlink($root . '/job-controls/' . $alias . '.json');
                        self::write($root . '/queue/' . $alias . '.json', ['alias' => $alias]);
                    }
                } finally { flock($jobLock, LOCK_UN); fclose($jobLock); }
            }
            if (!$job) { Auth::stop(404, 'Planches non préparées.'); }
            $content = $job['content'] ?? null;
            $manifest = is_string($content) && preg_match('/^[a-f0-9]{64}$/D', $content)
                ? self::read($root . '/assets/' . $content . '/manifest.json') : null;
            if (isset($_GET['sheet'])) {
                $sheet = filter_var($_GET['sheet'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 100000]]);
                if ($sheet === false || !$manifest || !in_array($sheet, $manifest['sheets'], true)
                    || !hash_equals($content, (string) ($_GET['v'] ?? ''))) { Auth::stop(404, 'Planche introuvable.'); }
                $image = $root . '/assets/' . $content . '/' . sprintf('sheet-%05d.jpg', $sheet);
                if (is_link($image) || !is_file($image)) { Auth::stop(404, 'Planche introuvable.'); }
                $etag = '"' . $content . '-' . $sheet . '-' . filesize($image) . '"';
                header('Cache-Control: private, no-cache'); header('Vary: Cookie'); header('ETag: ' . $etag);
                header('X-Content-Type-Options: nosniff'); header("Content-Security-Policy: default-src 'none'; sandbox");
                session_write_close();
                if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) { http_response_code(304); exit; }
                header('Content-Type: image/jpeg'); header('Content-Length: ' . filesize($image));
                if ($method !== 'HEAD') { readfile($image); } exit;
            }
            session_write_close();
            if (in_array($job['status'], ['queued', 'hashing', 'generating'], true)) { self::launch(); }
            $result = ['status' => $job['status'] === 'error' ? 'error' : ($manifest['status'] ?? $job['status']), 'width' => self::WIDTH, 'height' => self::HEIGHT,
                'columns' => self::COLUMNS, 'rows' => self::ROWS, 'sheets' => $manifest['sheets'] ?? []];
            if ($manifest) {
                $result += array_intersect_key($manifest, array_flip(['duration', 'interval', 'count']));
                $result['id'] = $content;
                $result['url'] = Routes::url('storyboards') . '?file=' . rawurlencode($href) . '&v=' . $content . '&sheet={sheet}';
            }
            if ($result['status'] === 'error') { $result['message'] = 'La génération des planches a échoué. Consulte les journaux du serveur.'; }
            header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: private, no-store');
            header('X-Content-Type-Options: nosniff');
            if ($method !== 'HEAD') { echo json_encode($result, JSON_INVALID_UTF8_SUBSTITUTE); } exit;
        } catch (Throwable $error) {
            error_log('h5ai storyboards: ' . $error->getMessage());
            Auth::stop(503, 'Préparation des planches indisponible.', false);
        }
    }

    private static function launch() {
        if (self::control()['mode'] !== 'running') { return; }
        // A separate container polls the shared queue; Apache only enqueues.
        if (getenv('H5AI_STORYBOARD_WORKER') === 'external') { return; }
        if (!function_exists('proc_open')) { throw new RuntimeException('proc_open est requis pour FFmpeg.'); }
        $lockPath = self::directory() . '/worker.lock';
        if (is_link($lockPath)) { throw new RuntimeException('Verrou des planches invalide.'); }
        $lock = fopen($lockPath, 'c+');
        if ($lock) {
            if (!flock($lock, LOCK_EX | LOCK_NB)) { fclose($lock); return; }
            flock($lock, LOCK_UN); fclose($lock);
        }
        $php = getenv('H5AI_PHP_CLI') ?: (in_array(PHP_SAPI, ['cli', 'cli-server'], true)
            ? PHP_BINARY : PHP_BINDIR . '/php' . (PHP_OS_FAMILY === 'Windows' ? '.exe' : ''));
        $worker = dirname(__DIR__) . '/storyboard-worker.php';
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        if (PHP_OS_FAMILY === 'Windows') {
            $line = 'start "" /B ' . escapeshellarg($php) . ' ' . escapeshellarg($worker) . ' >NUL 2>&1';
            $command = $line;
        } else {
            $line = escapeshellarg($php) . ' ' . escapeshellarg($worker) . ' </dev/null >/dev/null 2>&1 &';
            $command = ['/bin/sh', '-c', $line];
        }
        $process = proc_open($command, [0 => ['file', $null, 'r'], 1 => ['file', $null, 'a'], 2 => ['file', $null, 'a']], $pipes);
        if (!is_resource($process) || proc_close($process) !== 0) { throw new RuntimeException('Démarrage du générateur impossible.'); }
    }

    private static function jobLockHeld($alias) {
        $path = self::directory() . '/queue/' . $alias . '.run-lock';
        if (!is_file($path)) { return false; }
        if (is_link($path)) { return true; }
        $lock = @fopen($path, 'c+');
        if (!$lock) { return true; }
        $free = flock($lock, LOCK_EX | LOCK_NB);
        if ($free) { flock($lock, LOCK_UN); }
        fclose($lock); return !$free;
    }

    /** Reconcile persisted state after a container/process disappeared. */
    public static function recoverInterruptedJobs() {
        if (PHP_SAPI !== 'cli') { throw new RuntimeException('Commande CLI uniquement.'); }
        $root = self::directory(); self::mkdir($root); self::mkdir($root . '/queue');
        foreach (glob($root . '/index/*.json') ?: [] as $path) {
            $alias = basename($path, '.json');
            if (!preg_match('/^[a-f0-9]{64}$/D', $alias)) { continue; }
            $job = self::read($path); if (!$job) { continue; }
            $queued = $root . '/queue/' . $alias . '.json';
            $marker = self::read($queued);
            $savedMode = self::read($root . '/job-controls/' . $alias . '.json')['mode'] ?? null;
            if ($job['status'] === 'queued' && $marker && $savedMode === null) { continue; }
            if (in_array($job['status'], ['ready', 'error', 'stopped'], true) && !$marker) { continue; }
            $lockPath = $root . '/queue/' . $alias . '.run-lock';
            if (is_link($lockPath)) { continue; }
            $lock = fopen($lockPath, 'c+');
            if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { if ($lock) fclose($lock); continue; }
            try {
                $job = self::read($path); if (!$job) { continue; }
                if (in_array($job['status'], ['ready', 'error', 'stopped'], true)) { @unlink($queued); continue; }
                if (!in_array($job['status'], ['queued', 'hashing', 'generating', 'paused', 'resuming'], true)) { continue; }
                $controlPath = $root . '/job-controls/' . $alias . '.json';
                $mode = self::read($controlPath)['mode'] ?? null;
                if ($mode === 'cancelled' || (self::control()['mode'] === 'stopped' && $job['status'] !== 'queued')) {
                    $job['status'] = 'stopped'; self::write($path, $job); @unlink($queued); @unlink($controlPath); continue;
                }
                try {
                    self::checkSource($job['source']);
                    if (self::signature($job['source']) !== $job['signature']) { throw new RuntimeException('La source a changé pendant l’arrêt.'); }
                } catch (Throwable $error) {
                    $job['status'] = 'error'; $job['updated'] = time(); self::write($path, $job); @unlink($queued);
                    error_log('h5ai storyboard recovery: ' . $error->getMessage()); continue;
                }
                $manifest = preg_match('/^[a-f0-9]{64}$/D', $job['content'] ?? '')
                    ? self::read($root . '/assets/' . $job['content'] . '/manifest.json') : null;
                $expected = !empty($manifest['count']) ? (int) ceil($manifest['count'] / (self::COLUMNS * self::ROWS)) : 0;
                if (($manifest['status'] ?? null) === 'ready' && $expected > 0
                    && count(self::sheets($root . '/assets/' . $job['content'], $expected)) === $expected) {
                    $job['status'] = 'ready'; $job['updated'] = time(); self::write($path, $job);
                    @unlink($queued); @unlink($controlPath); continue;
                }
                self::mkdir($root . '/job-controls');
                if ($mode === 'paused' || ($job['status'] === 'paused' && $mode !== 'resume')) {
                    $job['status'] = 'paused'; self::write($controlPath, ['mode' => 'paused']);
                } elseif ($job['status'] !== 'queued' || $mode === 'resume') {
                    $job['status'] = 'resuming'; self::write($controlPath, ['mode' => 'resume']);
                } else { $job['status'] = 'queued'; }
                $job['recoveredAt'] = time(); self::write($path, $job);
                if (!self::read($queued)) { self::write($queued, ['alias' => $alias]); }
            } finally { flock($lock, LOCK_UN); fclose($lock); }
        }
    }

    private static function pendingQueue($exclude = []) {
        $root = self::directory();
        $queue = array_values(array_filter(glob($root . '/queue/*.json') ?: [], function ($path) use ($exclude) {
            return !isset($exclude[basename($path, '.json')]) && !is_link($path) && preg_match('/^[a-f0-9]{64}\.json$/D', basename($path))
                && !self::jobLockHeld(basename($path, '.json'))
                && !in_array(self::read(self::directory() . '/job-controls/' . basename($path))['mode'] ?? null, ['paused', 'cancelled'], true);
        }));
        $values = self::read($root . '/priorities.json') ?: []; $manual = self::manualOrder();
        $keys = [];
        foreach ($queue as $path) {
            $job = self::read($root . '/index/' . basename($path)) ?: [];
            $source = str_replace('\\', '/', $job['source'] ?? $path);
            $keys[$path] = ['priority' => self::priority($job, $values), 'path' => $source,
                'resume' => (self::read($root . '/job-controls/' . basename($path))['mode'] ?? null) === 'resume' ? 1 : 0,
                'manualRank' => $manual[$source] ?? PHP_INT_MAX];
        }
        usort($queue, function ($a, $b) use ($keys) { return self::compareQueue($keys[$a], $keys[$b]); });
        return $queue;
    }

    private static function reservedJobs($running) {
        $root = self::directory(); $reserved = [];
        foreach (glob($root . '/job-controls/*.json') ?: [] as $path) {
            $alias = basename($path, '.json');
            if (!preg_match('/^[a-f0-9]{64}$/D', $alias) || isset($running[$alias])) { continue; }
            if (in_array(self::read($path)['mode'] ?? null, ['paused', 'resume'], true)
                && self::read($root . '/queue/' . $alias . '.json')) { $reserved[$alias] = true; }
        }
        return $reserved;
    }

    /** A supervisor owns the global lock; children own independent job locks. */
    public static function workParallel() {
        $root = self::directory(); self::mkdir($root); self::mkdir($root . '/queue');
        if (is_link($root . '/worker.lock') || is_link($root . '/worker.log')) { throw new RuntimeException('Stockage du générateur invalide.'); }
        $lock = fopen($root . '/worker.lock', 'c+');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { if ($lock) fclose($lock); return; }
        $running = []; $reload = false;
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        try {
            self::recoverInterruptedJobs();
            while (true) {
                foreach ($running as $alias => $child) {
                    $state = proc_get_status($child);
                    if ($state['running']) { continue; }
                    $closed = proc_close($child); unset($running[$alias]);
                    if (($state['exitcode'] >= 0 ? $state['exitcode'] : $closed) !== 0) {
                        $path = $root . '/index/' . $alias . '.json'; $job = self::read($path);
                        if ($job && in_array($job['status'], ['hashing', 'generating'], true)) {
                            $attempt = ($job['interruptionRetries'] ?? 0) + 1;
                            $job['interruptionRetries'] = $attempt;
                            $controlPath = $root . '/job-controls/' . $alias . '.json';
                            $mode = self::read($controlPath)['mode'] ?? null;
                            if (self::control()['mode'] === 'stopped' || $mode === 'cancelled') {
                                $job['status'] = 'stopped'; @unlink($root . '/queue/' . $alias . '.json');
                            } elseif ($mode === 'paused') { $job['status'] = 'paused'; }
                            elseif (self::control()['mode'] === 'paused') { $job['status'] = 'queued'; }
                            elseif ($attempt <= 3) {
                                self::mkdir($root . '/job-controls'); self::write($controlPath, ['mode' => 'resume']);
                                $job['status'] = 'resuming';
                                self::write($root . '/queue/' . $alias . '.json', ['alias' => $alias]);
                            } else {
                                $job['status'] = 'error'; @unlink($root . '/queue/' . $alias . '.json');
                                error_log('h5ai storyboard: échec après trois relances du sous-processus pour ' . $alias);
                            }
                            $job['updated'] = time(); self::write($path, $job);
                        }
                    }
                }
                try { self::checkWorkerVersion(); } catch (Storyboardreload $error) { $reload = true; }
                if ($reload) {
                    if (!$running) { throw new Storyboardreload('Rechargement du générateur après les vidéos en cours.'); }
                } elseif (self::control()['mode'] === 'running') {
                    self::scanRequested();
                    if (self::control()['mode'] === 'running' && count($running) < self::concurrency()) {
                        $reserved = self::reservedJobs($running);
                        foreach (self::pendingQueue($running) as $queued) {
                            $alias = basename($queued, '.json');
                            if (isset($running[$alias])) { continue; }
                            if (count($running) >= self::concurrency()) { break; }
                            if (!isset($reserved[$alias]) && count($running) + count($reserved) >= self::concurrency()) { continue; }
                            $control = self::control(); if ($control['mode'] !== 'running') { break; }
                            $path = $root . '/index/' . $alias . '.json'; $job = self::read($path);
                            if (!$job || $job['status'] === 'stopped') { @unlink($queued); continue; }
                            $job['status'] = 'hashing'; $job['updated'] = time(); self::write($path, $job);
                            $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/storyboard-worker.php', '--job', $alias, $control['epoch']],
                                [0 => ['file', $null, 'r'], 1 => ['file', $null, 'a'], 2 => ['file', $root . '/worker.log', 'a']],
                                $pipes, null, null, ['bypass_shell' => true]);
                            if (is_resource($process)) { $running[$alias] = $process; unset($reserved[$alias]); }
                            else { $job['status'] = 'error'; self::write($path, $job); @unlink($queued); }
                        }
                    }
                }
                usleep(200000);
            }
        } finally {
            foreach ($running as $process) { proc_close($process); }
            flock($lock, LOCK_UN); fclose($lock);
        }
    }

    public static function workJob($alias, $epoch) {
        if (!preg_match('/^[a-f0-9]{64}$/D', $alias) || !is_string($epoch) || strlen($epoch) > 128) { throw new InvalidArgumentException('Travail invalide.'); }
        $root = self::directory(); $path = $root . '/index/' . $alias . '.json';
        $lockPath = $root . '/queue/' . $alias . '.run-lock';
        if (is_link($lockPath)) { throw new RuntimeException('Verrou invalide.'); }
        $lock = fopen($lockPath, 'c+');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { if ($lock) fclose($lock); return; }
        self::$workEpoch = $epoch; self::$workAlias = $alias; $job = self::read($path); $keep = false;
        if ($job) { $job['workerPid'] = getmypid(); }
        try {
            if ((self::read($root . '/job-controls/' . $alias . '.json')['mode'] ?? null) === 'resume') {
                @unlink($root . '/job-controls/' . $alias . '.json');
            }
            self::checkpoint(); if ($job) { self::generate($root, $path, $job); }
        } catch (Storyboardinterrupted $interrupted) {
            $keep = in_array($interrupted->getMessage(), ['paused', 'job-paused'], true);
            if ($job) {
                $resume = (self::read($root . '/job-controls/' . $alias . '.json')['mode'] ?? null) === 'resume';
                $job['status'] = $interrupted->getMessage() === 'job-paused' ? ($resume ? 'resuming' : 'paused') : ($keep ? 'queued' : 'stopped');
                $job['updated'] = time(); self::write($path, $job);
            }
        } catch (Throwable $error) {
            error_log('h5ai storyboard job: ' . $error->getMessage());
            if ($job) { $job['status'] = 'error'; $job['updated'] = time(); self::write($path, $job); }
        } finally {
            if (!$keep) { @unlink($root . '/queue/' . $alias . '.json'); }
            self::$workEpoch = null; self::$workAlias = null; flock($lock, LOCK_UN); fclose($lock);
        }
    }

    /** Integrated fallback processes the queue serially. */
    public static function work() {
        $control = self::control();
        if ($control['mode'] !== 'running') { return; }
        self::$workEpoch = $control['epoch'];
        $root = self::directory(); self::mkdir($root); self::mkdir($root . '/queue');
        if (is_link($root . '/worker.log')) { throw new RuntimeException('Journal des planches invalide.'); }
        ini_set('log_errors', '1'); ini_set('error_log', $root . '/worker.log');
        $lockPath = $root . '/worker.lock';
        if (is_link($lockPath)) { throw new RuntimeException('Verrou des planches invalide.'); }
        $lock = fopen($lockPath, 'c+');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { if ($lock) fclose($lock); return; }
        @chmod($lockPath, 0600);
        try {
            self::recoverInterruptedJobs();
            while (true) {
                self::checkWorkerVersion();
                // Include newly selected videos before choosing the next job.
                if (PHP_SAPI === 'cli') { self::scanRequested(); }
                $queue = self::pendingQueue();
                if (!$queue) { break; }
                if (self::control()['mode'] !== 'running') { return; }
                // Re-read priorities before each video, rather than retaining
                // a stale batch order while an administrator edits the queue.
                foreach (array_slice($queue, 0, 1) as $queued) {
                    $alias = basename($queued, '.json');
                    if (!preg_match('/^[a-f0-9]{64}$/D', $alias) || is_link($queued)) { continue; }
                    $path = $root . '/index/' . $alias . '.json';
                    $job = self::read($path);
                    self::$workAlias = $alias;
                    if ((self::read($root . '/job-controls/' . $alias . '.json')['mode'] ?? null) === 'resume') {
                        @unlink($root . '/job-controls/' . $alias . '.json');
                    }
                    try { self::checkpoint(); if ($job) { self::generate($root, $path, $job); } }
                    catch (Storyboardinterrupted $interrupted) {
                        if ($job) {
                            $job['status'] = $interrupted->getMessage() === 'job-paused' ? 'paused' : ($interrupted->getMessage() === 'paused' ? 'queued' : 'stopped');
                            $job['updated'] = time(); self::write($path, $job);
                        }
                        if ($interrupted->getMessage() === 'job-paused') { continue; }
                        if ($interrupted->getMessage() !== 'paused') { @unlink($queued); }
                        return;
                    }
                    catch (Throwable $error) {
                        error_log('h5ai storyboards worker: ' . $error->getMessage());
                        @chmod($root . '/worker.log', 0600);
                        if ($job) { $job['status'] = 'error'; $job['updated'] = time(); self::write($path, $job); }
                    }
                    @unlink($queued);
                }
            }
        } finally { self::$workEpoch = null; self::$workAlias = null; flock($lock, LOCK_UN); fclose($lock); }
        // A request can enqueue just as the worker releases its lock. Hand off
        // that last item even when its initiating browser has already closed.
        foreach (glob($root . '/queue/*.json') ?: [] as $pending) {
            if (!is_link($pending) && preg_match('/^[a-f0-9]{64}\.json$/D', basename($pending))) { self::launch(); break; }
        }
    }

    private static function checkSource($source) {
        $base = str_replace('\\', '/', realpath(dirname(__DIR__, 4)));
        $path = str_replace('\\', '/', $source);
        if (strpos($path, $base . '/') !== 0) { throw new RuntimeException('Source des planches hors du partage.'); }
        $cursor = $base;
        foreach (explode('/', substr($path, strlen($base) + 1)) as $part) {
            if ($part === '' || $part[0] === '.' || strpos($part, ':') !== false) { throw new RuntimeException('Source des planches invalide.'); }
            $cursor .= '/' . $part;
            if (is_link($cursor)) { throw new RuntimeException('Lien symbolique interdit pour les planches.'); }
        }
        $app = str_replace('\\', '/', realpath(dirname(__DIR__, 3)));
        $private = str_replace('\\', '/', realpath(Users::directory()));
        if (strpos($path, $app . '/') === 0 || $path === $private || strpos($path, $private . '/') === 0
            || !is_file($source) || !preg_match('/\.(mp4|m4v|mkv|webm|mov)$/i', $source)) {
            throw new RuntimeException('Source des planches invalide.');
        }
    }

    private static function generate($root, $jobPath, &$job) {
        $source = $job['source']; self::checkSource($source);
        if (self::signature($source) !== $job['signature']) { throw new RuntimeException('La vidéo a changé avant la génération.'); }
        $content = $job['content'] ?? null;
        // An interrupted job already identified this exact source signature.
        // Keep its identity instead of rereading the whole video on each restart.
        if (!is_string($content) || !preg_match('/^[a-f0-9]{64}$/D', $content)) {
        $job['status'] = 'hashing'; $job['updated'] = time(); self::write($jobPath, $job);
        // Hash the entire content in the background, never on the playback request.
        $hash = hash_init('sha256'); $fp = fopen($source, 'rb');
        if (!$fp) { throw new RuntimeException('Vidéo illisible.'); }
        try {
            $bytes = 0;
            while (!feof($fp)) {
                self::checkpoint(); $chunk = fread($fp, 4194304);
                if ($chunk === false) { throw new RuntimeException('Lecture vidéo impossible.'); }
                $bytes += strlen($chunk); hash_update($hash, $chunk);
            }
            $stat = fstat($fp);
        }
        finally { fclose($fp); }
        if ($bytes !== $stat['size'] || $stat['size'] !== $job['signature']['size'] || $stat['mtime'] !== $job['signature']['mtime']) {
            throw new RuntimeException('La vidéo a changé pendant son identification.');
        }
        $content = hash_final($hash); $job['content'] = $content;
        }
        $job['status'] = 'generating';
        $job['updated'] = time(); self::write($jobPath, $job);
        $directory = $root . '/assets/' . $content; self::mkdir($directory);
        // Copies of the same content share assets: only one child writes them.
        if (is_link($directory . '/generation.lock')) { throw new RuntimeException('Verrou de génération invalide.'); }
        $assetLock = fopen($directory . '/generation.lock', 'c+');
        if (!$assetLock) { throw new RuntimeException('Verrou de génération indisponible.'); }
        try {
        while (!flock($assetLock, LOCK_EX | LOCK_NB)) { self::checkpoint(); usleep(100000); }
        self::checkpoint();
        $manifestPath = $directory . '/manifest.json'; $manifest = self::read($manifestPath);
        if ($manifest && $manifest['status'] === 'ready') {
            $expectedReady = (int) ceil($manifest['count'] / (self::COLUMNS * self::ROWS));
            if ($expectedReady > 0 && count(self::sheets($directory, $expectedReady)) === $expectedReady) {
                $job['status'] = 'ready'; unset($job['interruptionRetries']); self::write($jobPath, $job); return;
            }
            $manifest['status'] = 'generating'; self::write($manifestPath, $manifest);
        }
        if (!$manifest) {
            $probe = self::process([getenv('H5AI_FFPROBE') ?: 'ffprobe', '-v', 'error', '-protocol_whitelist', 'file',
                '-format_whitelist', 'matroska,webm,mov,mp4,m4a,3gp,3g2,mj2', '-show_entries', 'format=duration:stream=duration,width,height',
                '-select_streams', 'V:0', '-of', 'json', '-i', $source], null, 30);
            $metadata = json_decode($probe, true);
            $duration = (float) ($metadata['streams'][0]['duration'] ?? 0);
            if ($duration <= 0) { $duration = (float) ($metadata['format']['duration'] ?? 0); }
            if (!is_finite($duration) || $duration <= 0 || $duration > 31536000 || empty($metadata['streams'][0]['width'])) { throw new RuntimeException('Durée vidéo indisponible.'); }
            $interval = max(2, (int) ceil($duration / 1800));
            $manifest = ['version' => self::VERSION, 'status' => 'generating', 'duration' => $duration, 'interval' => $interval,
                'count' => (int) ceil($duration / $interval), 'sheets' => []];
            self::write($manifestPath, $manifest);
        }
        $expected = (int) ceil($manifest['count'] / (self::COLUMNS * self::ROWS));
        $sheets = self::sheets($directory, $expected); $first = 0;
        while (in_array($first, $sheets, true)) { $first++; }
        if ($first < $expected) {
            $interval = (int) $manifest['interval']; $start = $first * self::COLUMNS * self::ROWS * $interval;
            $filter = 'fps=1/' . $interval . ':start_time=0:round=up,scale=' . self::WIDTH . ':' . self::HEIGHT .
                ':force_original_aspect_ratio=decrease:flags=lanczos,pad=' . self::WIDTH . ':' . self::HEIGHT .
                ':(ow-iw)/2:(oh-ih)/2:color=black,setsar=1,showinfo=checksum=0,tile=' . self::COLUMNS . 'x' . self::ROWS;
            $sampleCount = 0;
            $command = [getenv('H5AI_FFMPEG') ?: 'ffmpeg', '-nostdin', '-hide_banner', '-nostats', '-loglevel', 'info', '-threads', '1',
                '-protocol_whitelist', 'file', '-format_whitelist', 'matroska,webm,mov,mp4,m4a,3gp,3g2,mj2'];
            if ($start > 0) { $command = array_merge($command, ['-ss', (string) $start]); }
            $command = array_merge($command, ['-i', $source, '-map', '0:V:0', '-an', '-sn', '-dn', '-vf', $filter,
                '-filter_threads', '1', '-threads', '1', '-fps_mode', 'vfr', '-q:v', '3', '-start_number', (string) $first,
                '-atomic_writing', '1', '-y', $directory . '/sheet-%05d.jpg']);
            if (PHP_OS_FAMILY !== 'Windows' && is_executable('/usr/bin/nice')) { $command = array_merge(['/usr/bin/nice', '-n', '10'], $command); }
            self::process($command, function () use ($directory, $expected, $manifestPath, $first, &$manifest, &$sampleCount) {
                $sheets = self::sheets($directory, $expected);
                $observed = $first * self::COLUMNS * self::ROWS + $sampleCount;
                if ($sheets !== $manifest['sheets'] || $observed > ($manifest['observed'] ?? 0)) {
                    $manifest['sheets'] = $sheets; $manifest['observed'] = max($observed, $manifest['observed'] ?? 0);
                    self::write($manifestPath, $manifest);
                }
            }, 0, function ($log) use (&$sampleCount) {
                if (preg_match_all('/\bn:\s*(\d+)/', $log, $matches)) { $sampleCount = max($sampleCount, max(array_map('intval', $matches[1])) + 1); }
            });
            // Container duration can include audio beyond the last video
            // frame. Count actual thumbnails rather than serving blank cells.
            $count = $first * self::COLUMNS * self::ROWS + $sampleCount;
            if (!$sampleCount && $first) { $count = min($count, $manifest['observed'] ?? $count); }
            if ($count <= 0) { throw new RuntimeException('La vidéo ne contient aucune image exploitable.'); }
            $manifest['count'] = $count; $expected = (int) ceil($count / (self::COLUMNS * self::ROWS));
        }
        $manifest['sheets'] = self::sheets($directory, $expected);
        if (count($manifest['sheets']) < $expected) { throw new RuntimeException('FFmpeg a produit des planches incomplètes.'); }
        $manifest['status'] = 'ready'; self::write($manifestPath, $manifest);
        unset($job['interruptionRetries']);
        $job['status'] = 'ready'; $job['updated'] = time(); self::write($jobPath, $job);
        } finally { flock($assetLock, LOCK_UN); fclose($assetLock); }
    }

    private static function sheets($directory, $expected) {
        $result = [];
        for ($index = 0; $index < $expected; $index++) {
            $path = $directory . '/' . sprintf('sheet-%05d.jpg', $index);
            if (!is_link($path) && is_file($path) && filesize($path) > 0) { $result[] = $index; }
        }
        return $result;
    }

    private static function process($command, $tick = null, $timeout = 0, $frames = null) {
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        // File-backed diagnostics cannot deadlock when a platform does not
        // support nonblocking process pipes (notably Windows).
        $out = tempnam(self::directory() . '/queue', 'output-');
        $err = tempnam(self::directory() . '/queue', 'error-');
        if ($out === false || $err === false) { throw new RuntimeException('Stockage temporaire du générateur indisponible.'); }
        $process = proc_open($command, [0 => ['file', $null, 'r'], 1 => ['file', $out, 'w'], 2 => ['file', $err, 'w']], $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($process)) { @unlink($out); @unlink($err); throw new RuntimeException('FFmpeg/FFprobe indisponible.'); }
        $diagnostics = function () use ($err) {
            clearstatcache(true, $err); $size = filesize($err);
            return $size ? file_get_contents($err, false, null, max(0, $size - 16384), 16384) : '';
        };
        $output = ''; $error = ''; $started = microtime(true); $exit = -1;
        try {
            while (true) {
                try { self::checkpoint(); }
                catch (Storyboardinterrupted $interrupted) {
                    proc_terminate($process);
                    $deadline = microtime(true) + 2;
                    while (proc_get_status($process)['running'] && microtime(true) < $deadline) { usleep(50000); }
                    if (proc_get_status($process)['running']) { proc_terminate($process, 9); }
                    throw $interrupted;
                }
                $error = $diagnostics();
                if ($frames) { $frames($error); }
                $state = proc_get_status($process);
                if ($tick) { $tick(); }
                if (!$state['running']) { $exit = $state['exitcode']; break; }
                if ($timeout && microtime(true) - $started > $timeout) { proc_terminate($process); throw new RuntimeException('FFprobe trop long.'); }
                usleep(250000);
            }
            $output = file_get_contents($out); $error = $diagnostics();
            if ($frames) { $frames($error); }
        } finally {
            $closed = proc_close($process); @unlink($out); @unlink($err);
        }
        if (($exit >= 0 ? $exit : $closed) !== 0) { throw new RuntimeException('Échec FFmpeg/FFprobe : ' . $error); }
        return $output;
    }
}

class Storyboardinterrupted extends RuntimeException {}
class Storyboardreload extends RuntimeException {}
