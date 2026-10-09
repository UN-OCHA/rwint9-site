ReliefWeb Post API
==================


This module provides a simple API to allow partners to submit content.


Moderation status on PUT
------------------------

Creates and updates set moderation status differently:

- **Create:** start from the provider default status. If that is `pending`,
  the final status is determined by the user's posting rights (e.g. trusted →
  `published`).
- **Update:** always re-enter as `pending` (not the provider default, to ensure
  proper rights evaluation). The final status is determined by the user's
  posting rights (e.g. trusted → `published`), including when the document
  was previously `on-hold`, `to-review`, `expired`, or `withdrawn`.

Importers can pass an explicit `status`, bypassing provider default and
posting rights.

**Provider ownership:** PUT, PATCH, and DELETE on an existing document are
allowed only when the authenticated provider matches `field_post_api_provider`
on that document. An empty provider field is denied (`403` / "Not allowed to
modify this document."). Ownership is resolved with an entity query on UUID +
provider (no full entity load), before terminal-status short-circuits. Creates
(unknown UUID on PUT) skip this check. Importers call `process()` directly and
are not subject to this HTTP gate.

**Terminal locks:** PUT/PATCH submissions for entities in statuses returned by
the bundle moderation service's `getTerminalStatuses()` are rejected (`422` /
not queued) with "Document is marked as @status (not publicly available) and cannot be updated."
Today that is `refused` and `duplicate` for jobs/training, plus `archive` for
reports. Editorial CMS edit access to those statuses uses
`edit {status} content` permissions generated from the same terminal status
lists. Terminal status is resolved with a lightweight UUID query (no full
entity load).

**Unchanged payloads:** if the request body hashes to the same value as
`field_post_api_hash`, the API returns `200` with "No changes." (no queue item,
no revision, status unchanged). The controller checks this with an entity query
(no full entity load). Processors also short-circuit on hash match. Hash no-op
is skipped when the current status is among the bundle's retired statuses
(`withdrawn`, and `expired` for jobs/training) so an identical payload can
reopen the document.


PATCH (partial update)
----------------------

`PATCH` updates an **existing** document only (unknown UUID → `404`). It uses
the same auth, rate limits, ownership, terminal locks, queue/skip-queue, and
response messages as PUT. The controller sets `partial = TRUE` (clients cannot
set this flag).

**URL:** optional. If omitted, the path UUID identifies the document. If
present, the URL pattern and UUID-from-URL checks still apply.

**Root-field semantics (replace, not merge — except report `file`):**

- Omitted key → leave the field unchanged.
- Present key with a value → **full replace** of that root field for nested
  objects/arrays other than report `file` (`image`, `dates`, term lists, etc.);
  send the complete value for that root field.
- Present key with `null` → **clear** an optional field. Clearing a mandatory
  field is rejected (`400`).
- Report **`file`** is a UUID-keyed map with merge-by-key on PATCH (see
  Report file attachments below).

**Editorial fields:** PATCH does **not** clear report headline / feature /
ocha_product overlays. Use PUT for a full replace that resets those.

**Training conditionals:** JSON Schema `allOf` rules (country ↔ on-site format,
fee_information ↔ fee-based cost) are not applied to the partial payload alone.
When a PATCH touches those fields, the processor checks the effective result
against stored field values (narrow DB lookups, no full entity load) and
rejects inconsistent clears/updates before queueing.

**Hash:** the submission hash of the PATCH body is stored. An identical PATCH
returns `200` "No changes." A later full PUT generally will not match that
hash and will requeue/reprocess normally.

**Revision log:** "Automatic partial update from Post API."


DELETE (withdraw)
-----------------

`DELETE` on an existing document sets moderation status to `withdrawn`
synchronously (no queue, no request body). Posting rights are not applied.
Terminal and already-withdrawn documents are short-circuited with a UUID
status query (no entity load). Same-provider ownership applies before those
short-circuits (see Provider ownership above).

- Unknown UUID → `404`
- Wrong or empty provider → `403` with "Not allowed to modify this document."
- Terminal status → `200` with "Document is marked as @status and is not publicly available." (no status change)
- Already `withdrawn` → `200` with "Document already withdrawn." (idempotent)
- Success → `200` with "Document withdrawn."

`expired` remains for jobs/training past their closing date. Manual close in
the CMS and Post API DELETE use `withdrawn`.


Report file attachments
-----------------------

Report `file` is a **map keyed by permanent file UUID** (not an array).

**Breaking change:** payloads that sent `file` as an array must use the map
shape. There are no external partners on this contract yet; in-repo importers
were updated.

| Intent | Payload |
|--------|---------|
| PUT exact set | `"file": { "<uuid>": { … }, … }` — only these attachments remain |
| PUT / PATCH wipe all | `"file": null` (PUT empty `{}` also clears) |
| PATCH no-op | `"file": {}` |
| PATCH upsert one/several | `"file": { "<uuid>": { … } }` — other attachments kept |
| PATCH delete one | `"file": { "<uuid>": null }` |
| Set / reorder | `"file_order": ["<uuid>", …]` — matching IDs first as listed; rest keep relative order |
| PATCH reorder only | `"file_order": […]` without `file` |

The published JSON schema describes **object** map values (PUT shape). Nested
`null` to delete an attachment is a **PATCH** rule (same idea as clearing other
optional root fields with `null`); the processor allows it at validation time.

Each non-null value must include `uuid` (equal to the map key),
`download_url`, `filename`, and `checksum`. Optional: `description`,
`language`. On PATCH, omitting `description` or `language` preserves the
current value; `null` clears it. On PUT, omitting either defaults to empty.

- **`url`:** immutable attachment identifier (like the document `url`). Required
  when **creating** an attachment; optional on **update**. If present, must
  satisfy `uuid5(document_uuid, url) ===` map key / body `uuid`. Does not need
  to resolve and is not used to download.
- **`download_url`:** fetch location (provider file URL pattern). No path-
  extension requirement. Always required for create/update. May equal `url`.
  Changing it does not change the permanent UUID.
- **`filename`:** must end in lowercase `.pdf` (public API). Importers may relax
  this for non-PDF field_file types.
- **`file_order`:** optional ordered list of attachment UUIDs. Matching IDs are
  ordered as listed; unlisted attachments keep their relative order at the end;
  unknown IDs are ignored. If omitted, existing order is preserved and new
  attachments are appended. Ignored when `file` is `null`.

Image is a single object with full replace semantics (null clears). Required:
`download_url` (must end in `.jpg` / `.png` / `.webp`), `checksum`,
`description`, `copyright`. No client `uuid`, the media UUID is derived
server-side from checksum and `download_url`.


TODO
----

- [ ] How to give feedback?
      --> Add other endpoint to query status (404, refused, pending, published)?
- [ ] Limit the fields the provider can populate?
- [ ] Validate report original publication date so it cannot be in the future?
- [ ] Review authorizing a different provider (or non–Post-API content) to
      alter documents owned by another provider.
