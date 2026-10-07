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

**Root-field semantics (replace, not merge):**

- Omitted key → leave the field unchanged.
- Present key with a value → **full replace** of that root field. Nested
  objects/arrays (`file`, `image`, `dates`, term lists, etc.) are not deep-
  merged; send the complete value for that root field.
- Present key with `null` → **clear** an optional field. Clearing a mandatory
  field is rejected (`400`).

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


TODO
----

- [ ] How to give feedback?
      --> Add other endpoint to query status (404, refused, pending, published)?
- [ ] Limit the fields the provider can populate?
- [ ] Validate report original publication date so it cannot be in the future?
- [ ] Review authorizing a different provider (or non–Post-API content) to
      alter documents owned by another provider.
- [ ] Files sub-resource for single-attachment replace/remove, e.g.
      `PATCH`/`DELETE` `/api/v2/{resource}/{uuid}/files/{fileUuid}` (form-like
      replace), so clients need not resend the full `file` array.
