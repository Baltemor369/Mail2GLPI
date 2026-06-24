# Bibliothèques tierces (vendor)

La lecture des fichiers **`.msg` (Outlook)** côté navigateur s'appuie sur la bibliothèque
**[ykarpovich/msg.reader](https://github.com/ykarpovich/msg.reader)** — licence **Apache-2.0**.

Deux fichiers (navigateur, sans build) sont attendus **ici** :

| Fichier            | Rôle                                            |
|--------------------|-------------------------------------------------|
| `DataStream.js`    | Lecture binaire bas niveau (dépendance)         |
| `msg.reader.js`    | Parseur `.msg` → `MSGReader` (objet global)     |

Ces fichiers sont **vendorisés (committés)** dans le dépôt, avec leur licence Apache-2.0
(`LICENSE`). Le déploiement « copier le dossier » fonctionne donc tel quel, y compris hors-ligne.

## Mettre à jour les fichiers

Source : <https://github.com/ykarpovich/msg.reader>. Pour rafraîchir la bibliothèque :

```bash
cd <ce_dossier_vendor>
curl -L -o DataStream.js  https://cdn.jsdelivr.net/gh/ykarpovich/msg.reader@master/DataStream.js
curl -L -o msg.reader.js  https://cdn.jsdelivr.net/gh/ykarpovich/msg.reader@master/msg.reader.js
```

(Pour un déploiement reproductible, préférez épingler un commit précis plutôt que `@master`.)

## Limitations connues

- Sur certains `.msg` issus d'Exchange, l'adresse de l'expéditeur (`senderEmail`) peut être un
  nom X.500 (`/O=…/CN=…`) plutôt qu'une adresse SMTP. Sans impact sur titre/description/PJ ;
  l'affectation du demandeur (TODO) en tiendra compte.
- Les messages imbriqués (un `.msg` en pièce jointe d'un `.msg`) ne sont pas pris en charge.
