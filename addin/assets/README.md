# Icônes de l'add-in

Le manifeste référence des icônes PNG qui doivent être présentes dans ce dossier (elles sont
copiées telles quelles dans `dist/assets` par webpack).

Tailles requises (PNG, fond transparent) :

| Fichier | Taille | Usage |
|---|---|---|
| `icon-16.png` | 16×16 | Ruban (petit) |
| `icon-32.png` | 32×32 | Ruban (moyen) |
| `icon-64.png` | 64×64 | `IconUrl` du manifeste |
| `icon-80.png` | 80×80 | Ruban (grand) |
| `icon-128.png` | 128×128 | `HighResolutionIconUrl` |

> ⚠️ Ces fichiers ne sont pas encore fournis (placeholders à créer). Tant qu'ils sont absents,
> les icônes du ruban s'afficheront vides mais l'add-in reste fonctionnel.
