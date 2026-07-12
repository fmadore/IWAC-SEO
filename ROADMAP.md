# IWAC SEO — Refactoring & Improvement Roadmap

Outcome of a full-codebase review (v0.5.1, ~6,200 lines). The module is in good
shape — well-documented, dependency-injected, defensively coded — so nothing
here is a rewrite. The phases below consolidate duplication, fix small
inconsistencies, harden the HTTP/perf story, add a handful of features, and
put a test suite + CI underneath the code that regresses most silently (the
citation formatting). Each phase is an independent, reviewable commit; earlier
phases never depend on later ones.

## Phase 1 — Dead code & housekeeping *(no behaviour change)*

- Remove `PageSeoStore::set()` — never called (the admin save uses
  `replaceAll()` exclusively).
- Remove `CitationFormatter::publisherYear()`'s `$trailingYear` parameter —
  never passed as `false`.
- Remove the vestigial `jsonld` per-page override from the `PageSeoStore` and
  `HeadMetadata::applyPage()` docblocks — never written by the admin form,
  never read.
- Drop the unused `$view` parameter from `StructuredData::forResource()`.
- Drop `ext-dom` from `composer.json` — all XML is string-built.
- Introduce `Module::INTERNAL_SETTINGS` for the ping-bookkeeping keys instead
  of two inline arrays.
- Derive `ConfigForm`'s "everything optional" input-filter loop from the
  form's own elements so a new field cannot be forgotten.

## Phase 2 — Consolidation *(no behaviour change)*

- **`SiteResolver` service** — one implementation of "default site → first
  site" resolution plus the canonical host URL, with a per-request cache.
  Replaces four copies: `Module::defaultSiteSlug()`,
  `SitemapController::resolveSite()`, `CitationController::defaultSiteSlug()`,
  `SeoController::resolveSite()` (and the two duplicated `hostUrl()` helpers).
- **`SettingsReader` trait** — one `boolSetting()` / `stringSetting()` shared
  by `HeadMetadata`, the three public controllers and the admin controller,
  replacing three identical copies and three subtly different inline variants.
- **Extend `ResourceValueReader`** with the helpers still duplicated across
  the citation services: `cote()`, `doi()`, `pdfUrl()`, `clip()`,
  `isOrganizationClass()`, plus shared `DATE_TERMS` / `ABSTRACT_TERMS`
  constants for the repeated property lists.
- **`StructuredData` adopts the trait** — its private `firstLiteral()`,
  `firstValueLabel()` and `valueLabels()` are re-implementations of the
  trait's `firstString()`, `firstLabel()` and `labels()`.
- **Single ping flood-cap constant** — `PingSearchEngines` references
  `Module::PING_QUEUE_CAP` instead of shadowing it with its own `FLOOD_CAP`.

## Phase 3 — Correctness fixes

- Emit `og:locale` through the same `language_TERRITORY` mapping already used
  for `og:locale:alternate` (currently a bare `fr` can slip through).
- Validate the IndexNow key in `ConfigForm` against the route's
  `[A-Fa-f0-9]{8,128}` constraint, and warn on the dashboard when a stored key
  can never be served — today a non-hex key fails silently.
- Stamp the ping throttle (`iwac_seo_ping_last`) only after a successful job
  dispatch, so a failed dispatch doesn't burn the 15-minute window.
- Document the *deliberate* difference in description-source ordering (meta
  description prefers `bibo:shortDescription`, citations prefer
  `dcterms:abstract`) so a future cleanup doesn't "unify" it by accident.

## Phase 4 — Performance & HTTP caching

- `Cache-Control: public, max-age=<ttl>` + `Last-Modified` on the sitemap
  responses so crawlers and any CDN can revalidate instead of refetching.
- `COUNT(*)` queries for the dashboard's item-set and page counts (items
  already count server-side; the other two fetch full row sets).
- Skip the chunk-bound `COUNT` query when serving `sitemap-items-1.xml` —
  chunk 1 always exists, and at IWAC's scale it is the only chunk.
- Invalidate the sitemap cache when public content changes, so new items
  appear in the sitemap immediately instead of after the TTL.

## Phase 5 — Features

- **IndexNow on removal** — also ping when an item/page is deleted or goes
  private (engines recrawl and see the 404), not just on create/update.
- **hreflang coverage report** — a dashboard table of public pages that have
  no `page_pairs` entry, surfacing drift the moment a page is added or
  renamed instead of relying on someone remembering the config map.
- **Image sitemap entries** — `<image:image>` (the item's primary-media large
  thumbnail) in the items sitemap for Google Images, config-gated
  (`iwac_seo.sitemap.include_images`).
- **Asset picker** for the share-image column of the static-page table,
  replacing the raw asset-ID number input (feasibility-checked against
  Omeka's admin asset sidebar; falls back to the current input if the core
  helper can't be reused cleanly).
- **Uninstall cleanup** — clear the sitemap cache directory on uninstall.

## Phase 6 — Tests, CI & release

- **PHPUnit suite** for the pure, Omeka-independent logic: `CitationFormatter`
  (3 styles × 2 locales × the kind matrix), `CitationExport` (BibTeX escaping,
  RIS dates, CSL-JSON date-parts), `CitationData::pageRange()`, `Hreflang`
  pair resolution, and the text utilities (`truncate`, `extractToken`) —
  extracted to a small static `Text` class so they are testable.
- **GitHub Actions** — `php -l` over the module + PHPUnit on PHP 8.2/8.3/8.4.
- **CHANGELOG.md** reconstructed from the tagged history.
- Version bump to **0.6.0**; README updated for the new behaviour.

## Deliberately out of scope

The hand-rolled citation formatter (vs a CSL processor), string-built XML
(vs DOM/XMLWriter), the settings-based page-SEO store (vs a DB table) and the
class-id dispatch maps are all justified, documented trade-offs for a
self-contained, vendor-free module — replacing them would add complexity, not
remove it.
