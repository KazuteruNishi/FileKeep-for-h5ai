<!doctype html>
<html lang="fr"><head><meta charset="utf-8"><meta name="color-scheme" content="dark"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= Auth::escape(Auth::pageTitle('Connexion')) ?></title>
<link rel="stylesheet" href="<?= Auth::escape(dirname(Auth::endpoint())) ?>/css/password-toggle.css?v=2">
<script defer src="<?= Auth::escape(dirname(Auth::endpoint())) ?>/js/password-toggle.js?v=1"></script>
<style>
*{box-sizing:border-box}body{margin:0;min-height:100vh;min-height:100dvh;display:grid;place-items:center;background:#101010;color:#ededed;font:16px system-ui,sans-serif;padding:24px;color-scheme:dark}main{width:100%;max-width:400px;padding:32px;background:#181818;border:1px solid #303030;border-radius:14px;box-shadow:0 20px 60px #0006}h1{margin:0 0 8px;font-size:26px}p{color:#aaa;line-height:1.5}label{display:block;margin:20px 0 8px;font-size:14px;color:#ddd}input,button{box-sizing:border-box;width:100%;padding:12px;border:1px solid #444;border-radius:7px;font:inherit}input{background:#101010;color:#f5f5f5}input:hover{border-color:#666}button{margin-top:24px;background:#ededed;color:#111;border-color:#ededed;font-weight:600;cursor:pointer}button:hover{background:white}input:focus-visible,button:focus-visible{outline:2px solid #90bdeb;outline-offset:3px}.error{color:#ffadb6;background:#361a20;border:1px solid #66313b;border-radius:7px;padding:12px}small{display:block;margin-top:24px;color:#999}@media(max-width:420px){body{padding:16px}main{padding:24px}}
.remember{display:flex;align-items:center;gap:9px;margin:18px 0 0;color:#ccc;font-size:14px;cursor:pointer}.remember input{flex:0 0 18px;width:18px;height:18px;margin:0;padding:0;accent-color:#ddd}.remember span{font-size:12px;color:#999}
</style></head><body><main><h1>Connexion</h1><p>Identifiez-vous pour accéder aux fichiers.</p>
<?php if ($error !== '') { ?><p class="error" role="alert"><?= Auth::escape($error) ?></p><?php } ?>
<form method="post" action="">
<input type="hidden" name="h5ai_auth" value="login"><input type="hidden" name="_csrf" value="<?= Auth::escape($_SESSION['csrf']) ?>">
<label for="username">Identifiant</label><input id="username" name="username" autocomplete="username" maxlength="128" required autofocus>
<label for="password">Mot de passe</label><input id="password" name="password" type="password" autocomplete="current-password" maxlength="1024" required>
<label class="remember"><input type="checkbox" name="remember" value="1" <?= ($_POST['remember'] ?? null) === '1' ? 'checked' : '' ?>>Rester connecté</label>
<button type="submit">Se connecter</button></form><small>Accès privé · <?= Auth::escape(Auth::domain()) ?></small></main></body></html>
