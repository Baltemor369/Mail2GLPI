# Mail2GLPI — Drag'n'Drop Mail → Ticket

Convertir un e-mail en **ticket GLPI** en un geste : glisser-déposer un fichier e-mail,
extraction automatique des informations et **pré-remplissage des champs**, l'agent n'ayant
« plus qu'à valider ».

> **État du projet** (voir [`CHANGELOG.md`](./CHANGELOG.md)) :
> - **Brique A — Plugin GLPI** : ✅ **fonctionnel**. Dépôt d'un `.eml`/`.msg` → pré-remplit
>   titre, description, source « E-Mail », pièces jointes (images de signature filtrées) et
>   demandeur (compte GLPI si l'adresse est connue, sinon par e-mail). Reste à faire :
>   observateurs (Cc), règles d'affectation (entité/catégorie/SLA), traductions.
> - **Brique B — Add-in Outlook** : 🚧 **squelette** (non encore validé ; nécessite un GLPI en
>   HTTPS et un tenant M365 de test).

## Pourquoi deux briques ?

Le « glisser un mail directement dans une page web » n'est pas universel — c'est la contrainte
structurante du projet :

| Source | Comportement navigateur | Solution |
|---|---|---|
| Nouveau Outlook / Outlook Web (OWA) | Microsoft a **retiré** le drag de mails vers les apps web | **Add-in Outlook** (obligatoire) |
| Outlook classique → Chrome/Edge | Fichier `.msg` virtuel parfois exposé (instable) | Plugin GLPI (best-effort) ou Add-in |
| Outlook classique → Firefox | `DataTransfer` vide | Add-in / fallback fichier `.eml` |
| Fichier `.eml`/`.msg` enregistré puis glissé | Drop de fichier standard | Plugin GLPI (fallback universel) |

Le projet livre donc **deux briques complémentaires** :

- **Brique A — Plugin GLPI** : dropzone dans GLPI, parse les fichiers `.eml`/`.msg` côté
  serveur et pré-remplit le formulaire de création de ticket.
- **Brique B — Add-in Outlook** (Office.js) : bouton/volet « Créer un ticket GLPI » dans
  Outlook (classique, nouveau, web) ; lit le mail et appelle l'API REST GLPI. Seule voie
  fiable pour le nouveau Outlook / OWA.

## Cible & périmètre

- **GLPI 11.x auto-hébergé** (les plugins tiers sont nécessaires → exclut le Cloud SaaS).
- Clients : Outlook classique, nouveau Outlook, Outlook Web (M365).
- Mapping : titre, description, **demandeur par e-mail**, observateurs (Cc), pièces jointes,
  affectation entité/catégorie/urgence/SLA par règles.
- Authentification API : **jeton GLPI par utilisateur** (app-token + user-token).

## Documentation

- [`docs/INSTALLATION.md`](./docs/INSTALLATION.md) — **Guide d'installation** du plugin (admin).
- [`docs/UTILISATION.md`](./docs/UTILISATION.md) — **Guide d'utilisation** au quotidien (agents).
- [`plugin/README.md`](./plugin/README.md) — Détails techniques de la Brique A (plugin GLPI).
- [`addin/README.md`](./addin/README.md) — Détails techniques de la Brique B (add-in Outlook).
- [`CDC.md`](./CDC.md) — Cahier des Charges (périmètre, exigences, architecture, phasage, risques).
- [`CHANGELOG.md`](./CHANGELOG.md) — Historique des versions.

## Feuille de route

| État | Contenu |
|---|---|
| ✅ Fait | Plugin GLPI : dropzone `.eml`/`.msg`, pré-remplissage (titre, description, source, pièces jointes, demandeur), script de déploiement |
| ▶️ En cours / à suivre | Observateurs (Cc) ; règles d'affectation (entité/catégorie/urgence/SLA) ; traductions FR/EN |
| 🚧 À valider | Add-in Outlook (Brique B) — nécessite GLPI en HTTPS + tenant M365 de test |

## Licence

À définir.
