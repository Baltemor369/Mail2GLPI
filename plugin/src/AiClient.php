<?php

namespace GlpiPlugin\Mail2glpi;

/**
 * Client minimal pour un LLM **local** exposant l'API compatible OpenAI
 * (`/chat/completions`) — typiquement Ollama sur le réseau interne.
 *
 * Conçu pour respecter la contrainte de confidentialité : aucune donnée n'est envoyée vers un
 * service cloud ; seul l'endpoint local configuré est appelé. Tout est **best-effort** : en cas
 * d'échec (injoignable, timeout, réponse invalide), les méthodes renvoient null et le
 * pré-remplissage de base reste inchangé.
 */
class AiClient
{
    private string $baseUrl;
    private string $model;
    private int $timeout;
    private string $apiKey;

    /** Diagnostic du dernier appel (pour le mode debug de enrich.php). */
    private int $lastHttpCode = 0;
    private string $lastError = '';
    private string $lastRawContent = '';

    /**
     * @param array<string,mixed> $config clés : ai_base_url, ai_model, ai_timeout, ai_api_key
     */
    public function __construct(array $config)
    {
        $this->baseUrl = rtrim((string) ($config['ai_base_url'] ?? ''), '/');
        $this->model   = trim((string) ($config['ai_model'] ?? ''));
        $this->timeout = max(5, (int) ($config['ai_timeout'] ?? 60));
        $this->apiKey  = trim((string) ($config['ai_api_key'] ?? ''));
    }

    /** L'IA est utilisable si une URL de base et un modèle sont configurés. */
    public function isConfigured(): bool
    {
        return $this->baseUrl !== '' && $this->model !== '';
    }

    /** Code HTTP du dernier appel (0 = pas de réponse / non joignable). */
    public function getLastHttpCode(): int
    {
        return $this->lastHttpCode;
    }

    /** Message d'erreur du dernier appel (curl ou HTTP non-2xx), '' si aucun. */
    public function getLastError(): string
    {
        return $this->lastError;
    }

    /** Contenu brut renvoyé par le modèle au dernier appel (avant extraction JSON). */
    public function getLastRawContent(): string
    {
        return $this->lastRawContent;
    }

    /**
     * Demande au modèle un JSON { category, urgency, summary }. En pratique un seul appel ; une
     * 2e tentative (sans response_format) n'a lieu QUE si le 1er appel a abouti côté HTTP mais que
     * le JSON était inexploitable (200) ou que le format a été refusé (400). Si le serveur est
     * injoignable / en timeout (code 0 ou autre erreur), on n'inflige pas une 2e attente.
     *
     * @param string       $subject     sujet de l'e-mail
     * @param string       $body        corps (texte brut)
     * @param list<string> $categories  noms de catégories ITIL autorisés (peut être vide)
     * @return array{category?: string, urgency?: int, summary?: string}|null
     */
    public function enrich(string $subject, string $body, array $categories): ?array
    {
        if (!$this->isConfigured()) {
            return null;
        }

        $category_list = $categories === []
            ? '(aucune catégorie disponible)'
            : implode("\n", array_map(static fn($c) => '- ' . $c, $categories));

        $system = "Tu es un assistant de support informatique. À partir d'un e-mail, tu réponds "
            . "UNIQUEMENT par un objet JSON valide (aucun texte autour), avec exactement ces clés :\n"
            . '"category" : une valeur EXACTEMENT identique à l\'une des catégories autorisées, ou "" si aucune ne convient ;' . "\n"
            . '"urgency" : un CHIFFRE de 1 (très basse) à 5 (très haute) — un entier, pas un mot ;' . "\n"
            . '"summary" : un résumé en français, 1 à 2 phrases.';

        $user = "Catégories autorisées :\n{$category_list}\n\n"
            . "Sujet : {$subject}\n\nCorps :\n{$body}";

        $base_payload = [
            'model'       => $this->model,
            'messages'    => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
            'temperature' => 0.1,
            'stream'      => false,
        ];

        // 1re tentative : on force une sortie JSON via `response_format` (supporté par Ollama
        // récent et bien plus fiable qu'un petit modèle livré à lui-même).
        $parsed = $this->callAndParse($base_payload + ['response_format' => ['type' => 'json_object']]);
        if ($parsed !== null) {
            return $parsed;
        }

        // Repli sans `response_format` UNIQUEMENT si le serveur a répondu : code 400 (format refusé
        // par cette version d'Ollama) ou 200 (réponse reçue mais JSON inexploitable). Pour une
        // injoignabilité / un timeout (code 0 ou 5xx), on s'arrête : pas de 2e attente longue.
        if (in_array($this->lastHttpCode, [200, 400], true)) {
            return $this->callAndParse($base_payload);
        }

        return null;
    }

    /**
     * Effectue un appel et tente d'extraire un objet JSON de la réponse. Renvoie null en cas
     * d'échec (HTTP, ou réponse non parsable).
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>|null
     */
    private function callAndParse(array $payload): ?array
    {
        $response = $this->postChat($payload);
        if ($response === null) {
            return null;
        }
        $content = (string) ($response['choices'][0]['message']['content'] ?? '');
        $this->lastRawContent = $content;
        return $this->extractJson($content);
    }

    /**
     * Effectue l'appel HTTP POST /chat/completions. Renvoie le tableau décodé, ou null.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>|null
     */
    private function postChat(array $payload): ?array
    {
        $headers = ['Content-Type: application/json'];
        if ($this->apiKey !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->apiKey;
        }

        // JSON_INVALID_UTF8_SUBSTITUTE : un e-mail mal encodé (octets Latin-1 déclarés UTF-8) ne
        // doit pas faire échouer silencieusement json_encode (qui renverrait false -> corps vide).
        $body = json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
        if ($body === false) {
            $this->lastError = 'json_encode: ' . json_last_error_msg();
            return null;
        }

        $ch = curl_init($this->baseUrl . '/chat/completions');
        if ($ch === false) {
            return null;
        }
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
            // Sécurité : pas de suivi de redirection, et schémas restreints à http/https
            // (évite qu'une URL forgée détourne l'appel vers file://, gopher://, etc.).
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        if (defined('CURLOPT_PROTOCOLS_STR')) {
            curl_setopt($ch, CURLOPT_PROTOCOLS_STR, 'http,https');
        } elseif (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
            curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        }
        $raw    = curl_exec($ch);
        $code   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno  = curl_errno($ch);
        $errmsg = curl_error($ch);
        curl_close($ch);

        $this->lastHttpCode = $code;
        $this->lastError    = $errno !== 0 ? ('curl(' . $errno . '): ' . $errmsg) : '';

        if (!is_string($raw) || $code < 200 || $code >= 300) {
            if (is_string($raw) && $this->lastError === '') {
                // HTTP non-2xx avec corps : on conserve un extrait comme indice (ex. 400 Ollama).
                $this->lastError = 'HTTP ' . $code . ' : ' . mb_substr($raw, 0, 300);
            }
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    /**
     * Extrait un objet JSON d'une réponse texte (tolère un éventuel habillage autour du JSON).
     *
     * @return array<string,mixed>|null
     */
    private function extractJson(string $text): ?array
    {
        $text = trim($text);

        // Cas nominal : le modèle renvoie du JSON pur (response_format json_object).
        $json = json_decode($text, true);
        if (is_array($json)) {
            return $json;
        }

        // Repli : le modèle a ajouté du texte autour. On tente l'objet le plus large (du 1er « { »
        // au dernier « } »), puis le plus court (1er « { » au 1er « } ») — l'un des deux convient
        // pour un JSON plat sans imbrication, qui est ce qu'on demande.
        foreach (['/\{.*\}/s', '/\{.*?\}/s'] as $pattern) {
            if (preg_match($pattern, $text, $matches) === 1) {
                $json = json_decode($matches[0], true);
                if (is_array($json)) {
                    return $json;
                }
            }
        }
        return null;
    }
}
