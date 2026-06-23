/* global CFG_GLPI, tinymce */

/**
 * Mail2GLPI — comportement de la dropzone injectée dans le formulaire de création de ticket.
 *
 * Au dépôt d'un fichier .eml : envoi à l'endpoint d'analyse du plugin, puis pré-remplissage
 * des champs du formulaire (titre, description). Le rattachement du demandeur par e-mail, des
 * observateurs et des pièces jointes reste à câbler (TODO, cf. README).
 */
(function () {
    "use strict";

    // Le conteneur n'est présent que sur la fiche d'un nouveau ticket (rendu par setup.php).
    function init() {
        const dropzone = document.getElementById("mail2glpi-dropzone");
        if (!dropzone) {
            return;
        }
        wireDropzone(dropzone);
    }

    function wireDropzone(dropzone) {
        ["dragenter", "dragover"].forEach((type) => {
            dropzone.addEventListener(type, (event) => {
                event.preventDefault();
                dropzone.classList.add("mail2glpi-dropzone--active");
            });
        });
        ["dragleave", "drop"].forEach((type) => {
            dropzone.addEventListener(type, (event) => {
                event.preventDefault();
                dropzone.classList.remove("mail2glpi-dropzone--active");
            });
        });
        dropzone.addEventListener("drop", (event) => onDrop(event, dropzone));
    }

    function onDrop(event, dropzone) {
        const file = event.dataTransfer && event.dataTransfer.files[0];
        if (!file) {
            return;
        }
        if (!/\.eml$/i.test(file.name)) {
            setStatus(dropzone, "Format non pris en charge : déposez un fichier .eml.", "error");
            return;
        }
        parseAndFill(file, dropzone);
    }

    function parseAndFill(file, dropzone) {
        setStatus(dropzone, "Analyse de l'e-mail en cours…");

        const formData = new FormData();
        formData.append("emlfile", file);
        formData.append("_glpi_csrf_token", readCsrfToken());

        fetch(buildEndpoint(), {
            method: "POST",
            body: formData,
            credentials: "same-origin",
        })
            .then((response) => response.json().then((json) => ({ ok: response.ok, json })))
            .then(({ ok, json }) => {
                if (!ok || json.error) {
                    throw new Error(json.error || "Échec de l'analyse.");
                }
                fillForm(json.data, dropzone);
            })
            .catch((error) => setStatus(dropzone, "Erreur : " + error.message, "error"));
    }

    function fillForm(data, dropzone) {
        setFieldValue('[name="name"]', data.title);
        setDescription(data.content);

        // TODO : rattacher le demandeur (data.requester_email) et les observateurs
        // (data.observers) via les widgets acteurs de GLPI, et téléverser data.attachments.
        const summary = buildSummary(data);
        setStatus(dropzone, "Ticket pré-rempli. " + summary, "success");
    }

    /* ----------------------------------------------------------------- */
    /* Helpers de remplissage                                            */
    /* ----------------------------------------------------------------- */

    function setFieldValue(selector, value) {
        const field = document.querySelector(selector);
        if (field && typeof value === "string") {
            field.value = value;
            field.dispatchEvent(new Event("change", { bubbles: true }));
        }
    }

    function setDescription(html) {
        if (typeof html !== "string" || !window.tinymce) {
            return;
        }
        // On cible explicitement l'éditeur de la description (textarea name="content"),
        // et non l'éditeur actif (qui dépend du dernier clic de l'utilisateur).
        const editor = findContentEditor();
        if (editor) {
            editor.setContent(html);
        }
    }

    function findContentEditor() {
        const textarea = document.querySelector('textarea[name="content"]');
        if (textarea && textarea.id) {
            const editor = tinymce.get(textarea.id);
            if (editor) {
                return editor;
            }
        }
        return tinymce.activeEditor || null;
    }

    function buildSummary(data) {
        const parts = [];
        if (data.requester_email) {
            parts.push("Demandeur : " + data.requester_email);
        }
        const attachmentCount = (data.attachments || []).length;
        if (attachmentCount > 0) {
            parts.push(attachmentCount + " pièce(s) jointe(s) à rattacher manuellement");
        }
        return parts.join(" · ");
    }

    /* ----------------------------------------------------------------- */
    /* Helpers divers                                                    */
    /* ----------------------------------------------------------------- */

    function buildEndpoint() {
        const root = (typeof CFG_GLPI !== "undefined" && CFG_GLPI.root_doc) || "";
        return root + "/plugins/mail2glpi/ajax/parse.php";
    }

    function readCsrfToken() {
        const input = document.querySelector('input[name="_glpi_csrf_token"]');
        return input ? input.value : "";
    }

    function setStatus(dropzone, message, kind) {
        const status = dropzone.querySelector(".mail2glpi-dropzone__status");
        if (!status) {
            return;
        }
        status.textContent = message;
        status.className = "mail2glpi-dropzone__status" + (kind ? " mail2glpi-dropzone__status--" + kind : "");
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", init);
    } else {
        init();
    }
})();
