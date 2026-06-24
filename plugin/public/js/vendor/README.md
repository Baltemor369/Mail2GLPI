# Bibliothèques tierces (vendor)

La lecture des fichiers **`.msg` (Outlook)** côté navigateur s'appuie sur la bibliothèque
**[ykarpovich/msg.reader](https://github.com/ykarpovich/msg.reader)** — licence **Apache-2.0**.

Deux fichiers (navigateur, sans build) sont attendus **ici** :

| Fichier            | Rôle                                            |
|--------------------|-------------------------------------------------|
| `DataStream.js`    | Lecture binaire bas niveau (dépendance)         |
| `msg.reader.js`    | Parseur `.msg` → `MSGReader` (objet global)     |

> ⚠️ Ces fichiers ne sont **pas** committés par défaut (choix de chaîne d'approvisionnement :
> c'est à vous de décider d'introduire ce code tiers). Tant qu'ils sont absents, le `.eml`
> continue de fonctionner ; seul le `.msg` affichera « Lecteur .msg indisponible ».

## Récupérer les fichiers

```bash
cd <ce_dossier_vendor>
curl -L -o DataStream.js  https://cdn.jsdelivr.net/gh/ykarpovich/msg.reader@master/DataStream.js
curl -L -o msg.reader.js  https://cdn.jsdelivr.net/gh/ykarpovich/msg.reader@master/msg.reader.js
```

(Pour un déploiement reproductible, épinglez un commit précis plutôt que `@master`, et/ou
committez ces fichiers dans votre dépôt.)

## Limitations connues

- Sur certains `.msg` issus d'Exchange, l'adresse de l'expéditeur (`senderEmail`) peut être un
  nom X.500 (`/O=…/CN=…`) plutôt qu'une adresse SMTP. Sans impact sur titre/description/PJ ;
  l'affectation du demandeur (TODO) en tiendra compte.
- Les messages imbriqués (un `.msg` en pièce jointe d'un `.msg`) ne sont pas pris en charge.
