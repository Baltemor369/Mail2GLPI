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

/** Normalise une chaîne pour comparaison tolérante (minuscules + suppression des accents). */
function mail2glpi_norm(string $s): string
{
    $s = mb_strtolower(trim($s), 'UTF-8');
    if (class_exists('Normalizer')) {
        $decomposed = \Normalizer::normalize($s, \Normalizer::FORM_D);
        if (is_string($decomposed)) {
            $s = (string) preg_replace('/\p{Mn}/u', '', $decomposed);
        }
    }
    return $s;
}

/**
 * Convertit l'urgence renvoyée par le modèle en entier GLPI 1-5. Accepte un chiffre (1-5) ou un
 * mot fréquent (« Faible », « Haute », « critique »…), sinon null.
 *
 * @param mixed $raw
 */
function mail2glpi_parse_urgency($raw): ?int
{
    if (is_int($raw) || (is_string($raw) && ctype_digit(trim((string) $raw)))) {
        $n = (int) $raw;
        return ($n >= 1 && $n <= 5) ? $n : null;
    }
    $map = [
        'tres basse' => 1, 'tres faible' => 1, 'very low' => 1,
        'basse' => 2, 'faible' => 2, 'low' => 2,
        'moyenne' => 3, 'normale' => 3, 'medium' => 3, 'normal' => 3,
        'haute' => 4, 'elevee' => 4, 'high' => 4, 'importante' => 4,
        'tres haute' => 5, 'tres elevee' => 5, 'critique' => 5, 'urgente' => 5,
        'urgent' => 5, 'very high' => 5, 'critical' => 5,
    ];
    return $map[mail2glpi_norm((string) $raw)] ?? null;
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

    // Catégories ITIL existantes (restreintes à l'entité courante par find()).
    $categories  = []; // nom exact -> id
    $cat_by_norm = []; // nom normalisé (sans accents/casse) -> ['id'=>…, 'name'=>…]
    $itil = new ITILCategory();
    foreach ($itil->find([], [], MAIL2GLPI_AI_MAX_CATEGORIES) as $row) {
        $name = trim((string) ($row['completename'] ?? $row['name'] ?? ''));
        if ($name === '' || isset($categories[$name])) {
            continue;
        }
        $categories[$name] = (int) $row['id'];
        $norm = mail2glpi_norm($name);
        if (!isset($cat_by_norm[$norm])) {
            $cat_by_norm[$norm] = ['id' => (int) $row['id'], 'name' => $name];
        }
    }

    $result = (new AiClient($config))->enrich($subject, $body, array_keys($categories));
    if ($result === null) {
        // Cause la plus fréquente : LLM injoignable depuis le serveur GLPI (pare-feu/route),
        // timeout, ou réponse non-JSON. On journalise pour le diagnostic (best-effort sinon).
        trigger_error(
            'mail2glpi: appel IA sans résultat (LLM injoignable, timeout ou réponse invalide) — '
            . 'base_url=' . (string) ($config['ai_base_url'] ?? ''),
            E_USER_WARNING
        );
        mail2glpi_ai_out([]);
    }

    $out = [];

    $summary = trim((string) ($result['summary'] ?? ''));
    if ($summary !== '') {
        $out['summary'] = $summary;
    }

    $urgency = mail2glpi_parse_urgency($result['urgency'] ?? null);
    if ($urgency !== null) {
        $out['urgency'] = $urgency;
    }

    // La catégorie n'est acceptée que si elle correspond à une catégorie existante (exacte, ou
    // tolérante aux accents/casse). On ne pose jamais une catégorie inventée.
    $category = trim((string) ($result['category'] ?? ''));
    if ($category !== '') {
        if (isset($categories[$category])) {
            $out['category_id']   = $categories[$category];
            $out['category_name'] = $category;
        } else {
            $norm = mail2glpi_norm($category);
            if (isset($cat_by_norm[$norm])) {
                $out['category_id']   = $cat_by_norm[$norm]['id'];
                $out['category_name'] = $cat_by_norm[$norm]['name'];
            }
        }
    }

    mail2glpi_ai_out($out);
} catch (\Throwable $e) {
    trigger_error('mail2glpi enrich error: ' . $e->getMessage(), E_USER_WARNING);
    mail2glpi_ai_out([]); // best-effort : jamais bloquant
}
