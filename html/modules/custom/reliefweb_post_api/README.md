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

**Terminal locks:** PUT submissions for entities in statuses returned by the
bundle moderation service's `getTerminalStatuses()` are rejected (`422` /
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


DELETE (withdraw)
-----------------

`DELETE` on an existing document sets moderation status to `withdrawn`
synchronously (no queue, no request body). Posting rights are not applied.
Terminal and already-withdrawn documents are short-circuited with a UUID
status query (no entity load).

- Unknown UUID → `404`
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
