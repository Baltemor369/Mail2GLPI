<?php

/**
 * Hooks d'installation / désinstallation du plugin Mail2GLPI.
 *
 * Le plugin est pour l'instant « sans état » (parsing à la volée, aucune donnée persistée),
 * donc l'installation ne crée pas de table. Les futures fonctionnalités (règles de mapping
 * entité/catégorie/SLA) ajouteront ici les migrations correspondantes via la classe Migration.
 */

/**
 * Installation du plugin.
 *
 * @return bool
 */
function plugin_mail2glpi_install()
{
    // TODO (V2) : créer les tables de configuration des règles de mapping via Migration.
    return true;
}

/**
 * Désinstallation du plugin.
 *
 * @return bool
 */
function plugin_mail2glpi_uninstall()
{
    // TODO (V2) : supprimer les tables créées à l'installation.
    return true;
}
