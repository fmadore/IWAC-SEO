# IWAC SEO and citation audit

Reviewed 10 September 2026. Scope: module architecture, metadata and citation generation, public endpoints, sitemap/cache/IndexNow behaviour, existing tests and workflows, and current Google guidance. This records the pre-implementation examination. The repository now includes the 1.1 implementation described in [OPERATIONS.md](OPERATIONS.md) and [CHANGELOG.md](CHANGELOG.md); deployment and Google/cron activation remain separate. The evidence below describes the original baseline, not the revised test results.

The architecture is a good foundation. The main weakness is semantic fidelity: several paths produce plausible output while losing bibliographic facts or asserting facts the catalogue does not establish. Passing the existing tests does not establish correctness for every citation type.

## Evidence and verification

- `composer check`: passed on PHP 8.2.33, including translation freshness, PSR-12, PHPStan and **146 tests / 618 assertions**.
- `composer test:integration`: passed against the local Omeka tree, **34 tests / 111 assertions**.
- Anonymous API reads sampled all 14 mapped work classes: 36, 60, 38, 49, 58, 35, 178, 43, 40, 52, 88, 82, 77 and 305. This is representative sampling, not a census.
- Inspected public HTML for items 2231 and 10224, citation exports for 2231, 10224 and 12805, robots.txt and the sitemap index.
- No authenticated Search Console data was available or queried. No full crawl, browser performance benchmark, actual Zotero import round trip or database concurrency test was performed. Findings below distinguish observed output from code-level failure conditions.
- No dependency versions were changed. The local run does not substitute for the existing PHP 8.2–8.5 CI matrix.

### Reproducible live examples

| Record | Source facts | Observed output |
|---|---|---|
| [Newspaper article 2231](https://islam.zmo.de/s/westafrica/item/2231) | Date `2018-12-07`; French and English `bibo:shortDescription` values both exist | English page's description, Open Graph description and JSON-LD description use French. [BibTeX](https://islam.zmo.de/cite/2231/bibtex) keeps only `year = {2018}`. |
| [Publication issue 10224](https://islam.zmo.de/s/afrique_ouest/item/10224) | *Al Mawadda #48-49*; two issue values, `48` and `49`; date interval `2009-05/2009-08` | [CSL-JSON](https://islam.zmo.de/cite/10224/csljson) says `article-magazine`, issue `48`, issued `[[2009,5]]`. The interval endpoint disappears. HTML citation date retains the raw interval. |
| [Presentation 12805](https://islam.zmo.de/s/afrique_ouest/item/12805) | Linked event in `dcterms:isPartOf`; date `2023-11-09` | [CSL-JSON](https://islam.zmo.de/cite/12805/csljson) preserves the date and `speech` type but omits the event. |

## Priority findings

### 1. Preserve date precision and ranges across all outputs — high priority

Locations: [IssuedDate.php](C:/Users/frede/GitHub/IWAC-SEO/src/Service/Citation/IssuedDate.php:33), [CitationExport.php](C:/Users/frede/GitHub/IWAC-SEO/src/Service/CitationExport.php:51), [CitationData.php](C:/Users/frede/GitHub/IWAC-SEO/src/Service/CitationData.php:62).

`IssuedDate::parse()` searches for a date substring without anchoring or calendar validation. An interval becomes its first date; impossible dates such as `2021-02-30` become structured dates. BibTeX then emits only a year, and CSL export discards literal-only dates. This affects the identity of newspaper articles and combined periodical issues, not just punctuation.

Introduce a date value with start, optional end, precision, original literal and uncertainty. Parse only recognised complete forms; preserve unsupported forms as literals. Export full dates with BibLaTeX `date` (the module already targets biber/biblatex), two CSL date-parts arrays for intervals, and an explicit loss-preserving fallback for formats that cannot express the same precision. Do not turn an interval into a single publication month. [CSL specification](https://docs.citationstyles.org/en/stable/specification.html#dates).

### 2. Separate a complete periodical issue from an article — high priority

Locations: [instance.config.php](C:/Users/frede/GitHub/IWAC-SEO/config/instance.config.php), [CitationKind.php](C:/Users/frede/GitHub/IWAC-SEO/src/Service/CitationKind.php:73).

Class 60 is correctly `PublicationIssue` in JSON-LD but becomes `article-magazine`, RIS `MGZN` and Zotero `magazineArticle`. This is an interoperability approximation, not an exact description of the object. The formatter also quotes its title as an article title and ignores issue numbers in the periodical branches. `firstString(bibo:issue)` loses combined issue values, as item 10224 demonstrates.

Add an internal `PeriodicalIssue` kind, retain all issue values, and document each export format's approximation where there is no native issue type. Do not invent a CSL or Zotero type. Render the periodical, issue designation, date range and responsible organisation in a reviewed whole-issue citation. Keep class-based dispatch; the problem is the mapping, not the choice of dispatch key.

### 3. Select descriptions by language — high priority

Locations: [HeadMetadata.php](C:/Users/frede/GitHub/IWAC-SEO/src/Service/HeadMetadata.php:316), [StructuredData.php](C:/Users/frede/GitHub/IWAC-SEO/src/Service/StructuredData.php:76), [ResourceValueReader.php](C:/Users/frede/GitHub/IWAC-SEO/src/Service/Concern/ResourceValueReader.php).

These paths choose the first value, disregarding `@language`. Live item 2231 confirms the effect despite an available English summary. Pass the page locale into a shared language-aware selector, with explicit fallbacks: exact language, compatible base language, untagged French according to IWAC conventions, then another available language. Keep the existing distinction between description priority and formal-abstract priority.

The work's language and the interface's language are separate: a French newspaper article on an English interface should remain a French-language work. Normalise linked language authorities to language codes for machine exports where appropriate, without replacing the original language with the UI locale.

### 4. Do not manufacture video publication facts — high priority

Locations: [Text.php](C:/Users/frede/GitHub/IWAC-SEO/src/Service/Text.php), [StructuredData.php](C:/Users/frede/GitHub/IWAC-SEO/src/Service/StructuredData.php:265).

The code derives `uploadDate` from the general document date and pads a year/month to the first day at midnight UTC. A recording date is not necessarily an upload date. Even a syntactically valid timestamp can therefore misrepresent the source. Google defines this field as when the video was first published; timezone information is recommended. [Google video requirements](https://developers.google.com/search/docs/appearance/structured-data/video).

Store or derive the actual first-publication fact with provenance, distinguish uploaded YouTube material from deposited recordings, and retain partial catalogue dates without invented precision. Where required facts are unavailable, report an eligibility gap rather than fabricate them. Class 38 also needs media-subtype refinement: audio should not automatically become `VideoObject` / `motion_picture` / `VIDEO`.

Video indexing additionally depends on the rendered page actually being suitable for watching the video. An `embedUrl` alone does not establish that. Add a rendered-page acceptance check for player presence, prominence, crawlability and thumbnail access. [Google video SEO](https://developers.google.com/search/docs/appearance/video).

### 5. Complete type-specific citation fields and style rules — high priority

Locations: [CitationFormatter.php](C:/Users/frede/GitHub/IWAC-SEO/src/Service/CitationFormatter.php:120), [CitationRecord.php](C:/Users/frede/GitHub/IWAC-SEO/src/Service/Citation/CitationRecord.php), [CitationExport.php](C:/Users/frede/GitHub/IWAC-SEO/src/Service/CitationExport.php).

The visible styles are intentionally hand-written and do not yet warrant a claim of complete Chicago/APA/MLA compliance:

- APA and MLA newspaper branches omit supplied page ranges; periodical issue/volume details are also discarded by the generic branches. Chicago newspaper page omission alone is not a defect: style rules differ.
- APA drops journal issue numbers when no volume exists because issue rendering is nested inside the volume condition.
- All theses become `phdthesis` and “PhD diss.”, regardless of degree. The sampled thesis is doctoral, but the implementation cannot faithfully handle other degrees.
- Presentations become BibTeX `inproceedings` and RIS `CONF`, while CSL and Zotero describe a speech/presentation. `dcterms:isPartOf` event data is absent from the citation record.
- APA audiovisual/document/photo paths only emit `publisher`, but extraction puts their `dcterms:publisher` in `container`; these names disappear from the visible APA citation. Medium, platform and creator-role distinctions are not modelled.
- Report numbers, reviewed-work details, thesis genre, archive/repository fields and source URLs are missing from the normalised record. These fields need source-aware mapping, not placeholders.
- Quoted titles always get an extra period, producing endings such as `?.”` for question titles.
- The author-list implementation prints every Chicago author. Current Chicago guidance uses a shortened bibliography list above six authors. [Chicago citation guide](https://www.chicagomanualofstyle.org/tools_citationguide/citation-guide-1.html).
- APA needs its own large-author-list rule, medium descriptions and URL punctuation; `linkSegment()` currently adds a trailing full stop for every style. These should be verified against publisher examples before advertising edition-level conformance.

Use a reviewed citation corpus as the acceptance standard. A CSL processor is a strong option for presentation formatting, provided it is evaluated separately against the no-runtime-vendor constraint. The module's normalised data and downloads should remain server-side. A build-time bundle or precomputed rendering can avoid a network dependency per page. If retaining PHP formatting, split styles into small strategy classes and test the same reference corpus against them.

### 6. Reuse normalised creators and facts across metadata paths — medium priority

Locations: [Creator.php](C:/Users/frede/GitHub/IWAC-SEO/src/Service/Citation/Creator.php:46), [StructuredData.php](C:/Users/frede/GitHub/IWAC-SEO/src/Service/StructuredData.php:462), [CitationMeta.php](C:/Users/frede/GitHub/IWAC-SEO/src/Service/CitationMeta.php), [ZoteroRdf.php](C:/Users/frede/GitHub/IWAC-SEO/src/Service/ZoteroRdf.php).

`CitationRecord` is shared by the formatter and three downloads, but Highwire, Zotero RDF and JSON-LD still extract their own facts. Corporate authors are correctly preserved in some citation paths but JSON-LD assigns `Person` to all authors/editors/contributors. Creator parsing guesses the last token is the surname even though authority records can carry `foaf:firstName` and `foaf:lastName`.

Prefer structured authority names, then explicitly inverted literals, then a documented fallback preserving the original. Share person/organisation identity and date parsing. Extend the common record with role-aware creators, source URL, archive identifier, medium, event and genre. Let each output own its vocabulary and eligibility rules. Zotero RDF currently omits publisher information for non-periodical primary sources and does not consume editor roles; add translator round-trip tests before broadening its coverage.

### 7. Exclude noindex pages from sitemap recommendations — medium priority

Locations: [HeadMetadata.php](C:/Users/frede/GitHub/IWAC-SEO/src/Service/HeadMetadata.php:142), [SitemapGenerator.php](C:/Users/frede/GitHub/IWAC-SEO/src/Service/SitemapGenerator.php:80), [SeoController.php](C:/Users/frede/GitHub/IWAC-SEO/src/Controller/Admin/SeoController.php:162).

The page editor can set `noindex`, but sitemap generation reads all public pages without consulting SEO overrides. Saving overrides also does not invalidate the sitemap. The trigger is a public page explicitly marked noindex: HTML and sitemap then recommend conflicting treatment.

Introduce a shared page-indexability policy used by sitemap and HTML generation, and invalidate the relevant cache after overrides change. Sitemaps should nominate the canonical URLs intended for search. [Google sitemap guidance](https://developers.google.com/search/docs/crawling-indexing/sitemaps/build-sitemap).

### 8. The staging switch blocks discovery of its own noindex — medium priority

Locations: [SitemapController.php](C:/Users/frede/GitHub/IWAC-SEO/src/Controller/SitemapController.php:133), [HeadMetadata.php](C:/Users/frede/GitHub/IWAC-SEO/src/Service/HeadMetadata.php:219).

Enabling the master noindex emits both a page-level noindex and `Disallow: /`. A crawler prevented from fetching a previously indexed page cannot discover its new noindex. Use authentication for private staging; use crawlable noindex when the purpose is removal from indexing. Distinguish those modes in settings. [Google robots guidance](https://developers.google.com/search/docs/crawling-indexing/robots/intro).

### 9. Cache writes and database errors can produce misleading sitemap success — medium priority

Locations: [XmlCache.php](C:/Users/frede/GitHub/IWAC-SEO/src/Service/Sitemap/XmlCache.php:33), [SitemapRepository.php](C:/Users/frede/GitHub/IWAC-SEO/src/Service/Sitemap/SitemapRepository.php:89).

Writers use `LOCK_EX`, but readers take no shared lock and writes replace the live file directly. A concurrent reader can read an empty or partial document. Build into a temporary file in the same directory and publish atomically, checking write success. Add a lock or single-flight policy for cold-cache regeneration and an invalidation generation token to prevent an older in-flight build repopulating a cleared cache.

Repository exceptions become `[]` or `0`, which can be cached and served as successful but incomplete sitemaps. Retrying without the optional image query is sensible; silently treating a database failure as an empty collection is not. Log errors, retain a known-good document for bounded transient failures, and return a retryable error if no valid document is available. Do not retain stale private/deleted URLs indefinitely. These are code-level failure conditions, not observed production incidents.

### 10. IndexNow is neither an atomic queue nor a guaranteed delayed delivery mechanism — medium priority

Locations: [PingQueue.php](C:/Users/frede/GitHub/IWAC-SEO/src/Service/PingQueue.php:58), [PingSearchEngines.php](C:/Users/frede/GitHub/IWAC-SEO/src/Job/PingSearchEngines.php).

`push()` and `drain()` read and rewrite a settings array without atomicity. Concurrent saves can lose URLs; a drain can overwrite an intervening push. Failed submission is logged after the batch has been deleted. Edits within the 15-minute throttle window may remain pending indefinitely if no later edit triggers another dispatch.

Use durable per-URL records with a uniqueness constraint, atomic claiming, bounded retries/backoff and acknowledgement after success. Schedule a recurring drain or delayed job independent of future saves. Keep the existing bulk cap as an explicit policy, but record skipped batches and distinguish them from successful submissions. Sitemap invalidation remains useful independently of IndexNow.

## Citation coverage by resource class

“Foundation” means the principal mapping exists; it does not certify every style or importer.

| Class/type | Assessment | Acceptance requirements |
|---|---|---|
| 36 newspaper article | Foundation; full BibTeX date lost | Publication date, newspaper, available pages, byline/no-author cases, source URL and accession. |
| 60 periodical issue | Needs distinct internal kind | Combined issues, date ranges, organisation/editor responsibility, whole-issue title treatment. |
| 38 audiovisual | Overgeneralised as video | Audio/video subtype, actual publication date, creator role, platform, original URL, duration, deposited versus external media. |
| 49 document | Generic fallback | Document genre, responsible body, date or undated status, archive/collection/call number; APA must retain publisher facts. |
| 58 photograph | Generic fallback | Photographer, date, description/title distinction, medium, holding collection and identifier. |
| 35 journal article | Strongest foundation | Journal, volume, issue without volume, pages/article number, DOI; author and title punctuation edge cases. |
| 178 book review | Treated mostly as journal article | Reviewed title/author where available, correct review genre and journal details; no invented review rating. |
| 43 book chapter | Foundation | Chapter author, book title, editors, publisher, pages as required by style; do not repeat chapter title as an invented missing book title. |
| 40 book | Foundation | Publisher, edition/series/identifiers when present, author variants and title casing policy. |
| 52 edited book | Foundation; editor fallback exists | Editors explicitly labelled, preserved in every supported import route, organisation editor and no-author cases. |
| 88 thesis | Assumes doctorate | Actual degree/genre and institution, publication/repository status; preserve unknown degree without inventing one. |
| 82 report | Incomplete report-specific model | Institution, report number, series and organisational authors; omit absent facts. |
| 77 presentation | Cross-format typing inconsistent | Event title/place/date, presentation medium, distinguish published proceedings from an oral contribution. |
| 305 blog post | Foundation | Full date, blog/site name, byline, source URL; distinguish web publication from archival landing page. |
| 94/9/96/54/244 authority records | Deliberately excluded from work citations | Keep that default. If users need to cite the catalogue entry itself, offer a separate catalogue-entry citation with archive attribution. |
| Unmapped item class | Generic fallback | Label as generic; expose missing mapping in the dashboard rather than imply type-specific support. |

## Further SEO and efficiency improvements

1. **Separate page metadata from work identity.** Give the catalogue page and described work stable `@id` values with `mainEntity`/`mainEntityOfPage` relationships. Keep publisher, depositor and contributor roles distinct. Add `headline` for article types and bibliographic identifiers/pages/volume where supported; generic `name` alone leaves useful article metadata unexpressed.
2. **Use resource-specific images for structured data.** `forResource()` receives the default share image and puts it into the work's `image`. The code already avoids this for video `thumbnailUrl`; extend the distinction to the work image. A site graphic can remain a social share fallback. Google expects structured images to relate to the described content. [Structured-data policies](https://developers.google.com/search/docs/appearance/structured-data/sd-policies).
3. **Keep scholarly eligibility separate from Zotero compatibility.** Highwire tags are useful to citation tools for many types; they do not make newspapers, magazines or book reviews eligible for Scholar. Scholar expects suitable scholarly material and visible full text or complete author-written abstracts. An AI summary is not a substitute for an author-written abstract. Audit the PDF path relationship, searchable text and file sizes separately. [Scholar inclusion requirements](https://scholar.google.com/intl/en/scholar/inclusion.html).
4. **Check bilingual resource membership.** Hreflang currently assumes every resource belongs to every configured site. This is efficient under IWAC's shared-collection invariant. Add a scheduled membership/HTTP check rather than a database lookup per head render. Check static English-only pages too: the root sitemap currently follows the default site's pages.
5. **Enforce sitemap bytes as well as URL count.** The generator enforces 50,000 URLs but not the uncompressed 50 MB limit. Hreflang and image extensions increase size. Keep lean DBAL reads; measure query plans and peak memory before replacing them. Consider smaller stable chunks, streaming rows/XML and keyset boundaries if measured scale justifies it. No present oversize file was established.
6. **Make lastmod meaningful.** Index regeneration stamps every child with now even when its content has not changed. Track real child changes or omit index lastmod. Include material SEO/page changes in the appropriate modification policy. Google ignores sitemap priority/changefreq, so depth-based priority calculation is a simplification opportunity rather than an SEO investment. [Sitemap guidance](https://developers.google.com/search/docs/crawling-indexing/sitemaps/build-sitemap).
7. **Canonical host and cache namespace.** File-cache keys omit the origin and configuration version while generated URLs depend on them. Use a configured public origin, reject unexpected hosts at the edge, and namespace cache by origin/site/schema version. Host poisoning depends on deployment configuration; it was not tested against production.
8. **Replace blanket query handling with route policy.** Separate pagination, search facets and tracking parameters. Preserve a legitimate paginated page's own canonical; avoid making incidental tracking parameters an indexing decision. Review crawlable HTML browse links alongside the JavaScript search interface.
9. **Remove obsolete benefit claims.** `WebSite` remains useful, but `SearchAction` no longer produces Google's sitelinks search box. Keeping it for other consumers is optional. [Google's retirement announcement](https://developers.google.com/search/blog/2024/10/sitelinks-search-box).
10. **Keep error fallbacks observable.** Broad catches are appropriate at the public-page boundary when SEO must not break a page, but internal services should distinguish unavailable data from empty data and log diagnostic context.
11. **Improve export robustness.** Escape literal unmatched braces in BibTeX separately from structural braces; validate URL schemes before generating citation anchors; preserve corporate names in RIS with importer-tested conventions. These are robustness gaps rather than demonstrated hostile input.
12. **Improve editorial feedback.** Add an effective metadata preview, source-property/language labels, missing-field diagnostics per kind, and explicit notification that GSC verification is not API connectivity. The page form should validate allowed robots values and page ownership, label individual controls accessibly, and protect against two editors overwriting the entire settings map. Existing CSRF protection should remain.

Keep the current strengths: class-based dispatch, dependency-free runtime, public PDF/media checks, escaped JSON-LD/XML, HeadWriter's two-phase ownership, pure sitemap writer, inexpensive SQL rather than full resource hydration, generated hreflang mappings, and separate framework integration tests. Avoid a wholesale rewrite or duplicating Omeka's Laminas/PSR dependencies.

## Google Search Console in GitHub Actions

Yes: implement a **scheduled production SEO monitor**, separate from pull-request code validation. Search Console reflects Google's stored crawl/index state, so it is unsuitable as an immediate pass/fail gate for an undeployed PR. The existing scheduled hreflang workflow is a useful organisational precedent.

### What is available

| Capability | API support and proposed use |
|---|---|
| Sitemap errors/warnings, pending state, last download | Sitemaps list/get; poll the index and available child records. [Sitemaps resource](https://developers.google.com/webmaster-tools/v1/sitemaps). |
| Per-URL indexed state, fetch state, robots/indexing restrictions, selected/user canonical and crawl time | URL Inspection API; inspect a bounded sample and save the inspection-result link. This inspects Google's indexed version, not a live test. [Inspect endpoint](https://developers.google.com/webmaster-tools/v1/urlInspection.index/inspect). |
| Rich-result errors for inspected URLs | Read `richResultsResult`, detected items and issue severity when returned. Missing data is not a clean bill of health. [Inspection result schema](https://developers.google.com/webmaster-tools/v1/urlInspection.index/UrlInspectionResult). |
| Clicks, impressions, CTR, position | Search Analytics; compare completed, comparable periods and require meaningful sample sizes. Useful for trends, not proof of a software defect. |
| Full notification inbox, all page-indexing rows, dedicated video-indexing/CWV/manual-action/security reports | No general endpoints for these in the documented Search Console API. Keep native Search Console notifications for coverage beyond the API. This limitation follows from the published endpoint inventory. [API reference](https://developers.google.com/webmaster-tools/v1/api_reference_index). |
| Request ordinary archive pages be indexed | URL Inspection cannot do this. Google's separate Indexing API is restricted to eligible job/livestream use cases; do not use it as a generic archive submitter. [Indexing API scope](https://developers.google.com/search/apis/indexing-api/v3/quickstart). |

### Concrete workflow design

Proposed files: `.github/workflows/search-console-monitor.yml`, a small standalone monitor script, fixture-based tests, a curated URL/expectation manifest and operator documentation. No Google client library needs to enter the Omeka module's production dependencies.

1. Trigger daily off the hour and through `workflow_dispatch`; use a single concurrency group and a bounded timeout. Run deterministic HTML/citation checks in normal CI separately.
2. Authenticate GitHub OIDC through Google Workload Identity Federation and an impersonated service account authorised for the exact Search Console property. Grant the relevant GSC permission, enable the API and request `webmasters.readonly`; a Cloud IAM role alone does not grant Search Console property access. Bind federation to the intended repository and trusted workflow/ref. [GitHub OIDC guidance](https://docs.github.com/en/actions/how-tos/secure-your-work/security-harden-deployments/oidc-in-google-cloud-platform).
3. Configure the actual verified property string, for example `https://islam.zmo.de/` or `sc-domain:islam.zmo.de`, rather than assuming which exists. The HTML verification token already in Omeka is not an API credential. If federation is unavailable, a dedicated service-account key in a GitHub secret is a fallback with rotation obligations.
4. Fetch sitemap status; inspect a small fixed sentinel set covering both languages and all work types, plus a rotating sample and recently changed URLs. Start around 100–250 inspections/day, reserving quota for interactive troubleshooting. Google currently documents **2,000 inspections/day/site and 600/minute/site**. [Quota limits](https://developers.google.com/webmaster-tools/limits).
5. Keep an expected-state manifest: indexable record versus intentional noindex, expected canonical or accepted canonical family, and intended rich-result types. Treat expected exclusions as normal. Never fail simply because some archive URLs are not indexed or a video lacks rich-result eligibility.
6. Store timestamped JSON results and a concise Markdown run summary as restricted-retention artifacts. Track URL, class, locale, inspected crawl time, severity and previous state. Use a durable prior successful snapshot; an expired/missing baseline must produce an explicit initialisation state, not a false “all recovered”.
7. Alert only on new or worsened actionable states: failed fetch of an established indexable sentinel, accidental noindex, unexpected canonical, new sitemap errors or critical structured-data issues. Account for crawl lag after deployment and confirm transient problems over successive observations. Report API authentication/quota failures as monitoring failures, not SEO regressions.
8. If issue notifications are wanted, upsert one stable GitHub issue per problem group through `gh`, with a fingerprint, affected examples, first/last seen and inspection links. Update on meaningful state changes and mark confirmed recoveries; do not create a new issue on every daily run. Use narrowly scoped `issues: write` only for that job. Publishing these notifications is a separate operational step, not performed in this audit.
9. Honour 429/5xx backoff, timeouts and partial results. Do not log tokens. Avoid transmitting search-query data to public issues; report aggregate performance changes unless explicitly approved.

The required external setup is the verified property plus a Google Cloud identity authorised to read it. None was configured as part of this examination. The monitor can be implemented and tested with fixtures before enabling production authentication or GitHub notifications.

## Recommended delivery sequence

1. Fix locale selection, date/range loss, combined issues and presentation metadata with regression fixtures from the live examples above.
2. Expand the citation record and define exact output expectations for every row of the citation matrix. Add golden cases for all three styles in both languages, plus importer round trips for BibLaTeX, RIS, CSL and Zotero RDF. Include no-author, corporate authors, editor-only works, partial/range dates, invalid dates, punctuation and missing containers.
3. Repair noindex/sitemap consistency, cache publication/error handling and reliable IndexNow delivery. Add concurrency/failure tests where ordinary unit doubles cannot expose races.
4. Improve structured-data fidelity and video eligibility reporting. Add deterministic rendered HTML checks for canonical count, robots, locale, JSON parsing, URLs and source facts. Test anonymous and authenticated rendering so private values cannot accidentally enter public-cache output.
5. Add the scheduled Search Console monitor, first with artifacts and summaries, then agreed issue notifications. Keep Google-dependent observations separate from required PR checks.

This order tackles demonstrated information loss first, preserves the existing architecture, and gives future refactoring a meaningful acceptance standard.
