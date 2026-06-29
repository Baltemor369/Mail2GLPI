#!/usr/bin/env bash
#
# deploy.sh — Met à jour et déploie le plugin GLPI « mail2glpi » (Brique A).
#
# Usage (depuis le dépôt, ex. /opt/Mail2GLPI) :
#     bash deploy.sh
#
# Étapes : met à jour le dépôt git, copie le plugin dans GLPI, applique les droits
# au serveur web, puis vide le cache GLPI.
#
# Variables d'environnement (toutes optionnelles) :
#     GLPI_ROOT   Racine de l'installation GLPI         (défaut: /var/www/html/glpi)
#     WEB_USER    Utilisateur du serveur web            (défaut: www-data)
#     GIT_REF     Référence git à déployer              (défaut: origin/master)
#     PULL        1 = met à jour le dépôt, 0 = ignore   (défaut: 1)
#
# Exemples :
#     GLPI_ROOT=/var/www/glpi bash deploy.sh
#     GIT_REF=v0.6.0 bash deploy.sh        # déployer une version précise
#     PULL=0 bash deploy.sh                # déployer le code local sans git

set -euo pipefail

GLPI_ROOT="${GLPI_ROOT:-/var/www/html/glpi}"
WEB_USER="${WEB_USER:-www-data}"
GIT_REF="${GIT_REF:-origin/master}"
PULL="${PULL:-1}"

# Dossier du dépôt = emplacement de ce script.
REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_SRC="$REPO_DIR/plugin"
PLUGIN_DEST="$GLPI_ROOT/plugins/mail2glpi"

info()  { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
err()   { printf '\033[1;31mERREUR:\033[0m %s\n' "$*" >&2; }

# --- Vérifications de sûreté ------------------------------------------------
[ -f "$PLUGIN_SRC/setup.php" ] || { err "Plugin introuvable dans $PLUGIN_SRC (lancez le script depuis le dépôt)."; exit 1; }
[ -d "$GLPI_ROOT/plugins" ]    || { err "$GLPI_ROOT/plugins introuvable — réglez GLPI_ROOT (ex: GLPI_ROOT=/var/www/glpi bash deploy.sh)."; exit 1; }
[ -f "$GLPI_ROOT/bin/console" ] || { err "$GLPI_ROOT/bin/console introuvable — GLPI_ROOT semble incorrect."; exit 1; }

# Garde-fou : la destination DOIT se terminer par /plugins/mail2glpi (avant tout rm -rf).
case "$PLUGIN_DEST" in
    */plugins/mail2glpi) : ;;
    *) err "Destination inattendue: $PLUGIN_DEST — abandon par sécurité."; exit 1 ;;
esac

info "Déploiement Mail2GLPI"
echo  "    dépôt     : $REPO_DIR"
echo  "    GLPI      : $GLPI_ROOT"
echo  "    web user  : $WEB_USER"

# --- 1) Mise à jour du dépôt -----------------------------------------------
if [ "$PULL" = "1" ]; then
    info "Mise à jour du dépôt ($GIT_REF)"
    # S'assure que l'utilisateur courant possède le dépôt (évite les erreurs git "permission denied").
    if [ "$(stat -c %u "$REPO_DIR")" != "$(id -u)" ]; then
        info "Correction du propriétaire du dépôt"
        sudo chown -R "$(id -u):$(id -g)" "$REPO_DIR"
    fi
    git -C "$REPO_DIR" fetch --tags --prune origin
    git -C "$REPO_DIR" reset --hard "$GIT_REF"
fi

VERSION="$(grep -oE "[0-9]+\.[0-9]+\.[0-9]+" "$PLUGIN_SRC/setup.php" | head -1 || true)"
info "Version à déployer : ${VERSION:-inconnue}"

# Avertissement si la lib .msg manque (sinon seul le .eml fonctionnera).
if [ ! -f "$PLUGIN_SRC/public/js/vendor/msg.reader.js" ]; then
    err "Lib .msg absente ($PLUGIN_SRC/public/js/vendor/msg.reader.js) — le .msg ne fonctionnera pas."
fi

# --- 2) Déploiement ---------------------------------------------------------
info "Copie du plugin vers $PLUGIN_DEST"
sudo rm -rf "$PLUGIN_DEST"
sudo cp -r "$PLUGIN_SRC" "$PLUGIN_DEST"
sudo chown -R "$WEB_USER:$WEB_USER" "$PLUGIN_DEST"

# --- 3) Vidage du cache GLPI ------------------------------------------------
info "Vidage du cache GLPI (utilisateur $WEB_USER)"
sudo -u "$WEB_USER" php "$GLPI_ROOT/bin/console" cache:clear

# --- 4) Réactivation du plugin ----------------------------------------------
# GLPI désactive le plugin à chaque changement de version : on relance la mise à jour
# (install) puis l'activation. Best-effort (|| true) : en cas d'échec, réactiver via l'UI
# (Configuration > Plugins). L'option --username couvre les commandes qui l'exigent.
info "Réactivation du plugin mail2glpi"
sudo -u "$WEB_USER" php "$GLPI_ROOT/bin/console" glpi:plugin:install --username="${GLPI_USER:-glpi}" mail2glpi 2>/dev/null \
    || sudo -u "$WEB_USER" php "$GLPI_ROOT/bin/console" glpi:plugin:install mail2glpi 2>/dev/null || true
sudo -u "$WEB_USER" php "$GLPI_ROOT/bin/console" glpi:plugin:activate mail2glpi 2>/dev/null || true

info "Terminé — plugin mail2glpi v${VERSION:-?} déployé. Pensez à recharger la page (Ctrl+F5)."
echo "    Si le plugin est marqué « à mettre à jour » dans Configuration > Plugins, réactivez-le là."
