/* global CFG_GLPI, tinymce, getAjaxCsrfToken, uploadFile */

/**
 * Mail2GLPI — comportement de la dropzone injectée dans le formulaire de création de ticket.
 *
 * Au dépôt d'un fichier .eml : envoi à l'endpoint d'analyse du plugin, puis pré-remplissage
 * des champs du formulaire (titre, description). Le rattachement du demandeur par e-mail, des
 * observateurs et des pièces jointes reste à câbler (TODO, cf. README).
 *
 * On utilise la **délégation d'événements** au niveau du document (en phase de capture) :
 * la dropzone fonctionne même si le formulaire GLPI 11 est rendu après le chargement de ce
 * script, et on intercepte le drop avant l'uploader natif de GLPI.
 */
(function () {
    "use strict";

    const DROPZONE_ID = "mail2glpi-dropzone";

    document.addEventListener("dragover", onDragOver, true);
    document.addEventListener("dragleave", onDragLeave, true);
    document.addEventListener("drop", onDocumentDrop, true);

    /** Retourne la dropzone si l'événement se produit à l'intérieur, sinon null. */
    function closestDropzone(target) {
        const element = target && target.nodeType === 1 ? target : target && target.parentElement;
        return element ? element.closest("#" + DROPZONE_ID) : null;
    }

    function onDragOver(event) {
        const dropzone = closestDropzone(event.target);
        if (!dropzone) {
            return;
        }
        event.preventDefault(); // requis pour autoriser le drop sur la zone
        dropzone.classList.add("mail2glpi-dropzone--active");
    }

    function onDragLeave(event) {
        const dropzone = closestDropzone(event.target);
        if (dropzone) {
            dropzone.classList.remove("mail2glpi-dropzone--active");
        }
    }

    function onDocumentDrop(event) {
        const dropzone = closestDropzone(event.target);
        if (!dropzone) {
            return;
        }
        event.preventDefault();
        event.stopPropagation(); // empêche l'uploader natif de GLPI d'intercepter ce drop
        dropzone.classList.remove("mail2glpi-dropzone--active");
        handleDroppedFile(event, dropzone);
    }

    function handleDroppedFile(event, dropzone) {
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

        const csrfToken = readCsrfToken();
        const formData = new FormData();
        formData.append("emlfile", file);
        formData.append("_glpi_csrf_token", csrfToken);

        fetch(buildEndpoint(), {
            method: "POST",
            body: formData,
            credentials: "same-origin",
            headers: {
                // GLPI 11 valide le CSRF des requêtes AJAX via cet en-tête.
                "X-Requested-With": "XMLHttpRequest",
                "X-Glpi-Csrf-Token": csrfToken,
            },
        })
            .then((response) => response.text().then((text) => ({ ok: response.ok, text })))
            .then(({ ok, text }) => {
                const json = parseJsonOrThrow(text);
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

        if (data.source_id) {
            setDropdown('[name="requesttypes_id"]', data.source_id, "E-Mail");
        }
        const attachedCount = attachFiles(data.attachments || []);

        // TODO : rattacher le demandeur (data.requester_email) et les observateurs
        // (data.observers) via les widgets acteurs de GLPI.
        setStatus(dropzone, "Ticket pré-rempli. " + buildSummary(data, attachedCount), "success");
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

    function setDropdown(selector, value, fallbackLabel) {
        const select = document.querySelector(selector);
        if (!select) {
            return;
        }
        const strValue = String(value);
        const exists = Array.prototype.some.call(select.options, (o) => o.value === strValue);
        if (!exists) {
            // L'option n'est pas (encore) chargée dans le select2 : on l'ajoute.
            select.add(new Option(fallbackLabel || strValue, strValue, true, true));
        }
        select.value = strValue;
        // GLPI rend ce champ en select2 (jQuery) ; le trigger rafraîchit l'affichage.
        if (window.jQuery) {
            window.jQuery(select).trigger("change");
        } else {
            select.dispatchEvent(new Event("change", { bubbles: true }));
        }
    }

    function attachFiles(attachments) {
        // uploadFile(file, editor) est la fonction GLPI qui ajoute un fichier à l'uploader du
        // formulaire (et crée les champs cachés _filename[] pour le rattachement à la soumission).
        // L'éditeur TinyMCE est REQUIS : uploadFile() appelle editor.getElement() pour retrouver
        // l'uploader associé (via data-uploader-name).
        const editor = findContentEditor();
        if (typeof uploadFile !== "function" || !editor) {
            return 0;
        }
        let count = 0;
        attachments.forEach((attachment) => {
            if (!attachment.content_base64) {
                return;
            }
            try {
                uploadFile(base64ToFile(attachment.content_base64, attachment.name, attachment.type), editor);
                count++;
            } catch (e) {
                // best-effort : on n'interrompt pas, mais on trace pour le diagnostic.
                if (window.console) {
                    console.warn("mail2glpi : échec d'ajout de la pièce jointe", attachment.name, e);
                }
            }
        });
        return count;
    }

    function base64ToFile(base64, name, type) {
        const binary = atob(base64);
        const bytes = new Uint8Array(binary.length);
        for (let i = 0; i < binary.length; i++) {
            bytes[i] = binary.charCodeAt(i);
        }
        return new File([bytes], name || "piece-jointe", {
            type: type || "application/octet-stream",
        });
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

    function buildSummary(data, attachedCount) {
        const parts = [];
        if (data.requester_email) {
            parts.push("Demandeur : " + data.requester_email);
        }

        const attachments = data.attachments || [];
        // « éligibles » = PJ dont le contenu a été renvoyé ; les autres sont ignorées (trop
        // volumineuses ou indécodables) côté serveur — à distinguer d'un échec d'ajout.
        const eligible = attachments.filter((a) => a.content_base64).length;
        const skipped = attachments.length - eligible;

        if (attachments.length > 0) {
            if (eligible === 0) {
                parts.push(skipped + " pièce(s) jointe(s) ignorée(s) (trop volumineuse(s))");
            } else {
                let msg = attachedCount >= eligible
                    ? eligible + " pièce(s) jointe(s) ajoutée(s)"
                    : attachedCount + "/" + eligible + " pièce(s) jointe(s) ajoutée(s)";
                if (skipped > 0) {
                    msg += " · " + skipped + " ignorée(s)";
                }
                parts.push(msg);
            }
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
        // GLPI fournit un jeton CSRF réutilisable pour l'AJAX ; on l'utilise en priorité.
        if (typeof getAjaxCsrfToken === "function") {
            return getAjaxCsrfToken();
        }
        const input = document.querySelector('input[name="_glpi_csrf_token"]');
        return input ? input.value : "";
    }

    function parseJsonOrThrow(text) {
        try {
            return JSON.parse(text);
        } catch (e) {
            // Réponse non-JSON (page d'erreur HTML, session expirée…) : message lisible.
            throw new Error("Réponse inattendue du serveur (session expirée ?). Rechargez la page.");
        }
    }

    function setStatus(dropzone, message, kind) {
        const status = dropzone.querySelector(".mail2glpi-dropzone__status");
        if (!status) {
            return;
        }
        status.textContent = message;
        status.className = "mail2glpi-dropzone__status" + (kind ? " mail2glpi-dropzone__status--" + kind : "");
    }
})();
