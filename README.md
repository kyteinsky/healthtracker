<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Health Tracker 🩺

A Nextcloud app for recording and visualising lab test results from medical
reports (CBC, lipid profiles, vitamin panels and more). Import images or PDFs
of your lab sheets; the app OCR-extracts their text and uses a language model
to pull out the structured data — patient, date, doctor, lab and every
individual test with its value, unit, reference range and a severity tier
inferred from the sheet's own flags.

## Features

- 📄 **Import** — add files or folders from your Nextcloud via the file picker.
  Processing is tracked end to end: pending → OCR running → extraction → done
  (or failed). The status list polls while work is in flight.
- 🤖 **Task Processing** — uses the Nextcloud Task Processing API
  (`core:image2text:ocr` for OCR, `core:text2text` for LLM extraction). The
  admin enables an OCR provider and a Chat/LLM provider in Nextcloud's AI
  settings; nothing is hard-wired to a specific backend.
- 📊 **Results** — a chronological, colour-coded table of every test result
  across all your reports, filterable by test name and date range. Values are
  tinted by severity; a report column shows the source report's date and lab.
- 📈 **Trends** — pick a single test (e.g. Platelets Count) and see its values
  over time on a theme-aware SVG line chart, with the reference range drawn as
  a faint band and points coloured by severity.
- 🔒 **Private** — each user sees only their own reports and documents.

## Architecture

```
healthtracker/
├── appinfo/            info.xml, routes.php
├── lib/
│   ├── AppInfo/        Application.php — registers TaskProcessing event listeners
│   ├── Controller/     PageController (SPA), ConfigController, DocumentController, ReportController + ApiControllerTrait
│   ├── Db/             Document, Report, TestResult entities + QBMappers
│   ├── Migration/      initial schema (3 tables)
│   ├── Service/        DocumentService (lifecycle orchestrator), ExtractionService (LLM prompt + JSON parser),
│   │                   ProviderService (TaskProcessing availability), ConfigService
│   ├── Listener/       TaskProcessingListener — reacts to OCR/extraction success/failure
│   ├── Settings/       AdminSection + AdminSettings (provider status panel)
│   └── Exception/      domain exceptions
├── src/                Vue 3 SPA — App.vue, router, store, api, 3 views, 4 components
├── templates/          main.php (SPA mount), settings/admin.php
└── img/                app icons
```

### Data model

- `healthtracker_documents` — the imported input files, their processing status,
  cached OCR text and task ids.
- `healthtracker_reports` — one parsed lab report (patient, dates, doctor, lab,
  report type, raw LLM JSON for audit).
- `healthtracker_test_results` — individual tests within a report: name, value
  (text + numeric), unit, reference range (text + parsed bounds), severity tier
  and a severity note. Denormalised `user_id` for fast per-user queries.

### Severity model

Severity is **LLM-extracted per document**: the model reads each sheet and
returns, per test, a severity tier it infers from the sheet's own flags and
footnotes (`H`, `L`, `Critical`, `mild`, etc.). Valid tiers:
`normal · low · high · mild · cautious · severe · abnormal · unknown`.
The app does not maintain global per-test thresholds — severity reflects what
the lab actually printed.

### Processing flow

1. User picks files/folders → `DocumentController::create` →
   `DocumentService::addFiles` creates `PENDING` rows and schedules an OCR
   `Task` (`core:image2text:ocr`, customId = document id).
2. `TaskProcessingListener` receives `TaskSuccessfulEvent` for OCR →
   `handleOcrSuccess` stores the text, then schedules an LLM `Task`
   (`core:text2text`) with the extraction prompt.
3. `TaskSuccessfulEvent` for the LLM → `handleExtractionSuccess` parses the
   JSON output, creates a `Report` + `TestResult` rows in a transaction, sets
   the document `DONE`.
4. `TaskFailedEvent` → `handleFailure` marks the document `OCR_FAILED` or
   `FAILED` with the error message.

Each user's data is ownership-scoped on every read/write.

## Development

```bash
# PHP (run from the Nextcloud server root or here)
composer install
composer cs:check
composer psalm

# Frontend
npm install --legacy-peer-deps   # ecosystem peer ranges require this flag
npm run build                    # → js/healthtracker-*.mjs + chunk CSS
npm run watch                    # rebuild on change during development
```

Enable the app:

```bash
occ app:enable healthtracker
```

Then, as an admin, open **Administration settings → AI settings** and enable:

- an **OCR** provider (task type `core:image2text:ocr`) — e.g. the OpenAI /
  LocalAI integration or another ExApp providing OCR.
- a **Chat / text-to-text** provider (task type `core:text2text`) — e.g.
  `llm2`, the OpenAI / LocalAI / Ollama integration, or `integration_watsonx`.

The Health Tracker admin panel (**Administration settings → Health Tracker**)
shows current provider availability so you can confirm both are wired up before
users start uploading reports.

## License

AGPL-3.0-or-later
