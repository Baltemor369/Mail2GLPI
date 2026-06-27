<?php

/**
 * Page de configuration du plugin Mail2GLPI (lien « Configurer » depuis la liste des plugins).
 *
 * Permet de paramétrer l'IA locale : activation, URL de base (endpoint compatible OpenAI, ex.
 * Ollama), modèle, délai, et éventuelle clé d'API. Valeurs stockées en base via Config.
 *
 * GLPI 11 : page servie depuis plugins/mail2glpi/public/front/ via le routeur (pas d'include).
 */

Session::checkRight('config', UPDATE);

$context = 'plugin:mail2glpi';
$keys    = ['ai_enabled', 'ai_base_url', 'ai_model', 'ai_timeout', 'ai_api_key'];

// Enregistrement.
if (isset($_POST['update'])) {
    // CSRF : validé EN AMONT par le routeur GLPI 11 (qui consomme le jeton du formulaire). On ne
    // refait PAS de Session::checkCSRF ici, sinon on revérifie un jeton déjà consommé -> « action
    // non autorisée ». Le champ caché _glpi_csrf_token reste présent pour que le routeur valide.

    $base_url_in = trim((string) ($_POST['ai_base_url'] ?? ''));
    // Confidentialité : l'URL doit être http(s) (et, en pratique, un endpoint LOCAL). On rejette
    // tout schéma exotique pour éviter une exfiltration via une URL forgée.
    if ($base_url_in !== '' && preg_match('#^https?://#i', $base_url_in) !== 1) {
        Session::addMessageAfterRedirect(
            __("L'URL de base doit commencer par http:// ou https://", 'mail2glpi'),
            false,
            ERROR
        );
        Html::back();
    }

    $values = [
        'ai_enabled'  => isset($_POST['ai_enabled']) ? '1' : '0',
        'ai_base_url' => $base_url_in,
        'ai_model'    => trim((string) ($_POST['ai_model'] ?? '')),
        'ai_timeout'  => (string) max(5, (int) ($_POST['ai_timeout'] ?? 60)),
    ];
    // La clé d'API n'est mise à jour que si un nouveau secret est fourni (champ laissé vide =
    // on conserve la clé existante, et on ne la réémet jamais dans le formulaire).
    $api_key_in = trim((string) ($_POST['ai_api_key'] ?? ''));
    if ($api_key_in !== '') {
        $values['ai_api_key'] = $api_key_in;
    }

    Config::setConfigurationValues($context, $values);
    Session::addMessageAfterRedirect(__('Configuration enregistrée.', 'mail2glpi'));
    Html::back();
}

$config = Config::getConfigurationValues($context, $keys);

$enabled     = ($config['ai_enabled'] ?? '0') === '1';
$base_url     = htmlspecialchars((string) ($config['ai_base_url'] ?? ''), ENT_QUOTES, 'UTF-8');
$model        = htmlspecialchars((string) ($config['ai_model'] ?? ''), ENT_QUOTES, 'UTF-8');
$timeout      = (int) ($config['ai_timeout'] ?? 60);
$has_key      = trim((string) ($config['ai_api_key'] ?? '')) !== '';
$token        = Session::getNewCSRFToken();
$checked      = $enabled ? 'checked' : '';
$key_holder   = $has_key ? '•••••••• (clé définie — laisser vide pour conserver)' : '';

// $_SERVER['PHP_SELF'] est échappé : on neutralise tout PATH_INFO injecté (XSS réfléchi).
$self = htmlspecialchars((string) ($_SERVER['PHP_SELF'] ?? ''), ENT_QUOTES, 'UTF-8');

Html::header(__('Mail2GLPI'), $self, 'config', 'plugin');

echo <<<HTML
<div class="card" style="max-width: 760px; margin: 16px auto; padding: 16px;">
  <h2>Mail2GLPI — Suggestions IA (local)</h2>
  <p class="text-muted">
    Suggestions de <strong>catégorie</strong>, <strong>urgence</strong> et <strong>résumé</strong>
    au dépôt d'un e-mail, via un LLM <strong>local</strong> (ex. Ollama). Aucune donnée n'est
    envoyée vers un service externe. Fonctionnalité best-effort : si l'IA est injoignable, le
    pré-remplissage de base fonctionne normalement.
  </p>
  <form method="post" action="">
    <input type="hidden" name="_glpi_csrf_token" value="{$token}">

    <p>
      <label>
        <input type="checkbox" name="ai_enabled" value="1" {$checked}>
        Activer les suggestions IA
      </label>
    </p>

    <p>
      <label for="ai_base_url">URL de base (endpoint compatible OpenAI)</label><br>
      <input type="url" id="ai_base_url" name="ai_base_url" size="60"
             placeholder="http://192.168.1.81:11434/v1" value="{$base_url}">
      <br><small class="text-muted">Ex. Ollama : http://IP-VM-IA:11434/v1 (doit rester un endpoint LOCAL)</small>
    </p>

    <p>
      <label for="ai_model">Modèle</label><br>
      <input type="text" id="ai_model" name="ai_model" size="40"
             placeholder="llama3.2:3b" value="{$model}">
    </p>

    <p>
      <label for="ai_timeout">Délai max (secondes)</label><br>
      <input type="number" id="ai_timeout" name="ai_timeout" min="5" max="300" value="{$timeout}">
    </p>

    <p>
      <label for="ai_api_key">Clé d'API (optionnelle, si reverse-proxy d'auth)</label><br>
      <input type="password" id="ai_api_key" name="ai_api_key" size="40"
             autocomplete="new-password" value="" placeholder="{$key_holder}">
    </p>

    <p>
      <button type="submit" name="update" value="1" class="btn btn-primary">Enregistrer</button>
    </p>
  </form>
</div>
HTML;

Html::footer();
