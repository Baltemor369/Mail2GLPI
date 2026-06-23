/**
 * Client minimal pour l'API REST de GLPI (`apirest.php`).
 *
 * Authentification : App-Token (global au plugin/déploiement) + user-token (personnel).
 * Cf. documentation GLPI : https://github.com/glpi-project/glpi/blob/main/apirest.md
 *
 * NOTE : cette classe est un squelette. `initSession`, `createTicket` et `killSession` sont
 * implémentés ; la liaison d'acteurs par e-mail et l'envoi de documents sont des points à
 * confirmer lors du spike (marqués TODO) car ils dépendent de la version exacte de l'API.
 */

/** Délai max d'une requête vers GLPI, pour éviter un volet bloqué sur un serveur lent. */
const REQUEST_TIMEOUT_MS = 15000;

export class GlpiClient {
  /**
   * @param {{ baseUrl: string, appToken: string, userToken: string }} config
   */
  constructor({ baseUrl, appToken, userToken }) {
    this.baseUrl = baseUrl;
    this.appToken = appToken;
    this.userToken = userToken;
    this.sessionToken = null;
  }

  /** URL complète d'un endpoint de l'API REST. */
  endpoint(path) {
    return `${this.baseUrl}/apirest.php/${path}`;
  }

  /** En-têtes communs ; inclut le Session-Token dès qu'une session est ouverte. */
  buildHeaders(extra = {}) {
    const headers = {
      "App-Token": this.appToken,
      "Content-Type": "application/json",
      ...extra,
    };
    if (this.sessionToken) {
      headers["Session-Token"] = this.sessionToken;
    }
    return headers;
  }

  /**
   * Ouvre une session et mémorise le Session-Token.
   * @returns {Promise<string>} le session token
   */
  async initSession() {
    const response = await this.fetchWithTimeout(this.endpoint("initSession"), {
      method: "GET",
      headers: this.buildHeaders({
        Authorization: `user_token ${this.userToken}`,
      }),
    });

    const data = await this.parseResponse(response, "Échec de l'ouverture de session GLPI");
    if (!data || typeof data.session_token !== "string") {
      throw new Error("Réponse GLPI inattendue : aucun session_token reçu (vérifiez l'URL, l'App-Token et le User-Token).");
    }
    this.sessionToken = data.session_token;
    return this.sessionToken;
  }

  /**
   * Crée un ticket.
   * @param {object} input - champs du ticket GLPI (name, content, etc.)
   * @returns {Promise<{ id: number }>}
   */
  async createTicket(input) {
    this.ensureSession();
    const response = await this.fetchWithTimeout(this.endpoint("Ticket"), {
      method: "POST",
      headers: this.buildHeaders(),
      body: JSON.stringify({ input }),
    });

    const result = await this.parseResponse(response, "Échec de la création du ticket GLPI");
    // L'API GLPI renvoie soit un objet {id, message}, soit un tableau [{id, message}]
    // selon la version / le nombre d'inputs. On normalise vers un objet unique.
    const ticket = Array.isArray(result) ? result[0] : result;
    if (!ticket || typeof ticket.id === "undefined") {
      throw new Error("Réponse GLPI inattendue : identifiant du ticket introuvable.");
    }
    return ticket;
  }

  /**
   * TODO (spike) : rattacher un demandeur/observateur par e-mail, y compris sans compte GLPI.
   * Approche pressentie : créer un `Ticket_User` (type 1 = demandeur, 3 = observateur) avec
   * `users_id = 0`, `alternative_email = <email>` et `use_notification = 1` — comme le mailgate.
   * @param {number} ticketId
   * @param {string} email
   * @param {number} type 1 = demandeur, 3 = observateur
   */
  async linkActorByEmail(ticketId, email, type) {
    void ticketId;
    void email;
    void type;
    throw new Error("linkActorByEmail : à implémenter lors du spike (cf. apirest.md / Ticket_User).");
  }

  /**
   * TODO (spike) : téléverser une pièce jointe et la rattacher au ticket.
   * L'endpoint Document attend un envoi multipart avec un `uploadManifest`.
   * @param {number} ticketId
   * @param {{ name: string, contentBase64: string, mimeType: string }} file
   */
  async addDocument(ticketId, file) {
    void ticketId;
    void file;
    throw new Error("addDocument : à implémenter lors du spike (endpoint Document multipart).");
  }

  /** Ferme la session GLPI si elle est ouverte (best-effort, n'échoue jamais). */
  async killSession() {
    if (!this.sessionToken) {
      return;
    }
    try {
      await this.fetchWithTimeout(this.endpoint("killSession"), {
        method: "GET",
        headers: this.buildHeaders(),
      });
    } catch {
      // best-effort : on ignore toute erreur réseau pour ne pas masquer l'erreur d'origine.
    } finally {
      this.sessionToken = null;
    }
  }

  /** Vérifie qu'une session est ouverte avant un appel authentifié. */
  ensureSession() {
    if (!this.sessionToken) {
      throw new Error("Aucune session GLPI ouverte : appelez initSession() d'abord.");
    }
  }

  /** `fetch` avec timeout via AbortController, pour ne jamais bloquer le volet indéfiniment. */
  async fetchWithTimeout(url, options) {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), REQUEST_TIMEOUT_MS);
    try {
      return await fetch(url, { ...options, signal: controller.signal });
    } catch (error) {
      if (error && error.name === "AbortError") {
        throw new Error(`GLPI ne répond pas (délai de ${REQUEST_TIMEOUT_MS / 1000}s dépassé).`);
      }
      throw error;
    } finally {
      clearTimeout(timer);
    }
  }

  /** Parse la réponse JSON et lève une erreur explicite en cas d'échec HTTP. */
  async parseResponse(response, errorPrefix) {
    let payload = null;
    const text = await response.text();
    if (text) {
      try {
        payload = JSON.parse(text);
      } catch {
        payload = text;
      }
    }

    if (!response.ok) {
      const detail = typeof payload === "string" ? payload : JSON.stringify(payload);
      throw new Error(`${errorPrefix} (HTTP ${response.status}) : ${truncate(detail)}`);
    }
    return payload;
  }
}

/** Tronque un message technique pour éviter d'afficher une page d'erreur entière. */
function truncate(text, max = 300) {
  if (!text) {
    return "";
  }
  return text.length > max ? `${text.slice(0, max)}…` : text;
}
