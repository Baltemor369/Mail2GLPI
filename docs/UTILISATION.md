# Mail2GLPI — Guide d'utilisation (agents)

Mail2GLPI permet de **convertir un e-mail en ticket GLPI en un geste** : on glisse le fichier
de l'e-mail sur le formulaire de création de ticket, et les champs se **pré-remplissent**
automatiquement. Il ne reste plus qu'à vérifier et valider.

---

## 1. Obtenir le fichier de l'e-mail

Le plugin accepte deux formats de fichier :

- **`.msg`** — depuis **Outlook (application Windows)** : faites simplement glisser l'e-mail
  depuis Outlook vers votre **Bureau** (ou un dossier). Windows crée un fichier `.msg`.
- **`.eml`** — depuis un webmail : utilisez « Télécharger le message » / « Afficher la source »
  selon le client.

> Pourquoi un fichier et pas un glisser direct depuis Outlook ? Le glisser direct d'un mail
> dans une page web n'est pas fiable selon les navigateurs/clients ; passer par le fichier
> fonctionne partout. (Pour le nouveau Outlook / Outlook Web, un complément dédié est prévu.)

---

## 2. Créer le ticket

1. Ouvrez un **nouveau ticket** dans GLPI (**Assistance > Tickets > +**).
2. Repérez la zone en pointillés : **« Glissez ici un e-mail (.eml ou .msg)… »**.
3. **Glissez-déposez** votre fichier `.msg`/`.eml` sur cette zone.
4. Un message vert confirme : *« Ticket pré-rempli… »*.
5. **Vérifiez** les champs pré-remplis, ajustez si besoin, puis cliquez sur **Créer**.

---

## 3. Ce qui est pré-rempli automatiquement

| Champ du ticket | Renseigné à partir de… |
|---|---|
| **Titre** | L'objet de l'e-mail |
| **Description** | Le corps de l'e-mail (converti en texte) |
| **Source de la demande** | « E-Mail » |
| **Demandeur** | L'expéditeur : son **compte GLPI** si l'adresse est connue, sinon en **demandeur par e-mail** (notifications activées) |
| **Pièces jointes** | Les fichiers joints à l'e-mail (rattachés au ticket) |
| **Catégorie · Urgence · Résumé** *(si l'IA est activée)* | Suggérés par une **IA locale** quelques secondes après le dépôt — voir §4 bis |

---

## 3 bis. Suggestions IA (si activées par l'administrateur)

Si votre administrateur a activé l'IA, quelques secondes après le dépôt vous verrez **en plus** :

- une **catégorie** proposée (si elle correspond à une catégorie existante),
- une **urgence** positionnée,
- un bloc **« Résumé (IA) »** ajouté **en tête** de la description.

Le statut sous la zone l'indique : *« … · IA : catégorie + urgence + résumé ajouté(s) »*.

> Ce sont des **suggestions** : vérifiez-les et corrigez-les si besoin avant de créer le ticket.
> L'IA est **best-effort** et **100 % locale** (aucune donnée envoyée à l'extérieur) : si elle
> est indisponible, le reste du pré-remplissage fonctionne normalement, sans suggestion.

---

## 4. Bon à savoir

- **Signatures** : les images de signature (logos) ne sont **pas** ajoutées comme pièces
  jointes — seules les vraies pièces jointes le sont.
- **Pièces jointes volumineuses** : une pièce de plus de **5 Mo** (ou au-delà de 10 Mo cumulés)
  est ignorée ; le message de confirmation vous le signale (« …ignorée(s) »).
- **Demandeur sans compte** : si l'expéditeur n'a pas de compte GLPI, il est tout de même
  ajouté comme demandeur via son adresse e-mail (il recevra les notifications).
- **Rien n'est créé sans vous** : le dépôt ne fait que **pré-remplir** le formulaire ; le
  ticket n'est créé que lorsque vous cliquez sur **Créer**.
- **Observateurs (Cc)** : pas encore repris automatiquement (à venir) — ajoutez-les
  manuellement si nécessaire.

---

## 5. Messages et dépannage

| Message / situation | Signification | Que faire |
|---|---|---|
| « Format non pris en charge… » | Le fichier n'est ni `.eml` ni `.msg` | Enregistrez l'e-mail au bon format (cf. §1) |
| « Lecteur .msg indisponible » | Composant `.msg` non chargé côté serveur | Prévenez votre administrateur |
| « …pièce(s) jointe(s) ignorée(s) » | Pièce(s) trop volumineuse(s) | Ajoutez le fichier manuellement au ticket |
| Le demandeur n'apparaît pas | Cas particulier du formulaire | Ajoutez le demandeur manuellement ; signalez-le à l'administrateur |
| « Réponse inattendue du serveur… » | Session expirée | Rechargez la page (F5) et reconnectez-vous si besoin |

En cas de blocage persistant, communiquez à votre administrateur le message affiché sous la
zone de dépôt et, si possible, une copie d'écran de la console du navigateur (touche **F12**).
