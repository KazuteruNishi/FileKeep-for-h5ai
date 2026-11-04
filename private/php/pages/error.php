<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= Auth::escape(Auth::pageTitle($title)) ?></title>
<link rel="stylesheet" href="<?= Auth::escape($public) ?>/css/error.css">
<script src="<?= Auth::escape($public) ?>/js/error.js" defer></script>
</head>
<body>
<header><strong><?= Auth::escape(Auth::domain()) ?></strong></header>
<main><article class="split">
<div>
<div class="large-code" aria-hidden="true"><?= (int) $status ?></div>
<span class="code">Erreur <?= (int) $status ?></span>
<h1><?= Auth::escape($title) ?></h1>
<p><?= Auth::escape($description) ?></p>
<div class="actions">
<?php if ($retry): ?>
<button class="button primary" type="button" id="error-retry">Réessayer</button>
<?php else: ?>
<a class="button primary" href="<?= Auth::escape($home) ?>"><?= Auth::escape($label) ?></a>
<?php endif; ?>
</div>
<div class="note">Accès privé · <?= Auth::escape(Auth::domain()) ?></div>
</div>
<div class="portrait"><img class="illustration" src="<?= Auth::escape($public) ?>/images/error-angry.png" alt="Personnage contrarié"></div>
</article></main>
</body></html>
