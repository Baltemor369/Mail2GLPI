<?php

/**
 * Dictionnaire de traduction du plugin Mail2GLPI.
 *
 * Approche volontairement sans gettext (.po/.mo) : un simple tableau PHP par langue, chargé côté
 * serveur et injecté au JavaScript (window.MAIL2GLPI_I18N) selon la langue de l'utilisateur GLPI.
 * Cela couvre proprement les textes PHP ET JS, sans dépendance à un outil de compilation.
 *
 * - `en` est la langue par défaut (toute langue non française retombe sur l'anglais).
 * - Pour ajouter une langue, dupliquer un bloc et traduire les valeurs (mêmes clés).
 * - Les placeholders {x} sont remplacés à l'exécution (ex. {msg}, {n}, {email}).
 *
 * @return array<string, array<string, string>>
 */

return [
    'en' => [
        // Section dropzone (rendue par setup.php)
        'dropzone_label'          => 'Drop an email (.eml or .msg) here to pre-fill the ticket',
        'ai_toggle_label'         => 'AI suggestions (category, urgency, summary) for this drop',

        // Statuts / messages (dropzone.js)
        'unsupported_format'      => 'Unsupported format: drop a .eml or .msg file.',
        'parsing_eml'             => 'Parsing the email…',
        'reading_msg'             => 'Reading the Outlook message…',
        'msg_reader_unavailable'  => 'MSG reader unavailable (library not loaded).',
        'browser_too_old'         => 'Browser too old to read .msg files.',
        'msg_unreadable'          => 'Unreadable .msg file.',
        'parse_failed'            => 'Parsing failed.',
        'error_prefix'            => 'Error: {msg}',
        'unexpected_response'     => 'Unexpected server response (session expired?). Reload the page.',
        'prefilled'               => 'Ticket pre-filled.',
        'prefilled_attach_manual' => 'Ticket pre-filled (attachments to add manually).',
        'requester_label'         => 'Requester: {email}',
        'att_added'               => '{n} attachment(s) added',
        'att_added_partial'       => '{a}/{b} attachment(s) added',
        'att_skipped'             => '{n} attachment(s) skipped',
        'att_skipped_suffix'      => '{n} skipped',
        'category_fallback'       => 'Category',
        'urgency_label'           => 'Urgency {n}',
        'ai_summary_label'        => 'Summary (AI):',
        'ai_analyzing'            => 'AI: analyzing…',
        'ai_added'                => 'AI: {items} added',
        'ai_nothing'              => 'AI: nothing to suggest',
        'item_category'           => 'category',
        'item_urgency'            => 'urgency',
        'item_summary'            => 'summary',

        // Erreurs serveur (parse.php) renvoyées à l'agent
        'err_need_create_right'   => 'Ticket creation right required.',
        'err_msg_too_large'       => 'Message too large.',
        'err_no_file'             => 'No file received.',
        'err_only_eml'            => 'Only .eml files are supported.',
        'err_invalid_or_large'    => 'Invalid or too large file.',
        'err_unreadable_file'     => 'Unreadable file.',
        'err_unsupported_mode'    => 'Unsupported mode.',
        'err_parse_failed'        => 'Failed to parse the email.',
    ],

    'fr' => [
        'dropzone_label'          => 'Glissez ici un e-mail (.eml ou .msg) pour pré-remplir le ticket',
        'ai_toggle_label'         => 'Suggestions IA (catégorie, urgence, résumé) à ce dépôt',

        'unsupported_format'      => 'Format non pris en charge : déposez un fichier .eml ou .msg.',
        'parsing_eml'             => "Analyse de l'e-mail en cours…",
        'reading_msg'             => 'Lecture du message Outlook…',
        'msg_reader_unavailable'  => 'Lecteur .msg indisponible (bibliothèque non chargée).',
        'browser_too_old'         => 'Navigateur trop ancien pour lire les fichiers .msg.',
        'msg_unreadable'          => 'Fichier .msg illisible.',
        'parse_failed'            => "Échec de l'analyse.",
        'error_prefix'            => 'Erreur : {msg}',
        'unexpected_response'     => 'Réponse inattendue du serveur (session expirée ?). Rechargez la page.',
        'prefilled'               => 'Ticket pré-rempli.',
        'prefilled_attach_manual' => 'Ticket pré-rempli (pièces jointes à ajouter manuellement).',
        'requester_label'         => 'Demandeur : {email}',
        'att_added'               => '{n} pièce(s) jointe(s) ajoutée(s)',
        'att_added_partial'       => '{a}/{b} pièce(s) jointe(s) ajoutée(s)',
        'att_skipped'             => '{n} pièce(s) jointe(s) ignorée(s)',
        'att_skipped_suffix'      => '{n} ignorée(s)',
        'category_fallback'       => 'Catégorie',
        'urgency_label'           => 'Urgence {n}',
        'ai_summary_label'        => 'Résumé (IA) :',
        'ai_analyzing'            => 'IA : analyse en cours…',
        'ai_added'                => 'IA : {items} ajouté(s)',
        'ai_nothing'              => 'IA : rien à suggérer',
        'item_category'           => 'catégorie',
        'item_urgency'            => 'urgence',
        'item_summary'            => 'résumé',

        'err_need_create_right'   => 'Droit de création de ticket requis.',
        'err_msg_too_large'       => 'Message trop volumineux.',
        'err_no_file'             => 'Aucun fichier reçu.',
        'err_only_eml'            => 'Seuls les fichiers .eml sont pris en charge.',
        'err_invalid_or_large'    => 'Fichier invalide ou trop volumineux.',
        'err_unreadable_file'     => 'Fichier illisible.',
        'err_unsupported_mode'    => 'Mode non pris en charge.',
        'err_parse_failed'        => "Échec de l'analyse de l'e-mail.",
    ],
];
