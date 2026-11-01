# Connexion PHP et déploiement Docker

Le code protège l'interface, l'API et les fichiers distribués par la passerelle.
Aucun compte ni mot de passe par défaut. Au premier démarrage, les comptes bcrypt
du fichier htpasswd sont importés dans le stockage privé de l'administration.
Sans comptes initialisables ou stockage valide, PHP répond avec le statut 503.

**La protection des liens directs exige le virtual host fourni. Ajouter uniquement
les fichiers PHP ne protège pas les documents servis par Apache.** Les fichiers
de ce dépôt n'ont pas encore été installés dans ton conteneur distant.

## 1. Sauvegarder et installer

Sauvegarder `/docker/apache2/website/_h5ai` et la configuration Compose existante,
puis y copier cette version du projet. Les chemins ci-dessous correspondent à la
configuration Docker transmise (Apache, Traefik, domaine kazuteru.fr).

Le virtual host `deploy/apache-h5ai.conf` protège **tout** `/var/www/html`, y compris
les montages Documents, Series TV, Animes, Dessins Animes, Films et Films D'animation.
Il remplace les anciennes règles DirectoryIndex/Basic Auth. Les `.htaccess` des
dossiers partagés sont ignorés volontairement : ils ne peuvent pas annuler la
protection ni activer un interpréteur PHP. Les autres sites/PHP de ce virtual host
ne seront plus exécutés. Le seul point d'entrée PHP autorisé est celui de h5ai.

## 2. Créer ou reprendre un compte bcrypt

Sur le serveur Docker, avec `htpasswd` (paquet `apache2-utils`) :

```sh
sudo install -d -m 750 /docker/apache2/auth
sudo htpasswd -cB -C 12 /docker/apache2/auth/users.htpasswd ton_identifiant
```

Le mot de passe est demandé de façon interactive. Pour les comptes suivants,
utiliser `htpasswd -B -C 12` **sans `-c`**, sinon le fichier serait remplacé.
Le fichier doit être lisible par le processus PHP du conteneur (www-data, UID 33
dans l'image officielle). Par exemple, si ce groupe convient à ton serveur :

```sh
sudo chown root:33 /docker/apache2/auth/users.htpasswd
sudo chmod 640 /docker/apache2/auth/users.htpasswd
```

Pour réutiliser un fichier existant, monter son chemin à la place. Ses empreintes
doivent être bcrypt (`$2y$`, `$2b$`, `$2a$`). Les anciennes empreintes `$apr1$` et
`{SHA}` ne sont pas acceptées : réinitialiser les mots de passe avec `htpasswd -B`.
Le fichier `.htaccess` contient les règles Apache ; c'est `.htpasswd` qui contient
les comptes. Aucune base de données ni requête SQL n'est ajoutée.

Préparer aussi le dossier persistant que l'administration pourra modifier :

```sh
sudo install -d -o 33 -g 33 -m 700 /docker/apache2/auth/managed
```

Ce dossier contiendra `users.json` (empreintes bcrypt, rôles et droits) et un
fichier de verrouillage. Le conserver hors de `/docker/apache2/website` et le
sauvegarder. Le `.htpasswd` reste monté en lecture seule : après l'import initial,
les ajouts et changements de mots de passe se font dans la page Administration.
Modifier ensuite le `.htpasswd` ne modifie pas les comptes déjà importés.

## 3. Adapter Compose

Conserver le service `apache` existant et son image `php:8.5.1RC2-apache`.
Aucune nouvelle image ni compilation n'est nécessaire. Ajouter les paramètres
suivants à ce même service :

```yaml
command:
  - /bin/sh
  - -ec
  - |
    a2enmod rewrite
    apache2ctl -t
    exec apache2-foreground
environment:
  H5AI_AUTH_FILE: /run/secrets/h5ai.htpasswd
  H5AI_HTTPS: "1"
  H5AI_USERS_DIR: /var/lib/h5ai/users
  H5AI_AUTH_STATE: /var/lib/h5ai/users/login-attempts
  H5AI_ADMIN_USER: ton_identifiant
```

Ajouter à ses volumes existants :

```yaml
- /docker/apache2/auth/users.htpasswd:/run/secrets/h5ai.htpasswd:ro
- /docker/apache2/auth/managed:/var/lib/h5ai/users:rw
- /docker/apache2/website/_h5ai/deploy/apache-h5ai.conf:/etc/apache2/sites-available/000-default.conf:ro
- /docker/apache2/website/_h5ai/deploy/auth.ini:/usr/local/etc/php/conf.d/h5ai-auth.ini:ro
```

Ajouter à ses labels existants :

```yaml
- traefik.http.routers.apache.middlewares=apache-force-https
- traefik.http.middlewares.apache-force-https.redirectscheme.scheme=https
- traefik.http.middlewares.apache-force-https.redirectscheme.permanent=true
```

Conserver les montages de fichiers, les labels HTTPS, le réseau et Traefik.
`deploy/compose.apache.yml` sert d'extrait de référence, pas de remplacement
complet de ton Compose. Ne pas exposer de port Apache directement sur Internet.
`H5AI_HTTPS=1` active les cookies Secure derrière Traefik, sans faire confiance
à un en-tête `X-Forwarded-Proto` fourni par un client.

Remplacer `ton_identifiant` par ton compte bcrypt existant : il devient le premier
administrateur lors de l'import. Si le `.htpasswd` ne contient qu'un compte,
celui-ci est choisi automatiquement en l'absence de cette variable. Avec plusieurs
comptes, la variable est obligatoire. Les autres comptes importés commencent sans
accès aux fichiers : leur attribuer des dossiers dans l'administration.
Cette variable n'attribue plus de rôle après la création de `users.json`.

La commande active mod_rewrite et vérifie la configuration avant de lancer
Apache. Elle est réexécutée à chaque démarrage : l'activation du module reste
effective même après une recréation du conteneur. Les fichiers de configuration
sont montés en lecture seule depuis l'hôte. Le virtual host fourni remplace celui
par défaut pour ce site ; conserver ailleurs une copie de l'ancien fichier si
tu y avais ajouté des réglages personnels à reporter.

Si tu avais déjà appliqué la précédente proposition, retirer `build` et rétablir
ton image existante à la place de `h5ai-auth:local`. Le Dockerfile fourni ne sert
plus qu'au test isolé décrit à la fin de ce document.

Les sessions PHP sont locales à cet unique conteneur. Après une recréation,
« Rester connecté » peut recréer une session grâce au jeton conservé dans le
montage privé persistant. Pour plusieurs réplicas, il faudrait aussi partager
le stockage des sessions et de limitation des connexions.

Depuis le dossier de ton Compose :

```sh
docker compose config
docker compose up -d --no-deps --no-build --pull never apache
docker compose exec apache apache2ctl -t
```

Compose recrée si nécessaire **le conteneur du même service** pour appliquer les
nouveaux volumes et variables. Cela conserve l'image locale existante et les
montages des documents ; aucun second serveur Apache n'est ajouté. Une brève
interruption est possible pendant cette opération. Si le démarrage échoue,
consulter `docker compose logs --tail=100 apache`.

Les dossiers `_h5ai/private/cache` et `_h5ai/public/cache` doivent être accessibles
en écriture par www-data. Le sous-dossier `private/cache/auth` est créé au premier
essai de connexion. Ne pas donner des droits 777 à toute l'arborescence.

## 4. Utiliser l'administration

Les pages utilisent désormais des URL courtes : `/` pour les fichiers et la
connexion, `/administration`, `/mon-compte` et `/journaux`. La déconnexion utilise un POST
sur `/deconnexion` et la photo privée `/mon-compte/photo`. Ces noms sont réservés
à l'application à la racine du partage.

Les anciennes adresses de pages `/_h5ai/public/index.php`, avec ou sans paramètres,
et `/_h5ai/public/` répondent **404**, sans redirection, y compris pour les POST.
L'entrée PHP physique reste utilisée par la réécriture interne Apache. Les
ressources CSS/JS/images restent disponibles pour le fonctionnement du site.
Le virtual host déjà fourni gère ces routes : aucun nouveau montage n'est requis.

Après connexion avec un compte administrateur, cliquer sur **Administration** en
en haut à droite, ou ouvrir `https://kazuteru.fr/administration`.

- Créer un compte : un mot de passe temporaire aléatoire de 20 caractères est
  généré automatiquement et affiché une seule fois après l'enregistrement.
  Le copier et le transmettre à l'utilisateur. Seule son empreinte bcrypt est
  enregistrée dans le stockage des comptes.
- Choisir son rôle : utilisateur ou administrateur. Un administrateur a accès à
  tous les dossiers et à la gestion des comptes.
- Cocher les dossiers autorisés. Chaque droit s'étend à leurs sous-dossiers.
- Pour un accès plus précis, saisir par exemple `/Documents/Factures` dans les
  sous-dossiers spécifiques, en laissant `/Documents` décoché.
- Aucun dossier sélectionné signifie aucun accès aux fichiers. Les dossiers
  parents restent visibles uniquement pour naviguer vers un sous-dossier permis.
- Modifier les droits, réinitialiser un mot de passe ou supprimer un autre compte.
  La modification du rôle ou des dossiers ne change pas le mot de passe.
  Pour le réinitialiser, utiliser **Générer un nouveau mot de passe temporaire**
  dans la fiche du compte : il est affiché une fois, remplace immédiatement
  l'ancien et doit être changé à la prochaine connexion. Les sessions et cookies
  persistants de ce compte sont invalidés. Cette action conserve le rôle, les droits
  et la photo de profil. Le changement de son propre mot de passe se fait dans
  **Mon compte**, avec vérification du mot de passe actuel.

La page est réservée aux administrateurs, y compris pour les requêtes POST.
Un administrateur ne peut ni se supprimer ni retirer son propre rôle, afin de
conserver un accès à la gestion. Les écritures sont verrouillées et atomiques.
Une modification d'un compte ou de ses droits déconnecte uniquement les sessions
de ce compte à leur prochaine requête. Les autres utilisateurs, y compris
l'administrateur qui enregistre, restent connectés. La création d'un utilisateur
n'invalide aucune connexion existante. Chaque compte possède sa propre version
d'authentification, renouvelée lors d'une modification ; supprimer puis recréer
le même identifiant ne réactive pas ses anciens cookies.

À l'installation de cette évolution, les sessions et cookies datant de l'ancienne
politique globale demandent une reconnexion unique. Le stockage des comptes
existant reste compatible et ne doit pas être supprimé.

Les droits sont vérifiés aussi pour les liens directs, la recherche, les archives,
les tailles de dossiers et les miniatures. Les anciennes miniatures sans métadonnées
de source sont refusées jusqu'à leur prochaine demande de génération par h5ai.

### Mon compte

À la première connexion d'un nouveau compte, l'utilisateur est dirigé vers un
écran obligatoire de choix du mot de passe. Il doit saisir le mot de passe
temporaire, puis choisir et confirmer un mot de passe différent, d'au moins
12 caractères avec une majuscule, une minuscule et un caractère spécial
(72 octets maximum). Les fichiers, miniatures, recherches, téléchargements et
la page d'administration restent inaccessibles jusque-là, y compris pour un
nouvel administrateur. La déconnexion reste disponible.

Après le changement, le mot de passe temporaire est inutilisable et l'accès aux
dossiers attribués devient disponible. Si « Rester connecté » avait été coché,
le cookie persistant n'est créé qu'après cette étape. Les comptes existants
ou importés avant cette évolution ne sont pas obligés de changer leur mot de passe.
Si l'administrateur perd le mot de passe temporaire avant de l'avoir transmis,
il peut en générer un autre dans la modification du compte : l'obligation de
changement à la première connexion reste active.

Chaque utilisateur connecté dispose du lien **Mon compte** dans le bandeau,
ou de l'adresse `https://kazuteru.fr/mon-compte`.
Il peut modifier son identifiant, changer son mot de passe et ajouter, remplacer
ou supprimer sa photo de profil. Le mot de passe actuel est demandé pour toute
modification. Un nouveau mot de passe doit être confirmé et respecter la règle
de complexité ci-dessus ; les comptes importés conservent leur ancien mot de
passe tant qu'il n'est pas changé. Aucun chiffre n'est imposé.

Le changement d'identifiant conserve le rôle et les droits sur les dossiers.
La session courante est renouvelée ; les autres sessions de ce même compte
doivent se reconnecter. Les autres utilisateurs restent connectés.

Photos : JPEG, PNG ou WebP, 512 Ko et 2048 × 2048 pixels maximum. Le type et les
dimensions sont contrôlés côté serveur ; SVG et contenus non reconnus sont refusés.
Les images sont stockées dans le dossier privé `H5AI_USERS_DIR/avatars` avec des
noms aléatoires, et ne sont servies qu'à leur propriétaire connecté. Ce dossier
utilise le montage persistant existant : aucune modification Docker supplémentaire
n'est nécessaire. La photo est affichée dans Mon compte et son bouton du bandeau.

Un outil de recadrage dans Mon compte permet de déplacer la photo, de zoomer et de
prévisualiser son affichage circulaire. Cliquer sur **Utiliser ce cadrage**, puis
enregistrer les modifications du compte avec le mot de passe actuel. Le navigateur
prépare un JPEG carré de **512 × 512 pixels** ; la photo originale n'est pas envoyée.
Sources acceptées pour le recadrage : JPEG/PNG/WebP, 10 Mo, 24 mégapixels et
8192 pixels par côté maximum. Sans JavaScript, les limites d'envoi direct de
512 Ko et 2048 × 2048 pixels continuent de s'appliquer.

## 5. Vérifier après installation

- En navigation privée, `/`, un sous-dossier, un PDF et une vidéo affichent la
  connexion sans transmettre les fichiers. L'API POST sans session répond 401.
- En HTTP, Traefik redirige vers HTTPS avant le formulaire.
- Après connexion, navigation, recherche, aperçus et archives fonctionnent.
- Les vidéos acceptent les requêtes Range (206) et HEAD ne transmet pas de corps.
- Un POST sans le jeton CSRF répond 403 ; 10 essais de connexion par compte
  en 15 minutes entraînent un refus 429, même après suppression du cookie.
  Une limite supplémentaire de 100 essais par adresse réseau s'applique ; derrière
  Traefik, elle couvre l'ensemble des utilisateurs passant par ce proxy.
- Un lien vers `_h5ai/private/conf/options.json`, un fichier caché, un lien
  symbolique ou un chemin contenant `..` ne révèle rien.
- Une miniature dans `_h5ai/public/cache` nécessite aussi une session.
- Après déconnexion, les liens directs demandent de nouveau une connexion.

Les sessions expirent après 30 minutes d'inactivité ou 8 heures maximum. Modifier
les données d'un compte invalide ses sessions à leur prochaine requête.

La case **Rester connecté** est décochée par défaut. Si elle est cochée, un cookie
HttpOnly, SameSite=Lax et Secure sous HTTPS permet de recréer une session pendant
30 jours sur cet appareil. Il contient un identifiant aléatoire et un secret
aléatoire ; ni l'identifiant utilisateur ni le mot de passe n'y sont stockés.
Seule une empreinte du secret est conservée côté serveur dans
`H5AI_USERS_DIR/remember`, avec la date d'expiration et la version du compte concerné.
Le secret est renouvelé à chaque restauration de session, sans prolonger les
30 jours. Une courte fenêtre de 30 secondes gère les requêtes parallèles du
navigateur. La reconnexion automatique ne se fait que sur GET/HEAD, afin de
conserver le contrôle CSRF des opérations POST.

La déconnexion révoque le jeton de cet appareil et supprime le cookie. Une
modification d'un compte (mot de passe, identifiant, droits ou suppression)
invalide aussi les jetons persistants de ce compte, selon la même politique que
les sessions. Les jetons des autres comptes restent valides. Le dossier privé déjà monté suffit ; aucun nouveau volume Docker
n'est nécessaire. Sans la case cochée, le cookie PHP reste un cookie de session.
Les droits portent sur la consultation et le téléchargement, sans ajout de
fonctions d'écriture dans les dossiers partagés. Les fichiers HTML/SVG
partagés sont téléchargés, jamais exécutés comme pages du site. Les sources PHP sont refusées ; les fichiers
cachés et les liens symboliques sont exclus, y compris des archives.
Les en-têtes/pieds de page HTML personnalisés sont désactivés et les aperçus
Markdown sont affichés comme texte pour empêcher leur exécution dans la session.

Le mode administrateur historique de h5ai est distinct de la connexion au site.
Son mot de passe reste réglable dans `private/conf/options.json` ; le mot de passe
vide fourni par défaut est désormais refusé. Un compte
connecté ne reçoit pas automatiquement ce privilège. Ce mode historique concerne
la page d'informations techniques ; la gestion des utilisateurs utilise les rôles
du nouveau stockage privé.

## 6. Journaux de sécurité

Le menu des administrateurs contient **Journaux**, accessible à
`https://kazuteru.fr/journaux`. La page est en lecture seule, réservée aux
administrateurs ayant terminé le changement de mot de passe initial.
Elle propose des filtres par dates, auteur/compte concerné, événement et résultat,
avec une pagination de 50 événements. Les heures sont affichées en Europe/Paris,
et les événements sont stockés avec un horodatage UTC. Une pagination conserve
un instantané pour éviter les doublons lorsque de nouvelles actions sont ajoutées.

Sont enregistrés : connexions réussies/échouées, reconnexions automatiques,
déconnexions, expirations/invalidations de sessions, limitation des tentatives,
refus CSRF, accès refusés, créations/modifications/suppressions de comptes,
changements d'identifiant/rôle/dossiers/photo, changements/réinitialisations de
mots de passe, remplacement du mot de passe temporaire et consultations des journaux.
Les comptes supprimés ou renommés restent recherchables dans l'historique.
Les refus d'accès par API sont également journalisés, sans enregistrer les
éléments simplement masqués pendant une navigation normale.

Les données sont conservées dans **`H5AI_USERS_DIR/audit`**, grâce au volume privé
déjà monté. Ce dossier est créé automatiquement avec des fichiers privés et
verrouillés. Aucun mot de passe, empreinte bcrypt, cookie, jeton ou contenu complet
de formulaire n'est enregistré. Les chemins enregistrés ne contiennent pas la
chaîne de paramètres de la requête. La page échappe les textes pour empêcher
les injections HTML et ne permet pas d'effacer ou de modifier les événements.

Conservation : **60 jours**, avec nettoyage des anciens fichiers lors de l'activité.
Rotation quotidienne en segments de 5 Mio, avec un maximum de deux segments par
jour (10 Mio). Une alerte apparaît sur la page si ce plafond est atteint ; des
événements peuvent alors manquer pour cette journée. Une erreur d'écriture est
signalée dans les journaux PHP et sur la page lorsqu'elle peut être consultée,
sans bloquer les connexions ni annuler une modification de compte déjà enregistrée.
L'historique commence à l'installation de cette version, sans import des anciens
journaux Apache.

### Adresse IP réelle derrière Traefik

Sans configuration supplémentaire, l'adresse `REMOTE_ADDR` est enregistrée : dans
ton installation, elle peut être celle de Traefik. Les en-têtes envoyés par un
client ne sont jamais considérés comme fiables par défaut.

Pour identifier l'adresse de Traefik sur le réseau partagé avec Apache, exécuter
sur le serveur :

```sh
sudo docker inspect traefik --format '{{range $name, $network := .NetworkSettings.Networks}}{{$name}}: {{$network.IPAddress}}{{println}}{{end}}'
```

Ajouter ensuite dans `environment` du service Apache, avec **l'adresse réellement
obtenue** pour ce réseau (exemple seulement) :

```yaml
H5AI_TRUSTED_PROXIES: "172.21.0.2"
```

Déclarer uniquement l'adresse du proxy ; elle doit être mise à jour si Traefik
change d'IP. Les adresses et CIDR IPv4/IPv6 sont acceptés, séparés par des virgules.
Le parcours de `X-Forwarded-For` part du proxy vers le client et retient la première
adresse non approuvée, afin d'ignorer les préfixes falsifiés. L'adresse réelle
n'est utilisée ici que pour les journaux ; la limitation des connexions conserve
sa politique existante. L'interface indique la provenance de l'adresse affichée.

Après changement d'environnement :

```sh
cd /docker/docker-build
sudo docker-compose -p docker-build up -d --no-deps --no-build apache
```

## Vérification locale

Avec PHP CLI : `php tests/auth-security.php`. Ce test utilise uniquement une
arborescence temporaire et un serveur PHP local ; il n'installe pas Apache et ne
remplace pas les vérifications du virtual host dans le conteneur.

Sur une machine avec Docker et curl : `sh tests/docker-security.sh` construit
l'image et vérifie le vrai routage Apache dans un conteneur temporaire isolé.
Il teste aussi les miniatures, un lien symbolique et un fichier PHP partagé.

`php tests/audit-storage.php` vérifie les proxys de confiance, les écritures
simultanées, les filtres, la pagination stable, la rotation, la rétention, le
plafond quotidien et le comportement en cas de stockage indisponible.
