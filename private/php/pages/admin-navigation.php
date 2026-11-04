<?php
if (!Users::isAdmin() || Users::mustChangePassword()) { return; }
$adminPages = [
    'admin' => ['Utilisateurs', '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>'],
    'previews' => ['Aperçus vidéo', '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="m9 8 7 4-7 4z"/>'],
    'logs' => ['Journaux de sécurité', '<path d="M7 3h10l3 3v15H4V3h3M8 9h8M8 13h8M8 17h5"/>'],
    'filelogs' => ['Journaux des fichiers', '<path d="M14 3H5v18h14V8zM14 3v5h5M8 12h8M8 16h5"/>']
];
?>
<aside class="h5ai-admin-sidebar"><nav aria-label="Administration"><p class="h5ai-admin-nav-title">Administration</p>
<?php foreach ($adminPages as $route => [$label, $icon]) { ?>
<a href="<?= Auth::escape(Routes::url($route)) ?>"<?= Routes::matches($route) ? ' aria-current="page"' : '' ?>><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?= $icon ?></svg><span><?= Auth::escape($label) ?></span></a>
<?php } ?></nav></aside>
