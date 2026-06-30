# Mail2GLPI — Plugin GLPI (Brique A)

Plugin GLPI 11 qui ajoute une **zone de dépôt** dans le formulaire de création de ticket :
on y glisse un fichier e-mail **`.eml` ou `.msg`**, et le plugin **pré-remplit** le formulaire
(titre, description, source « E-Mail », pièces jointes, demandeur), l'agent n'ayant plus qu'à
valider.

> **État** : fonctionnel. Reste à faire : observateurs (Cc), règles d'affectation
> (entité/catégorie/urgence/SLA), traductions. Voir le [CHANGELOG](../CHANGELOG.md).

📘 **Guides** : [installation (admin)](../docs/INSTALLATION.md) ·
[utilisation (agents)](../docs/UTILISATION.md). Ce README couvre les détails techniques.

## Formats pris en charge : `.eml` et `.msg`

- **`.eml`** (RFC 822) → analysé **côté serveur** (laminas/laminas-mail, fourni par GLPI).
- **`.msg`** (Outlook classic) → lu **côté navigateur** par la bibliothèque `msg.reader`
  (Apache-2.0, **versionnée** dans `public/js/vendor/`). Ses champs sont envoyés au serveur pour
  le mapping/source ; ses pièces jointes sont rattachées côté client.

Le drag direct d'un mail depuis Outlook vers une page web n'est pas fiable (voir le CDC à la
racine) ; le dépôt d'un **fichier** (`.eml`/`.msg`) fonctionne dans tous les navigateurs. Pour le
nouveau Outlook / OWA, c'est la **Brique B (add-in Outlook)** qui prend le relais.

## Clé du plugin & déploiement

La **clé du plugin est `mail2glpi`** (utilisée dans les noms de fonctions/hooks). Le dossier
déployé dans GLPI **doit** s'appeler `mail2glpi`.

> ⚠️ Sur GLPI 11, déployez une **copie** du dossier (pas un lien symbolique) : la racine web
> est `…/glpi/public/` et Apache ne sert pas les fichiers statiques à travers un symlink.

### Déploiement automatique (recommandé)

Un script à la racine du dépôt fait tout (MAJ git → copie → droits → cache) :

```bash
bash deploy.sh
```

Variables optionnelles : `GLPI_ROOT` (défaut `/var/www/html/glpi`), `WEB_USER` (défaut
`www-data`), `GIT_REF` (défaut `origin/master`), `PULL` (`0` pour déployer le code local
sans git). Exemple : `GLPI_ROOT=/var/www/glpi bash deploy.sh`.

### Déploiement manuel

```bash
GLPIROOT=/var/www/html/glpi
sudo rm -rf "$GLPIROOT/plugins/mail2glpi"
sudo cp -r ./plugin "$GLPIROOT/plugins/mail2glpi"
sudo chown -R www-data:www-data "$GLPIROOT/plugins/mail2glpi"
sudo -u www-data php "$GLPIROOT/bin/console" cache:clear
```

Puis, dans GLPI : **Configuration > Plugins** → installer et activer « Mail2GLPI ».

## Structure

```
plugin/
  setup.php                    Init du plugin, hooks, rendu de la section dropzone
  hook.php                     Install / uninstall (sans état pour l'instant)
  composer.json                Autoload PSR-4 GlpiPlugin\Mail2glpi
  src/MailParser.php           Analyse MIME du .eml (laminas/laminas-mail fourni par GLPI)
  src/TicketMapper.php         Mapping e-mail -> champs ticket + assainissement HTML
  public/ajax/parse.php        Endpoint : reçoit le .eml, renvoie le mapping en JSON
  public/js/dropzone.js        Dropzone + pré-remplissage du formulaire
  public/css/dropzone.css      Styles de la dropzone
  locales/                     Catalogues de traduction (domaine mail2glpi)
```

> **GLPI 11** : les assets statiques et scripts PHP accessibles par le web doivent être sous
> `public/`. L'URL n'inclut pas `/public` (ex. `public/js/dropzone.js` →
> `/plugins/mail2glpi/js/dropzone.js`). Les scripts de `public/` sont initialisés par le routeur
> GLPI (pas d'`include inc/includes.php`).

## Sécurité

- L'endpoint `ajax/parse.php` exige un **utilisateur authentifié**, le **droit de créer des
  tickets** et un **jeton CSRF** valide.
- Seuls les fichiers **`.eml`** sont acceptés, avec une **limite de taille** (10 Mo) ; seul le
  fichier temporaire uploadé est lu (le nom de fichier client n'est jamais utilisé comme chemin).
- Pour le pré-remplissage, la description est produite en **texte échappé** (jamais de HTML
  brut non fiable injecté) afin de réduire la surface XSS. La préservation du HTML riche, avec
  assainissement complet, est repoussée à la V1.

## Pièces jointes & source

- Les **pièces jointes** de l'e-mail sont ajoutées à l'uploader du formulaire via la fonction
  GLPI `uploadFile()` (champs cachés `_filename[]` rattachés à la soumission). Plafonds :
  **5 Mo par pièce** et **10 Mo cumulés** ; au-delà, la pièce est ignorée (signalée à l'agent).
  Le contrôle des **types de fichiers** autorisés reste assuré par la politique de documents de
  GLPI à l'upload — ne pas la désactiver.
- La **source de la demande** est positionnée automatiquement sur « E-Mail »
  (`RequestType::getDefault('mail')`), comme le collecteur de mails natif.

## Suggestions IA (locales, optionnelles)

Au dépôt d'un e-mail, le plugin peut suggérer **catégorie**, **urgence** et **résumé** via un
**LLM local** (endpoint compatible OpenAI, ex. **Ollama**). **Aucune donnée n'est envoyée vers
le cloud** — seul l'endpoint local configuré est appelé.

- **Asynchrone & best-effort** : le formulaire se pré-remplit immédiatement ; les suggestions IA
  arrivent quelques secondes après (un seul appel renvoyant un JSON catégorie/urgence/résumé).
  Si l'IA est désactivée ou injoignable, le pré-remplissage de base est inchangé.
- **Configuration** : *Configuration > Plugins > Mail2GLPI > Configurer* — activer, renseigner
  l'URL de base (`http://IP-VM-IA:11434/v1`), le modèle (ex. `llama3.2:3b`), le délai, et une clé
  optionnelle. Stockée en base (`Config`, contexte `plugin:mail2glpi`).
- **Robustesse** : la catégorie est validée contre les catégories ITIL existantes (correspondance
  exacte, puis tolérante aux accents/casse, puis sur le nom de feuille pour les taxonomies
  hiérarchiques `A > B > Feuille`) ; l'urgence est normalisée en 1-5 (chiffre, flottant entier, ou
  mot FR/EN).
- **Sécurité** : appels serveur uniquement ; entrées bornées ; URL restreinte à http(s) (endpoint
  local) ; secret jamais réémis ni journalisé ; `ai_timeout` plafonné [5, 300] s ; `set_time_limit`
  borné (anti-DoS).
- **Mode debug / self-test** (option « Mode debug IA », **admin uniquement**) : la réponse de
  `enrich.php` inclut un objet `_debug` (config lue, `http_code`, erreur curl, contenu brut du
  modèle, JSON décodé) ; `ajax/enrich.php?selftest=1` exécute un exemple intégré — pour
  diagnostiquer la chaîne IA sans déposer d'e-mail.
- **Fichiers** : `src/AiClient.php` (client LLM), `src/AiText.php` (helpers purs testables),
  `public/ajax/enrich.php` (endpoint), `public/front/config.form.php` (config). Tests :
  `tests/AiTextTest.php` (`php tests/AiTextTest.php`).

## Reste à faire

- [x] ~~Demandeur = expéditeur (compte GLPI si connu, sinon par e-mail)~~ — fait (v0.6.0).
- [x] ~~Pièces jointes rattachées au ticket~~ — fait (images de signature inline filtrées).
- [x] ~~Source « E-Mail » automatique~~ — fait.
- [x] ~~Suggestions IA : catégorie, urgence, résumé (LLM local)~~ — fait (v0.7.0).
- [ ] Rattacher les **observateurs** (Cc) via le widget acteurs (`data-actor-type="observer"`).
- [ ] Affecter entité / catégorie / SLA par **règles** (sans IA).
- [ ] Fournir les catalogues de traduction (`locales/`).
