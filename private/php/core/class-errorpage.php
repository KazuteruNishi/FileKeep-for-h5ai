<?php

/** Browser error screen; API clients keep the existing JSON contract. */
class ErrorPage {
    public static function wantsHtml() {
        if (strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest') { return false; }
        if (strpos(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== false) { return false; }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'
            && ($_SERVER['HTTP_SEC_FETCH_DEST'] ?? '') !== 'document'
            && !isset($_POST['h5ai_auth'])) { return false; }
        $accept = strtolower($_SERVER['HTTP_ACCEPT'] ?? '');
        return strpos($accept, 'text/html') !== false && strpos($accept, 'application/json') === false;
    }

    public static function render($status, $message) {
        $titles = [400 => 'Demande incorrecte', 401 => 'Connexion requise', 403 => 'Accès refusé',
            404 => 'Ce fichier est introuvable', 405 => 'Méthode non autorisée',
            416 => 'Lecture impossible', 429 => 'Un peu de patience…',
            500 => 'Une erreur est survenue', 503 => 'Bientôt de retour !'];
        $messages = [403 => 'Votre compte ne dispose pas des autorisations nécessaires pour accéder à cette ressource.',
            404 => 'Le fichier ou le dossier demandé a peut-être été déplacé, supprimé ou renommé.',
            429 => 'Trop de tentatives pour ce compte. Réessayez dans quelques minutes.',
            500 => 'Impossible de terminer votre demande pour le moment. Vous pouvez réessayer dans un instant.',
            503 => 'Le service est temporairement indisponible. Merci de patienter quelques instants.'];
        $title = $titles[$status] ?? 'Impossible de terminer la demande';
        // Operational details stay in server logs, never on the public error page.
        $description = $messages[$status] ?? $message;
        $public = rtrim(str_replace('\\', '/', dirname(Auth::endpoint())), '/');
        $home = Routes::url('home');
        $lastFolder = $_SESSION['access']['last_folder'] ?? null;
        if (!in_array($status, [401, 429], true) && $lastFolder !== null
            && Gateway::folderPath($lastFolder) !== null) {
            $home = $lastFolder;
        }
        $retry = $status >= 500 || in_array($status, [405, 416], true);
        $label = in_array($status, [401, 429], true) ? 'Retour à la connexion' : 'Retour aux fichiers';
        header('Content-Type: text/html; charset=utf-8');
        header("Content-Security-Policy: default-src 'none'; style-src 'self'; img-src 'self'; script-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') { return; }
        require __DIR__ . '/../pages/error.php';
    }
}
