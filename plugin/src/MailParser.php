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

    /** Taille max d'une pièce jointe dont on renvoie le contenu (au-delà : métadonnées seules). */
    private const MAX_ATTACHMENT_BYTES = 20 * 1024 * 1024;

    /** Budget cumulé du contenu des pièces jointes renvoyé (au-delà : métadonnées seules). */
    private const MAX_TOTAL_ATTACHMENT_BYTES = 30 * 1024 * 1024;

    /** Compteur de parties explorées sur l'analyse en cours. */
    private int $partCount = 0;

    /** Budget restant pour le contenu des pièces jointes de l'analyse en cours. */
    private int $remainingAttachmentBudget = self::MAX_TOTAL_ATTACHMENT_BYTES;

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
     *   attachments: list<array{name: string, type: string, size: int, content_base64: string, skipped: bool}>
     * }
     */
    public function parse(string $rawEml): array
    {
        $message = new Message(['raw' => $rawEml]);

        $this->partCount                 = 0;
        $this->remainingAttachmentBudget = self::MAX_TOTAL_ATTACHMENT_BYTES;
        $bodies                          = ['html' => '', 'text' => ''];
        $attachments                     = [];
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

        // Les images "inline" (signature, logos) sont marquées "inline" et référencées dans le
        // corps via un Content-ID : on ne les rattache pas comme pièces jointes du ticket.
        $isInline = str_contains($disposition, 'inline') && $part->getHeaders()->has('content-id');

        // Une partie est une pièce jointe si elle est marquée "attachment" ou possède un nom.
        if (!$isInline && (str_contains($disposition, 'attachment') || $filename !== '')) {
            $attachments[] = $this->buildAttachment($part, $filename, $contentType);
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

    /**
     * Construit l'entrée d'une pièce jointe. Le contenu (base64) n'est inclus que s'il tient
     * sous le plafond unitaire ET le budget cumulé restant ; sinon seules les métadonnées sont
     * renvoyées (skipped = true). Un contenu indécodable est également marqué skipped.
     *
     * @return array{name: string, type: string, size: int, content_base64: string, skipped: bool}
     */
    private function buildAttachment($part, string $filename, string $contentType): array
    {
        $raw      = $part->getContent();
        $encoding = strtolower(trim($this->headerValue($part, 'content-transfer-encoding', '')));

        $attachment = [
            'name'           => $filename !== '' ? $filename : 'piece-jointe',
            'type'           => $contentType,
            'size'           => $this->upperBoundDecodedSize($raw, $encoding),
            'content_base64' => '',
            'skipped'        => false,
        ];

        // Pré-contrôle sur une borne HAUTE sûre de la taille décodée : évite de décoder en
        // mémoire une pièce jointe qui dépasserait de toute façon les plafonds.
        if ($attachment['size'] > self::MAX_ATTACHMENT_BYTES
            || $attachment['size'] > $this->remainingAttachmentBudget) {
            $attachment['skipped'] = true;
            return $attachment;
        }

        $content = $this->decodeStrict($raw, $encoding);
        if ($content === null) {
            // Contenu indécodable (base64 invalide) : on n'envoie pas de données corrompues.
            $attachment['skipped'] = true;
            return $attachment;
        }

        $attachment['size'] = strlen($content);
        $this->remainingAttachmentBudget -= $attachment['size'];
        $attachment['content_base64'] = base64_encode($content);
        return $attachment;
    }

    /**
     * Borne HAUTE (jamais sous-estimée) de la taille décodée, sans matérialiser le binaire.
     * base64 → ~3/4 de l'encodé ; quoted-printable/7bit/8bit/brut → la taille décodée ne
     * dépasse pas la taille encodée, donc on prend cette dernière comme majorant sûr.
     */
    private function upperBoundDecodedSize(string $raw, string $encoding): int
    {
        if ($encoding === 'base64') {
            $clean = preg_replace('/\s+/', '', $raw) ?? '';
            return (int) ceil(mb_strlen($clean, '8bit') * 3 / 4);
        }
        return mb_strlen($raw, '8bit');
    }

    /**
     * Décode le contenu d'une pièce jointe selon son encodage.
     * Retourne null si le contenu base64 est invalide (pour ne pas renvoyer de données corrompues).
     */
    private function decodeStrict(string $raw, string $encoding): ?string
    {
        if ($encoding === 'base64') {
            $decoded = base64_decode($raw, true);
            return $decoded === false ? null : $decoded;
        }
        if ($encoding === 'quoted-printable') {
            return quoted_printable_decode($raw);
        }
        return $raw;
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
