<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="color-scheme" content="dark"><title><?= Auth::escape(Auth::pageTitle('Aperçus vidéo')) ?></title>
<?php require __DIR__ . '/auth-controls.php'; ?>
<link rel="stylesheet" href="<?= Auth::escape(dirname(Auth::endpoint())) ?>/css/preview-admin.css?v=<?= substr(hash_file('sha256', __DIR__ . '/../../../public/css/preview-admin.css'), 0, 12) ?>">
<script defer src="<?= Auth::escape(dirname(Auth::endpoint())) ?>/js/preview-admin.js?v=<?= substr(hash_file('sha256', __DIR__ . '/../../../public/js/preview-admin.js'), 0, 12) ?>"></script></head><body class="h5ai-admin-shell">
<header><div class="h5ai-header-leading"><a class="h5ai-back-link" href="<?= Auth::escape($home) ?>" aria-label="Retour aux fichiers" title="Retour aux fichiers"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m12 5-7 7 7 7M5 12h14"/></svg></a><h1>Aperçus vidéo</h1></div><div class="h5ai-header-actions"><div id="h5ai-account-slot"><?php require __DIR__ . '/logout-control.php'; ?></div></div></header><?php require __DIR__ . '/admin-navigation.php'; ?>
<main><section class="preview-intro"><div><h2>Génération des aperçus</h2><p>Les vidéos sont traitées en parallèle selon les places disponibles, même après fermeture du lecteur ou de cette page.</p></div>
<form method="post" action="<?= Auth::escape(Routes::url('previews')) ?>"><input type="hidden" name="_csrf" value="<?= Auth::escape($_SESSION['csrf']) ?>"><button type="submit">Précharger toutes les vidéos</button></form></section>
<section class="generation-controls" aria-label="Commandes du générateur">
<p id="generator-state" role="status">État du générateur : chargement…</p>
<div class="generation-actions">
<?php foreach (['pause' => 'Mettre en pause', 'resume' => 'Reprendre', 'stop' => 'Arrêter et vider la file'] as $action => $label) { ?>
<form method="post" action="<?= Auth::escape(Routes::url('previews')) ?>"><input type="hidden" name="_csrf" value="<?= Auth::escape($_SESSION['csrf']) ?>"><input type="hidden" name="action" value="<?= $action ?>"><button type="submit"><?= $label ?></button></form>
<?php } ?></div><p>La pause conserve la file. L’arrêt annule le traitement et vide la file sans supprimer les planches produites. Une reprise de vidéo repart de sa dernière planche complète.</p>
<details id="preview-picker"><summary><span id="picker-summary">Choisir des fichiers ou des dossiers…</span><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></summary>
<div class="picker-body"><div class="picker-search"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="10" cy="10" r="6"/><path d="m15 15 5 5"/></svg><label class="picker-sr-only" for="preview-search">Rechercher dans le dossier et ses sous-dossiers</label><input id="preview-search" type="search" placeholder="Rechercher un fichier ou un dossier…" maxlength="256" autocomplete="off"></div>
<p id="picker-message" role="status"></p><div id="picker-results"></div>
<div class="picker-footer"><strong id="picker-count">0 sélection</strong><button id="picker-clear" type="button">Effacer la sélection</button>
<label for="picker-priority">Priorité</label><select id="picker-priority"><option value="1000">Urgente</option><option value="100">Haute</option><option value="0" selected>Normale</option><option value="-100">Basse</option></select>
<button id="picker-queue" type="button">Précharger la sélection</button></div>
<p>Cocher un dossier inclut toutes ses vidéos et ses sous-dossiers. Décocher un élément conserve les autres sélections et décoche ses parents. Un dossier se coche automatiquement quand tout son contenu est sélectionné. La sélection est conservée pendant la recherche et la navigation. Les priorités s’appliquent aux prochains démarrages, sans interrompre les vidéos en cours ; une priorité définie sur un fichier passe avant celle de son dossier.</p>
</div></details></section>
<p><strong id="preview-storage">Stockage total des aperçus : chargement…</strong><br>Planches, index et fichiers de fonctionnement du générateur inclus.</p>
<p id="scan-state" role="status"></p><p id="preview-error" role="alert" hidden>Actualisation indisponible. Nouvelle tentative dans quelques secondes.</p>
<p id="queue-notice" role="status" aria-live="polite"></p>
<p id="worker-version-warning" role="alert" hidden></p>
<p>Cette liste contient les vidéos déjà identifiées. Le préchargement recherche aussi celles qui n’ont jamais été ouvertes. Chaque place libérée prend la prochaine vidéo de la file. Le pourcentage de génération est estimé ; le calcul initial de l’empreinte n’a pas de pourcentage.</p>
<div id="preview-lists" data-endpoint="<?= Auth::escape(Routes::url('previews')) ?>" data-home="<?= Auth::escape($home) ?>"><p>Chargement…</p></div><noscript>JavaScript est nécessaire pour actualiser la liste. Le bouton de préchargement reste utilisable.</noscript></main></body></html>
