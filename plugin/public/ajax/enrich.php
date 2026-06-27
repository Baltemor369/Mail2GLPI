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
 */

use GlpiPlugin\Mail2glpi\AiClient;

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
 * Renvoie un objet JSON et arrête le script.
 *
 * @param array<string,mixed> $data
 * @return never
 */
function mail2glpi_ai_out(array $data)
{
    echo json_encode($data);
    exit;
}

try {
    // Sécurité : utilisateur authentifié + droit de créer des tickets (CSRF géré par le routeur).
    Session::checkLoginUser();
    if (!Session::haveRight('ticket', CREATE)) {
        mail2glpi_ai_out([]);
    }

    // Configuration IA (stockée en base via Config). Désactivée -> rien à faire.
    $config = Config::getConfigurationValues('plugin:mail2glpi', [
        'ai_enabled', 'ai_base_url', 'ai_model', 'ai_timeout', 'ai_api_key',
    ]);
    if (($config['ai_enabled'] ?? '0') !== '1') {
        mail2glpi_ai_out([]);
    }

    $subject = mb_substr(trim((string) ($_POST['subject'] ?? '')), 0, MAIL2GLPI_AI_MAX_SUBJECT);
    $body    = mb_substr(trim((string) ($_POST['body'] ?? '')), 0, MAIL2GLPI_AI_MAX_BODY);
    if ($subject === '' && $body === '') {
        mail2glpi_ai_out([]);
    }

    // Catégories ITIL existantes : nom -> id (restreintes à l'entité courante par find()).
    $categories = [];
    $itil = new ITILCategory();
    foreach ($itil->find([], [], MAIL2GLPI_AI_MAX_CATEGORIES) as $row) {
        $name = trim((string) ($row['completename'] ?? $row['name'] ?? ''));
        if ($name !== '' && !isset($categories[$name])) {
            $categories[$name] = (int) $row['id'];
        }
    }

    $result = (new AiClient($config))->enrich($subject, $body, array_keys($categories));
    if ($result === null) {
        mail2glpi_ai_out([]);
    }

    $out = [];

    $summary = trim((string) ($result['summary'] ?? ''));
    if ($summary !== '') {
        $out['summary'] = $summary;
    }

    $urgency = (int) ($result['urgency'] ?? 0);
    if ($urgency >= 1 && $urgency <= 5) {
        $out['urgency'] = $urgency;
    }

    // La catégorie n'est acceptée que si elle correspond EXACTEMENT à une catégorie existante.
    $category = trim((string) ($result['category'] ?? ''));
    if ($category !== '' && isset($categories[$category])) {
        $out['category_id']   = $categories[$category];
        $out['category_name'] = $category;
    }

    mail2glpi_ai_out($out);
} catch (\Throwable $e) {
    trigger_error('mail2glpi enrich error: ' . $e->getMessage(), E_USER_WARNING);
    mail2glpi_ai_out([]); // best-effort : jamais bloquant
}
