# Mail2GLPI — Plugin GLPI (Brique A)

Plugin GLPI 11 qui ajoute une **zone de dépôt** dans le formulaire de création de ticket :
on y glisse un fichier e-mail **`.eml`**, le plugin l'analyse côté serveur et **pré-remplit**
le formulaire (titre, description), l'agent n'ayant plus qu'à valider.

> 🚧 **Squelette / V1 en cours.** Le parcours « déposer un `.eml` → analyse serveur →
> pré-remplissage titre + description » est en place. Le rattachement du **demandeur par
> e-mail**, des **observateurs** et l'**upload des pièces jointes** sont laissés en `TODO`
> (à finaliser avec une instance GLPI 11 de test).

## Pourquoi `.eml` et pas le drag direct depuis Outlook ?

Le drag direct d'un mail depuis Outlook vers une page web n'est pas fiable (voir le CDC à la
racine). Le dépôt d'un **fichier `.eml`** fonctionne dans tous les navigateurs. Pour le nouveau
Outlook / OWA, c'est la **Brique B (add-in Outlook)** qui prend le relais.

## Clé du plugin & déploiement

La **clé du plugin est `mail2glpi`** (utilisée dans les noms de fonctions/hooks). Le dossier
déployé dans GLPI **doit** s'appeler `mail2glpi` :

```bash
# Exemple : lien symbolique depuis ce dépôt vers l'installation GLPI
ln -s /chemin/vers/Mail2GLPI/plugin /chemin/vers/glpi/plugins/mail2glpi
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

## Reste à faire (V1 & V2)

- [ ] Rattacher le demandeur par e-mail et les observateurs (widgets « acteurs » GLPI).
- [ ] Affecter entité / catégorie / urgence / SLA par **règles** (V2).
- [ ] Fournir les catalogues de traduction (`locales/`).
- [ ] Support du format `.msg` (Outlook) derrière la même interface de parsing.
