/**
 * Point d'entrée du volet (task pane) de l'add-in Mail2GLPI.
 *
 * Parcours : lecture de l'e-mail courant → aperçu du mapping → l'utilisateur valide →
 * création du ticket via l'API REST GLPI. Le rattachement du demandeur par e-mail et des
 * pièces jointes sont des étapes à finaliser lors du spike (cf. GlpiClient).
 */
import "./taskpane.css";
import { loadConfig, saveConfig, isConfigComplete } from "../config.js";
import { readCurrentMail } from "../outlook/mailReader.js";
import { GlpiClient } from "../glpi/glpiClient.js";
import { buildTicketInput, sanitizeHtml } from "../glpi/mailMapper.js";

/** État courant : l'e-mail lu, réutilisé à la création du ticket. */
let currentMail = null;

Office.onReady((info) => {
  if (info.host !== Office.HostType.Outlook) {
    return;
  }
  initSettingsForm();
  document.getElementById("create-ticket").addEventListener("click", onCreateTicket);
  loadPreview().catch((error) => setStatus(describeError(error), "error"));
});

/** Charge l'e-mail courant et alimente l'aperçu. */
async function loadPreview() {
  currentMail = await readCurrentMail();

  setText("preview-sender", formatSender(currentMail));
  setText("preview-subject", currentMail.subject || "(Sans objet)");
  setText("preview-attachments", formatAttachments(currentMail.attachments));

  // innerHTML alimenté uniquement avec du HTML assaini.
  document.getElementById("preview-body").innerHTML = sanitizeHtml(currentMail.bodyHtml);
}

/** Crée le ticket GLPI à partir de l'e-mail affiché. */
async function onCreateTicket() {
  const config = loadConfig();
  if (!isConfigComplete(config)) {
    setStatus("Renseignez d'abord les paramètres GLPI (URL, App-Token, User-Token).", "error");
    return;
  }
  if (!currentMail) {
    setStatus("Aucun e-mail chargé.", "error");
    return;
  }

  const button = document.getElementById("create-ticket");
  button.disabled = true;
  setStatus("Création du ticket en cours…");

  const client = new GlpiClient(config);
  try {
    await client.initSession();
    const ticketInput = buildTicketInput(currentMail);
    const created = await client.createTicket(ticketInput);

    // TODO (spike) : client.linkActorByEmail(created.id, currentMail.senderEmail, 1)
    //               et client.addDocument(...) pour les pièces jointes.
    setStatus(buildSuccessMessage(config.baseUrl, created.id), "success");
  } catch (error) {
    setStatus(describeError(error), "error");
  } finally {
    await client.killSession();
    button.disabled = false;
  }
}

/** Initialise le formulaire des paramètres GLPI. */
function initSettingsForm() {
  const config = loadConfig();
  document.getElementById("cfg-baseUrl").value = config.baseUrl;
  document.getElementById("cfg-appToken").value = config.appToken;
  document.getElementById("cfg-userToken").value = config.userToken;

  document.getElementById("settings-form").addEventListener("submit", async (event) => {
    event.preventDefault();
    try {
      await saveConfig({
        baseUrl: document.getElementById("cfg-baseUrl").value,
        appToken: document.getElementById("cfg-appToken").value,
        userToken: document.getElementById("cfg-userToken").value,
      });
      setStatus("Paramètres enregistrés.", "success");
    } catch (error) {
      setStatus(describeError(error), "error");
    }
  });
}

/* ------------------------------------------------------------------ */
/* Helpers d'affichage                                                 */
/* ------------------------------------------------------------------ */

function formatSender(mail) {
  if (mail.senderName && mail.senderEmail) {
    return `${mail.senderName} <${mail.senderEmail}>`;
  }
  return mail.senderEmail || "(inconnu)";
}

function formatAttachments(attachments) {
  const files = attachments.filter((attachment) => !attachment.isInline);
  if (files.length === 0) {
    return "Aucune";
  }
  return files.map((file) => file.name).join(", ");
}

function buildSuccessMessage(baseUrl, ticketId) {
  return `Ticket #${ticketId} créé : ${baseUrl}/front/ticket.form.php?id=${ticketId}`;
}

function setText(elementId, text) {
  document.getElementById(elementId).textContent = text;
}

function setStatus(message, kind) {
  const status = document.getElementById("status");
  status.textContent = message;
  status.className = "status" + (kind ? ` status--${kind}` : "");
}

function describeError(error) {
  return `Erreur : ${error && error.message ? error.message : String(error)}`;
}
