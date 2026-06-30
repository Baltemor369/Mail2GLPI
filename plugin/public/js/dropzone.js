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

    // Jeton de séquence : si l'utilisateur dépose un 2e fichier avant que l'enrichissement IA du
    // 1er ne réponde, on jette le résultat périmé (sinon les résumés/catégories se mélangeraient
    // dans la description). Incrémenté à chaque dépôt ; capturé dans la closure de l'appel IA.
    let enrichSeq = 0;

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
            setStatus(dropzone, t("unsupported_format"), "error");
        }
    }

    /** .eml : le serveur analyse le fichier et renvoie le mapping (PJ incluses en base64). */
    function parseEmlAndFill(file, dropzone) {
        setStatus(dropzone, t("parsing_eml"));
        const formData = new FormData();
        formData.append("emlfile", file);
        sendToServer(formData, dropzone, (data) => {
            fillForm(data, dropzone, bundleFromServerAttachments(data.attachments || []));
        });
    }

    /** .msg : lu dans le navigateur ; ses champs vont au serveur, ses PJ restent côté client. */
    function parseMsgAndFill(file, dropzone) {
        if (typeof MSGReader !== "function") {
            setStatus(dropzone, t("msg_reader_unavailable"), "error");
            return;
        }
        if (typeof file.arrayBuffer !== "function") {
            setStatus(dropzone, t("browser_too_old"), "error");
            return;
        }
        setStatus(dropzone, t("reading_msg"));

        file.arrayBuffer()
            .then((buffer) => {
                let reader;
                let fileData;
                try {
                    reader = new MSGReader(buffer);
                    fileData = reader.getFileData();
                } catch (e) {
                    throw new Error(t("msg_unreadable"));
                }
                if (!fileData || fileData.error) {
                    throw new Error(t("msg_unreadable"));
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
            .catch((error) => setStatus(dropzone, t("error_prefix", { msg: error.message }), "error"));
    }

    /** Analyse (parse.php) : extrait le mapping puis remplit le formulaire. */
    function sendToServer(formData, dropzone, onData) {
        postForm("ajax/parse.php", formData)
            .then(({ ok, json }) => {
                if (!ok || json.error) {
                    throw new Error(json.error || t("parse_failed"));
                }
                onData(json.data);
            })
            .catch((error) => setStatus(dropzone, t("error_prefix", { msg: error.message }), "error"));
    }

    /** POST générique vers un endpoint du plugin (gère le CSRF AJAX). Résout { ok, json }. */
    function postForm(path, formData) {
        const csrfToken = readCsrfToken();
        formData.append("_glpi_csrf_token", csrfToken);
        return fetch(endpointUrl(path), {
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
            .then(({ ok, text }) => ({ ok: ok, json: parseJsonOrThrow(text) }));
    }

    function fillForm(data, dropzone, bundle) {
        setFieldValue('[name="name"]', data.title);
        setDescription(data.content);

        if (data.source_id) {
            setDropdown('[name="requesttypes_id"]', data.source_id, "E-Mail");
        }
        const attached = attachFileObjects(bundle.files);

        // TODO : rattacher les observateurs (Cc) via le widget acteurs (data-actor-type="observer").
        const summary = buildSummary(data, bundle, attached);
        let baseMsg;
        let baseKind;
        if (bundle.eligible > 0 && attached === 0) {
            // Champs remplis mais aucune PJ ajoutée (éditeur non prêt / uploader indisponible).
            baseMsg = t("prefilled_attach_manual") + (summary ? " " + summary : "");
            baseKind = "error";
        } else {
            baseMsg = t("prefilled") + (summary ? " " + summary : "");
            baseKind = "success";
        }
        setStatus(dropzone, baseMsg, baseKind);

        // IMPORTANT — ordre : on pose le DEMANDEUR en DERNIER, après l'enrichissement IA.
        // Poser un acteur déclenche un rechargement du formulaire par GLPI (pour appliquer
        // l'entité/gabarit du demandeur). Ce rechargement resoumet le formulaire et PRÉSERVE les
        // champs déjà remplis. En posant le demandeur APRÈS l'IA, la catégorie/urgence/résumé sont
        // déjà en place et survivent au rechargement (sinon il partait ~30 ms après le dépôt, bien
        // avant que le LLM réponde ~10 s plus tard, et écrasait l'enrichissement).
        const setRequesterLast = function () {
            if (data.requester) {
                setRequester(data.requester);
            }
        };

        // Enrichissement IA (catégorie / urgence / résumé) en arrière-plan : best-effort, ne bloque
        // jamais l'agent. `setRequesterLast` est appelé une fois l'IA appliquée (ou ignorée).
        enrichWithAi(data, dropzone, baseMsg, baseKind, setRequesterLast);
    }

    /* ----------------------------------------------------------------- */
    /* Enrichissement IA (asynchrone)                                    */
    /* ----------------------------------------------------------------- */

    function enrichWithAi(data, dropzone, baseMsg, baseKind, onComplete) {
        const finish = typeof onComplete === "function" ? onComplete : function () {};
        const subject = data.title || "";
        const body = data.body_plain || "";
        if (!subject && !body) {
            finish(); // pas de contenu pour l'IA : on enchaîne quand même (pose du demandeur).
            return;
        }
        // Case « Suggestions IA » du dépôt : si l'agent l'a décochée, on pré-remplit sans LLM.
        const aiToggle = document.querySelector("#mail2glpi-ai-toggle");
        if (aiToggle && !aiToggle.checked) {
            finish();
            return;
        }
        setStatus(dropzone, baseMsg + " · " + t("ai_analyzing"), baseKind);

        // On capture la séquence courante : tout dépôt ultérieur l'incrémentera et rendra
        // ce résultat périmé (à ignorer pour ne pas corrompre le formulaire du nouveau dépôt).
        const mySeq = ++enrichSeq;
        const isStale = () => mySeq !== enrichSeq;

        const formData = new FormData();
        formData.append("subject", subject);
        formData.append("body", body);

        console.debug("[mail2glpi] IA → enrich.php", { subjectLen: subject.length, bodyLen: body.length });
        postForm("ajax/enrich.php", formData)
            .then(({ ok, json }) => {
                if (isStale()) {
                    return; // un dépôt plus récent a pris la main : il posera son propre demandeur.
                }
                console.debug("[mail2glpi] IA ← enrich.php", { ok, json });
                if (ok && json && typeof json === "object") {
                    applyAiEnrichment(json, dropzone, baseMsg, baseKind);
                } else {
                    setStatus(dropzone, baseMsg, baseKind);
                }
                finish(); // demandeur posé APRÈS l'IA (le rechargement GLPI préservera les champs).
            })
            .catch((err) => {
                if (isStale()) {
                    return;
                }
                // best-effort : on retombe sur le statut de base, sans erreur bloquante.
                console.debug("[mail2glpi] IA enrich.php erreur", err);
                setStatus(dropzone, baseMsg, baseKind);
                finish();
            });
    }

    function applyAiEnrichment(ai, dropzone, baseMsg, baseKind) {
        console.debug("[mail2glpi] applyAiEnrichment", ai);
        const done = [];
        if (ai.category_id) {
            // quiet=true : ne PAS déclencher le rechargement de gabarit GLPI (qui resoumet le
            // formulaire et provoquait un 403). La catégorie reste posée pour la création.
            setDropdown('[name="itilcategories_id"]', ai.category_id, ai.category_name || t("category_fallback"), true);
            done.push(t("item_category"));
        }
        if (ai.urgency) {
            // quiet=true : comme la catégorie, on évite de réveiller un handler GLPI susceptible de
            // recharger/resoumettre le formulaire (le rechargement détruisait l'enrichissement IA).
            setDropdown('[name="urgency"]', ai.urgency, t("urgency_label", { n: ai.urgency }), true);
            done.push(t("item_urgency"));
        }
        if (ai.summary) {
            prependSummary(ai.summary);
            done.push(t("item_summary"));
        }
        const tail = done.length > 0 ? t("ai_added", { items: done.join(" + ") }) : t("ai_nothing");
        setStatus(dropzone, baseMsg + " · " + tail, baseKind);
    }

    /** Insère le résumé IA en tête de la description (texte échappé). */
    function prependSummary(summary) {
        const editor = findContentEditor();
        if (!editor) {
            return;
        }
        const block = "<p><strong>" + escapeHtml(t("ai_summary_label")) + "</strong> " + escapeHtml(summary) + "</p><p></p>";
        editor.setContent(block + editor.getContent());
    }

    function escapeHtml(value) {
        const div = document.createElement("div");
        div.textContent = String(value);
        return div.innerHTML;
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

    /**
     * Positionne la valeur d'un select (rendu en select2 par GLPI).
     *
     * @param {boolean} quiet  si true, met à jour l'affichage select2 SANS déclencher les autres
     *   handlers « change ». Indispensable pour la catégorie : un « change » classique réveille
     *   le handler GLPI qui recharge le gabarit lié à la catégorie en RESOUMETTANT le formulaire
     *   (ce qui provoquait une navigation et un 403). Le champ reste valorisé pour la soumission.
     */
    function setDropdown(selector, value, fallbackLabel, quiet) {
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
        if (window.jQuery) {
            // change.select2 = rafraîchit l'affichage select2 sans réveiller les handlers GLPI.
            window.jQuery(select).trigger(quiet ? "change.select2" : "change");
        } else if (!quiet) {
            select.dispatchEvent(new Event("change", { bubbles: true }));
        }
    }

    /**
     * Ajoute des objets File à l'uploader du formulaire via uploadFile(file, editor) de GLPI.
     * L'éditeur TinyMCE est REQUIS : uploadFile() appelle editor.getElement() pour retrouver
     * l'uploader associé (via data-uploader-name) et crée les champs cachés _filename[].
     */
    /**
     * Positionne le demandeur du ticket dans le composant « Acteurs » de GLPI 11.
     * - avec compte GLPI (items_id > 0) → acteur utilisateur ;
     * - sans compte → acteur « par e-mail » (items_id 0 + alternative_email).
     * On ajoute une option Select2 portant les données de l'acteur, puis on déclenche le
     * flux natif de GLPI (select2:select → updateActors → écriture du champ caché _actors).
     */
    function setRequester(requester) {
        if (!requester || !requester.email || !window.jQuery) {
            return;
        }
        const select = document.querySelector('select[data-actor-type="requester"]');
        if (!select) {
            return;
        }

        const isUser = Number(requester.items_id) > 0;
        const value = isUser ? String(requester.items_id) : requester.email;
        const text = requester.name || requester.email;

        // Évite un doublon si l'acteur est déjà présent.
        if (Array.prototype.some.call(select.options, (o) => o.value === value)) {
            return;
        }

        // Objet acteur au format attendu par updateActors() (lu via select2('data')).
        const actorData = {
            id: value,
            text: text,
            itemtype: "User",
            items_id: isUser ? Number(requester.items_id) : 0,
            use_notification: 1,
            default_email: isUser ? requester.email : "",
            alternative_email: isUser ? "" : requester.email,
        };

        const $select = window.jQuery(select);
        const option = new Option(text, value, true, true);
        // Select2 renvoie cet objet complet via select2('data') quand 'data' est attaché ainsi.
        window.jQuery(option).data("data", actorData);
        $select.append(option).trigger("change");
        // Déclenche le gestionnaire natif (updateActors) pour synchroniser le champ _actors.
        $select.trigger({ type: "select2:select", params: { data: actorData } });
    }

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
            // Les images "inline" (signature, logos) portent un Content-ID : elles sont
            // référencées dans le corps du mail et ne sont pas de vraies pièces jointes.
            if (attachment.pidContentId) {
                return;
            }
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
            parts.push(t("requester_label", { email: data.requester_email }));
        }

        const total = bundle.eligible + bundle.skipped;
        if (total > 0) {
            if (bundle.eligible === 0) {
                parts.push(t("att_skipped", { n: bundle.skipped }));
            } else {
                let msg = attached >= bundle.eligible
                    ? t("att_added", { n: bundle.eligible })
                    : t("att_added_partial", { a: attached, b: bundle.eligible });
                if (bundle.skipped > 0) {
                    msg += " · " + t("att_skipped_suffix", { n: bundle.skipped });
                }
                parts.push(msg);
            }
        }
        return parts.join(" · ");
    }

    /* ----------------------------------------------------------------- */
    /* Helpers divers                                                    */
    /* ----------------------------------------------------------------- */

    var i18nDict = null;

    /** Charge (une fois) le dictionnaire de traduction depuis l'attribut data- de la dropzone. */
    function i18n() {
        if (i18nDict) {
            return i18nDict;
        }
        var el = document.getElementById(DROPZONE_ID);
        var raw = el && el.getAttribute("data-mail2glpi-i18n");
        if (raw) {
            try {
                i18nDict = JSON.parse(raw);
            } catch (e) {
                i18nDict = {};
            }
        }
        return i18nDict || {};
    }

    /**
     * Traduction : récupère la chaîne `key` (langue de l'utilisateur GLPI, fournie par le serveur)
     * et remplace les placeholders {x}. Repli sur la clé elle-même si le dictionnaire est absent.
     */
    function t(key, params) {
        var dict = i18n();
        var str = Object.prototype.hasOwnProperty.call(dict, key) ? dict[key] : key;
        if (params) {
            Object.keys(params).forEach(function (name) {
                str = str.replace("{" + name + "}", params[name]);
            });
        }
        return str;
    }

    function endpointUrl(path) {
        const root = ((typeof CFG_GLPI !== "undefined" && CFG_GLPI.root_doc) || "").replace(/\/$/, "");
        return root + "/plugins/mail2glpi/" + path;
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
            throw new Error(t("unexpected_response"));
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
