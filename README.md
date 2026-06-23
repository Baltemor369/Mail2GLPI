# Mail2GLPI — Drag'n'Drop Mail → Ticket

Convertir un e-mail en **ticket GLPI** en un geste : glisser-déposer (ou un clic depuis
Outlook), extraction automatique des informations et **pré-remplissage des champs**, l'agent
n'ayant « plus qu'à valider ».

> ⚠️ **Projet en phase de cadrage.** À ce stade, le dépôt contient l'étude de faisabilité et le
> Cahier des Charges. Le code (plugin GLPI + add-in Outlook) n'est pas encore implémenté.

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

- [`CDC.md`](./CDC.md) — Cahier des Charges (périmètre, exigences, architecture, phasage, risques).
- [`CHANGELOG.md`](./CHANGELOG.md) — Historique des versions.

## Feuille de route

| Phase | Contenu |
|---|---|
| **POC / Spikes** | Lever les risques : drag Outlook, add-in Office.js, pré-remplissage formulaire GLPI 11. |
| **MVP** | Add-in Outlook (3 clients) + mapping de base + auth jeton/utilisateur. |
| **V1** | Plugin GLPI : dropzone `.eml` + pré-remplissage du formulaire. |
| **V2** | Règles de mapping, observateurs Cc, support `.msg`, configuration avancée. |

## Licence

À définir.
