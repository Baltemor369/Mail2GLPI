<?php

/**
 * Hooks d'installation / désinstallation du plugin Mail2GLPI.
 *
 * À l'installation, on initialise la configuration IA (stockée en base via Config). Le parsing
 * des e-mails reste « sans état » (aucune table propre) ; seule la config IA est persistée.
 */

/** Contexte et valeurs par défaut de la configuration du plugin. */
const MAIL2GLPI_CONFIG_CONTEXT = 'plugin:mail2glpi';
const MAIL2GLPI_CONFIG_DEFAULTS = [
    'ai_enabled'  => '0',
    'ai_base_url' => '',
    'ai_model'    => 'llama3.2:3b',
    'ai_timeout'  => '60',
    'ai_api_key'  => '',
    'ai_debug'    => '0',
];

/**
 * Installation du plugin : initialise la config IA sans écraser des valeurs existantes
 * (utile lors d'une réinstallation/mise à jour).
 *
 * @return bool
 */
function plugin_mail2glpi_install()
{
    $existing = Config::getConfigurationValues(MAIL2GLPI_CONFIG_CONTEXT, array_keys(MAIL2GLPI_CONFIG_DEFAULTS));

    $to_set = [];
    foreach (MAIL2GLPI_CONFIG_DEFAULTS as $key => $default) {
        if (!array_key_exists($key, $existing)) {
            $to_set[$key] = $default;
        }
    }
    if ($to_set !== []) {
        Config::setConfigurationValues(MAIL2GLPI_CONFIG_CONTEXT, $to_set);
    }

    return true;
}

/**
 * Désinstallation : supprime la configuration du plugin.
 *
 * @return bool
 */
function plugin_mail2glpi_uninstall()
{
    Config::deleteConfigurationValues(MAIL2GLPI_CONFIG_CONTEXT, array_keys(MAIL2GLPI_CONFIG_DEFAULTS));
    return true;
}
