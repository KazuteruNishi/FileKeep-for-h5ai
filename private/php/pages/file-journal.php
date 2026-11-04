<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="color-scheme" content="dark"><title><?= Auth::escape(Auth::pageTitle('Journaux des fichiers')) ?></title>
<?php require __DIR__ . '/auth-controls.php'; ?><link rel="stylesheet" href="<?= Auth::escape(dirname(Auth::endpoint())) ?>/css/audit.css?v=1"></head><body class="h5ai-admin-shell">
<header><div class="h5ai-header-leading"><a class="h5ai-back-link" href="<?= Auth::escape($home) ?>" aria-label="Retour aux fichiers" title="Retour aux fichiers"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m12 5-7 7 7 7M5 12h14"/></svg></a><h1>Journaux des fichiers</h1></div><div class="h5ai-header-actions"><div id="h5ai-account-slot"><?php require __DIR__ . '/logout-control.php'; ?></div></div></header><?php require __DIR__ . '/admin-navigation.php'; ?>
<main><div class="heading"><h2>Fichiers ouverts et lectures</h2><p>Heure de Paris · Conservation de <?= Filejournal::RETENTION ?> jours · 50 ouvertures par page</p></div>
<?php if ($error !== '') { ?><p class="alert" role="alert"><?= Auth::escape($error) ?></p><?php } ?>
<form class="filters" method="get" action="<?= Auth::escape(Routes::url('filelogs')) ?>">
<label>Du<input type="date" name="from" value="<?= Auth::escape($filter['from']) ?>" required></label>
<label>Au<input type="date" name="to" value="<?= Auth::escape($filter['to']) ?>" required></label>
<label>Utilisateur<input name="user" value="<?= Auth::escape($filter['user']) ?>" placeholder="Tous les comptes" maxlength="128"></label>
<label>Fichier ou dossier<input name="search" value="<?= Auth::escape($filter['search']) ?>" placeholder="Rechercher un chemin" maxlength="512"></label>
<label>Type<select name="kind"><option value="">Tous les fichiers</option><?php foreach (['video' => 'Vidéos', 'audio' => 'Musiques', 'file' => 'Autres fichiers'] as $key => $label) { ?><option value="<?= $key ?>" <?= $filter['kind'] === $key ? 'selected' : '' ?>><?= $label ?></option><?php } ?></select></label>
<div class="filter-actions"><button type="submit">Filtrer</button><a href="<?= Auth::escape(Routes::url('filelogs')) ?>">Réinitialiser</a></div></form>
<div class="table-wrap"><table><thead><tr><th>Ouverture</th><th>Utilisateur</th><th>Fichier / dossier</th><th>Type</th><th>Temps visionné / écouté</th><th>Position / durée</th><th>État</th></tr></thead><tbody>
<?php foreach ($rows as $row) {
    $date = (new DateTimeImmutable('@' . (int) $row['openedAt']))->setTimezone($timezone);
    $state = $row['state'];
    if (in_array($state, ['playing', 'opened'], true) && $row['kind'] !== 'file' && time() - $row['updatedAt'] > 45) { $state = 'interrupted'; }
    $states = ['opened' => 'Ouvert', 'playing' => 'En lecture', 'paused' => 'En pause', 'ended' => 'Terminé', 'closed' => 'Fermé', 'interrupted' => 'Sans nouvelles'];
?>
<tr><td class="date"><time datetime="<?= gmdate('c', (int) $row['openedAt']) ?>"><?= $date->format('d/m/Y H:i:s') ?></time></td><td><?= Auth::escape($row['user']) ?></td><td class="details"><strong><?= Auth::escape(basename($row['file'])) ?></strong><br><?= Auth::escape(dirname($row['file'])) ?></td><td><?= ['video' => 'Vidéo', 'audio' => 'Musique', 'file' => 'Fichier'][$row['kind']] ?? 'Fichier' ?></td><td class="date"><?= $row['kind'] === 'file' ? '–' : Filejournal::clock($row['watched']) ?></td><td class="date"><?= $row['kind'] === 'file' || !$row['duration'] ? '–' : Filejournal::clock($row['position']) . ' / ' . Filejournal::clock($row['duration']) ?></td><td><?= Auth::escape($states[$state] ?? 'Ouvert') ?></td></tr>
<?php } ?>
<?php if (!$rows) { ?><tr><td colspan="7" class="empty">Aucun fichier ouvert pour ces filtres.</td></tr><?php } ?>
</tbody></table></div>
<nav class="pagination" aria-label="Pages des journaux des fichiers"><?php if ($filter['page'] > 1) { ?><a class="button" rel="prev" href="<?= Auth::escape(Filejournal::pageUrl($filter, $filter['page'] - 1)) ?>">Précédent</a><?php } ?><span>Page <?= (int) $filter['page'] ?> / <?= max(1, (int) ceil($total / 50)) ?></span><?php if ($filter['page'] * 50 < $total) { ?><a class="button" rel="next" href="<?= Auth::escape(Filejournal::pageUrl($filter, $filter['page'] + 1)) ?>">Suivant</a><?php } ?></nav>
<p class="hint">Le temps correspond à la lecture effective dans les lecteurs du site, hors pauses, attentes de chargement et sauts. Il est actualisé toutes les dix secondes et à la fermeture. Il peut dépasser la durée du média si certains passages sont relus. Une interruption brutale peut perdre les dernières secondes. Les ouvertures directes hors du site et les lecteurs externes ne transmettent pas leur temps de lecture. Limite : <?= Filejournal::DAILY_LIMIT ?> nouvelles ouvertures par jour.</p>
<a href="<?= Auth::escape(Routes::url('filelogs')) . '?' . Auth::escape(http_build_query($filter, '', '&', PHP_QUERY_RFC3986)) ?>">Actualiser le journal</a>
</main></body></html>
