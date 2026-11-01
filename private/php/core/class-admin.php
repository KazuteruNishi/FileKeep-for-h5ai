<?php

class Admin {
    public static function apply() {
        if (!Routes::matches('admin')) { return; }
        if (!Users::isAdmin()) { Auth::stop(403, 'Cette page est réservée aux administrateurs.'); }
        if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'], true)) { Auth::stop(405, 'Méthode non autorisée.'); }
        $error = '';
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            Auth::requireCsrf($_POST['_csrf'] ?? null);
            try {
                $operation = $_POST['operation'] ?? null;
                $name = $_POST['username'] ?? null;
                if ($operation === 'delete') {
                    Users::delete($name);
                } elseif ($operation === 'reset_password') {
                    $password = Users::resetPassword($name);
                    $_SESSION['new_credentials'] = ['username' => $name, 'password' => $password, 'reset' => true];
                } elseif ($operation === 'create' || $operation === 'save') {
                    if ($operation === 'save' && ($_POST['password'] ?? '') !== '') {
                        throw new InvalidArgumentException('Utilisez le bouton de génération pour réinitialiser un mot de passe.');
                    }
                    $folders = $_POST['folders'] ?? [];
                    $extra = $_POST['extra_folders'] ?? '';
                    if (!is_array($folders) || !is_string($extra) || strlen($extra) > 65536) {
                        throw new InvalidArgumentException('Liste des dossiers invalide.');
                    }
                    $extra = preg_split('/\r\n|\r|\n/', $extra);
                    $extra = array_filter(array_map('trim', $extra), function ($value) { return $value !== ''; });
                    $password = $operation === 'create' ? Users::temporaryPassword() : '';
                    Users::save($name, $password, $_POST['role'] ?? '', array_merge($folders, $extra), $operation === 'create');
                    if ($operation === 'create') { $_SESSION['new_credentials'] = ['username' => $name, 'password' => $password]; }
                } else {
                    throw new InvalidArgumentException('Action invalide.');
                }
                // Keep this session if the administrator edited their own account.
                $_SESSION['access']['revision'] = Users::accountRevision($_SESSION['access']['user']);
                $_SESSION['admin_notice'] = $operation === 'delete' ? 'Utilisateur supprimé.' : ($operation === 'reset_password'
                    ? 'Mot de passe réinitialisé. L’utilisateur devra le changer à sa prochaine connexion.'
                    : 'Utilisateur enregistré.');
                $target = $operation === 'delete' ? '' : '?user=' . rawurlencode($name);
                header('Location: ' . Routes::url('admin') . $target, true, 303);
                exit;
            } catch (InvalidArgumentException $exception) {
                Audit::record('user.failed', 'failure', is_string($name) ? $name : null, ['action' => in_array($operation, ['create', 'save', 'delete', 'reset_password'], true) ? $operation : 'save', 'reason' => 'validation_failed']);
                http_response_code(400);
                $error = $exception->getMessage();
            } catch (RuntimeException $exception) {
                Audit::record('user.failed', 'failure', is_string($name) ? $name : null, ['reason' => 'storage_unavailable']);
                http_response_code(503);
                error_log('h5ai admin: ' . $exception->getMessage());
                $error = 'Enregistrement impossible. Vérifiez les droits du stockage ou reconnectez-vous.';
            }
        }
        $users = Users::all();
        ksort($users, SORT_NATURAL | SORT_FLAG_CASE);
        $selected = isset($_GET['user']) && is_string($_GET['user']) && isset($users[$_GET['user']]) ? $_GET['user'] : null;
        $editing = $selected === null ? ['role' => 'user', 'folders' => []] : $users[$selected];
        $folders = ['/'];
        $root = dirname(__DIR__, 4);
        foreach (scandir($root) as $name) {
            $path = $root . '/' . $name;
            if (is_dir($path) && Auth::allowedPath($path) && $name !== '.' && $name !== '..') {
                $folders[] = '/' . $name;
            }
        }
        $extraFolders = array_diff($editing['folders'], $folders);
        $notice = $_SESSION['admin_notice'] ?? '';
        unset($_SESSION['admin_notice']);
        $credentials = $_SESSION['new_credentials'] ?? null;
        unset($_SESSION['new_credentials']);
        $home = Routes::url('home');
        header('Content-Type: text/html; charset=utf-8');
        header("Content-Security-Policy: default-src 'none'; style-src 'self' 'unsafe-inline'; script-src 'self'; img-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
        require __DIR__ . '/../pages/admin.php';
        exit;
    }
}
