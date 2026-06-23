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
        return [
            'title'           => $this->buildTitle($parsed['subject'] ?? ''),
            'content'         => $this->buildContent($parsed),
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
     * Construit le contenu (description) à partir du corps de l'e-mail.
     *
     * Choix de sécurité : pour le pré-remplissage, on produit du **texte échappé** (jamais de
     * HTML brut non fiable). On privilégie le corps texte ; à défaut on dérive un texte depuis
     * le HTML en supprimant les balises. La préservation du HTML riche (avec assainissement
     * complet) est repoussée à la V1, pour réduire la surface XSS du squelette.
     */
    private function buildContent(array $parsed): string
    {
        $text = trim((string) ($parsed['body_text'] ?? ''));
        if ($text === '') {
            $html = (string) ($parsed['body_html'] ?? '');
            // strip_tags ne suffit pas seul à neutraliser le HTML, mais le résultat est ensuite
            // intégralement échappé : aucune balise ne peut donc être interprétée.
            $text = trim(strip_tags($html));
        }

        return nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8'));
    }
}
