# Changelog

All notable changes to the IWAC SEO module. Versions follow
[semantic versioning](https://semver.org/); dates are ISO 8601.

## 1.0.2 — 2026-09-01

### Fixed

Six rich-result errors in Search Console, all of them in this module's own
JSON-LD, covering **1,091 flagged URLs**. Verified by running `StructuredData`
against the live archive record behind every one of them.

- **`thumbnailUrl` was missing from all 1,790 videos** (989 flagged so far, and
  climbing daily — it is the largest of the six). `image` was being emitted and
  is not a substitute: schema.org requires `thumbnailUrl` on a `VideoObject`.
  It is now taken from the item's own media and **only** from there. `image`
  may honestly fall back to the site's default share graphic, because a share
  card needs some picture; `thumbnailUrl` may not, because it asserts that the
  picture depicts the video. `HeadMetadata::resourceImage()` therefore splits
  into `resourceThumbnail()` (own media, or null) plus that fallback at the one
  call site that wants it. 1,788 of 1,790 videos now carry one.

- **An `Event`'s `startDate` was rejected as "not ISO 8601" whenever the record
  held an interval.** 55 of the 243 event records do — `2000/2001`,
  `1979-11-04/1981-01-20` — which schema.org expresses as `startDate` +
  `endDate`, not as one value. `Text::dateRange()` splits them, and drops
  either side that is not a plain ISO date instead of passing it through: a
  date a validator cannot read invalidates the node around it, so none is
  better than one. Only one such URL had been flagged; the other 54 were
  waiting to be crawled.

- **A conference paper's `isPartOf` event had neither `startDate` nor
  `location`.** A validator reads a nested node as a full `Event`, not as a
  reference to one, so it wants an Event's required fields — and the paper
  knows both: its `dcterms:date` is when the event happened and its
  `dcterms:provenance` is where (Berlin, for a talk at the ZMO Open Day). This
  was most of the "missing startDate" and "missing location" reports, which
  named communication records that contain no `Event` of their own.

- **Book reviews are no longer typed `Review`.** Google's review snippet
  requires `reviewRating.ratingValue`, and an academic book review awards no
  score — so `Review` could only ever be reported invalid, and supplying the
  missing `itemReviewed` would have traded "missing itemReviewed" for "missing
  reviewRating" on the same 12 URLs. `fabio:BookReview` (class 178) now maps to
  `ScholarlyArticle`, which is what a book review in a journal is, with the
  reviewed book in `schema:about` from `bibo:reviewOf` — present on all 19
  review records. The citation side is untouched: the Zotero/Highwire kind
  stays `review`, since Zotero has an item type for it and Google does not.

Net: **1,051 of the 1,091 flagged URLs** now satisfy every required property.

### Known data gaps

The remaining 40 are missing metadata in the archive, not markup this module
can generate, so they are listed rather than papered over. Filling any of them
makes the record valid on the next crawl with no code change:

| gap | items |
|---|---|
| Event with no `dcterms:date` (13) | 126, 147, 199, 200, 203, 204, 206, 15616, 23519, 23536, 23585, 23651, 67433 |
| Event with no `dcterms:spatial` (2) | 186, 15773 |
| Audiovisual with no date at all (23) | 15852–15893, a single accession of DVD recordings |
| Audiovisual with no media, so no thumbnail (2) | 78275, 78278 |

`Event` and `VideoObject` are kept on these records even so — 230 of 243 events
and 1,760 of 1,790 videos do satisfy the requirements, so the types earn their
place. That is the same test `Review` failed and the reason it was dropped: not
"is this record complete?" but "can this type ever be satisfied?".

## 1.0.1 — 2026-08-31

### Fixed
- **The layout gap-fill made every query permutation its own canonical page.**
  `applyGlobals()` filled a missing canonical with `serverUrl(true)` — the URL
  *including* its query string — for any route no phase-1 listener claimed.
  `/s/{site}/search` is IwacSearch's controller, so that is exactly what
  happened there: each facet combination emitted a self-referential canonical
  declaring itself a distinct page worth indexing, and, because `applyBrowse()`
  never ran, none of them carried the `noindex, follow` that the browse routes
  pair with a self-canonical. Search Console has 1,306 URLs from that crawl
  space in its structured-data report, all of them variants of one page.

  Unclaimed routes now canonicalise to the query-less URL and mark a
  query-carrying variant `noindex, follow`. The browse listener keeps its
  self-referential canonical — page 2 of a listing is a real page in a series
  and must not collapse onto page 1 — so the two rules now sit side by side,
  each with the reason it differs from the other. `og:url`, previously re-derived
  from `serverUrl(true)`, now mirrors whatever canonical was written, so a share
  of a filtered page can no longer claim an identity the canonical disowns.

  The query test moved to `Text::withoutQuery()`, which both call sites share.

### Not a code change, but the release this belongs with

The Search Console report that prompted this release had a second, larger cause
with no code in it. Every public resource page also carried a
`<script type="application/ld+json">` in the page body holding Omeka's full API
representation of the resource (`@context: /api-context`). Omeka S core puts it
there — `application/Module.php` attaches
`AbstractResourceRepresentation::embeddedJsonLd()` to `view.show.after` and
`view.browse.after` on the Item/ItemSet/Media controllers, "for the purpose of
machine-readable metadata discovery".

At IWAC's scale that block is 157 KB on an ordinary item (it carries the whole
OCR text), 25 blocks / 507 KB on a browse page, and **2.2–3.9 MB on an authority
record**, where the representation's `@reverse` enumerates all ~13,000 items
that link to it. Google truncates it, and the cut landing at a different byte
offset per `?page=N` is exactly why one URL family produces four different
"unparsable structured data" messages in Search Console (48 × missing `}`,
4 × missing `:`, 2 × missing `,` or `}`, 1 × missing `,` or `]`).
validator.schema.org, which has no size cap, parses the same page cleanly:
13,398 objects, zero errors, one `UNKNOWN_JSONLD_CONTEXT` warning.

Core gates it on one per-site checkbox — **Admin → Sites → … → Settings →
General → "Disable JSON-LD embed"** (`disable_jsonld_embed`) — **ticked on both
`afrique_ouest` and `westafrica` on 2026-08-31**. Nothing on the site consumed
the embed (no theme or module JS reads `ld+json`), the same data is still served
at `/api/items/{id}`, and Zotero import here goes through unAPI and the citation
meta tags this module emits. Measured on the live site immediately after:

| page | before | after |
|---|---|---|
| `item/67396` (authority record) | 2,313,338 B | 103,622 B |
| `item/10563` (publication issue) | 362,588 B | 205,100 B |
| `/item` (browse, 25 results) | 564,994 B | 57,258 B |

What remains on a resource page is this module's own JSON-LD — the schema.org
`@type` for the resource class plus a `BreadcrumbList`, ~350 and ~500 bytes in
`<head>` — which is the only structured data Google could ever have used for a
rich result anyway.

## 1.0.0 — 2026-08-26

First stable release. The module has run islam.zmo.de's SEO, citation and
sitemap layer since 0.1.0; 1.0.0 records that its settings,
its `iwac_seo.*` configuration keys and its public routes (`/sitemap.xml`,
`/robots.txt`, `/unapi`, the citation endpoints) are now a compatibility
promise rather than a moving target. Changing any of them incompatibly needs a
major bump from here on. Nothing else about the module changes at 1.0.0 beyond
what is listed below.

### Fixed
- **Two pages emitted no `hreflang` alternates and a third advertised one that
  404s.** `audiovisuel` / `audiovisual` (Vidéos et enregistrements / Video and
  recordings) had no row in `page_pairs`, so neither side linked to the other;
  and the row for `press-language` named a French page `language-presse` that
  does not exist — the live slug is `langue-presse` — so the English page
  advertised an alternate that leads nowhere while the French one advertised
  nothing. Regenerated from the Internationalisation module, which had all
  three pairings recorded correctly: the table was wrong, not the site.

  Worth noting for how the table is maintained: 0.10.0 verified all 37 pairs
  against that module on 24 August, and this drift was present two days later.
  A generated table with a weekly job behind it is the right shape for
  something that goes stale on a timescale like that.

- **The coverage report marked as covered the one page it should have flagged
  loudest.** `hreflangGaps()` asked only whether a page's slug appeared
  somewhere in `page_pairs`, so a row naming a counterpart that no longer
  exists counted as coverage — the stale row concealed the page it broke.
  `press-language` was therefore absent from the dashboard's list precisely
  because it had a row, while the two genuinely unpaired pages were reported.

  The report now distinguishes the two failures and lists the worse one first:
  **broken alternate links** (paired with something that is not a public page,
  so the alternate 404s — worse for search engines than emitting none) and
  **pages with no pair** (which correctly emit nothing). Deciding this needs
  both sites' pages in hand, since whether a counterpart resolves is a question
  about the *other* site, so the dashboard now loads them before judging
  either. `Hreflang::partnersFor()` exposes what to check; `Hreflang` itself
  stays pure config, with no lookup added to any page render.

## 0.10.0 — 2026-08-24

### Fixed
- **`hreflang` alternates were missing on 20 pages and broken on one.** The
  `page_pairs` table had drifted from the site: `collection-overview`,
  `explore`, `browse`, `references`, `secularism`, the six country pages and
  every visualisation page had no entry, so they emitted no cross-language
  alternate at all. Worse, `sentiment-analysis` was mapped to a French
  `sentiment-analysis` page that does not exist — the real slug is
  `analyse-sentiment` — so that page advertised an alternate that 404s, which
  is worse for SEO than advertising none. All 37 page pairs on both sites are
  now covered and verified against the Internationalisation module's own
  mapping.

### Removed
- **16 dead `page_pairs` rows.** `news`/`nouvelles`, `map-browse`/`carte`,
  `article`, `book`/`livre`, `iwac-chatbot`, `sub-collections`,
  `submit-a-reference`, `digital-humanities-ai`, `iwac-keyword-explorer`,
  `spatial-network-visualisation` and the six `visualisations-XX` country
  pages named pages that no longer exist on either site. They were inert —
  `pairFor()` only matches slugs that actually render — but they made the
  table half fiction, which is how the wrong `sentiment-analysis` row stayed
  hidden in it.

### Added
- **`page_pairs` is now generated rather than hand-maintained.** The
  Internationalisation module already records each page's counterpart and the
  REST API exposes it as `o-module-internationalisation:related_page`, so that
  module is the only place a pairing is authored and the committed table is a
  build product. `composer hreflang:fix` regenerates it;
  `composer hreflang:check` audits it and fails on three kinds of drift —
  pairs the module records that the table omits, rows that contradict it, and
  rows naming pages that no longer exist. Output is sorted and aligned
  deterministically, so regenerating an already-correct table rewrites
  nothing.

  `Hreflang` itself deliberately does not read the module at request time: it
  stays pure config, with no API or database access on a page render. Making
  the table a generated file gets the single source of truth without paying
  for a lookup on every page.

- **The `hreflang drift` workflow keeps it current by itself.** Adding or
  renaming a page invalidates the table, and nothing in this repository
  changes when that happens, so a push-triggered job would never fire when the
  damage is done. The workflow regenerates the table weekly and opens a pull
  request if anything moved — a pull request rather than a push to main,
  because the table drives what search engines are told about every page, and
  onto one fixed branch so a weekly cron cannot accumulate a pull request per
  Monday for the same drift. It also audits, without rewriting, any PR that
  touches the table. Since the module is developed without PHP available, the
  regeneration has to happen in CI to be useful at all. Kept out of
  `composer check` and `ci.yml` so a live-site dependency can never fail an
  unrelated code PR.

## 0.9.0 — 2026-08-05

### Added
- **Real Omeka/Laminas integration coverage.** A dedicated PHP 8.5 CI job
  installs Omeka S 4.2.1, loads the application vendor before the module's
  development vendor, and exercises `CitationMeta`, `ZoteroRdf`, `HeadMetadata`,
  service/controller manager wiring and public-route matching against the real
  framework classes. The fast unit suite still uses its deliberately narrow
  shims; no Laminas or PSR package is added to the module.

- **The licence is now actually distributed.** `composer.json` and the README
  had declared GPL-3.0-or-later since the beginning, but no `LICENSE` file was
  ever committed, so GitHub reported the repository as unlicensed and no copy
  carried its terms. Adds the full GPL-3.0 text.
- **`CITATION.cff`.** Citation File Format 1.2.0 metadata with ORCID and
  affiliation, so GitHub renders a "Cite this repository" button and emits APA
  and BibTeX. Fitting for a module whose subject is citation metadata.
- **Releases ship an installable zip.** `.github/workflows/release.yml` builds
  `IwacSeo-<version>.zip` with `git archive --prefix=IwacSeo/` on a `v*` tag,
  which both honours the export-ignore rules and gives the top-level folder the
  name the namespace requires — GitHub's own source archives name it after the
  repository and are therefore not installable. The job cross-checks the tag
  against `config/module.ini` and `CITATION.cff`, asserts that no development
  file leaked into the zip, draws release notes from this changelog, and can be
  run manually to rehearse the packaging without publishing anything.

### Fixed
- **Development files no longer leak into distributed archives.** `CLAUDE.md`
  and `phpunit.integration.xml.dist` were both missing from the export-ignore
  list — the latter because it was added after the list was last reviewed —
  and appeared in the output of `git archive` and `composer archive`.
- **Open Graph metadata now works with Omeka's HTML5 doctype.** Laminas View
  2.x rejects `HeadMeta::setProperty()` unless the doctype is RDFa, while Omeka
  configures HTML5 and Open Graph requires `property="…"`. `HeadWriter` now
  retains the public helper path where supported and uses the helper's public
  container as the compatibility path, including repeated locale alternates.

## 0.8.0 — 2026-07-31

### Changed
- **Sitemap policy now depends on a narrow repository contract.**
  `SitemapRepositoryInterface` keeps Doctrine behind the production adapter and
  lets the generator be tested in memory: child-chunk selection, homepage/menu/
  unlisted-page ordering, URL encoding, image URLs and reciprocal hreflang links
  now have direct regression coverage.
- **CI metadata and dependency downloads are hardened.** Composer metadata is
  validated strictly, its download cache is shared across jobs with
  `actions/cache@v5`, workflow permissions are read-only, superseded runs are
  cancelled and both jobs have explicit timeouts. The test matrix remains PHP
  8.2–8.5, covering both the declared floor and the production runtime.
- **CI runs the whole of `composer check`.** A second `quality` job adds the
  PSR-12 check, PHPStan (level 6) and the translation-template freshness gate,
  which were declared in 0.7.0 but enforced nowhere — a green build previously
  covered only `php -l` and PHPUnit. Its steps run independently of each other's
  failures, so one push reports every category at once. `actions/checkout` moved
  v4 → v7 (Node runtime; the v7 fork-PR restriction applies to
  `pull_request_target`/`workflow_run`, neither of which this workflow uses).
- **PHP 8.5 added to the test matrix.** It is what islam.zmo.de runs, so the
  deployed version was the one version never tested. The matrix now spans the
  declared floor (8.2) to production (8.5).
- **`phpstan.neon.dist` rewritten for PHPStan 2.x.** The configuration had never
  been executed and did not work: its ignore patterns matched 1.x message
  wording, so five of six matched nothing and — with `reportUnmatchedIgnoredErrors`
  defaulting to true — became errors in their own right. Ignores are now keyed by
  error identifier, and `tests/Stub/omeka-laminas.stub` declares the seven Omeka
  and Laminas supertypes the module extends, because "extends unknown class" is
  the one error PHPStan refuses to let `ignoreErrors` suppress; without it those
  files would have had to leave analysis entirely via `excludePaths`.
- **PHP_CodeSniffer bumped to `^4.0`** (4.0.1), which needed no ruleset changes
  and found nothing to fix — 75 files, zero errors. 4.0 removes the JS/CSS and
  MySource standards, splits `PSR12.Files.FileHeader.SpacingAfterBlock` into
  several error codes, and drops the old array-property and
  `@codingStandardsIgnore` syntaxes; this ruleset uses none of them, and its two
  exclusions name whole sniffs rather than error codes, so the split passed
  straight through.
- **PSR-12 conformance.** `PSR12.Files.FileHeader` is excluded, with a rationale:
  every file opens `<?php` / `declare(strict_types=1);` / docblock, where the
  standard wants the docblock first, and the house order is uniform across ~80
  files. `PSR1.Classes.ClassDeclaration.MultipleClasses` is excluded for
  `tests/Shim/omeka.php`, which declares its stand-ins in one file on purpose.
  The remaining 13 findings are fixed: two multi-line conditions reflowed, a
  wrapped call joined, a stray blank line after a class brace removed, and two
  `foreach ([…] as $x)` literals hoisted to named arrays.

### Fixed
- **JSON-LD no longer changes escaping for the shared `HeadScript` helper.** The
  `noescape` exception is attached only to each `application/ld+json` entry;
  scripts appended later by the theme retain Laminas's normal escaping.
- **The translation template no longer goes stale when a string merely moves.**
  `#:` references now carry the file path alone; with line numbers, deleting one
  blank line in `SeoController.php` shifted six references and failed
  `composer i18n:check` without a single msgid changing. Since the module is
  developed without PHP, regenerating is not a local one-liner, so a gate that
  fired on layout rather than content cost a CI round trip each time. It now
  fails when the set of translatable strings actually changes.
- **`CitationFormatter::creators()` documented the wrong parameter type** —
  `@param array<string,mixed> $record` against a native `CitationRecord`, left
  behind when 0.7.0 gave the record a type.
- **`StructuredData::links()` had an unparseable `@return`.** The `@type` key in
  the array shape needs quoting; unquoted, the whole docblock was discarded, so
  the method's return type was untyped as well.
- **`View\Helper\Citation` injected `CitationExport` and never read it.** The
  helper only ever touches `CitationExport::FORMATS` statically. Dropped from the
  constructor and the factory.

### Added
- IWAC configuration-contract tests: the schema.org and citation class maps
  must stay aligned across all 19 known resource classes (including all nine
  reference classes), hreflang page pairs must be complete and unique, sitemap
  chunks must respect the 50,000-URL protocol limit, and runtime Composer
  dependencies may not duplicate Omeka's Laminas/PSR packages.
- `phpstan-baseline.neon`, recording the 59 findings that predate analysis being
  enforced — 56 missing array value types, plus three deliberate calls: a return
  type that could be tightened only by also removing a caller's guard, a
  defensive `??` that is unreachable only because two const tables agree, and an
  unresolvable template type that follows from an untyped array. Level 6 applies
  in full to new and changed code; the baseline is a ledger to shrink.
- `CLAUDE.md`, recording the constraints that are not visible from the tree —
  no PHP or Composer in the development environment, the class-id dispatch rule,
  the `module.config.php` / `instance.config.php` split, and the head-metadata
  write ordering.

## 0.7.0 — 2026-07-25

A structural refactoring release: no new features, no change to what any page,
sitemap or export produces. It follows the second full-codebase review (see
`ROADMAP.md`), which targeted implicit contracts, classes carrying several
concerns, and the citation vocabulary spread across seven const tables.

### Added
- **French translation.** `language/fr.po`/`fr.mo`, plus a regenerated
  `template.pot` — the previous template covered 30 of ~80 translatable
  strings, and no catalogue shipped at all. Two dependency-free scripts under
  `.github/scripts/` extract and compile them (`composer i18n`).
- **`Module::upgrade()`.** Defaults introduced after a site's first install
  (`iwac_seo_sitemap_ttl`, `iwac_seo_noindex_browse`) previously never reached
  that site. The hook applies any that are still unset, and never overwrites an
  administrator's value.
- `.editorconfig`, a PSR-12 `phpcs.xml.dist` and a `phpstan.neon.dist`, runnable
  via `composer lint` / `composer analyse`.

### Changed
- **Citation kinds are a `CitationKind` enum.** The CSL, BibTeX, RIS, Zotero
  item-type, part-of-work and authority-record tables were facets of one closed
  vocabulary spread over five classes, with `ENTITY_KINDS` copied verbatim in
  two. A single `CitationKindMap` service replaces the raw array + default
  threaded through four factories — which had already drifted, `ZoteroRdf`
  falling back to `null` where the others fell back to `'item'`. A `class_kinds`
  typo now degrades to the default instead of producing a kind nothing handles.
- **The citation record is a `CitationRecord`** (with `Creator` and
  `IssuedDate`), replacing an `array<string,mixed>` whose twenty-key shape was
  documented in five docblocks and enforced nowhere. The array form remains at
  the one boundary that needs it: `toArray()` is what the view helper hands the
  theme, so **the theme contract is unchanged**.
- **`SitemapGenerator` split** into `SitemapRepository` (the DBAL queries),
  `UrlsetWriter` (the XML) and `XmlCache` (the TTL cache), leaving policy. Builds
  return a `SitemapDocument` carrying its own generation time, replacing the
  `lastModified()` side channel the controller read off the shared service.
- **`HeadWriter` extracted from `HeadMetadata`**, isolating the module's one
  piece of request-scoped mutable state (the applied-signal set spanning the
  action and layout render passes) with a documented lifetime.
- **`PingQueue` extracted from `Module`**, which carried ~65 lines of IndexNow
  policy in the bootstrap class and had to expose its flood cap publicly so the
  job could agree with it.
- **`SettingsGate` replaces the `SettingsReader` trait**, which read an
  undeclared property and so could not be used from a factory — the reason the
  citation view helper's factory carried a hand-synced copy of the truthiness
  rule.
- Instance data (class maps, page translations) moves to
  `config/instance.config.php`; `module.config.php` is wiring only.
- Sitemap cache keys are namespaced by site id, so changing `default_site` no
  longer serves the previous site's XML until the TTL expires.

### Fixed
- A bulk import ran a cache glob-and-unlink cycle **per saved item**; cache
  clearing is now debounced per request.
- `changefreq` and `priority` were interpolated into the sitemap unescaped.
  Config-sourced, so not exposure — but a config edit could have emitted
  unparseable XML.
- `PageSeoStore` re-read and re-decoded the whole page-override map on every
  call; `Hreflang` linear-scanned 33 page pairs on every page render.
- Release tarballs shipped `tests/`, `.github/` and `phpunit.xml.dist`.

### Internal
- Removed: an unused `$locale` parameter, a form action set in both controller
  and view, unreachable null branches, and `method_exists()` guards on
  already-typed representations.
- Deduplicated: the `lang`-helper workaround (twice), guarded `siteUrl()`
  (seven times), the resource-class-to-id dance (eight times), the page-range
  rule (twice), and the controllers' response plumbing (three times).
- Tests: 47 → 103, now covering the sitemap XML writer and cache, the settings
  gate, the ping queue, the kind vocabulary and creator-name splitting. A
  bootstrap with guarded Omeka shims makes those reachable at all. CI validates
  `composer.json`, caches downloads, and fails when the translation template is
  out of date.

## 0.6.0 — 2026-07-12

A refactoring & hardening release (see `ROADMAP.md` for the full plan).

### Added
- **Image sitemap**: the items sitemap now carries an `<image:image>` entry per
  item (the primary media's large thumbnail) for Google Images. Config-gated
  via `iwac_seo.sitemap.include_images`.
- **hreflang coverage report** on the admin dashboard: public static pages
  missing from the `page_pairs` map are listed, so drift is caught the moment
  a page is added or renamed.
- **IndexNow on removal**: deleting an item/page now also pings IndexNow so
  engines recrawl and drop the URL.
- **Asset picker** for the share-image column of the static-page SEO table
  (Omeka's own `common/asset-form`), replacing the raw asset-ID input.
- **HTTP caching** on sitemap responses: `Cache-Control: public, max-age=<ttl>`
  and `Last-Modified` from the server-side cache file.
- **Test suite + CI**: PHPUnit coverage for the citation formatter/exports,
  hreflang resolution and text utilities; GitHub Actions runs syntax checks
  and the suite on PHP 8.2–8.4.

### Changed
- The sitemap cache is invalidated when an item or page changes, so new
  content appears on the next crawl instead of after the TTL.
- `og:locale` is emitted in `language_TERRITORY` form (previously a bare
  site locale like `fr` could slip through).
- The IndexNow key is validated against the `/{key}.txt` route constraint in
  the config form, and the dashboard warns when a stored key can never be
  served.
- The ping throttle window is only stamped after a successful job dispatch.
- Dashboard counts use `COUNT(*)` queries; `sitemap-items-1.xml` no longer
  pays a bound-checking count query.
- Internal consolidation: one `SiteResolver` service (default-site lookup +
  host URL), one `SettingsReader` trait, and a widened `ResourceValueReader`
  trait replace ~10 duplicated private helpers across services/controllers.
- Uninstall now removes the sitemap cache directory.

### Removed
- Dead code: `PageSeoStore::set()`, `CitationFormatter::publisherYear()`'s
  unused parameter, the vestigial per-page `jsonld` override, the unused
  `ext-dom` requirement.

## 0.5.1 — 2026-06

- Show editors in citations; fix Zotero book typing.

## 0.5.0 — 2026-06

- Register the "How to cite" resource page block; fix FR date/locale in
  citations.

## 0.4.0 — 2026-05

- Item-page citation data + BibTeX/RIS/CSL-JSON exports at `/cite/{id}/{format}`.

## 0.3.0 — 2026-05

- unAPI endpoint serving Zotero RDF for primary sources; Sujet + Couverture
  spatiale as Zotero tags.

## 0.2.x — 2026-04

- Adaptation of the module from DRE-SEO into IWAC SEO.
- Bilingual hreflang alternates, `og:locale:alternate`, sitemap `xhtml:link`
  alternates; site selector for static-page SEO; photograph pages typed as
  Zotero artwork.

## 0.1.0

- Initial module (DRE SEO): meta tags, Open Graph/Twitter cards, canonical
  links, schema.org JSON-LD, citation meta tags, XML sitemap, robots.txt,
  Search Console verification, IndexNow ping.
