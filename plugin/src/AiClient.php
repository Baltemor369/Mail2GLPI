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

    /**
     * Un **unique** appel qui demande au modèle de renvoyer un JSON
     * { category, urgency, summary }. Un seul appel = une seule latence (important en CPU).
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
            . '"urgency" : un entier de 1 (très basse) à 5 (très haute) ;' . "\n"
            . '"summary" : un résumé en français, 1 à 2 phrases.';

        $user = "Catégories autorisées :\n{$category_list}\n\n"
            . "Sujet : {$subject}\n\nCorps :\n{$body}";

        $payload = [
            'model'           => $this->model,
            'messages'        => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
            'temperature'     => 0.1,
            'stream'          => false,
            'response_format' => ['type' => 'json_object'],
        ];

        $response = $this->postChat($payload);
        if ($response === null) {
            return null;
        }

        $content = (string) ($response['choices'][0]['message']['content'] ?? '');
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

        $ch = curl_init($this->baseUrl . '/chat/completions');
        if ($ch === false) {
            return null;
        }
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => (string) json_encode($payload),
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
        $raw  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!is_string($raw) || $code < 200 || $code >= 300) {
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
