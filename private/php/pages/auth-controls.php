<meta name="h5ai-csrf" content="<?= Auth::escape($_SESSION['csrf']) ?>">
<link rel="stylesheet" href="<?= Auth::escape(dirname(Auth::endpoint())) ?>/css/password-toggle.css?v=2">
<script defer src="<?= Auth::escape(dirname(Auth::endpoint())) ?>/js/password-toggle.js?v=1"></script>
<link rel="stylesheet" href="<?= Auth::escape(dirname(Auth::endpoint())) ?>/css/auth-ui.css?v=8">
<template id="h5ai-auth-template"><?php require __DIR__ . '/logout-control.php'; ?></template>
<script defer src="<?= Auth::escape(dirname(Auth::endpoint())) ?>/js/auth-ui.js?v=3"></script>
