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
  was previously `on-hold`, `to-review`, or `expired`.

Importers can pass an explicit `status`, bypassing provider default and
posting rights.

**Terminal locks:** submissions for entities in statuses returned by the
bundle moderation service's `getTerminalStatuses()` are rejected (`422` / not
queued) and are not processed again. Today that is `refused` and `duplicate`
for jobs/training, plus `archive` for reports. Editorial CMS edit access to
those statuses uses `edit {status} content` permissions generated from the
same terminal status lists.

**Unchanged payloads:** if the request body hashes to the same value as
`field_post_api_hash`, the API returns `200` with "No changes." (no queue item,
no revision, status unchanged). The controller checks this with an entity query
(no full entity load). Processors also short-circuit on hash match.


TODO
----

- [ ] How to give feedback?
      --> Add other endpoint to query status (404, refused, pending, published)?
- [ ] Limit the fields the provider can populate?
- [ ] Validate report original publication date so it cannot be in the future?
