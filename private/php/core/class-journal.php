<?php

class Journal {
    private static function date($value, $end) {
        if (!is_string($value)) { throw new InvalidArgumentException('Date invalide.'); }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('Europe/Paris'));
        if (!$date || $date->format('Y-m-d') !== $value) { throw new InvalidArgumentException('Date invalide.'); }
        return ($end ? $date->setTime(23, 59, 59) : $date)->getTimestamp();
    }

    public static function pageUrl($filter, $page) {
        return Routes::url('logs') . '?' . http_build_query([
            'from' => $filter['from_text'], 'to' => $filter['to_text'], 'user' => $filter['user'],
            'event' => $filter['event'], 'result' => $filter['result'], 'page' => $page, 'before' => $filter['before']
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public static function description($details) {
        $names = ['reason' => 'Motif', 'action' => 'Action', 'old_name' => 'Ancien identifiant', 'new_name' => 'Nouvel identifiant',
            'from_role' => 'Ancien rôle', 'to_role' => 'Nouveau rôle', 'path' => 'Chemin', 'fields' => 'Champs',
            'added_folders' => 'Dossiers ajoutés', 'removed_folders' => 'Dossiers retirés', 'status' => 'HTTP', 'folder_count' => 'Dossiers autorisés'];
        $values = ['invalid_credentials' => 'Identifiants incorrects', 'invalid_format' => 'Format invalide',
            'too_many_attempts' => 'Trop de tentatives', 'expired' => 'Délai dépassé', 'account_changed' => 'Compte modifié ou supprimé',
            'validation_failed' => 'Validation refusée', 'storage_unavailable' => 'Stockage indisponible',
            'login' => 'Connexion', 'profile' => 'Mon compte', 'create' => 'Création', 'save' => 'Modification',
            'delete' => 'Suppression', 'reset_password' => 'Réinitialisation', 'user' => 'Utilisateur', 'admin' => 'Administrateur',
            'role' => 'Rôle', 'folders' => 'Dossiers', 'username' => 'Identifiant', 'password' => 'Mot de passe', 'photo' => 'Photo'];
        $parts = [];
        foreach ($details as $key => $value) {
            if (!isset($names[$key])) { continue; }
            if (is_array($value)) { $value = implode(', ', array_map(function ($item) use ($values) { return $values[$item] ?? $item; }, $value)); }
            elseif (is_string($value) && $key !== 'path' && $key !== 'old_name' && $key !== 'new_name') { $value = $values[$value] ?? $value; }
            $parts[] = $names[$key] . ' : ' . $value;
        }
        return implode(' · ', $parts);
    }

    public static function apply() {
        if (!Routes::matches('logs')) { return; }
        if (!Users::isAdmin()) { Auth::stop(403, 'Cette page est réservée aux administrateurs.'); }
        if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD'], true)) { Auth::stop(405, 'Méthode non autorisée.'); }
        $timezone = new DateTimeZone('Europe/Paris');
        $now = new DateTimeImmutable('now', $timezone);
        $filter = ['from_text' => $now->modify('-' . Audit::RETENTION . ' days')->format('Y-m-d'), 'to_text' => $now->format('Y-m-d'),
            'user' => '', 'event' => '', 'result' => '', 'page' => 1, 'before' => ''];
        $history = ['rows' => [], 'next' => false, 'warnings' => false, 'quota' => false, 'write_error' => false];
        $error = '';
        try {
            foreach (['from' => 'from_text', 'to' => 'to_text', 'user' => 'user', 'event' => 'event', 'result' => 'result', 'before' => 'before'] as $query => $key) {
                if (isset($_GET[$query])) {
                    if (!is_string($_GET[$query]) || strlen($_GET[$query]) > 512) { throw new InvalidArgumentException('Filtre invalide.'); }
                    $filter[$key] = trim($_GET[$query]);
                }
            }
            $filter['from'] = self::date($filter['from_text'], false);
            $filter['to'] = self::date($filter['to_text'], true);
            if ($filter['from'] > $filter['to']) { throw new InvalidArgumentException('La date de début doit précéder la date de fin.'); }
            if (($filter['event'] !== '' && !isset(Audit::events()[$filter['event']]))
                || !in_array($filter['result'], ['', 'success', 'failure', 'denied'], true)
                || ($filter['before'] !== '' && !preg_match('/^\d{20}-[a-f0-9]{8}$/D', $filter['before']))) {
                throw new InvalidArgumentException('Filtre invalide.');
            }
            $page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
            if ($page === false) { throw new InvalidArgumentException('Numéro de page invalide.'); }
            $filter['page'] = $page;
            $viewId = Audit::record('audit.view');
            if ($filter['before'] === '') {
                $filter['before'] = $viewId ?: sprintf('%020.0f', microtime(true) * 1000000) . '-ffffffff';
            }
            $history = Audit::query($filter);
            $history['write_error'] = $viewId === false && !$history['quota'];
        } catch (InvalidArgumentException $exception) {
            Audit::record('audit.view', 'failure', null, ['reason' => 'validation_failed']);
            http_response_code(400);
            $error = $exception->getMessage();
        } catch (RuntimeException $exception) {
            http_response_code(503);
            $error = 'Les journaux ne sont pas accessibles. Vérifiez les permissions du stockage privé.';
        }
        $users = array_keys(Users::all());
        sort($users, SORT_NATURAL | SORT_FLAG_CASE);
        $events = Audit::events();
        $home = Routes::url('home');
        header('Content-Type: text/html; charset=utf-8');
        header("Content-Security-Policy: default-src 'none'; style-src 'self'; script-src 'self'; img-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
        require __DIR__ . '/../pages/journal.php';
        exit;
    }
}
