<meta name="h5ai-csrf" content="<?= Auth::escape($_SESSION['csrf']) ?>">
<link rel="stylesheet" href="<?= Auth::escape(dirname(Auth::endpoint())) ?>/css/password-toggle.css?v=2">
<script defer src="<?= Auth::escape(dirname(Auth::endpoint())) ?>/js/password-toggle.js?v=1"></script>
<link rel="stylesheet" href="<?= Auth::escape(dirname(Auth::endpoint())) ?>/css/auth-ui.css?v=8">
<template id="h5ai-auth-template"><?php require __DIR__ . '/logout-control.php'; ?></template>
<script defer src="<?= Auth::escape(dirname(Auth::endpoint())) ?>/js/auth-ui.js?v=3"></script>
<?php if (Users::isAdmin() && (Routes::matches('admin') || Routes::matches('previews') || Routes::matches('logs') || Routes::matches('filelogs'))) { ?>
<link rel="icon" type="image/x-icon" href="<?= Auth::escape(dirname(Auth::endpoint())) ?>/images/favicon/favicon-16-32.ico">
<link rel="apple-touch-icon" href="<?= Auth::escape(dirname(Auth::endpoint())) ?>/images/favicon/favicon-152.png">
<link rel="stylesheet" href="<?= Auth::escape(dirname(Auth::endpoint())) ?>/css/admin-navigation.css?v=<?= substr(hash_file('sha256', __DIR__ . '/../../../public/css/admin-navigation.css'), 0, 12) ?>">
<?php } ?>
