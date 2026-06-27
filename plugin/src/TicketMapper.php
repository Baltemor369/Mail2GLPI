<?php

namespace GlpiPlugin\Mail2glpi;

/**
 * Transforme le résultat de {@see MailParser} en valeurs prêtes à pré-remplir le formulaire
 * de ticket GLPI.
 *
 * Sécurité : le corps HTML d'un e-mail est une donnée non fiable. Il est assaini avant d'être
 * renvoyé au navigateur, en réutilisant l'assainisseur de GLPI quand il est disponible.
 */
class TicketMapper
{
    /**
     * Construit la charge utile de pré-remplissage à partir d'un e-mail analysé.
     *
     * @param array $parsed résultat de MailParser::parse()
     * @return array{
     *   title: string,
     *   content: string,
     *   requester_email: string,
     *   requester_name: string,
     *   observers: list<string>,
     *   attachments: list<array{name: string, type: string, size: int}>
     * }
     */
    public function map(array $parsed): array
    {
        $plain = $this->buildPlain($parsed);

        return [
            'title'           => $this->buildTitle($parsed['subject'] ?? ''),
            // Description : texte échappé (jamais de HTML brut non fiable injecté).
            'content'         => nl2br(htmlspecialchars($plain, ENT_QUOTES, 'UTF-8')),
            // Texte brut, destiné à l'enrichissement IA local (non affiché tel quel).
            'body_plain'      => $plain,
            'requester_email' => $parsed['from']['email'] ?? '',
            'requester_name'  => $parsed['from']['name'] ?? '',
            'observers'       => $parsed['cc'] ?? [],
            'attachments'     => $parsed['attachments'] ?? [],
        ];
    }

    /** Construit le titre du ticket à partir du sujet. */
    private function buildTitle(string $subject): string
    {
        $subject = trim($subject);
        return $subject !== '' ? $subject : __('(Sans objet)', 'mail2glpi');
    }

    /**
     * Extrait le corps de l'e-mail en **texte brut**. Privilégie le corps texte ; à défaut,
     * dérive un texte depuis le HTML en supprimant les balises. Ce texte sert à la fois de
     * base à la description (après échappement) et à l'enrichissement IA local.
     */
    private function buildPlain(array $parsed): string
    {
        $text = trim((string) ($parsed['body_text'] ?? ''));
        if ($text === '') {
            // strip_tags laisse les entités (&amp;, &nbsp;…) ; on les décode pour obtenir un
            // vrai texte brut (sinon double-encodage à l'affichage et dans le résumé IA).
            $html = strip_tags((string) ($parsed['body_html'] ?? ''));
            $text = trim(html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        return $text;
    }
}
