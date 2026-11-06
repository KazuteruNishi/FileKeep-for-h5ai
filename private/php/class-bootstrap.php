<?php

class Bootstrap {
    private static $autopaths = ['core', 'ext'];

    public static function run() {
        spl_autoload_register(['Bootstrap', 'autoload']);
        set_exception_handler(function ($error) {
            error_log('h5ai unhandled: ' . $error);
            if (headers_sent()) { exit; }
            Auth::stop(500, 'Une erreur est survenue.', false);
        });
        self::normalize_script_name();
        Routes::apply();
        putenv('LANG=en_US.UTF-8');
        setlocale(LC_CTYPE, 'en_US.UTF-8');
        date_default_timezone_set(@date_default_timezone_get());
        Auth::start();
        Account::apply();
        Admin::apply();
        Previewadmin::apply();
        Journal::apply();
        Filejournal::apply();
        Suggestions::apply();
        Videoviews::apply();
        Playbackprogress::apply();
        Media::apply();
        Storyboards::apply();

        // Intercept files before constructing h5ai's directory/API context.
        Gateway::apply();

        $session = new Session($_SESSION);
        $request = new Request($_REQUEST, file_get_contents('php://input'));
        $setup = new Setup($request->query_boolean('refresh', false));
        $context = new Context($session, $request, $setup);

        if ($context->is_api_request()) {
            (new Api($context))->apply();
        } elseif ($context->is_info_request()) {
            // The public entry point is also the login/logout target. Never
            // expose h5ai's legacy donation/password screen after signing in.
            header('Location: ' . $setup->get('ROOT_HREF'), true, 303);
            exit;
        } else {
            $public_href = $setup->get('PUBLIC_HREF');
            $x_head_tags = $context->get_x_head_html();
            $fallback_mode = $context->is_fallback_mode();
            $fallback_html = (new Fallback($context))->get_html();
            require __DIR__ . '/pages/index.php';
        }
    }

    private static function normalize_script_name() {
        // Apache rewrites may report the original URL in SCRIPT_NAME. Derive
        // the real public entry URL from disk, independently of the requested URL.
        $document_root = !empty($_SERVER['DOCUMENT_ROOT']) ? realpath($_SERVER['DOCUMENT_ROOT']) : false;
        $entry = realpath(dirname(__DIR__, 2) . '/public/index.php');
        if ($document_root === false || $entry === false) {
            Auth::stop(503, 'Impossible de déterminer le chemin public de h5ai.');
        }
        $document_root = rtrim(str_replace('\\', '/', $document_root), '/');
        $entry = str_replace('\\', '/', $entry);
        if (strpos($entry, $document_root . '/') !== 0) {
            Auth::stop(503, 'Le point d’entrée h5ai doit être situé dans DocumentRoot.');
        }
        $relative = substr($entry, strlen($document_root) + 1);
        $_SERVER['SCRIPT_NAME'] = '/' . implode('/', array_map('rawurlencode', explode('/', $relative)));
    }

    public static function autoload($class_name) {
        $filename = 'class-' . strtolower($class_name) . '.php';

        foreach (Bootstrap::$autopaths as $path) {
            $file = __DIR__  . '/' . $path . '/' . $filename;
            if (file_exists($file)) {
                require_once $file;
                return true;
            }
        }
    }
}
