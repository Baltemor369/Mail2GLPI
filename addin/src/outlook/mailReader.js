/**
 * Lecture de l'e-mail actuellement ouvert via l'API Office.js (mode MessageRead).
 * Nécessite le requirement set Mailbox 1.8 (pour getAttachmentsAsync).
 */

/**
 * @typedef {Object} MailAttachmentMeta
 * @property {string} id
 * @property {string} name
 * @property {number} size
 * @property {string} contentType
 * @property {boolean} isInline
 */

/**
 * @typedef {Object} MailData
 * @property {string} senderEmail
 * @property {string} senderName
 * @property {string[]} ccEmails
 * @property {string} subject
 * @property {string} bodyHtml
 * @property {MailAttachmentMeta[]} attachments
 */

/**
 * Lit les informations de l'e-mail courant.
 * @returns {Promise<MailData>}
 */
export async function readCurrentMail() {
  const item = Office.context.mailbox.item;
  if (!item) {
    throw new Error("Aucun e-mail n'est ouvert.");
  }

  const [bodyHtml, attachments] = await Promise.all([
    getBodyAsHtml(item),
    getAttachments(item),
  ]);

  return {
    senderEmail: item.from ? item.from.emailAddress : "",
    senderName: item.from ? item.from.displayName : "",
    ccEmails: (item.cc || []).map((recipient) => recipient.emailAddress),
    subject: item.subject || "",
    bodyHtml,
    attachments,
  };
}

/** Récupère le corps de l'e-mail au format HTML. */
function getBodyAsHtml(item) {
  return new Promise((resolve, reject) => {
    item.body.getAsync(Office.CoercionType.Html, (result) => {
      if (result.status === Office.AsyncResultStatus.Succeeded) {
        resolve(result.value);
      } else {
        reject(result.error);
      }
    });
  });
}

/** Récupère les métadonnées des pièces jointes (sans leur contenu). */
function getAttachments(item) {
  // getAttachmentsAsync nécessite Mailbox 1.8 ; sur un client plus ancien qui chargerait
  // quand même l'add-in, on dégrade proprement vers « aucune pièce jointe ».
  if (typeof item.getAttachmentsAsync !== "function") {
    return Promise.resolve([]);
  }
  return new Promise((resolve, reject) => {
    item.getAttachmentsAsync((result) => {
      if (result.status !== Office.AsyncResultStatus.Succeeded) {
        reject(result.error);
        return;
      }
      const attachments = (result.value || []).map((attachment) => ({
        id: attachment.id,
        name: attachment.name,
        size: attachment.size,
        contentType: attachment.contentType,
        isInline: attachment.isInline,
      }));
      resolve(attachments);
    });
  });
}
