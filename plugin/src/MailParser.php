<?php

namespace GlpiPlugin\Mail2glpi;

use Laminas\Mail\Header\AbstractAddressList;
use Laminas\Mail\Storage\Message;
use Laminas\Mail\Storage\Part;

/**
 * Analyse un e-mail brut au format RFC 822 (.eml) et en extrait les informations utiles
 * à la création d'un ticket.
 *
 * S'appuie sur laminas/laminas-mail, déjà fourni par le cœur de GLPI (utilisé par le
 * collecteur de mails). Aucune dépendance supplémentaire n'est donc nécessaire.
 *
 * Le format .msg (Outlook, binaire OLE) n'est pas géré ici : il nécessitera une bibliothèque
 * dédiée et sera ajouté ultérieurement derrière la même interface.
 */
class MailParser
{
    /** Profondeur maximale d'imbrication MIME explorée (garde-fou anti-DoS). */
    private const MAX_DEPTH = 20;

    /** Nombre maximal de parties MIME explorées (garde-fou anti-DoS). */
    private const MAX_PARTS = 200;

    /** Compteur de parties explorées sur l'analyse en cours. */
    private int $partCount = 0;

    /**
     * Analyse le contenu brut d'un e-mail.
     *
     * @param string $rawEml contenu brut du fichier .eml
     * @return array{
     *   subject: string,
     *   from: array{email: string, name: string},
     *   cc: list<string>,
     *   body_html: string,
     *   body_text: string,
     *   attachments: list<array{name: string, type: string, size: int}>
     * }
     */
    public function parse(string $rawEml): array
    {
        $message = new Message(['raw' => $rawEml]);

        $this->partCount = 0;
        $bodies          = ['html' => '', 'text' => ''];
        $attachments     = [];
        $this->walkParts($message, $bodies, $attachments, 0);

        return [
            'subject'     => $this->decodeHeader($this->headerValue($message, 'subject')),
            'from'        => $this->parseFrom($message),
            'cc'          => $this->parseAddressList($message, 'cc'),
            'body_html'   => $bodies['html'],
            'body_text'   => $bodies['text'],
            'attachments' => $attachments,
        ];
    }

    /**
     * Parcourt récursivement les parties MIME pour collecter corps et pièces jointes.
     *
     * @param Message|Part $part
     * @param array{html: string, text: string} $bodies
     * @param list<array{name: string, type: string, size: int}> $attachments
     */
    private function walkParts($part, array &$bodies, array &$attachments, int $depth): void
    {
        if ($depth > self::MAX_DEPTH || ++$this->partCount > self::MAX_PARTS) {
            return;
        }

        if ($part->isMultipart()) {
            // API Laminas : les sous-parties s'obtiennent via countParts()/getPart() (1-indexé),
            // un Part n'étant pas itérable directement avec foreach.
            $count = $part->countParts();
            for ($i = 1; $i <= $count; $i++) {
                $this->walkParts($part->getPart($i), $bodies, $attachments, $depth + 1);
            }
            return;
        }

        $contentType = trim(explode(';', strtolower($this->headerValue($part, 'content-type', 'text/plain')))[0]);
        $disposition = strtolower($this->headerValue($part, 'content-disposition', ''));
        $filename    = $this->extractFilename($part);

        // Une partie est une pièce jointe si elle est marquée "attachment" ou possède un nom.
        if (str_contains($disposition, 'attachment') || $filename !== '') {
            $attachments[] = [
                'name' => $filename !== '' ? $filename : 'piece-jointe',
                'type' => $contentType,
                // Taille estimée sans matérialiser le binaire décodé (anti-DoS mémoire).
                'size' => $this->estimateDecodedSize($part),
            ];
            return;
        }

        if ($contentType === 'text/html' && $bodies['html'] === '') {
            $bodies['html'] = $this->decodeContent($part);
        } elseif ($contentType === 'text/plain' && $bodies['text'] === '') {
            $bodies['text'] = $this->decodeContent($part);
        }
    }

    /**
     * Extrait et décode l'expéditeur.
     *
     * @param Message $message
     * @return array{email: string, name: string}
     */
    private function parseFrom(Message $message): array
    {
        if (!$message->getHeaders()->has('from')) {
            return ['email' => '', 'name' => ''];
        }
        $header = $message->getHeader('from');
        if (!$header instanceof AbstractAddressList) {
            return ['email' => '', 'name' => ''];
        }
        foreach ($header->getAddressList() as $address) {
            return [
                'email' => $address->getEmail(),
                'name'  => $this->decodeHeader((string) $address->getName()),
            ];
        }
        return ['email' => '', 'name' => ''];
    }

    /**
     * Extrait la liste d'adresses d'un en-tête (ex. "cc").
     *
     * @param Message|Part $part
     * @return list<string>
     */
    private function parseAddressList($part, string $headerName): array
    {
        if (!$part->getHeaders()->has($headerName)) {
            return [];
        }
        $header = $part->getHeader($headerName);
        if (!$header instanceof AbstractAddressList) {
            return [];
        }
        $emails = [];
        foreach ($header->getAddressList() as $address) {
            $emails[] = $address->getEmail();
        }
        return $emails;
    }

    /** Récupère le nom de fichier d'une pièce jointe (Content-Disposition ou Content-Type). */
    private function extractFilename($part): string
    {
        $disposition = $this->headerValue($part, 'content-disposition', '');
        $contentType = $this->headerValue($part, 'content-type', '');

        foreach (['filename', 'name'] as $key) {
            if (preg_match('/' . $key . '="?([^";]+)"?/i', $disposition . ';' . $contentType, $matches)) {
                // basename neutralise tout séparateur de chemin présent dans le nom non fiable.
                return basename($this->decodeHeader(trim($matches[1])));
            }
        }
        return '';
    }

    /** Décode le contenu textuel d'une partie selon son Content-Transfer-Encoding. */
    private function decodeContent($part): string
    {
        $content  = $part->getContent();
        $encoding = strtolower(trim($this->headerValue($part, 'content-transfer-encoding', '')));

        if ($encoding === 'base64') {
            $decoded = base64_decode($content, true);
            return $decoded !== false ? $decoded : $content;
        }
        if ($encoding === 'quoted-printable') {
            return quoted_printable_decode($content);
        }
        return $content;
    }

    /** Estime la taille (octets) d'une partie une fois décodée, sans la décoder réellement. */
    private function estimateDecodedSize($part): int
    {
        $encoding = strtolower(trim($this->headerValue($part, 'content-transfer-encoding', '')));
        $raw      = $part->getContent();

        if ($encoding === 'base64') {
            $clean = preg_replace('/\s+/', '', $raw) ?? '';
            return (int) (mb_strlen($clean, '8bit') * 3 / 4);
        }
        return mb_strlen($raw, '8bit');
    }

    /** Lit la valeur brute d'un en-tête, avec valeur par défaut si absent. */
    private function headerValue($part, string $name, string $default = ''): string
    {
        if (!$part->getHeaders()->has($name)) {
            return $default;
        }
        return $part->getHeader($name)->getFieldValue();
    }

    /** Décode un en-tête éventuellement encodé MIME (=?UTF-8?...). */
    private function decodeHeader(string $value): string
    {
        if ($value === '') {
            return '';
        }
        $decoded = iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
        return $decoded !== false ? $decoded : $value;
    }
}
