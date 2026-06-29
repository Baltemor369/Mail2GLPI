<?php

/**
 * Mail2GLPI — Brique A : plugin GLPI.
 *
 * Injecte une zone de dépôt (dropzone) dans le formulaire de création de ticket pour
 * convertir un fichier e-mail (.eml) glissé en pré-remplissage du formulaire.
 */

define('PLUGIN_MAIL2GLPI_VERSION', '0.7.3');
define('PLUGIN_MAIL2GLPI_MIN_GLPI_VERSION', '11.0.0');
define('PLUGIN_MAIL2GLPI_MAX_GLPI_VERSION', '11.1.99');

/**
 * Initialise le plugin : enregistre les hooks. Appelée sur toutes les pages GLPI.
 *
 * @return void
 */
function plugin_init_mail2glpi()
{
    /** @var array $PLUGIN_HOOKS */
    global $PLUGIN_HOOKS;

    // Le plugin gère lui-même ses jetons CSRF (endpoint AJAX).
    $PLUGIN_HOOKS['csrf_compliant']['mail2glpi'] = true;

    // Assets front. Le script vérifie lui-même qu'on est sur un formulaire de ticket.
    // Les libs vendor (lecture des .msg Outlook) doivent être chargées AVANT dropzone.js.
    $PLUGIN_HOOKS['add_javascript']['mail2glpi'] = [
        'js/vendor/DataStream.js',
        'js/vendor/msg.reader.js',
        'js/dropzone.js',
    ];
    $PLUGIN_HOOKS['add_css']['mail2glpi'] = ['css/dropzone.css'];

    // Ancre la dropzone dans la fiche d'un objet ITIL (ticket) — GLPI 11.
    $PLUGIN_HOOKS['post_itil_info_section']['mail2glpi'] = 'plugin_mail2glpi_itil_section';

    // Page de configuration (lien « Configurer » depuis la liste des plugins).
    $PLUGIN_HOOKS['config_page']['mail2glpi'] = 'front/config.form.php';
}

/**
 * Métadonnées du plugin.
 *
 * @return array<string, mixed>
 */
function plugin_version_mail2glpi()
{
    return [
        'name'           => 'Mail2GLPI',
        'version'        => PLUGIN_MAIL2GLPI_VERSION,
        'author'         => 'Mail2GLPI',
        'license'        => 'GPL-3.0-or-later',
        'homepage'       => 'https://github.com/Baltemor369/Mail2GLPI',
        'requirements'   => [
            'glpi' => [
                'min' => PLUGIN_MAIL2GLPI_MIN_GLPI_VERSION,
                'max' => PLUGIN_MAIL2GLPI_MAX_GLPI_VERSION,
            ],
        ],
    ];
}

/**
 * Vérifie les prérequis avant installation (version de GLPI).
 *
 * @return bool
 */
function plugin_mail2glpi_check_prerequisites()
{
    if (version_compare(GLPI_VERSION, PLUGIN_MAIL2GLPI_MIN_GLPI_VERSION, '<')) {
        echo sprintf(
            'This plugin requires GLPI >= %s',
            PLUGIN_MAIL2GLPI_MIN_GLPI_VERSION
        );
        return false;
    }
    if (version_compare(GLPI_VERSION, PLUGIN_MAIL2GLPI_MAX_GLPI_VERSION, '>')) {
        echo sprintf(
            'This plugin requires GLPI <= %s',
            PLUGIN_MAIL2GLPI_MAX_GLPI_VERSION
        );
        return false;
    }
    return true;
}

/**
 * Vérifie la configuration runtime du plugin.
 *
 * @param bool $verbose
 * @return bool
 */
function plugin_mail2glpi_check_config($verbose = false)
{
    // Aucune configuration obligatoire à ce stade (parsing sans état).
    return true;
}

/**
 * Rendu de la section ITIL : conteneur de la dropzone, affiché uniquement à la création
 * d'un ticket. Le comportement (drop, parsing, pré-remplissage) est câblé par dropzone.js.
 *
 * @param array{item?: object, options?: array} $params
 * @return void
 */
function plugin_mail2glpi_itil_section(array $params)
{
    $item = $params['item'] ?? null;

    // On ne propose la dropzone que sur un nouveau Ticket.
    if (!($item instanceof \Ticket) || !$item->isNewItem()) {
        return;
    }

    echo <<<HTML
        <section class="mail2glpi-section">
            <div id="mail2glpi-dropzone" class="mail2glpi-dropzone" tabindex="0">
                <span class="mail2glpi-dropzone__label">
                    Glissez ici un e-mail (.eml ou .msg) pour pré-remplir le ticket
                </span>
                <p class="mail2glpi-dropzone__status" role="status" aria-live="polite"></p>
            </div>
        </section>
        HTML;
}
