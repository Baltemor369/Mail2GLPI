# Mail2GLPI

> **GLPI 11** plugin: drag and drop an email (`.eml` or `.msg`) onto the ticket creation form and
> the fields are **filled in automatically** — optionally with **local-AI suggestions** (category,
> urgency, summary). The agent only has to review and submit.

**Version:** 1.0.0 · **GLPI:** 11.0 → 11.1 · **License:** GPL-3.0-or-later

---

## Purpose

Turning an email into a GLPI ticket is usually tedious: copy the subject, paste the body, find the
requester, attach the files, pick a category… Mail2GLPI does all of this **in a single drag &
drop**, and can **suggest the category, urgency and a summary** through a **locally hosted AI
model** — without any data leaving your network.

## Features

- 📥 **Drag & drop** of an `.eml` (RFC 822) or `.msg` (Outlook classic) email file onto the ticket
  creation form.
- ✍️ **Automatic pre-fill**: title (subject), description (sanitized body text), **source
  “Email”**, **requester** (GLPI account if the address is known, otherwise an email requester),
  **attachments** (inline signature images filtered out).
- 🤖 **Local-AI suggestions** (optional): **category** (validated against existing ITIL
  categories), **urgency** (1-5), **summary**. 100% local, best-effort (never blocks ticket
  creation).
- 🎛️ **Two-level switch**: **global** enablement by the admin (plugin configuration) + a **per-drop**
  checkbox for the agent (use AI or not for this ticket).
- 🧪 **Debug / self-test mode** (admin) to diagnose the AI chain without dropping an email.
- 🌐 **Bilingual UI** (English / French): all agent-facing texts follow the GLPI user's language.
- 🔒 **Privacy**: contents are sent only to the configured **local** endpoint, never to a cloud
  service.

## How it works

```
Drop .eml/.msg
      │
      ├─ .eml → parsed SERVER-side (ajax/parse.php → MailParser, laminas/laminas-mail)
      └─ .msg → read in the BROWSER (msg.reader lib) then mapped by the server
      │
      ▼
Form pre-fill: title · description · source · attachments
      │
      ├─ (if AI enabled + checkbox ticked)
      │     subject + body ─► ajax/enrich.php ─► AiClient ─► LOCAL LLM (OpenAI-compatible API)
      │                                    ◄─ JSON { category, urgency, summary }
      │     apply: category (validated) · urgency · summary at the top of the description
      │
      ▼
Requester set LAST (GLPI reloads the form to apply the requester's entity/template;
setting it after AI lets the reload preserve every field already filled in)
      │
      ▼
The agent reviews and clicks “Create”
```

- **`.eml`**: everything is parsed server-side (secure — the client file name is never used as a
  path).
- **`.msg`**: read in the browser (the binary is already available there); its fields are sent to
  the server for mapping, its attachments are attached client-side.
- **AI**: a **single call** returning JSON; the category is set only if it matches an existing
  category (exact match, then accent/case-insensitive, then on the leaf name for category trees).

## Requirements & recommended specs

### Plugin (GLPI server)
- **GLPI 11.0 → 11.1**, PHP **8.x** (standard GLPI extensions; `intl` recommended).
- For **large attachments**: raise PHP `upload_max_filesize` / `post_max_size` / `memory_limit`
  **and** the maximum document size in GLPI (see `docs/INSTALLATION.md`).
- The plugin itself is **lightweight** (no service, no dedicated table).

### Local AI (optional) — inference server such as **Ollama**
| Profile | CPU / GPU | RAM | Recommended model | Indicative latency |
|---|---|---|---|---|
| **Minimum (CPU)** | 2 vCPU | 8 GB | `llama3.2:1b` (q4) | ~3-8 s |
| **Comfortable (CPU)** | 4+ vCPU | 8-16 GB | `llama3.2:3b` (q4) | ~5-15 s |
| **Fast (GPU)** | GPU 4-6 GB VRAM | 8-16 GB | `llama3.2:3b` | **< 1-2 s** |

**Speed tips:**
- `OLLAMA_KEEP_ALIVE=-1`: keeps the model in RAM (removes the cold reload, ~55 s → near zero).
- Use a **smaller model** (`llama3.2:1b`) on CPU; use a **GPU** for a real performance jump.
- The endpoint must expose the **OpenAI-compatible** API (`/v1/chat/completions`), reachable
  **locally** from the GLPI server (open the port on the AI VM's firewall).

> Design constraint: **no data leaves for the cloud**. The AI is **self-hosted** on your network.

## Installation

> ⚠️ **GLPI 11**: deploy a **copy** of the folder (not a symlink). The deployed folder **must** be
> named `mail2glpi`.

```bash
# All-in-one script (git pull → copy → permissions → cache → reactivation)
bash deploy.sh
```
Optional variables: `GLPI_ROOT` (default `/var/www/html/glpi`), `WEB_USER` (`www-data`),
`GIT_REF` (`origin/master`), `PULL=0` (deploy local code without git).

Then, in GLPI: **Setup > Plugins** → install and enable **Mail2GLPI**.
Detailed guide: **[docs/INSTALLATION.md](../docs/INSTALLATION.md)**.

## Update

```bash
cd /opt/Mail2GLPI && bash deploy.sh
```
The script **re-enables the plugin automatically** (GLPI disables it on every version change).
Reload the page with **Ctrl+F5** (browser cache).

## Configuration (local AI)

**Setup > Plugins > Mail2GLPI > Configure**:
- **Enable** AI suggestions (master switch, admin),
- **Base URL** of the local endpoint (e.g. `http://AI-VM-IP:11434/v1`),
- **Model** (e.g. `llama3.2:3b`), **max timeout**, **API key** (optional),
- **Debug mode** (admin): adds a `_debug` object to responses and enables the self-test
  `ajax/enrich.php?selftest=1`.

Settings are stored in the database (`Config`, context `plugin:mail2glpi`). On the agent side, an
**“AI suggestions”** checkbox below the dropzone toggles AI **for that drop**.

## Project structure

```
plugin/
  setup.php                     Init, hooks, dropzone section rendering (+ AI checkbox)
  hook.php                      Install / uninstall (default config values)
  composer.json                 PSR-4 autoload GlpiPlugin\Mail2glpi
  src/
    MailParser.php              MIME parsing of the .eml (laminas/laminas-mail bundled with GLPI)
    TicketMapper.php            Email → ticket fields mapping + sanitization
    AiClient.php                HTTP client to the local LLM (OpenAI-compatible API)
    AiText.php                  Pure helpers (normalization, urgency parsing) — unit-testable
  public/
    ajax/parse.php              Endpoint: receives the email, returns the JSON mapping
    ajax/enrich.php             Endpoint: AI suggestions (category/urgency/summary)
    front/config.form.php       Configuration page (AI)
    js/dropzone.js              Dropzone + form pre-fill + AI call
    js/vendor/                  msg.reader (.msg) — Apache-2.0, vendored
    css/dropzone.css            Styles
  locales/                      Translation catalogs (mail2glpi domain)
docs/                           INSTALLATION.md · UTILISATION.md
tests/AiTextTest.php            Framework-less unit tests (php tests/AiTextTest.php)
deploy.sh                       Automatic deployment + reactivation
```

> **GLPI 11**: web-accessible assets and scripts must live under `public/`; the URL excludes
> `/public` (e.g. `public/js/dropzone.js` → `/plugins/mail2glpi/js/dropzone.js`). Scripts under
> `public/` are bootstrapped by the GLPI router (no `include`).

## Security & privacy

- **Authenticated** endpoints (ticket-creation right); CSRF handled by the GLPI 11 router.
- **Bounded** inputs; description injected as **escaped text** (reduced XSS surface).
- AI: **server-side calls only**, URL restricted to **local** http(s), **secret never re-emitted**
  nor logged; the `_debug` object is admin-only.

## License

**GPL-3.0-or-later**. Vendored third-party library: `msg.reader` (Apache-2.0, see
`public/js/vendor/LICENSE`).
