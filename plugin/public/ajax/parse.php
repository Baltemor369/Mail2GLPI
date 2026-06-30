<?php

/**
 * Endpoint AJAX : produit les valeurs de pré-remplissage d'un ticket à partir d'un e-mail.
 * Deux modes d'entrée, qui aboutissent au même mapping (titre, description assainie, source) :
 *  - upload d'un fichier .eml (champ `emlfile`) → analysé côté serveur (MailParser) ;
 *  - champs déjà extraits d'un .msg côté navigateur (`mode=msg`) → mappés tels quels.
 * Les pièces jointes d'un .msg sont gérées côté navigateur (le binaire y est déjà disponible).
 * Aucune donnée n'est persistée.
 */

use GlpiPlugin\Mail2glpi\MailParser;
use GlpiPlugin\Mail2glpi\TicketMapper;

// GLPI 11 : ce script est servi depuis plugins/mail2glpi/public/ via le routeur GLPI, qui
// initialise automatiquement l'environnement (plus de include('inc/includes.php')) et applique
// la stratégie de pare-feu par défaut (accès réservé aux utilisateurs authentifiés).

header('Content-Type: application/json; charset=utf-8');

// Taille maximale acceptée pour un fichier .eml / un message .msg reconstitué (50 Mo). NB : la
// limite RÉELLE d'upload reste celle de PHP (post_max_size / upload_max_filesize) et de GLPI
// (Configuration > Assistance / Documents) — à relever côté serveur pour les grosses pièces.
// define() gardé pour éviter toute redéclaration en cas d'inclusion multiple.
if (!defined('MAIL2GLPI_MAX_BYTES')) {
    define('MAIL2GLPI_MAX_BYTES', 50 * 1024 * 1024);
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
    // Sécurité : utilisateur authentifié + droit de créer des tickets.
    //
    // CSRF : la protection est assurée EN AMONT par le routeur GLPI 11, qui valide le jeton de
    // l'en-tête X-Glpi-Csrf-Token (envoyé par dropzone.js avec X-Requested-With) pour toute
    // requête AJAX. On ne refait donc PAS de validateCSRF ici : ce jeton est déjà consommé par
    // le routeur, et une seconde validation échouerait toujours (faux rejet 403).
    Session::checkLoginUser();

    if (!Session::haveRight('ticket', CREATE)) {
        mail2glpi_fail(403, __('Droit de création de ticket requis.', 'mail2glpi'));
    }

    $mode = $_POST['mode'] ?? 'eml';

    if ($mode === 'msg') {
        // Mode .msg : les champs ont déjà été extraits côté navigateur (lib msg.reader).
        $subject   = (string) ($_POST['subject'] ?? '');
        $body_html = (string) ($_POST['body_html'] ?? '');
        $body_text = (string) ($_POST['body_text'] ?? '');

        // Borne de taille équivalente au .eml : ces champs sont entièrement contrôlés par le
        // client, on évite donc une amplification mémoire (anti-DoS).
        if (strlen($subject) + strlen($body_html) + strlen($body_text) > MAIL2GLPI_MAX_BYTES) {
            mail2glpi_fail(400, __('Message trop volumineux.', 'mail2glpi'));
        }

        $parsed = [
            'subject'     => $subject,
            'from'        => [
                'email' => (string) ($_POST['from_email'] ?? ''),
                'name'  => (string) ($_POST['from_name'] ?? ''),
            ],
            'cc'          => [],
            'body_html'   => $body_html,
            'body_text'   => $body_text,
            'attachments' => [], // pièces jointes du .msg rattachées côté navigateur
        ];
    } elseif ($mode === 'eml') {
        // Mode .eml : analyse côté serveur du fichier uploadé.
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
    } else {
        mail2glpi_fail(400, __('Mode non pris en charge.', 'mail2glpi'));
    }

    $mapped = (new TicketMapper())->map($parsed);

    // Source de la demande = « E-Mail » (même source que celle utilisée par le collecteur).
    $source_id = (int) RequestType::getDefault('mail');
    if ($source_id > 0) {
        $mapped['source_id'] = $source_id;
    }

    // Demandeur = expéditeur du mail. Si l'adresse correspond à un compte GLPI, on lie ce
    // compte ; sinon, demandeur « par e-mail » (items_id = 0 + alternative_email), comme le
    // collecteur de mails natif.
    $sender_email = trim((string) ($parsed['from']['email'] ?? ''));
    if ($sender_email !== '') {
        $user_id   = 0;
        $user_name = '';
        try {
            $user = new User();
            if (
                $user->getFromDBbyEmail($sender_email)
                && (int) ($user->fields['is_deleted'] ?? 0) === 0
                && (int) ($user->fields['is_active'] ?? 1) === 1
            ) {
                $user_id   = (int) $user->getID();
                $user_name = $user->getFriendlyName() ?: $sender_email;
            }
        } catch (\Throwable $e) {
            // Échec du lookup : on retombe proprement sur le demandeur par e-mail.
            $user_id = 0;
        }

        $mapped['requester'] = [
            'items_id'         => $user_id,
            'name'             => $user_id > 0
                ? $user_name
                : ((string) ($parsed['from']['name'] ?? '') ?: $sender_email),
            'email'            => $sender_email,
            'use_notification' => 1,
        ];
    }

    echo json_encode(['data' => $mapped]);
} catch (\Throwable $e) {
    // On journalise le détail technique mais on ne le renvoie pas au client.
    trigger_error('mail2glpi parse error: ' . $e->getMessage(), E_USER_WARNING);
    mail2glpi_fail(500, __("Échec de l'analyse de l'e-mail.", 'mail2glpi'));
}
