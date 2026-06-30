# Mail2GLPI — Guide d'installation (administrateur)

Ce guide explique comment installer, mettre à jour et dépanner le **plugin GLPI `mail2glpi`**
(Brique A) sur un serveur GLPI 11 auto-hébergé.

> Pour l'add-in Outlook (Brique B), voir `addin/README.md` (composant distinct, non couvert ici).

---

## 1. Prérequis

- **GLPI 11.x** auto-hébergé (le Cloud SaaS Teclib ne permet pas les plugins tiers).
- **PHP 8.1+** (déjà requis par GLPI 11).
- Un serveur web dont la racine pointe sur **`…/glpi/public/`** (configuration standard GLPI 11).
- Un **accès shell** au serveur (exemples ci-dessous pour Debian) avec `sudo`.
- `git` installé sur le serveur (pour le déploiement automatique).
- Aucune clé d'API GLPI n'est nécessaire : le plugin fonctionne dans la session de l'agent.

Le plugin réutilise `laminas/laminas-mail` (fourni par le cœur de GLPI) pour le `.eml` et
embarque la bibliothèque `msg.reader` (Apache-2.0, déjà versionnée) pour le `.msg`. **Rien
d'autre à installer.**

---

## 2. Récupérer le code

```bash
cd /opt
git clone https://github.com/Baltemor369/Mail2GLPI.git
```

> Le dossier de travail (`/opt/Mail2GLPI`) est distinct du dossier déployé dans GLPI.

---

## 3. Déployer le plugin

> ⚠️ **GLPI 11 : déployez une COPIE du dossier, pas un lien symbolique.** La racine web est
> `…/glpi/public/` et Apache ne sert pas les fichiers statiques à travers un symlink (→ 404).

### Méthode recommandée : le script `deploy.sh`

Depuis le dépôt :

```bash
bash deploy.sh
```

Le script enchaîne : mise à jour git → copie vers `…/glpi/plugins/mail2glpi` → droits
`www-data` → vidage du cache, puis affiche la version déployée.

Variables d'environnement (optionnelles) :

| Variable    | Défaut                  | Rôle                                            |
|-------------|-------------------------|-------------------------------------------------|
| `GLPI_ROOT` | `/var/www/html/glpi`    | Racine de l'installation GLPI                    |
| `WEB_USER`  | `www-data`              | Utilisateur du serveur web                       |
| `GIT_REF`   | `origin/master`         | Version à déployer (ex. `v0.6.1`)               |
| `PULL`      | `1`                     | `0` = déployer le code local sans `git`          |

Exemple : `GLPI_ROOT=/var/www/glpi bash deploy.sh`

### Méthode manuelle

```bash
GLPIROOT=/var/www/html/glpi
sudo rm -rf "$GLPIROOT/plugins/mail2glpi"
sudo cp -r /opt/Mail2GLPI/plugin "$GLPIROOT/plugins/mail2glpi"
sudo chown -R www-data:www-data "$GLPIROOT/plugins/mail2glpi"
sudo -u www-data php "$GLPIROOT/bin/console" cache:clear
```

---

## 4. Activer le plugin dans GLPI

1. **Configuration > Plugins**.
2. Ligne **« Mail2GLPI »** → **Installer**, puis **Activer**.

Aucune configuration supplémentaire n'est requise pour le fonctionnement de base.

---

## 5. Vérifier l'installation

1. Les assets doivent être servis (HTTP **200**) :
   ```bash
   curl -s -o /dev/null -w "%{http_code}\n" "http://VOTRE-GLPI/plugins/mail2glpi/js/dropzone.js"
   ```
2. Ouvrir un **nouveau ticket** (`/front/ticket.form.php`) : une **zone de dépôt** en pointillés
   « Glissez ici un e-mail (.eml ou .msg)… » doit apparaître.
3. Glisser `examples/sample.eml` (fourni dans le dépôt) : le titre et la description se remplissent.

---

## 5 bis. Pièces jointes volumineuses (PDF de plusieurs Mo)

Le plugin accepte jusqu'à **20 Mo par pièce** et **30 Mo cumulés**. Mais la limite **réelle** est
imposée par **PHP** puis par **GLPI** ; par défaut PHP plafonne souvent à **2 Mo**, ce qui rejette
les PDF plus gros. Pour réellement accepter de grosses pièces jointes :

1. **PHP** (`php.ini` du serveur web ; ex. `/etc/php/8.x/apache2/php.ini`) :
   ```ini
   upload_max_filesize = 50M
   post_max_size       = 60M
   memory_limit        = 256M
   ```
   puis redémarrer le service web (`sudo systemctl restart apache2`).
2. **GLPI** : *Configuration > Assistance* (et la politique de documents) — vérifier la **taille
   maximale des documents** et les **types de fichiers** autorisés.

> Garder une cohérence : `post_max_size` ≥ `upload_max_filesize`, et les plafonds GLPI ≥ taille des
> PJ attendues. Les plafonds du plugin (20/30 Mo) ne servent à rien s'ils dépassent ceux de PHP/GLPI.

---

## 6. Mettre à jour

```bash
cd /opt/Mail2GLPI && bash deploy.sh
```

Puis recharger la page en **Ctrl+F5** (cache navigateur).

---

## 7. Dépannage

| Symptôme | Cause probable | Solution |
|---|---|---|
| **404** sur `dropzone.js` / `dropzone.css` | Assets non servis | Vérifier le déploiement (copie, pas symlink) ; ils doivent être sous `plugins/mail2glpi/public/…` |
| Dropzone **absente** du formulaire | Plugin non activé / cache | Activer le plugin ; `cache:clear` ; Ctrl+F5 |
| Au dépôt : **403** « Jeton de sécurité invalide » | CSRF | Recharger la page ; vérifier qu'on est bien sur GLPI 11 (le routeur gère le CSRF AJAX) |
| Au dépôt : **500** | Erreur PHP | `tail -n 30 …/glpi/files/_log/php-errors.log` |
| `.msg` : « **Lecteur .msg indisponible** » | Lib vendor absente | Vérifier `plugins/mail2glpi/public/js/vendor/msg.reader.js` (re-déployer) |
| Le **demandeur** ne se remplit pas | Sélecteur du widget acteurs | Vérifier la console (F12) ; le plugin cible `select[data-actor-type="requester"]` |
| Numéro de version affiché incohérent | Cache | `cache:clear` ; comparer `grep MAIL2GLPI_VERSION …/mail2glpi/setup.php` |

---

## 8. Désinstaller

1. **Configuration > Plugins** → **Désactiver**, puis **Désinstaller** « Mail2GLPI ».
2. Supprimer le dossier :
   ```bash
   sudo rm -rf /var/www/html/glpi/plugins/mail2glpi
   ```

Le plugin ne crée aucune table ni donnée persistante : la désinstallation est sans effet de bord.
