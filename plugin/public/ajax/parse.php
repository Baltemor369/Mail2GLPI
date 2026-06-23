<?php

/**
 * Endpoint AJAX : reçoit un fichier e-mail (.eml) téléversé, l'analyse et renvoie en JSON
 * les valeurs de pré-remplissage du ticket. Aucune donnée n'est persistée.
 */

use GlpiPlugin\Mail2glpi\MailParser;
use GlpiPlugin\Mail2glpi\TicketMapper;

// GLPI 11 : ce script est servi depuis plugins/mail2glpi/public/ via le routeur GLPI, qui
// initialise automatiquement l'environnement (plus de include('inc/includes.php')) et applique
// la stratégie de pare-feu par défaut (accès réservé aux utilisateurs authentifiés).

header('Content-Type: application/json; charset=utf-8');

// Taille maximale acceptée pour un fichier .eml (10 Mo). define() gardé pour éviter toute
// redéclaration en cas d'inclusion multiple.
if (!defined('MAIL2GLPI_MAX_BYTES')) {
    define('MAIL2GLPI_MAX_BYTES', 10 * 1024 * 1024);
}

/**
 * Renvoie une erreur JSON et arrête le script.
 *
 * @param int $status code HTTP
 * @param string $message message destiné à l'utilisateur (sans détail technique)
 * @return never
 */
function mail2glpi_fail(int $status, string $message)
{
    http_response_code($status);
    echo json_encode(['error' => $message]);
    exit;
}

try {
    // Sécurité : utilisateur authentifié + droit de créer des tickets + jeton CSRF valide.
    Session::checkLoginUser();
    Session::checkCSRF($_REQUEST);
    if (!Session::haveRight('ticket', CREATE)) {
        mail2glpi_fail(403, __('Droit de création de ticket requis.', 'mail2glpi'));
    }

    $upload = $_FILES['emlfile'] ?? null;
    if ($upload === null || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        mail2glpi_fail(400, __('Aucun fichier reçu.', 'mail2glpi'));
    }

    // On ne fait jamais confiance au nom de fichier client (sécurité) : on l'utilise seulement
    // pour valider l'extension et on lit uniquement le fichier temporaire uploadé.
    $extension = strtolower(pathinfo((string) $upload['name'], PATHINFO_EXTENSION));
    if ($extension !== 'eml') {
        mail2glpi_fail(415, __('Seuls les fichiers .eml sont pris en charge.', 'mail2glpi'));
    }

    // La taille réelle sur disque prime sur la taille déclarée par le client (falsifiable).
    if (!is_uploaded_file($upload['tmp_name']) || filesize($upload['tmp_name']) > MAIL2GLPI_MAX_BYTES) {
        mail2glpi_fail(400, __('Fichier invalide ou trop volumineux.', 'mail2glpi'));
    }

    $raw = file_get_contents($upload['tmp_name']);
    if ($raw === false || $raw === '') {
        mail2glpi_fail(400, __('Fichier illisible.', 'mail2glpi'));
    }

    $parsed = (new MailParser())->parse($raw);
    $mapped = (new TicketMapper())->map($parsed);

    echo json_encode(['data' => $mapped]);
} catch (\Throwable $e) {
    // On journalise le détail technique mais on ne le renvoie pas au client.
    trigger_error('mail2glpi parse error: ' . $e->getMessage(), E_USER_WARNING);
    mail2glpi_fail(500, __("Échec de l'analyse de l'e-mail.", 'mail2glpi'));
}
