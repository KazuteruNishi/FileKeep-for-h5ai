<?php

class Account {
    public static function apply() {
        if (!Routes::matches('account') && !Routes::matches('photo')) { return; }
        if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD', 'POST'], true)) { Auth::stop(405, 'Méthode non autorisée.'); }
        $name = $_SESSION['access']['user'];
        $account = Users::get($name);
        $mustChange = Users::mustChangePassword();
        if (Routes::matches('photo')) {
            if ($_SERVER['REQUEST_METHOD'] === 'POST') { Auth::stop(405, 'Méthode non autorisée.'); }
            Photo::send($account['avatar'] ?? null);
        }
        $error = '';
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            Auth::requireCsrf($_POST['_csrf'] ?? null);
            Auth::limitAccountChanges();
            $photo = null;
            try {
                $newPassword = $_POST['new_password'] ?? '';
                if (!is_string($newPassword) || $newPassword !== ($_POST['confirm_password'] ?? '')) {
                    throw new InvalidArgumentException('La confirmation du nouveau mot de passe ne correspond pas.');
                }
                $newName = $_POST['username'] ?? null;
                Users::validateName($newName);
                if ($newPassword !== '') { Users::validatePassword($newPassword); }
                $photo = Photo::upload($_FILES['avatar'] ?? null);
                $removePhoto = isset($_POST['remove_avatar']);
                if ($photo !== null && $removePhoto) { throw new InvalidArgumentException('Choisissez entre remplacer la photo et la supprimer.'); }
                Users::updateSelf($newName, $_POST['current_password'] ?? '', $newPassword, $removePhoto ? false : $photo);
                if ($removePhoto || $photo !== null) { Photo::remove($account['avatar'] ?? null); }
                $_SESSION['access']['user'] = $newName;
                $_SESSION['access']['revision'] = Users::accountRevision($newName);
                $_SESSION['csrf'] = bin2hex(random_bytes(32));
                session_regenerate_id(true);
                $_SESSION['account_notice'] = 'Votre compte a été mis à jour.';
                if (!empty($_SESSION['remember_after_password_change'])) {
                    unset($_SESSION['remember_after_password_change']);
                    try { Remember::issue($newName); } catch (RuntimeException $exception) {
                        error_log('h5ai remember after initial password: ' . $exception->getMessage());
                        $_SESSION['account_notice'] .= ' La connexion persistante n’a pas pu être activée.';
                    }
                }
                header('Location: ' . Routes::url($mustChange ? 'home' : 'account'), true, 303);
                exit;
            } catch (InvalidArgumentException $exception) {
                Audit::record('user.failed', 'failure', $name, ['action' => 'profile', 'reason' => 'validation_failed']);
                Photo::remove($photo);
                http_response_code(400);
                $error = $exception->getMessage();
            } catch (RuntimeException $exception) {
                Audit::record('user.failed', 'failure', $name, ['action' => 'profile', 'reason' => 'storage_unavailable']);
                Photo::remove($photo);
                error_log('h5ai account: ' . $exception->getMessage());
                http_response_code(503);
                $error = 'Enregistrement impossible. Reconnectez-vous ou contactez un administrateur.';
            }
        }
        $notice = $_SESSION['account_notice'] ?? '';
        unset($_SESSION['account_notice']);
        $home = Routes::url('home');
        header('Content-Type: text/html; charset=utf-8');
        header("Content-Security-Policy: default-src 'none'; style-src 'self' 'unsafe-inline'; script-src 'self'; img-src 'self' blob:; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
        require __DIR__ . '/../pages/account.php';
        exit;
    }
}
