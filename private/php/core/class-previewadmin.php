<?php

class Previewadmin {
    public static function apply() {
        if (!Routes::matches('previews')) { return; }
        if (!Users::isAdmin()) { Auth::stop(403, 'Cette page est réservée aux administrateurs.'); }
        if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD', 'POST'], true)) { Auth::stop(405, 'Méthode non autorisée.'); }
        header('Cache-Control: no-store');
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            Auth::requireCsrf($_POST['_csrf'] ?? null);
            try {
                $action = $_POST['action'] ?? 'scan';
                if (in_array($action, ['pause', 'resume', 'stop'], true)) {
                    Storyboards::setControl(['pause' => 'paused', 'resume' => 'running', 'stop' => 'stopped'][$action]);
                } elseif ($action === 'clear-cancelled') { Storyboards::clearCancelledHistory();
                } elseif (in_array($action, ['clear-history', 'clear-history-entry'], true)) {
                    $status = $_POST['category'] ?? null;
                    if (!in_array($status, ['ready', 'error', 'stopped'], true)) { throw new InvalidArgumentException('Catégorie invalide.'); }
                    $source = null;
                    if ($action === 'clear-history-entry') {
                        $source = $_POST['source'] ?? null;
                        if (!Auth::allowedPath(Storyboards::selectedPath($source))) { throw new InvalidArgumentException('Chemin non autorisé.'); }
                    }
                    Storyboards::clearHistory($status, $source);
                } elseif (in_array($action, ['cancel-job', 'clear-cancelled-entry'], true)) {
                    $source = $_POST['source'] ?? null;
                    if (!Auth::allowedPath(Storyboards::selectedPath($source))) { throw new InvalidArgumentException('Chemin non autorisé.'); }
                    if ($action === 'cancel-job') { Storyboards::cancelQueued($source); }
                    else { Storyboards::clearCancelledHistory($source); }
                } elseif (in_array($action, ['pause-job', 'resume-job'], true)) {
                    $source = $_POST['source'] ?? null;
                    if (!Auth::allowedPath(Storyboards::selectedPath($source))) { throw new InvalidArgumentException('Chemin non autorisé.'); }
                    Storyboards::controlJob($source, $action === 'pause-job');
                } elseif ($action === 'queue-priority') {
                    $source = $_POST['source'] ?? null;
                    $priority = filter_var($_POST['priority'] ?? null, FILTER_VALIDATE_INT);
                    if (!Auth::allowedPath(Storyboards::selectedPath($source))) { throw new InvalidArgumentException('Chemin non autorisé.'); }
                    Storyboards::prioritizeQueued($source, $priority);
                } elseif ($action === 'reorder') {
                    $source = $_POST['source'] ?? null; $target = $_POST['target'] ?? null;
                    if (!Auth::allowedPath(Storyboards::selectedPath($source)) || !Auth::allowedPath(Storyboards::selectedPath($target))) {
                        throw new InvalidArgumentException('Chemin non autorisé.');
                    }
                    Storyboards::reorder($source, $target, $_POST['placement'] ?? 'before');
                } elseif ($action === 'reset-order') { Storyboards::resetOrder();
                } elseif (in_array($action, ['select', 'priority', 'selection-exclude'], true)) {
                    if (isset($_POST['paths_json']) && (!is_string($_POST['paths_json']) || strlen($_POST['paths_json']) > 524288)) {
                        throw new InvalidArgumentException('Sélection trop volumineuse.');
                    }
                    $paths = isset($_POST['paths_json']) && is_string($_POST['paths_json'])
                        ? json_decode($_POST['paths_json'], true) : ($_POST['paths'] ?? []);
                    if (!is_array($paths) || !$paths || count($paths) > 5000) { throw new InvalidArgumentException('Sélectionnez entre 1 et 5 000 fichiers ou dossiers.'); }
                    $priority = filter_var($_POST['priority'] ?? 0, FILTER_VALIDATE_INT);
                    if (!in_array($priority, [-100, 0, 100, 1000], true)) { throw new InvalidArgumentException('Priorité invalide.'); }
                    foreach ($paths as $path) {
                        if (!Auth::allowedPath(Storyboards::selectedPath($path))) { throw new InvalidArgumentException('Chemin non autorisé.'); }
                    }
                    if ($action === 'selection-exclude') {
                        $excluded = $_POST['excluded'] ?? null;
                        if (!Auth::allowedPath(Storyboards::selectedPath($excluded))) { throw new InvalidArgumentException('Chemin non autorisé.'); }
                        $paths = Storyboards::selectionWithout($paths, $excluded);
                        header('Content-Type: application/json; charset=utf-8');
                        echo json_encode(['paths' => $paths], JSON_INVALID_UTF8_SUBSTITUTE); exit;
                    }
                    $memo = [];
                    $paths = array_values(array_filter($paths, function ($path) use (&$memo) {
                        return !Storyboards::completion(Storyboards::selectedPath($path), $memo)['complete'];
                    }));
                    if ($paths) {
                        Storyboards::prioritize($paths, $priority);
                        if ($action === 'select') { Storyboards::requestScan($paths, $priority); }
                    }
                } elseif ($action === 'scan') { Storyboards::requestScan(); }
                else { throw new InvalidArgumentException('Action inconnue.'); }
            }
            catch (InvalidArgumentException $error) { Auth::stop(400, $error->getMessage()); }
            catch (Throwable $error) { Auth::stop(503, 'La mise en file est indisponible.'); }
            if (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false) {
                header('Content-Type: application/json; charset=utf-8'); echo '{"ok":true}'; exit;
            }
            header('Location: ' . Routes::url('previews'), true, 303); exit;
        }
        if (isset($_GET['status'])) {
            session_write_close();
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(Storyboards::overview(), JSON_INVALID_UTF8_SUBSTITUTE); exit;
        }
        if (isset($_GET['catalog'])) {
            $path = $_GET['path'] ?? ''; $search = $_GET['search'] ?? '';
            if (!is_string($path) || !is_string($search) || strlen($search) > 256) { Auth::stop(400, 'Recherche invalide.'); }
            session_write_close();
            try { $data = Storyboards::catalog($path, $search); }
            catch (InvalidArgumentException $error) { Auth::stop(400, $error->getMessage()); }
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE); exit;
        }
        $home = Routes::url('home');
        header('Content-Type: text/html; charset=utf-8');
        header("Content-Security-Policy: default-src 'none'; style-src 'self'; script-src 'self'; connect-src 'self'; img-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
        require __DIR__ . '/../pages/previews.php'; exit;
    }
}
