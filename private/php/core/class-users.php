<?php

/** Atomic, private account/ACL store. The htpasswd file is a migration source. */
class Users {
    private static $data;
    private static $revision;

    public static function directory() {
        return getenv('H5AI_USERS_DIR') ?: __DIR__ . '/../../conf/accounts';
    }

    public static function initialize($seed) {
        if (is_file(self::directory() . '/users.json')) { self::load(); return; }
        self::transaction(function (&$data) use ($seed) {
            if ($data !== null) { return; }
            $accounts = [];
            if (!is_readable($seed)) { throw new RuntimeException('Fichier htpasswd initial introuvable.'); }
            foreach (file($seed, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2 && preg_match('/^\$2[aby]\$/', $parts[1])) {
                    $accounts[$parts[0]] = ['hash' => $parts[1], 'role' => 'user', 'folders' => [], 'auth_version' => bin2hex(random_bytes(16))];
                }
            }
            $admin = getenv('H5AI_ADMIN_USER');
            if (!$admin && count($accounts) === 1) { $admin = (string) array_keys($accounts)[0]; }
            if (!$admin || !isset($accounts[$admin])) {
                throw new RuntimeException('Définissez H5AI_ADMIN_USER avec un compte bcrypt existant.');
            }
            $accounts[$admin]['role'] = 'admin';
            $data = ['version' => 1, 'users' => $accounts];
        });
    }

    private static function load() {
        $json = @file_get_contents(self::directory() . '/users.json');
        $data = $json === false ? null : json_decode($json, true);
        if (!is_array($data) || ($data['version'] ?? null) !== 1 || !is_array($data['users'] ?? null)) {
            throw new RuntimeException('Stockage des utilisateurs invalide.');
        }
        foreach ($data['users'] as $user) {
            if (!is_array($user) || !is_string($user['hash'] ?? null)
                || !in_array($user['role'] ?? null, ['admin', 'user'], true)
                || !is_array($user['folders'] ?? null)) {
                throw new RuntimeException('Compte utilisateur invalide.');
            }
            if (isset($user['avatar']) && !Photo::validReference($user['avatar'])) {
                throw new RuntimeException('Photo de profil invalide.');
            }
            if (isset($user['must_change_password']) && !is_bool($user['must_change_password'])) {
                throw new RuntimeException('État du mot de passe invalide.');
            }
            if (isset($user['auth_version']) && (!is_string($user['auth_version']) || !preg_match('/^[a-f0-9]{32}$/D', $user['auth_version']))) {
                throw new RuntimeException('Version du compte invalide.');
            }
            foreach ($user['folders'] as $folder) {
                if (!is_string($folder) || $folder === '' || $folder[0] !== '/' || strpos($folder, '\\') !== false
                    || preg_match('#(?:^|/)\.[^/]*#', $folder)) {
                    throw new RuntimeException('Droit de dossier invalide.');
                }
            }
        }
        self::$data = $data;
        self::$revision = hash('sha256', $json);
        return $data;
    }

    public static function all() {
        if (self::$data === null) { self::load(); }
        return self::$data['users'];
    }

    public static function get($name) {
        return self::all()[$name] ?? null;
    }

    public static function revision() {
        if (self::$data === null) { self::load(); }
        return self::$revision;
    }

    public static function accountRevision($name) {
        $account = self::get($name);
        if ($account === null) { return null; }
        // Include the name and complete account so manual credential/ACL changes
        // also revoke sessions. auth_version prevents revoked sessions reviving
        // if permissions are later restored or a deleted username is recreated.
        return 'user:' . hash('sha256', json_encode([(string) $name, $account]));
    }

    public static function isAdmin() {
        $user = self::get($_SESSION['access']['user'] ?? '');
        return $user !== null && $user['role'] === 'admin';
    }

    public static function mustChangePassword() {
        return !empty(self::get($_SESSION['access']['user'] ?? '')['must_change_password']);
    }

    public static function temporaryPassword() {
        $groups = ['ABCDEFGHJKLMNPQRSTUVWXYZ', 'abcdefghjkmnpqrstuvwxyz', '23456789', '!@#$%*-_?'];
        $password = [];
        foreach ($groups as $group) { $password[] = $group[random_int(0, strlen($group) - 1)]; }
        $alphabet = implode('', $groups);
        while (count($password) < 20) { $password[] = $alphabet[random_int(0, strlen($alphabet) - 1)]; }
        for ($index = count($password) - 1; $index > 0; $index--) {
            $other = random_int(0, $index);
            $value = $password[$index]; $password[$index] = $password[$other]; $password[$other] = $value;
        }
        return implode('', $password);
    }

    public static function permits($relative, $directory) {
        $user = self::get($_SESSION['access']['user'] ?? '');
        if ($user === null) { return false; }
        if ($user['role'] === 'admin') { return true; }
        $relative = '/' . trim($relative, '/');
        foreach ($user['folders'] as $grant) {
            if ($grant === '/' || $relative === $grant || strpos($relative, $grant . '/') === 0) { return true; }
            // Ancestors are navigation containers, never authorization for files.
            if ($directory && ($relative === '/' || strpos($grant, $relative . '/') === 0)) { return true; }
        }
        // The root remains an empty navigation page for users with no grants.
        return $directory && $relative === '/';
    }

    public static function save($name, $password, $role, $folders, $create) {
        self::validateName($name);
        if ($password !== '') { self::validatePassword($password); }
        if (!in_array($role, ['user', 'admin'], true) || !is_array($folders) || count($folders) > 200) {
            throw new InvalidArgumentException('Rôle ou dossiers invalides.');
        }
        $clean = [];
        foreach ($folders as $folder) {
            if (!is_string($folder) || strlen($folder) > 2048 || strpos($folder, '\\') !== false
                || preg_match('/[\x00-\x1f]/', $folder)) { throw new InvalidArgumentException('Chemin invalide.'); }
            $folder = '/' . trim(preg_replace('#/+#', '/', trim($folder)), '/');
            $path = dirname(__DIR__, 4) . ($folder === '/' ? '' : $folder);
            // Admin callers still pass the canonical safety policy (no symlinks/internals).
            if (!is_dir($path) || !Auth::allowedPath($path)) {
                throw new InvalidArgumentException('Dossier absent ou interdit : ' . $folder);
            }
            $clean[] = $folder;
        }
        $clean = array_values(array_unique($clean));
        $hash = $password === '' ? null : password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        $previous = null;
        self::transaction(function (&$data) use ($name, $hash, $role, $clean, $create, &$previous) {
            self::assertAdmin($data);
            $exists = isset($data['users'][$name]);
            $previous = $data['users'][$name] ?? null;
            if ($create && $exists) { throw new InvalidArgumentException('Cet identifiant existe déjà.'); }
            if (!$create && !$exists) { throw new InvalidArgumentException('Utilisateur introuvable.'); }
            if (!$exists && $hash === null) { throw new InvalidArgumentException('Un mot de passe est nécessaire.'); }
            if ($name === $_SESSION['access']['user'] && $role !== 'admin') {
                throw new InvalidArgumentException('Vous ne pouvez pas retirer votre propre rôle administrateur.');
            }
            $data['users'][$name] = array_merge($data['users'][$name] ?? [], ['hash' => $hash ?? $data['users'][$name]['hash'], 'role' => $role, 'folders' => $clean]);
            $data['users'][$name]['auth_version'] = bin2hex(random_bytes(16));
            if ($create) { $data['users'][$name]['must_change_password'] = true; }
        });
        $fields = [];
        if ($previous !== null && $previous['role'] !== $role) {
            $fields[] = 'role';
            Audit::record('user.role', 'success', $name, ['from_role' => $previous['role'], 'to_role' => $role]);
        }
        if ($previous !== null && $previous['folders'] !== $clean) {
            $fields[] = 'folders';
            Audit::record('user.folders', 'success', $name, ['added_folders' => array_values(array_diff($clean, $previous['folders'])),
                'removed_folders' => array_values(array_diff($previous['folders'], $clean)), 'folder_count' => count($clean)]);
        }
        Audit::record($create ? 'user.created' : 'user.updated', 'success', $name, ['fields' => $fields, 'to_role' => $role, 'folder_count' => count($clean)]);
    }

    public static function validateName($name) {
        if (!is_string($name) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.@-]{0,63}$/D', $name)) {
            throw new InvalidArgumentException('Identifiant : 1 à 64 caractères, lettres, chiffres, _ . @ ou -.');
        }
    }

    public static function validatePassword($password) {
        if (!is_string($password) || strlen($password) > 72 || preg_match('/[\x00-\x1f\x7f]/', $password)
            || preg_match_all('/./us', $password) < 12 || !preg_match('/\p{Lu}/u', $password)
            || !preg_match('/\p{Ll}/u', $password) || !preg_match('/[\p{P}\p{S}]/u', $password)) {
            throw new InvalidArgumentException('Le mot de passe doit contenir au moins 12 caractères, une majuscule, une minuscule et un caractère spécial (72 octets maximum).');
        }
    }

    public static function updateSelf($name, $currentPassword, $password, $avatar) {
        self::validateName($name);
        if (!is_string($currentPassword) || strlen($currentPassword) > 1024 || strpos($currentPassword, "\0") !== false || !is_string($password)) {
            throw new InvalidArgumentException('Mot de passe invalide.');
        }
        if ($password !== '') { self::validatePassword($password); }
        if ($avatar !== null && $avatar !== false && !Photo::validReference($avatar)) {
            throw new InvalidArgumentException('Photo de profil invalide.');
        }
        $oldName = $_SESSION['access']['user'];
        $previous = null;
        self::transaction(function (&$data) use ($name, $currentPassword, $password, $avatar, &$previous) {
            $oldName = $_SESSION['access']['user'];
            if (!isset($data['users'][$oldName]) || !hash_equals(self::accountRevision($oldName), $_SESSION['access']['revision'])) {
                throw new RuntimeException('La configuration a changé. Reconnectez-vous.');
            }
            $account = $data['users'][$oldName];
            $previous = $account;
            if (!password_verify($currentPassword, $account['hash'])) {
                throw new InvalidArgumentException('Le mot de passe actuel est incorrect.');
            }
            if (!empty($account['must_change_password']) && ($password === '' || password_verify($password, $account['hash']))) {
                throw new InvalidArgumentException('Choisissez un nouveau mot de passe différent du mot de passe temporaire.');
            }
            if ($name !== $oldName && isset($data['users'][$name])) {
                throw new InvalidArgumentException('Cet identifiant existe déjà.');
            }
            if ($password !== '') {
                $account['hash'] = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
                $account['must_change_password'] = false;
            }
            if ($avatar === false) { unset($account['avatar']); }
            elseif ($avatar !== null) { $account['avatar'] = $avatar; }
            $account['auth_version'] = bin2hex(random_bytes(16));
            // Role and folder permissions are copied unchanged from the server.
            unset($data['users'][$oldName]);
            $data['users'][$name] = $account;
        });
        $fields = [];
        if ($oldName !== $name) {
            $fields[] = 'username';
            Audit::record('user.renamed', 'success', $name, ['old_name' => $oldName, 'new_name' => $name]);
        }
        if ($password !== '') {
            $fields[] = 'password';
            Audit::record(!empty($previous['must_change_password']) ? 'password.initial' : 'password.changed', 'success', $name);
        }
        if ($avatar !== null) { $fields[] = 'photo'; Audit::record('user.photo', 'success', $name); }
        Audit::record('user.updated', 'success', $name, ['fields' => $fields]);
    }

    public static function resetPassword($name) {
        if (!is_string($name)) { throw new InvalidArgumentException('Identifiant invalide.'); }
        $password = self::temporaryPassword();
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        self::transaction(function (&$data) use ($name, $hash) {
            self::assertAdmin($data);
            if ($name === $_SESSION['access']['user']) {
                throw new InvalidArgumentException('Utilisez Mon compte pour changer votre propre mot de passe.');
            }
            if (!isset($data['users'][$name])) { throw new InvalidArgumentException('Utilisateur introuvable.'); }
            $data['users'][$name]['hash'] = $hash;
            $data['users'][$name]['must_change_password'] = true;
            $data['users'][$name]['auth_version'] = bin2hex(random_bytes(16));
        });
        Audit::record('password.reset', 'success', $name);
        return $password;
    }

    public static function delete($name) {
        if (!is_string($name)) { throw new InvalidArgumentException('Identifiant invalide.'); }
        $avatar = null;
        self::transaction(function (&$data) use ($name, &$avatar) {
            self::assertAdmin($data);
            if ($name === $_SESSION['access']['user']) { throw new InvalidArgumentException('Vous ne pouvez pas supprimer votre propre compte.'); }
            if (!isset($data['users'][$name])) { throw new InvalidArgumentException('Utilisateur introuvable.'); }
            $avatar = $data['users'][$name]['avatar'] ?? null;
            unset($data['users'][$name]);
        });
        Photo::remove($avatar);
        Audit::record('user.deleted', 'success', $name);
    }

    private static function assertAdmin($data) {
        if (($data['users'][$_SESSION['access']['user'] ?? '']['role'] ?? null) !== 'admin') {
            throw new RuntimeException('Droits administrateur requis.');
        }
        if (!hash_equals(self::accountRevision($_SESSION['access']['user']), $_SESSION['access']['revision'])) {
            throw new RuntimeException('La configuration a changé. Reconnectez-vous.');
        }
    }

    private static function transaction($change) {
        $dir = self::directory();
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('Dossier des comptes non accessible en écriture.');
        }
        $lock = @fopen($dir . '/users.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX)) { throw new RuntimeException('Impossible de verrouiller les comptes.'); }
        $temporary = null;
        try {
            $data = is_file($dir . '/users.json') ? self::load() : null;
            $change($data);
            $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json === false) { throw new RuntimeException('Encodage des comptes impossible.'); }
            $temporary = tempnam($dir, '.users-');
            if ($temporary === false || file_put_contents($temporary, $json, LOCK_EX) !== strlen($json)) {
                throw new RuntimeException('Écriture des comptes impossible.');
            }
            chmod($temporary, 0600);
            if (!rename($temporary, $dir . '/users.json')) { throw new RuntimeException('Enregistrement des comptes impossible.'); }
            self::$data = $data;
            self::$revision = hash('sha256', $json);
        } finally {
            if ($temporary && is_file($temporary)) { unlink($temporary); }
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
