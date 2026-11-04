<details id="h5ai-auth-actions">
<summary aria-label="Ouvrir le menu du compte">
<?php if (isset(Users::get($_SESSION['access']['user'])['avatar'])) { ?><img class="h5ai-profile-thumb" src="<?= Auth::escape(Routes::url('photo')) ?>" alt="" width="28" height="28"><?php } else { ?><span class="h5ai-profile-initial" aria-hidden="true"><?= Auth::escape(strtoupper(substr($_SESSION['access']['user'], 0, 1))) ?></span><?php } ?>
<span class="h5ai-account-name"><?= Auth::escape($_SESSION['access']['user']) ?></span><svg class="h5ai-chevron" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
</summary>
<div class="h5ai-account-panel">
<div class="h5ai-account-identity"><strong><?= Auth::escape($_SESSION['access']['user']) ?></strong><span class="h5ai-account-role"><?= Users::isAdmin() ? 'Administrateur' : 'Utilisateur' ?></span></div>
<nav aria-label="Compte utilisateur">
<a href="<?= Auth::escape(Routes::url('account')) ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="7" r="3"/><path d="M5 21v-3a7 7 0 0 1 14 0v3"/></svg>Mon compte</a>
<?php if (Users::isAdmin() && !Users::mustChangePassword()) { ?><a href="<?= Auth::escape(Routes::url('admin')) ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>Administration</a>


<?php } ?>
</nav>
<form method="post" action="<?= Auth::escape(Routes::url('logout')) ?>">
<input type="hidden" name="h5ai_auth" value="logout"><input type="hidden" name="_csrf" value="<?= Auth::escape($_SESSION['csrf']) ?>">
<button type="submit"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 4H4v16h5M14 7l5 5-5 5M8 12h11"/></svg>Déconnexion</button></form>
</div></details>
