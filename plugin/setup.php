<?php

/**
 * Mail2GLPI — Brique A : plugin GLPI.
 *
 * Injecte une zone de dépôt (dropzone) dans le formulaire de création de ticket pour
 * convertir un fichier e-mail (.eml) glissé en pré-remplissage du formulaire.
 */

define('PLUGIN_MAIL2GLPI_VERSION', '1.1.0');
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
 * Renvoie le dictionnaire de traduction pour la langue de l'utilisateur GLPI (anglais par défaut ;
 * toute langue commençant par « fr » bascule en français). Voir locales/strings.php.
 *
 * @return array<string, string>
 */
function mail2glpi_i18n()
{
    /** @var array<string, array<string, string>> $strings */
    $strings = include __DIR__ . '/locales/strings.php';
    $lang    = (string) ($_SESSION['glpilanguage'] ?? 'en_GB');
    $code    = stripos($lang, 'fr') === 0 ? 'fr' : 'en';
    return $strings[$code] ?? $strings['en'];
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

    $t = mail2glpi_i18n();

    // Case « Suggestions IA » : affichée uniquement si l'IA est activée ET configurée côté serveur.
    // Cochée par défaut ; si l'agent la décoche, le dépôt pré-remplit sans appeler le LLM.
    $cfg = Config::getConfigurationValues('plugin:mail2glpi', ['ai_enabled', 'ai_base_url', 'ai_model']);
    $ai_ready = ($cfg['ai_enabled'] ?? '0') === '1'
        && trim((string) ($cfg['ai_base_url'] ?? '')) !== ''
        && trim((string) ($cfg['ai_model'] ?? '')) !== '';

    $label       = htmlspecialchars($t['dropzone_label'], ENT_QUOTES, 'UTF-8');
    $toggle_text = htmlspecialchars($t['ai_toggle_label'], ENT_QUOTES, 'UTF-8');
    $ai_toggle   = '';
    if ($ai_ready) {
        $ai_toggle = <<<TOGGLE
            <label class="mail2glpi-ai-toggle">
                    <input type="checkbox" id="mail2glpi-ai-toggle" checked>
                    {$toggle_text}
                </label>
TOGGLE;
    }

    // Dictionnaire transmis au JS via un attribut data- (lu par dropzone.js). On évite un <script>
    // inline, susceptible d'être bloqué par la CSP de GLPI 11. L'attribut est échappé pour le HTML.
    $i18n_attr = htmlspecialchars((string) json_encode($t, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');

    echo <<<HTML
        <section class="mail2glpi-section">
            <div id="mail2glpi-dropzone" class="mail2glpi-dropzone" tabindex="0"
                 data-mail2glpi-i18n="{$i18n_attr}">
                <span class="mail2glpi-dropzone__label">{$label}</span>
                <p class="mail2glpi-dropzone__status" role="status" aria-live="polite"></p>
            </div>
            {$ai_toggle}
        </section>
        HTML;
}
