<?php

/**
 * Endpoint AJAX d'enrichissement IA (asynchrone, best-effort).
 *
 * Appelé par dropzone.js APRÈS le pré-remplissage de base, pour ne pas faire attendre l'agent.
 * À partir du sujet + corps de l'e-mail, interroge le LLM **local** (cf. AiClient) et renvoie
 * une suggestion de catégorie (validée contre les catégories existantes), d'urgence et un résumé.
 *
 * Confidentialité : les données ne sont envoyées qu'à l'endpoint local configuré (jamais au cloud).
 * Robustesse : toute erreur ou IA désactivée -> réponse JSON vide ({}), sans bloquer le ticket.
 *
 * Mode debug (config `ai_debug`) : ajoute un objet `_debug` à la réponse (état config, http_code,
 * erreur curl, contenu brut du modèle, JSON décodé…). Self-test admin : `?selftest=1` (GET) exécute
 * un exemple intégré et renvoie le `_debug` — pour diagnostiquer sans avoir à déposer un e-mail.
 */

use GlpiPlugin\Mail2glpi\AiClient;
use GlpiPlugin\Mail2glpi\AiText;

header('Content-Type: application/json; charset=utf-8');

/** Longueurs max envoyées au modèle (anti-DoS / perf). */
if (!defined('MAIL2GLPI_AI_MAX_SUBJECT')) {
    define('MAIL2GLPI_AI_MAX_SUBJECT', 500);
}
if (!defined('MAIL2GLPI_AI_MAX_BODY')) {
    define('MAIL2GLPI_AI_MAX_BODY', 8000);
}
/** Nombre max de catégories proposées au modèle. */
if (!defined('MAIL2GLPI_AI_MAX_CATEGORIES')) {
    define('MAIL2GLPI_AI_MAX_CATEGORIES', 300);
}

/**
 * Renvoie la réponse JSON (en y joignant `_debug` si le mode debug est actif) et arrête le script.
 *
 * @param array<string,mixed> $out
 * @param bool                 $debug_on
 * @param array<string,mixed>  $debug
 * @return never
 */
function mail2glpi_out(array $out, bool $debug_on, array $debug = [])
{
    if ($debug_on) {
        $out['_debug'] = $debug;
    }
    echo json_encode($out);
    exit;
}

$debug_on = false;

try {
    Session::checkLoginUser();

    $is_admin   = Session::haveRight('config', UPDATE);
    $can_create = Session::haveRight('ticket', CREATE);

    $config = Config::getConfigurationValues('plugin:mail2glpi', [
        'ai_enabled', 'ai_base_url', 'ai_model', 'ai_timeout', 'ai_api_key', 'ai_debug',
    ]);
    // _debug ne doit être renvoyé qu'à un admin : il révèle l'IP interne du LLM (base_url) et le
    // contenu brut du modèle. On le réserve donc à config/UPDATE, même quand ai_debug est actif.
    $debug_on = (($config['ai_debug'] ?? '0') === '1') && $is_admin;

    // L'inférence locale (surtout à froid) peut être longue : on relève la limite PHP au timeout
    // configuré + une marge, sans la supprimer totalement (garde-fou anti-DoS).
    $ai_timeout = min(300, max(5, (int) ($config['ai_timeout'] ?? 60)));
    @set_time_limit($ai_timeout + 15);

    // Self-test : réservé à un admin avec le debug actif (?selftest=1). Renvoie toujours le _debug.
    $selftest = isset($_GET['selftest']) && $is_admin && $debug_on;

    if (!$can_create && !$selftest) {
        mail2glpi_out([], $debug_on, ['stop' => 'no_ticket_create_right']);
    }
    if (($config['ai_enabled'] ?? '0') !== '1' && !$selftest) {
        mail2glpi_out([], $debug_on, ['stop' => 'ai_disabled']);
    }

    if ($selftest) {
        $subject = 'Imprimante en panne au 2e étage';
        $body    = "Bonjour, l'imprimante du 2e étage affiche une erreur depuis ce matin, "
            . "plus personne ne peut imprimer. Merci de créer un ticket.";
    } else {
        $subject = mb_substr(trim((string) ($_POST['subject'] ?? '')), 0, MAIL2GLPI_AI_MAX_SUBJECT);
        $body    = mb_substr(trim((string) ($_POST['body'] ?? '')), 0, MAIL2GLPI_AI_MAX_BODY);
    }
    if ($subject === '' && $body === '') {
        mail2glpi_out([], $debug_on, ['stop' => 'empty_input']);
    }

    // Catégories ITIL existantes (restreintes à l'entité courante par find()). Tri explicite pour
    // que le sous-ensemble exposé (plafond MAIL2GLPI_AI_MAX_CATEGORIES) soit déterministe.
    $categories  = []; // nom complet exact -> id
    $cat_by_norm = []; // nom complet normalisé (accents/casse) -> ['id'=>…, 'name'=>…]
    $cat_by_leaf = []; // nom de feuille normalisé -> ['id'=>…, 'name'=>…] (null si homonyme ambigu)
    $itil = new ITILCategory();
    foreach ($itil->find([], ['completename ASC'], MAIL2GLPI_AI_MAX_CATEGORIES) as $row) {
        $name = trim((string) ($row['completename'] ?? $row['name'] ?? ''));
        if ($name === '' || isset($categories[$name])) {
            continue;
        }
        $id = (int) $row['id'];
        $categories[$name] = $id;
        $norm = AiText::normalize($name);
        if (!isset($cat_by_norm[$norm])) {
            $cat_by_norm[$norm] = ['id' => $id, 'name' => $name];
        }
        // Repli sur la feuille (dernier segment après « > ») : les petits modèles renvoient souvent
        // « Imprimantes » au lieu de « IT > Support > Imprimantes ». Homonymes -> null (pas de pose).
        $parts = explode('>', $name);
        $leaf  = AiText::normalize(trim((string) end($parts)));
        if ($leaf !== '' && $leaf !== $norm) {
            if (!array_key_exists($leaf, $cat_by_leaf)) {
                $cat_by_leaf[$leaf] = ['id' => $id, 'name' => $name];
            } elseif ($cat_by_leaf[$leaf] !== null && $cat_by_leaf[$leaf]['id'] !== $id) {
                $cat_by_leaf[$leaf] = null;
            }
        }
    }

    $ai     = new AiClient($config);
    $result = $ai->enrich($subject, $body, array_keys($categories));

    // Diagnostic (n'est renvoyé que si ai_debug est actif).
    $debug = [
        'enabled'          => (string) ($config['ai_enabled'] ?? '0'),
        'configured'       => $ai->isConfigured(),
        'base_url'         => (string) ($config['ai_base_url'] ?? ''),
        'model'            => (string) ($config['ai_model'] ?? ''),
        'timeout'          => (int) ($config['ai_timeout'] ?? 60),
        'categories_count' => count($categories),
        'http_code'        => $ai->getLastHttpCode(),
        'curl_error'       => $ai->getLastError(),
        'raw_content'      => mb_substr($ai->getLastRawContent(), 0, 1000),
        'parsed'           => $result,
        'selftest'         => $selftest,
    ];

    if ($result === null) {
        trigger_error(
            'mail2glpi: appel IA sans résultat — http=' . $ai->getLastHttpCode()
            . ' err=' . $ai->getLastError()
            . ' base_url=' . (string) ($config['ai_base_url'] ?? ''),
            E_USER_WARNING
        );
        mail2glpi_out([], $debug_on, $debug);
    }

    $out = [];

    $summary = trim((string) ($result['summary'] ?? ''));
    if ($summary !== '') {
        $out['summary'] = $summary;
    }

    $urgency = AiText::parseUrgency($result['urgency'] ?? null);
    if ($urgency !== null) {
        $out['urgency'] = $urgency;
    }

    // La catégorie n'est acceptée que si elle correspond à une catégorie existante (exacte, ou
    // tolérante aux accents/casse). On ne pose jamais une catégorie inventée.
    $category = trim((string) ($result['category'] ?? ''));
    if ($category !== '') {
        $norm  = AiText::normalize($category);
        $match = null;
        if (isset($categories[$category])) {
            $match = ['id' => $categories[$category], 'name' => $category];
        } elseif (isset($cat_by_norm[$norm])) {
            $match = $cat_by_norm[$norm];
        } elseif (isset($cat_by_leaf[$norm])) { // isset() est faux si la feuille est ambiguë (null)
            $match = $cat_by_leaf[$norm];
        }
        if ($match !== null) {
            $out['category_id']   = $match['id'];
            $out['category_name'] = $match['name'];
        }
    }

    mail2glpi_out($out, $debug_on, $debug);
} catch (\Throwable $e) {
    trigger_error('mail2glpi enrich error: ' . $e->getMessage(), E_USER_WARNING);
    mail2glpi_out([], $debug_on, ['exception' => $e->getMessage()]);
}
