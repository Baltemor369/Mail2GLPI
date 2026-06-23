/**
 * Transformation d'un e-mail (lu via Office.js) vers les champs d'un ticket GLPI.
 *
 * Sécurité : le corps HTML d'un e-mail est une donnée non fiable. Il est systématiquement
 * assaini avec DOMPurify avant d'être affiché dans le volet OU envoyé à GLPI, pour éviter
 * toute injection (XSS stocké côté GLPI, exécution de script dans le volet).
 */
import DOMPurify from "dompurify";

/**
 * Assainit du HTML provenant d'un e-mail.
 *
 * On conserve la **liste blanche par défaut** de DOMPurify (profil HTML), qui supprime déjà
 * scripts, gestionnaires d'événements et contenus actifs. On ne définit volontairement PAS de
 * `FORBID_TAGS`/`FORBID_ATTR` partiels : raisonner en liste noire affaiblirait la protection
 * (des handlers comme `onmouseover`, `onfocus`, etc. passeraient au travers).
 * @param {string} html
 * @returns {string} HTML sûr
 */
export function sanitizeHtml(html) {
  return DOMPurify.sanitize(html || "", { USE_PROFILES: { html: true } });
}

/**
 * Construit l'objet `input` attendu par l'API GLPI pour créer un ticket.
 * @param {{ subject: string, bodyHtml: string }} mail
 * @returns {{ name: string, content: string }}
 */
export function buildTicketInput(mail) {
  return {
    name: buildTitle(mail.subject),
    content: sanitizeHtml(mail.bodyHtml),
  };
}

/**
 * Construit un titre de ticket à partir du sujet de l'e-mail.
 * @param {string} subject
 * @returns {string}
 */
export function buildTitle(subject) {
  const cleaned = (subject || "").trim();
  return cleaned || "(Sans objet)";
}
