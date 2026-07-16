ReliefWeb content analyzer
==========================

Analysis helpers for ReliefWeb editorial workflows. Only report series matching is implemented today; additional analyzers will likely live in this module later.

## Report series matching

When a new report is saved, this feature looks for earlier reports in the same recurring document series (e.g. a situation report published monthly by the same organization). If it finds a strong enough match it automatically copies the series fields (country, language, content format, themes, etc.) to the new report and attempts to generate a title that follows the series naming pattern. If confidence is lower, it still flags the report but leaves more for editorial review.

The final moderation status is adjusted based on confidence: a high-confidence match may keep the original status, while a low-confidence match will downgrade to pending. Configurable **outcome policies** can further ceiling the outcome tier (or skip applying the match) based on field provenance (e.g. tags copied only from the most recent candidate) and global rules (e.g. empty body when the series usually has body text). The applied status is never more permissive than what the submission would have received without a match.

### For editors

Inspect the match result on the **Report series matching** tab of any report: `/node/{nid}/report-series-match` (requires the `access report series matching` permission). The results summary shows evidence stats (candidates, clusters, signals) and editor-facing reasons when outcome policies demote or skip a match. Revision logs use the same short phrases.

Review applied matches on the **Report series match log** page: `/admin/content/report-series-match-log` (requires the `view report series match log` permission).

### For site administrators

Configure automation, confidence thresholds, outcome policies, and matching parameters at `/admin/config/content/reliefweb-content-analyzer` (requires `administer reliefweb content analyzer settings`).

**When automation runs automatically**

- **Reports created via the editorial form** — requires both the site-level "form create" automation flag and the `apply report series matching automation on form create` permission on the editor's account.
- **Reports submitted via the Post API or an import pipeline** — requires only the site-level "imported" automation flag; no per-user permission check.
- Automation is skipped for reports in certain moderation states (e.g. `refused`), configurable via the skip list.

### How revisions work

When a match is applied automatically, two revisions are created rather than one. The first revision saves the original submission exactly as received (as a draft), so it is always there to revert to. The second revision adds the series fields and sets the final moderation status. This means the submitter's original data is never overwritten.

### For developers

- Matcher algorithm: [`src/Services/ReportSeriesMatcher.php`](src/Services/ReportSeriesMatcher.php)
- Drupal hook integration and two-save flow: [`src/Hook/ReportSeriesMatchClassificationHooks.php`](src/Hook/ReportSeriesMatchClassificationHooks.php)
- Outcome policies: [`src/ReportSeriesMatch/SeriesMatchOutcomePolicyEvaluator.php`](src/ReportSeriesMatch/SeriesMatchOutcomePolicyEvaluator.php)
- Per-request state carried across hook phases: [`src/ReportSeriesMatch/SeriesMatchApplyContext.php`](src/ReportSeriesMatch/SeriesMatchApplyContext.php)
- Behavior examples: unit tests under `tests/src/Unit/`

### Follow-up (not yet implemented)

- Better handling when few candidates match the most specific pattern and many match a short prefix.
- AI title date accuracy (prompt / structured output).

### Dependencies

`reliefweb_files`, `reliefweb_moderation`, `ocha_ai`, `ocha_content_classification` — see [`reliefweb_content_analyzer.info.yml`](reliefweb_content_analyzer.info.yml).
