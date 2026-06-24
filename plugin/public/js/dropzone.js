/* global CFG_GLPI, tinymce, getAjaxCsrfToken, uploadFile, MSGReader */

/**
 * Mail2GLPI — comportement de la dropzone injectée dans le formulaire de création de ticket.
 *
 * Au dépôt d'un fichier :
 *  - .eml → envoyé au serveur pour analyse (MailParser) ;
 *  - .msg (Outlook) → lu dans le navigateur (lib msg.reader), puis ses champs sont envoyés au
 *    serveur pour le mapping/source ; ses pièces jointes sont rattachées directement côté client.
 * Dans les deux cas, le formulaire est ensuite pré-rempli (titre, description, source, PJ).
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
        if (/\.eml$/i.test(file.name)) {
            parseEmlAndFill(file, dropzone);
        } else if (/\.msg$/i.test(file.name)) {
            parseMsgAndFill(file, dropzone);
        } else {
            setStatus(dropzone, "Format non pris en charge : déposez un fichier .eml ou .msg.", "error");
        }
    }

    /** .eml : le serveur analyse le fichier et renvoie le mapping (PJ incluses en base64). */
    function parseEmlAndFill(file, dropzone) {
        setStatus(dropzone, "Analyse de l'e-mail en cours…");
        const formData = new FormData();
        formData.append("emlfile", file);
        sendToServer(formData, dropzone, (data) => {
            fillForm(data, dropzone, bundleFromServerAttachments(data.attachments || []));
        });
    }

    /** .msg : lu dans le navigateur ; ses champs vont au serveur, ses PJ restent côté client. */
    function parseMsgAndFill(file, dropzone) {
        if (typeof MSGReader !== "function") {
            setStatus(dropzone, "Lecteur .msg indisponible (bibliothèque non chargée).", "error");
            return;
        }
        if (typeof file.arrayBuffer !== "function") {
            setStatus(dropzone, "Navigateur trop ancien pour lire les fichiers .msg.", "error");
            return;
        }
        setStatus(dropzone, "Lecture du message Outlook…");

        file.arrayBuffer()
            .then((buffer) => {
                let reader;
                let fileData;
                try {
                    reader = new MSGReader(buffer);
                    fileData = reader.getFileData();
                } catch (e) {
                    throw new Error("Fichier .msg illisible.");
                }
                if (!fileData || fileData.error) {
                    throw new Error("Fichier .msg illisible.");
                }

                const formData = new FormData();
                formData.append("mode", "msg");
                formData.append("subject", fileData.subject || "");
                formData.append("from_email", fileData.senderEmail || "");
                formData.append("from_name", fileData.senderName || "");
                formData.append("body_text", fileData.body || "");
                formData.append("body_html", fileData.bodyHTML || "");

                sendToServer(formData, dropzone, (data) => {
                    fillForm(data, dropzone, bundleFromMsgAttachments(reader, fileData.attachments || []));
                });
            })
            .catch((error) => setStatus(dropzone, "Erreur : " + error.message, "error"));
    }

    /** Envoi commun vers l'endpoint d'analyse (gère le CSRF AJAX et les erreurs JSON/HTML). */
    function sendToServer(formData, dropzone, onData) {
        const csrfToken = readCsrfToken();
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
                onData(json.data);
            })
            .catch((error) => setStatus(dropzone, "Erreur : " + error.message, "error"));
    }

    function fillForm(data, dropzone, bundle) {
        setFieldValue('[name="name"]', data.title);
        setDescription(data.content);

        if (data.source_id) {
            setDropdown('[name="requesttypes_id"]', data.source_id, "E-Mail");
        }
        const attached = attachFileObjects(bundle.files);

        // TODO : rattacher le demandeur (data.requester_email) et les observateurs
        // (data.observers) via les widgets acteurs de GLPI.
        const summary = buildSummary(data, bundle, attached);
        if (bundle.eligible > 0 && attached === 0) {
            // Champs remplis mais aucune PJ ajoutée (éditeur non prêt / uploader indisponible).
            setStatus(dropzone, "Ticket pré-rempli (pièces jointes à ajouter manuellement). " + summary, "error");
        } else {
            setStatus(dropzone, "Ticket pré-rempli. " + summary, "success");
        }
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

    /**
     * Ajoute des objets File à l'uploader du formulaire via uploadFile(file, editor) de GLPI.
     * L'éditeur TinyMCE est REQUIS : uploadFile() appelle editor.getElement() pour retrouver
     * l'uploader associé (via data-uploader-name) et crée les champs cachés _filename[].
     */
    function attachFileObjects(files) {
        const editor = findContentEditor();
        if (typeof uploadFile !== "function" || !editor) {
            return 0;
        }
        let count = 0;
        files.forEach((file) => {
            try {
                uploadFile(file, editor);
                count++;
            } catch (e) {
                // best-effort : on n'interrompt pas, mais on trace pour le diagnostic.
                if (window.console) {
                    console.warn("mail2glpi : échec d'ajout de la pièce jointe", file && file.name, e);
                }
            }
        });
        return count;
    }

    /** Construit la liste de File à partir des PJ renvoyées par le serveur (.eml, base64). */
    function bundleFromServerAttachments(attachments) {
        const files = [];
        let skipped = 0;
        attachments.forEach((attachment) => {
            if (attachment.content_base64) {
                try {
                    files.push(base64ToFile(attachment.content_base64, attachment.name, attachment.type));
                } catch (e) {
                    skipped++;
                }
            } else {
                // Ignorée côté serveur (trop volumineuse / indécodable).
                skipped++;
            }
        });
        return { files: files, eligible: files.length, skipped: skipped };
    }

    /** Construit la liste de File à partir des PJ lues dans le .msg (binaire côté navigateur). */
    function bundleFromMsgAttachments(reader, attachments) {
        const files = [];
        let skipped = 0;
        attachments.forEach((attachment) => {
            try {
                const extracted = reader.getAttachment(attachment); // { fileName, content: Uint8Array }
                if (!extracted || !extracted.content || !extracted.content.length) {
                    skipped++; // contenu vide/indisponible : on ne crée pas de fichier 0 octet
                    return;
                }
                const name = extracted.fileName || attachment.fileName || "piece-jointe";
                files.push(new File([extracted.content], name, { type: "application/octet-stream" }));
            } catch (e) {
                skipped++;
                if (window.console) {
                    console.warn("mail2glpi : pièce jointe .msg illisible", attachment && attachment.fileName, e);
                }
            }
        });
        return { files: files, eligible: files.length, skipped: skipped };
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

    function buildSummary(data, bundle, attached) {
        const parts = [];
        if (data.requester_email) {
            parts.push("Demandeur : " + data.requester_email);
        }

        const total = bundle.eligible + bundle.skipped;
        if (total > 0) {
            if (bundle.eligible === 0) {
                parts.push(bundle.skipped + " pièce(s) jointe(s) ignorée(s)");
            } else {
                let msg = attached >= bundle.eligible
                    ? bundle.eligible + " pièce(s) jointe(s) ajoutée(s)"
                    : attached + "/" + bundle.eligible + " pièce(s) jointe(s) ajoutée(s)";
                if (bundle.skipped > 0) {
                    msg += " · " + bundle.skipped + " ignorée(s)";
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
