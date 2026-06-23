# Traductions

Les chaînes du plugin utilisent les fonctions de traduction GLPI (`__()`, `_n()`) avec le
domaine `mail2glpi`.

Pour générer les catalogues :

1. Extraire les chaînes vers `mail2glpi.pot` (ex. via `xgettext` ou l'outil GLPI dédié).
2. Créer les fichiers `fr_FR.po` / `en_GB.po`, les traduire, puis les compiler en `.mo`.

> Aucun catalogue n'est encore fourni : GLPI affiche alors les chaînes par défaut (français,
> telles qu'écrites dans le code).
