# IWAC SEO — Refactoring & Improvement Roadmap

Outcome of a second full-codebase review (v0.6.0, 40 source files / ~6,900
lines). The previous round — dead code, the `SiteResolver` / `SettingsReader` /
`ResourceValueReader` consolidation, HTTP caching, the image sitemap, the
hreflang coverage report and the first test suite — shipped in 0.6.0; see
`CHANGELOG.md`. That work removed the *copy-paste* duplication, so this round
is about the layer underneath it: implicit contracts, classes that carry three
concerns, and the vocabulary that is still spelled out in seven places.

Nothing here is a rewrite, and nothing here is urgent. Phases are ordered by
risk, not by value: each is an independent, reviewable commit, and earlier
phases never depend on later ones.

> **Status: delivered in 0.7.0 and the subsequent CI follow-up.** Each
> phase landed as its own commit; see `CHANGELOG.md` for what changed and why.
> The plan is kept here as the record of what was found and the reasoning
> behind each decision — including the two things it recommends *not* doing.
>
> **Since 0.7.0:** PHPStan, PHP_CodeSniffer and the i18n freshness check are now
> wired into CI as a `quality` job, closing the gap where a green build covered
> only `php -l` and PHPUnit — half of what `composer check` runs.
>
> The original note here said to run them locally first and fix what they found,
> because a step that has never passed just turns the build red. That advice
> could not be followed: neither tool can be installed in the environment this
> module is developed in (no PHP, no Composer), which is why neither had ever
> been run. CI is therefore the first execution, and the `quality` job is built
> to report all three categories in one run rather than one per re-run. Expect
> the first build to be red, and treat its output as the backlog.

## Phase 1 — Dead code & housekeeping *(no behaviour change)*

- **`CitationFormatter::publisherYear()` takes an unused `$locale`.** All four
  call sites pass it; the body never reads it. Drop the parameter.
- **The regenerate form's action is set twice** — once in
  `SeoController::dashboardAction()` (line 61) and again in
  `dashboard.phtml` (line 103). Keep the controller's; the view should just
  render what it is handed.
- **`HeadMetadata::applyGlobals()` accepts `?SiteRepresentation`** but
  `Module::handleLayout()` returns early when there is no site, so the null
  branch inside is unreachable. Tighten the signature and delete the branch.
- **`HeadMetadata::setOpenGraph()` tests `$content === null`** on a parameter
  typed `array<string,string>`; the `''` check is the one that fires.
- **`method_exists($x, 'isPublic')` guards on already-typed representations**
  (`CitationController:82`, `UnapiController:103`, `SeoController:93`,
  `ResourceValueReader:146`) are always true. Keep the guard only in
  `Module::handleContentChange()`, where the value really is an untyped
  `object` off the API response.
- **`.gitattributes` under-ignores.** Only `.gitattributes` and `.gitignore`
  are `export-ignore`d, so `composer archive` / release tarballs ship
  `tests/`, `.github/`, `phpunit.xml.dist` and this file. Add them.
- **`language/template.pot` is stale.** 30 msgids against ~80 translatable
  strings — every one of `ConfigForm`'s 29 labels and info texts is missing —
  and no `.po`/`.mo` ships at all, on a deployment whose primary audience is
  francophone. Regenerate the template and add a `fr` catalogue.

## Phase 2 — Deduplication *(no behaviour change)*

- **`classId()`** — `$r->resourceClass() ? $r->resourceClass()->id() : null`
  appears eight times (plus twice more for `->label()`), including in the two
  controllers and the view helper that do not use `ResourceValueReader`. One
  helper, reachable from all three layers.
- **Guarded canonical URLs** — seven copies of
  `try { $r->siteUrl($slug, true); } catch (\Throwable) { null; }` across
  `Hreflang`, `HeadMetadata` (×2), `StructuredData` (×2), `CitationController`,
  `View\Helper\Citation` and `Module`. Extract
  `ResourceUrl::forSite($resource, $slug): ?string`.
- **Page range, twice** — `CitationData::pageRange(array $record)` (static,
  record-based) and `ZoteroRdf::pageRange(ItemRepresentation)` encode the same
  first/last/single rule. Put the rule in the trait and have both call it.
- **One citation vocabulary, seven tables.** `ENTITY_KINDS` is duplicated
  verbatim in `CitationData` and `CitationMeta`; `CSL_TYPE`, `BIBTEX_TYPE`,
  `RIS_TYPE`, `ZOTERO_TYPE`, `BIB_TYPE`, `DC_TYPE_OVERRIDES`, `PART_KINDS` and
  `ELIGIBLE_KINDS` are each a facet of the same closed set of ~14 kinds, spread
  over five classes. A `CitationKind` enum (PHP 8.2 is already the floor) that
  owns *is this an authority record*, *is this a part-of work*, and the four
  export-type mappings would make an unmapped kind a compile-time-ish error
  instead of a silent `?? 'misc'`.
- **Kind lookup, four times** — `$classKinds[$classId] ?? $default` lives in
  `CitationData::kind()`, `CitationMeta::apply()` and `ZoteroRdf` (twice), fed
  by four separate factories that each re-read
  `Config['iwac_seo']['citation']`. Note the drift this already allows:
  `ZoteroRdf` uses `?? null`, the others `?? 'item'`. Harmless today only
  because `'item'` is not an eligible kind. Inject one `CitationKindMap`
  service instead of threading the raw array + default through four factories.
- **Locale resolution, twice** — `HeadMetadata::locale()` and
  `View\Helper\Citation::locale()` are the same workaround for `lang` being a
  `__call` helper, carrying the same explanatory comment about the bug it
  fixed. Extract it once; the comment is worth keeping in one place.
- **Controller response plumbing** — `status()`, `notFound()`, `text()`,
  `xml()`, `body()` and `fileResponse()` are hand-rolled across the three
  public controllers. One `Concern\SendsResponses` trait.

## Phase 3 — Design & modularity

- **`SettingsReader` depends on an undeclared property.** The trait reads
  `$this->settings`, which nothing in the trait declares — an implicit contract
  PHP only enforces at call time, and one a factory cannot satisfy at all.
  That is exactly why `ViewHelper\CitationFactory` re-implements the truthiness
  rule inline, with a comment saying it must stay in sync. Replace the trait
  with a small injectable `SettingsGate` (`isOn()`, `text()`) registered in the
  service manager, so controllers, `HeadMetadata` *and* factories share one
  object and one definition of "on".
- **Move the IndexNow queue out of `Module`.** `handleContentChange()` is ~65
  lines of business logic — cache invalidation, visibility policy, dedupe,
  queue cap, throttle stamping, job dispatch — living in the bootstrap class,
  and `Module::PING_QUEUE_CAP` is `public` solely so `PingSearchEngines` can
  read it (the docblock says as much). A `PingQueue` service would own the cap
  and the throttle, `Module` would keep only the listener wiring, the job would
  ask the service to drain, and the whole thing would become unit-testable.
- **There is no `Module::upgrade()`.** Install applies `DEFAULTS`, uninstall
  drops `SETTINGS`, but Omeka's upgrade hook is unimplemented — so any default
  added after a site's first install (`iwac_seo_sitemap_ttl` and
  `iwac_seo_noindex_browse` both arrived after 0.1) is never applied to an
  existing instance. An idempotent "set the DEFAULTS that are still null" is
  five lines and closes the gap for good.
- **Split `SitemapGenerator` (524 lines, three concerns).** It is
  simultaneously a DBAL repository (four queries), an XML writer (pure string
  building) and a TTL file cache. Splitting them makes the writer — the part
  that produces the actual protocol output — unit-testable; today the sitemap
  is the module's largest untested surface.
- **Replace the `lastModified()` side channel.** `SitemapController` calls
  `buildX()`, then reads mutable state back off the *shared* generator to write
  the `Last-Modified` header. Returning a `SitemapDocument{xml, lastModified}`
  removes the temporal coupling and the shared mutable field.
- **Isolate `HeadMetadata`'s request state.** 540 lines and three mutable
  fields (`$applied`, `$description`, `$defaultImage*`) on a shared service.
  It is correct under Omeka's process-per-request model, but it is the module's
  only piece of global mutable state and it is what makes the two-phase
  apply/gap-fill dance hard to follow. Extract a `HeadWriter` that owns the
  placeholder setters *and* the applied-set, leaving `HeadMetadata` as the
  policy layer deciding what to write.
- **Give the citation record a type.** `CitationData::build()` returns an
  `array<string,mixed>` whose 20-key shape is documented in five docblocks and
  enforced nowhere, then read by string key in `CitationFormatter`,
  `CitationExport`, the view helper and the theme partial. A readonly
  `CitationRecord` (+ `Creator` for the `{family,given,literal,isInstitution}`
  quadruple) would collapse those docblocks into a signature. The tell that the
  array is really an object: `pageRange()` is a *static* method on the builder,
  called from two other classes.
- **Separate wiring from instance data.** `config/module.config.php` is 354
  lines, of which roughly half is IWAC-specific tables — 20 `class_types`, 19
  `class_kinds`, 33 `page_pairs`. Moving them to `config/instance.config.php`
  (merged in) separates "how the module is wired" from "what this archive's
  class ids mean", and is the single change that would let another Omeka
  instance adopt the module without editing framework wiring.
- **`Hreflang::pairFor()` linear-scans 33 pairs on every page render.** Index
  by `[siteSlug][pageSlug]` once in the constructor. Longer term, consider
  moving `page_pairs` to a site setting: today adding a static page requires a
  code deploy, and the dashboard's gap report exists precisely because that
  drifts.

## Phase 4 — Performance & correctness

- **The sitemap cache is cleared on every single content change.** A bulk
  import of N items runs N × (`glob` + unlinks), and unlike the ping queue —
  which has an explicit bulk guard — the invalidation has none. Debounce to
  once per request.
- **Sitemap cache keys are per-type, not per-site.** The docblock notes the
  host is baked in; the site id is too, and unmentioned. Changing
  `default_site` serves the previous site's XML until the TTL expires. Add the
  site id to the key.
- **`renderUrlset()` escapes `loc`, `lastmod`, alternates and images but
  interpolates `changefreq` and `priority` raw.** Config-sourced today, so not
  exploitable — but the asymmetry means a future config edit could emit invalid
  XML. Escape them for consistency.
- **`SitemapController::xml()` re-reads the TTL setting** after the generator
  has already been given it. Pass it through.
- **`PageSeoStore::get()` re-reads and re-decodes the whole page map** from
  site settings on each call. Memoize per target id.
- **Name the entity-class literals.** `'Omeka\Entity\Item'` /
  `'Omeka\Entity\ItemSet'` appear as raw strings in five queries.

## Phase 5 — Toolchain & tests

- **Add static analysis.** PHPStan at level 6–7, with the documented array
  shapes as real types, would have found the unused `$locale`, the undeclared
  `$this->settings`, the unreachable null branches and the `?? null` /
  `?? 'item'` divergence — every Phase 1 item, mechanically. Add a PSR-12 check
  (PHP_CodeSniffer or php-cs-fixer) alongside it, and wire both into CI and
  `composer.json` scripts.
- **Close the test gaps.** The original 47 tests covered only the pure citation,
  hreflang and text logic. The writer, cache, ping queue and generator policy
  now have direct tests. `ZoteroRdf::render()` and `CitationMeta` remain better
  verified against a real Omeka/Laminas stack than by expanding the local shim.
- **CI polish.** Delivered after 0.7.0: cache Composer downloads between runs
  and validate `composer.json` strictly. Workflow permissions, concurrency and
  timeouts are explicit too.
- **Add `.editorconfig`** — the codebase is uniformly 4-space / LF, but nothing
  states it.

## Deliberately out of scope

Unchanged from the previous round, and still right: the hand-rolled citation
formatter (vs a CSL processor), string-built XML (vs DOM/XMLWriter), the
settings-backed page-SEO store (vs a database table) and class-id dispatch (vs
resource templates) are justified, documented trade-offs for a self-contained,
vendor-free module.

One addition. `CitationFormatter`'s three ~95-line style methods share a
skeleton — creator, title, container, link — and each branches over the same
kind matrix, which reads as an obvious candidate for a Strategy per style. It
is well covered by tests, so the refactor would be safe; but the styles differ
in *ordering* and *punctuation* at nearly every joint, and a shared skeleton
would have to be parameterised until it was less readable than the three
explicit methods. Leave it, unless a fourth style is ever added.
