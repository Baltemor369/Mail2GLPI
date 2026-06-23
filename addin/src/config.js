/**
 * Gestion de la configuration de connexion à GLPI.
 *
 * Les paramètres sont stockés dans les « roaming settings » Office : ils sont propres à
 * l'utilisateur et à sa boîte aux lettres, et suivent l'utilisateur d'un appareil à l'autre.
 * Le `userToken` étant un secret personnel, il ne doit jamais être codé en dur ni partagé.
 */

const SETTING_KEYS = {
  baseUrl: "mail2glpi.baseUrl",
  appToken: "mail2glpi.appToken",
  userToken: "mail2glpi.userToken",
};

/**
 * Lit la configuration GLPI enregistrée pour l'utilisateur courant.
 * @returns {{ baseUrl: string, appToken: string, userToken: string }}
 */
export function loadConfig() {
  const settings = Office.context.roamingSettings;
  return {
    baseUrl: settings.get(SETTING_KEYS.baseUrl) || "",
    appToken: settings.get(SETTING_KEYS.appToken) || "",
    userToken: settings.get(SETTING_KEYS.userToken) || "",
  };
}

/**
 * Enregistre la configuration GLPI pour l'utilisateur courant.
 * @param {{ baseUrl: string, appToken: string, userToken: string }} config
 * @returns {Promise<void>}
 */
export function saveConfig(config) {
  const settings = Office.context.roamingSettings;
  settings.set(SETTING_KEYS.baseUrl, normalizeBaseUrl(config.baseUrl));
  settings.set(SETTING_KEYS.appToken, config.appToken.trim());
  settings.set(SETTING_KEYS.userToken, config.userToken.trim());
  // normalizeBaseUrl lève une erreur si l'URL est invalide ou non HTTPS : la promesse
  // ci-dessous n'est donc atteinte qu'avec une configuration valide.

  return new Promise((resolve, reject) => {
    settings.saveAsync((result) => {
      if (result.status === Office.AsyncResultStatus.Succeeded) {
        resolve();
      } else {
        reject(result.error);
      }
    });
  });
}

/**
 * Indique si la configuration est complète (les trois champs renseignés).
 * @param {{ baseUrl: string, appToken: string, userToken: string }} config
 * @returns {boolean}
 */
export function isConfigComplete(config) {
  return Boolean(config.baseUrl && config.appToken && config.userToken);
}

/**
 * Normalise et valide l'URL de base GLPI.
 * - supprime le « / » final éventuel ;
 * - exige une URL valide servie en **HTTPS** (les tokens transitent dans les en-têtes ;
 *   `http://` les exposerait en clair). Seul `localhost` est toléré en clair pour le dev.
 * @param {string} baseUrl
 * @returns {string}
 * @throws {Error} si l'URL est invalide ou non sécurisée
 */
function normalizeBaseUrl(baseUrl) {
  const trimmed = (baseUrl || "").trim().replace(/\/+$/, "");

  let parsed;
  try {
    parsed = new URL(trimmed);
  } catch {
    throw new Error("URL GLPI invalide. Exemple attendu : https://glpi.exemple.fr");
  }

  const isLocalhost = parsed.hostname === "localhost" || parsed.hostname === "127.0.0.1";
  if (parsed.protocol !== "https:" && !isLocalhost) {
    throw new Error("L'URL GLPI doit être en HTTPS pour protéger les jetons d'authentification.");
  }

  return trimmed;
}
