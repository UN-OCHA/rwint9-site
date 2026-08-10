ReliefWeb content analyzer
==========================

Analysis helpers for ReliefWeb editorial workflows. This module currently provides **report series matching** and **report near-duplicate detection**.

## Report near-duplicate detection

When a **new** report is saved, this feature looks for recent reports that appear to be near-duplicates (by default, only those that share at least one source). The current strategy compares **body text** for reports that have a body (required). Skipping reports with attachments is configurable (default **off**); attachment near-duplicates continue to use the file MinHash pipeline in `reliefweb_files` regardless.

Matching uses two gates, after building a candidate set from the short
created-date/source window **unioned** with embedding nearest neighbors:

1. **Hard (Jaccard):** word 3-gram Jaccard at or above the configured threshold (default **0.92**), after a length-ratio gate. Applies the configured Jaccard target status (default **`duplicate`**) via demotion-only restrictiveness comparison. Series matching is skipped when the result is `duplicate`.
2. **Soft (TF-IDF filter + embedding confirm):** if Jaccard does not pass, pairwise TF-IDF cosine with language stopwords must be at or above the filter threshold (default **0.70**). Those candidates are confirmed with **local cosine** on stored vectors (or freshly generated via the Content embeddings **`/embed`** endpoint). Soft matches skip the length-ratio gate. Default embedding threshold **0.90** (also used as the NN retrieval floor). Applies the configured soft target status (default **`to-review`**) demotion-only; series matching can still run afterward. If the probe cannot be embedded and no stored probe vector exists, soft confirmation is skipped (fail closed); Jaccard still works.

**Series siblings:** when first-revision titles compare as `COMPARE_SERIES_SIBLING` via `TitlePatternHelper`, hard and soft matches are discarded (shown as `series_sibling` on the inspection form) and do not demote.

### Content embeddings

Report body (and optionally title / attachment text) embeddings are stored via `EmbeddingsStorageInterface` (default: MariaDB 11.8+ `MariaDbEmbeddingsStorage`, table `reliefweb_content_analyzer_embeddings` with `VECTOR` + cosine index). Configure under **Content embeddings** on the module settings form. The duplicate matcher uses the same **embed** endpoint when a probe or soft-pending candidate has no stored vector.

Generate or refresh embeddings:

```bash
drush rwca:embed --limit=100
drush reliefweb_content_analyzer:generate-embeddings --fields=body --skip-existing=id --sort=desc
drush rwca:embed --skip-existing=hash --limit=50
drush rwca:embed --ids=4212273,4212205 --skip-existing=no
drush rwca:embed --dry-run --limit=20
```

Skip modes: `id` (default, skip existing PKs), `hash` (re-embed when text/field profile changed), `no` (always upsert). Entity delete removes the corresponding row. Storage exposes `loadVector` / `findNearest`. The duplicate matcher unions window/source candidates with `findNearest` (configurable top-K, default **50**; embedding lookback default **1095** days; no source filter on NN hits). If storage is empty/unavailable, only the window/source set is used.

On **editorial form create**, a Messenger warning lists links to the matched reports (and which gate scored them). Import / Post API saves get status + revision log only (no messenger).

### How matching works (body strategy)

1. Gates: new report; non-empty body; not in skip statuses (default `refused`, `duplicate`). When source filtering is on (default), requires `field_source`. Optionally skip when the report has `field_file` (setting **Skip reports that have file attachments**, default off).
2. Candidates: (a) window/source SQL set as before (defaults lookback **7** / lookforward **1**, limit **50**); (b) embedding top-K above the embedding similarity threshold within the embedding lookback, post-filtered for moderation / body length / attachments / exclude self. Union by nid (`window` / `embedding` / `both`).
3. Resolve probe vector: `loadVector` when the report already has an embedding; otherwise `/embed` (and upsert when `nid` is known).
4. Normalize bodies; score length ratio, Jaccard, TF-IDF. Hard match when length ratio and Jaccard pass, unless series-sibling discarded. Otherwise TF-IDF gate → local embedding cosine (load or `/embed`+upsert missing candidate vectors) → soft match unless series-sibling discarded.
5. Exact normalized hash → Jaccard 1.0. Hard matches sort above soft matches.

### When automation runs

- **Form create** — site-level form flag + `apply report duplication automation on form create` permission.
- **Post API / import** — site-level imported flag only (`field_post_api_provider` set).

Configure under **Report near-duplicate detection** at `/admin/config/content/reliefweb-content-analyzer`.

### For editors

Inspect detection on the **Report duplicate matching** tab of any report: `/node/{nid}/report-duplicate-match` (requires the `access report duplicate matching` permission). This runs the matcher with current settings and shows **all scored candidates** with length ratio, Jaccard, TF-IDF, Embedding, candidate **source**, duplicate flag, and **disposition** (gate, `series_sibling` discard, or skip reason) — or a skip reason when detection cannot run. Nothing is saved.

### For developers

- Matcher: [`src/Services/ReportDuplicateMatcher.php`](src/Services/ReportDuplicateMatcher.php)
- Hooks: [`src/Hook/ReportDuplicateMatchHooks.php`](src/Hook/ReportDuplicateMatchHooks.php) (runs before series-match hooks)
- Embeddings: [`src/Services/EmbeddingsStorageInterface.php`](src/Services/EmbeddingsStorageInterface.php), [`src/Services/MariaDbEmbeddingsStorage.php`](src/Services/MariaDbEmbeddingsStorage.php), [`src/Services/EmbeddingGenerator.php`](src/Services/EmbeddingGenerator.php), [`src/Drush/Commands/GenerateEmbeddingsCommand.php`](src/Drush/Commands/GenerateEmbeddingsCommand.php)
- Inspection form: [`src/Form/ReportDuplicateMatchForm.php`](src/Form/ReportDuplicateMatchForm.php)
- Domain types: [`src/ReportDuplicateMatch/`](src/ReportDuplicateMatch/)
- Normalizer / similarity: [`src/Helpers/PlainTextNormalizer.php`](src/Helpers/PlainTextNormalizer.php), [`src/Helpers/TextJaccardSimilarity.php`](src/Helpers/TextJaccardSimilarity.php), [`src/Helpers/TextTfidfSimilarity.php`](src/Helpers/TextTfidfSimilarity.php), [`src/Helpers/EmbeddingVectorSimilarity.php`](src/Helpers/EmbeddingVectorSimilarity.php), [`src/Helpers/Stopwords.php`](src/Helpers/Stopwords.php) (en/fr/es/ar/ru for TF-IDF)

## Report series matching

When a new report is saved, this feature looks for earlier reports in the same recurring document series (e.g. a situation report published monthly by the same organization). If it finds a strong enough match it automatically copies the series fields (country, language, content format, themes, etc.) to the new report and attempts to generate a title that follows the series naming pattern. If confidence is lower, it still flags the report but leaves more for editorial review.

The final moderation status is adjusted based on confidence: a high-confidence match may keep the original status, while a low-confidence match will downgrade to pending. Configurable **outcome policies** can further ceiling the outcome tier (or skip applying the match) based on field provenance (e.g. tags copied only from the most recent candidate) and global rules (e.g. empty body when the series usually has body text). The applied status is never more permissive than what the submission would have received without a match.

Report near-duplicate detection runs **before** series matching. If the report is set to `duplicate`, series automation does not run.

### For editors

Inspect the match result on the **Report series matching** tab of any report: `/node/{nid}/report-series-match` (requires the `access report series matching` permission). The results summary states why matching stopped or was skipped (for example not enough similar reports, or series confidence below the configured minimum) and lists editor-facing reasons when outcome policies demote or skip a match. Revision logs mention series matching only when a match was actually applied.

Review applied matches on the **Report series match log** page: `/admin/content/report-series-match-log` (requires the `view report series match log` permission).

### For site administrators

Configure automation, confidence thresholds, outcome policies, and matching parameters at `/admin/config/content/reliefweb-content-analyzer` (requires `administer reliefweb content analyzer settings`).

**When automation runs automatically**

- **Reports created via the editorial form** — requires both the site-level "form create" automation flag and the `apply report series matching automation on form create` permission on the editor's account.
- **Reports submitted via the Post API or an import pipeline** — requires only the site-level "imported" automation flag; no per-user permission check.
- Automation is skipped for reports in certain moderation states (e.g. `refused`, `duplicate`), configurable via the skip list.

### How revisions work

When a match is applied automatically, two revisions are created rather than one. The first revision saves the original submission exactly as received (as a draft), so it is always there to revert to. The second revision adds the series fields and sets the final moderation status. This means the submitter's original data is never overwritten.

### For developers

- Matcher algorithm: [`src/Services/ReportSeriesMatcher.php`](src/Services/ReportSeriesMatcher.php)
- Drupal hook integration and two-save flow: [`src/Hook/ReportSeriesMatchClassificationHooks.php`](src/Hook/ReportSeriesMatchClassificationHooks.php)
- Outcome policies: [`src/ReportSeriesMatch/SeriesMatchOutcomePolicyEvaluator.php`](src/ReportSeriesMatch/SeriesMatchOutcomePolicyEvaluator.php)
- Per-request state carried across hook phases: [`src/ReportSeriesMatch/SeriesMatchApplyContext.php`](src/ReportSeriesMatch/SeriesMatchApplyContext.php)
- Behavior examples: unit tests under `tests/src/Unit/`

When series matching proposes disasters, `field_disaster_type` is derived from those disaster terms (union of their types, excluding Complex Emergency), matching the report form. Series-copied disaster types are used only when no disasters are proposed.

### Follow-up (not yet implemented)

- AI title date accuracy (prompt / structured output).

Series candidate selection prefers a high pattern-score core and only adds lower-score matches when they are similar enough to that core (title/tag similarity), so short-prefix noise cannot outvote or artificially pad a series.

### Dependencies

`reliefweb_files`, `reliefweb_moderation`, `ocha_ai`, `ocha_content_classification` — see [`reliefweb_content_analyzer.info.yml`](reliefweb_content_analyzer.info.yml).
