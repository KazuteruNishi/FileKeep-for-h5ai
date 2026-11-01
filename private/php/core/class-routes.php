<?php

/** Public URLs are independent of the physical PHP entry point and assets. */
class Routes {
    public static function url($name) {
        $root = rtrim(str_replace('\\', '/', dirname(dirname(dirname(Auth::endpoint())))), '/') . '/';
        $paths = ['home' => '', 'account' => 'mon-compte', 'admin' => 'administration',
            'photo' => 'mon-compte/photo', 'logout' => 'deconnexion', 'logs' => 'journaux'];
        return $root . $paths[$name];
    }

    private static function legacy() {
        $path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
        return strpos($path, Auth::endpoint()) === 0 || rtrim($path, '/') === dirname(Auth::endpoint());
    }

    public static function matches($name) {
        $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        return rtrim($path, '/') === rtrim(self::url($name), '/');
    }

    public static function loginTarget() {
        return parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    }

    public static function apply() {
        // REQUEST_URI remains the public URL during an internal Apache rewrite.
        // Reject direct use of the physical entry point, for GET and POST alike.
        if (self::legacy()) { Auth::stop(404, 'Page introuvable.'); }
        if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD'], true)) { return; }
        $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $target = null;
        foreach (['admin', 'account', 'photo', 'logs'] as $name) {
            if ($path !== self::url($name) && self::matches($name)) { $target = self::url($name); break; }
        }
        if (self::matches('logout')) { $target = self::url('home'); }
        if ($target !== null) {
            if ($target === self::url('admin') && isset($_GET['user']) && is_string($_GET['user'])) {
                $target .= '?user=' . rawurlencode($_GET['user']);
            }
            header('Location: ' . $target, true, 303);
            exit;
        }
    }
}
