# IWAC SEO

Search-engine optimisation and XML sitemap for the **Islam West Africa Collection
(IWAC)** Omeka S instance ([islam.zmo.de](https://islam.zmo.de/s/afrique_ouest/page/accueil)).

The site ships almost no SEO metadata out of the box: pages have a `<title>` but no
description, no Open Graph / Twitter cards, no canonical link, no structured data, no
Google Search Console verification, and there is no `sitemap.xml` or `robots.txt`. This
module fills all of that in — **manually for static pages, automatically for every
resource page** — and adds a sitemap, a robots file, and an optional IndexNow ping.

It is a self-contained, settings-only module. No third-party Composer dependencies, no
database tables, no theme edits.

> **IWAC is bilingual.** The same collection is published as two Omeka sites —
> `afrique_ouest` (French, the default the host root redirects to) and `westafrica`
> (English). The module resolves the current site at request time, so every signal
> (site title, locale, **self-referential** canonical, breadcrumbs) is emitted in the
> right language, and each page advertises its other-language counterpart via
> `rel="alternate" hreflang`. See [Bilingual SEO](#bilingual-seo-hreflang).

---

## What it does

| Area | Detail |
|---|---|
| **Meta tags** | `<title>`, `<meta name="description">`, canonical link on every public page. |
| **Open Graph / Twitter** | `og:title/description/image/type/url/site_name/locale` + `twitter:card/title/description/image/site` so shared links render a rich preview. |
| **schema.org JSON-LD** | Per-resource structured data typed from the resource **class** (Person, Place, Organization, Event, NewsArticle, PublicationIssue, the scholarly reference types, VideoObject …), plus `WebSite` + `SearchAction` on the home page and `BreadcrumbList` on resource pages. |
| **Citation metadata (Zotero)** | Highwire Press `citation_*` + Dublin Core `DC.*` `<meta>` tags so the **Zotero Connector**, Google Scholar, Mendeley and other reference managers capture each item as a properly-typed reference (newspaper article, magazine issue, journal article, book, chapter, thesis, report, blog post …). |
| **unAPI (Zotero RDF)** | Primary-source items also advertise a `/unapi` endpoint serving **Zotero RDF**. Zotero prefers unAPI over the meta tags, so it imports a fuller record — the call number (*Cote*) from the `iwac-` identifier, single-field institutional creators, and Sujet + Couverture spatiale as tags. |
| **Item-page citation tools** | A **"How to cite"** resource page block — a formatted **Chicago / APA / MLA** reference (switchable, copy-to-clipboard) plus **BibTeX / RIS / CSL-JSON** downloads at `/cite/{id}/{format}` and the Zotero-RDF link for eligible kinds. Placed via the theme's *Configure resource pages* screen; the theme renders the UI (its `common/citation` partial) via the `iwacCitation` view helper, and this module owns the data. Replaces the BulkExport block for single-item exports. |
| **og:image** | The large thumbnail of the item's primary media (the page scan / cover); falls back to a site-wide default share image. |
| **XML sitemap** | `/sitemap.xml` index → `/sitemap-pages.xml`, `/sitemap-item-sets.xml`, `/sitemap-items-{n}.xml` (chunked at 50k). Public resources only, with `<lastmod>`, `<changefreq>`, `<priority>`. Cached. |
| **robots.txt** | `/robots.txt` disallowing `/admin` and pointing crawlers at the sitemap. A staging switch can `Disallow: /` the whole site. |
| **Google Search Console** | Paste the verification snippet in the module config; the `<meta name="google-site-verification">` tag is injected site-wide. |
| **IndexNow ping** | Optionally notifies Bing/Yandex when public content changes (throttled; skips bulk imports). |

Static-page SEO is **set by hand** (a central admin table); resource-page SEO is **derived
automatically** from each item's metadata, with the configurable defaults filling any gaps.

---

## Why dispatch on resource *class*, not template

Both the schema.org typing and the citation typing key off the Omeka **resource class**
(the RDF type), not the data-entry template. IWAC's templates are not 1:1 with classes:

- Template 8 ("Newspaper article") historically held **both** newspaper articles
  (class 36 `bibo:Article`) and Islamic-publication issues (class 60 `bibo:Issue`); the
  latter now also has its own template 21, but class is still the reliable split.
- The bibliographic references share templates across classes (e.g. template 10 covers
  `bibo:Book`, `bibo:EditedBook` and `bibo:Thesis`).

So the maps in `config/module.config.php` are keyed by class id. This matches how the
IWAC → Hugging Face pipeline derives its subsets (also by class). See the `iwac-data`
skill (`omeka-structure.md`) for the full class catalogue.

---

## Installation

The module folder **must be named `IwacSeo`** inside Omeka's `modules/` directory (the
folder name has to match the namespace). Then:

1. **Admin → Modules → IWAC SEO → Install.**
2. **Configure** (see below) — at minimum paste your Google Search Console snippet and pick
   a default share image.
3. Visit `/sitemap.xml` and `/robots.txt` to confirm they render.

No web-server changes are needed: nginx's `try_files … /index.php` already routes the
`.xml`/`.txt` endpoints to Omeka because no such static files exist.

There are no third-party dependencies, so `composer install` is **not** required — Omeka
autoloads the `IwacSeo\` namespace from `src/`.

**Requirements:** Omeka S `^4.2.0`, PHP `>= 8.2`.

---

## Configuration

**Modules → IWAC SEO → Configure.** Every field is optional; sensible defaults are applied
on install.

| Setting | Purpose |
|---|---|
| **Google Search Console verification** | Paste the whole `<meta name="google-site-verification" …>` tag (or just the token). Injected on every public page. Then add the property at [search.google.com/search-console](https://search.google.com/search-console) and submit `/sitemap.xml`. |
| **Bing Webmaster verification** | Same, for the `msvalidate.01` tag. |
| **Default meta description** | Used on pages without their own description (home, browse, search). |
| **Default social share image** | `og:image` fallback (≈1200×630). Used when a page or an item with no media is shared. |
| **Twitter / X @handle** | Emitted as `twitter:site`. |
| **Discourage indexing (whole site)** | Staging switch: every page becomes `noindex,nofollow` and robots.txt disallows everything. **Off in production.** |
| **Noindex filtered/paginated browse** | Keeps facet/pagination URLs out of the index while still following links to resources. On by default. |
| **Emit schema.org JSON-LD** | Toggle structured data. On by default. |
| **Emit citation meta tags** | Toggle Zotero / Scholar tags. On by default. |
| **Serve unAPI (Zotero RDF)** | Toggle the `/unapi` endpoint + discovery tags for primary sources. On by default; needs the meta tags on for the fallback. |
| **Serve /sitemap.xml & /robots.txt** | Toggle the sitemap/robots endpoints. On by default. |
| **Sitemap cache lifetime** | Seconds before the cached sitemap is rebuilt. Default 86400 (24h). |
| **Ping IndexNow when content changes** | Off by default. See the caveat below. |
| **IndexNow key** | A hex key you choose (`openssl rand -hex 16`); served at `/{key}.txt`. |

### Per-page SEO

**Admin → SEO → Static pages** lists every site page with editable **meta title**, **meta
description**, **share image** (picked with Omeka's asset selector) and **indexing**
(default / index / noindex). Blank
fields fall back to the page's own title and the site-wide defaults. Resource pages are not
listed — their SEO is automatic.

Because IWAC publishes the collection as two Omeka sites (`afrique_ouest`/fr and
`westafrica`/en), the screen opens on the default site and a **Site** selector at the top
switches between them — overrides are stored per site, so the English pages
(`home`, `about`, …) are edited on the `westafrica` site and the French ones on
`afrique_ouest`.

### Admin dashboard

**Admin → SEO** shows what is configured, the sitemap/robots URLs with public-resource counts,
a **bilingual (hreflang) coverage** report listing public pages that are missing from the
`page_pairs` map, and a **Regenerate** button that clears the sitemap cache so it rebuilds on
the next request. It also warns when the stored IndexNow key cannot match the `/{key}.txt`
route (non-hex) and would fail verification.

---

## How the metadata is produced

The head signals are written into Omeka's request-global head placeholder helpers
(`headTitle`, `headMeta`, `headLink`, `headScript`), which the theme already echoes in
`<head>` — so **no theme template needs to change**.

* **Resource pages** (`view.show.after` on the Item/ItemSet/Media controllers): title from
  `displayTitle()`, description from `bibo:shortDescription` (the AI summary on newspaper
  articles) / `dcterms:abstract` / `dcterms:description` (truncated to ~160 chars),
  canonical from the resource's site URL, `og:image` from the primary media, and JSON-LD
  from the resource class.
* **Static pages** (`view.show.after` on the Page controller): the editor's per-page
  overrides, else defaults; `WebSite` JSON-LD on the home page.
* **Browse/search pages** (`view.browse.after`): self-referential canonical and optional
  `noindex` on faceted/paginated variants.
* **Every page** (`view.layout`): site-wide constants (`og:site_name`, `og:locale`,
  `twitter:card`, verification tags) and gap-fills for anything not already set. Resource
  values always win because the resource listeners run before the layout listener.

### schema.org `@type` map

Driven by `config/module.config.php → iwac_seo.structured_data.class_types` (overridable via
`config/local.config.php`), keyed by Omeka **resource class** id:

| Resource class | `@type` |
|---|---|
| `foaf:Person` (94) | `Person` (+ `givenName`/`familyName`, `affiliation`) |
| `dcterms:Location` (9) | `Place` (+ `geo` from `curation:coordinates`) |
| `foaf:Organization` (96) | `Organization` (+ `parentOrganization`) |
| `bibo:Event` (54) | `Event` (+ `startDate`, `location`) |
| `fabio:AuthorityFile` (244) | `DefinedTerm` (subjects / authority files) |
| `bibo:Article` (36) | `NewsArticle` (newspaper article) |
| `bibo:Issue` (60) | `PublicationIssue` (Islamic-publication issue) |
| `bibo:AudioVisualDocument` (38) | `VideoObject` (+ `duration`, `uploadDate`) |
| `bibo:Document` (49) | `DigitalDocument` |
| `bibo:AcademicArticle` (35) | `ScholarlyArticle` |
| `fabio:BookReview` (178) | `Review` |
| `bibo:Chapter` (43) | `Chapter` |
| `bibo:Book` (40) / `bibo:EditedBook` (52) | `Book` |
| `bibo:Thesis` (88) | `Thesis` |
| `bibo:Report` (82) | `Report` |
| `bibo:PersonalCommunication` (77) | `CreativeWork` |
| `fabio:BlogPost` (305) | `BlogPosting` |

Creative works also carry `author`/`editor`/`contributor` (from `bibo:authorList`,
`dcterms:creator`, `bibo:editorList`, `dcterms:contributor`), `datePublished`, `inLanguage`,
`keywords`, `spatialCoverage`, `isPartOf` (the journal/newspaper/book/event) and `publisher`
when present. Every record carries `sameAs` from its `dcterms:identifier` **URI** values
(Wikidata, GeoNames, VIAF …) — opaque internal ids like `iwac-article-0000001` are skipped.

---

## Citation metadata (Zotero, Google Scholar, …)

So that the **Zotero Connector** and similar tools capture a resource page as a reference,
each item page also emits embedded bibliographic `<meta>` tags — the two vocabularies
Zotero's *Embedded Metadata* translator reads:

* **Highwire Press** (`citation_title`, repeated `citation_author` / `citation_editor`,
  `citation_publication_date`, `citation_journal_title` / `citation_inbook_title`,
  `citation_volume`, `citation_issue`, `citation_firstpage`/`citation_lastpage`,
  `citation_publisher`, `citation_dissertation_institution`,
  `citation_technical_report_institution`, `citation_doi`, `citation_language`,
  `citation_keywords`, `citation_abstract`, `citation_pdf_url`, `citation_public_url`).
* **Dublin Core** (`DC.title`, `DC.creator`, `DC.date`, `DC.publisher`, `DC.type`,
  `DC.language`, `DC.identifier`, `DC.subject`, `DC.description`) — emitted for **every**
  resource, including authority pages, as a generic fallback.

IWAC's field conventions are baked into the per-kind tags (see `src/Service/CitationMeta.php`):

- the **journal / newspaper / publication title** lives in `dcterms:publisher` (often a
  linked item set), not `dcterms:isPartOf`;
- a **book chapter's book title** lives in `dcterms:alternative`;
- **DOIs** live in `bibo:doi` (a URI value); **ISBN/ISSN are not recorded** in IWAC, so
  those tags are not emitted;
- **Zotero tags** are built from both `dcterms:subject` (*Sujet*) and `dcterms:spatial`
  (*Couverture spatiale*). Both sets are emitted as repeated `DC.subject` — the channel
  Zotero's *Embedded Metadata* translator turns into tags via its RDF backend (it pre-empts
  `citation_keywords`) — and also folded into `citation_keywords` for Google Scholar and as
  a fallback.

The citation kind per class lives in `iwac_seo.citation.class_kinds` and is overridable.
`citation_pdf_url` is emitted only for a **public** PDF media, so restricted bitstreams are
never exposed. Toggle the whole feature with **Emit citation meta tags** in Configure.

### Newspaper articles & magazine issues — correct Zotero typing

Newspaper articles (class 36) and Islamic-publication issues (class 60) are the bulk of the
archive, but Highwire Press has **no** container tag for a newspaper or magazine — and any
`citation_*` container tag forces Zotero's item type to `journalArticle` (in the Embedded
Metadata translator, the Highwire type wins over `DC.type`). So those kinds instead:

- set **`DC.type`** to a valid Zotero item-type id (`newspaperArticle` / `magazineArticle`),
  which the translator's RDF backend honours **because no Highwire container tag is present**; and
- route the publication name through **`prism.publicationName`** → Zotero's `publicationTitle`.

The same DC.type technique types blog posts (`blogPost`), audiovisual (`videoRecording`) and
scientific communications (`presentation`). The scholarly references (journal article, book,
chapter, thesis, report, review) use the standard Highwire container tags, which type them
precisely on their own.

### unAPI — full-fidelity Zotero import for primary sources

Two things the archive needs simply **cannot** be expressed through the flat `<meta>` tags
that Zotero's *Embedded Metadata* translator reads (verified against `translators/RDF.js`):

- a **call number** (French Zotero: *Cote*) — Zotero only fills `callNumber` from a *typed*
  RDF node (`dcterms:LCC`), never from a meta tag; a bare `dc:identifier` that is not an
  ISBN/ISSN/DOI is dropped; and
- a **single-field institutional creator** — a literal author is always run through Zotero's
  `cleanAuthor()` and split, so `Association Islamique d'Al Mawadda Burkina Faso` becomes
  `… Burkina / Faso`.

So the **primary-source** kinds (newspaper article, periodical issue, document, audiovisual,
photograph) additionally expose [**unAPI**](https://www.zotero.org/support/dev/exposing_metadata):
each page carries `<link rel="unapi-server" href="/unapi">` and `<abbr class="unapi-id">`, and
`/unapi?id={url}&format=rdf_zotero` serves the item as **Zotero RDF**. Zotero ranks the unAPI
translator (priority 300) **above** Embedded Metadata (400), so the Connector imports the RDF
instead of scraping the meta tags — which lets the module set every field exactly:

- `z:itemType` → the precise item type; `dc:identifier → dcterms:URI` → the item URL;
- **Cote** ← the `iwac-` identifier, as a `dc:subject → dcterms:LCC → rdf:value` node → `callNumber`;
- **institutional creators** ← a `foaf:Person` node with only `foaf:surname` → a single-field
  creator; **persons** stay literal `dcterms:creator` values that Zotero splits into first/last;
- **tags** ← `dcterms:subject` (Sujet) + `dcterms:spatial` (Couverture spatiale) as `dc:subject`;
- `prism:publicationName`, `prism:number`/`prism:volume`, `bib:pages`, `dc:date`, `dc:language`,
  `dcterms:abstract`, `dc:rights`, and the public PDF as an `eprints:document_url` attachment.

The Highwire / DC `<meta>` tags stay on every page — they still feed Google Scholar and are the
fallback if unAPI is unreachable, and they remain the sole path for the bibliographic-reference
kinds (which Highwire already types precisely). `ZoteroRdf` reuses the same
`iwac_seo.citation.class_kinds` map as `CitationMeta`; toggle the endpoint with **Serve unAPI**
in Configure (the `iwac_seo_unapi` setting).

---

## Item-page citation tools — "How to cite" + downloads

The capture above is for reference-manager software. Human readers get a public **"How to cite"**
panel, provided as a **resource page block**: this module registers it (`resource_page_block_layouts`),
so it appears in the theme's **Configure resource pages** screen (Admin → Themes → *your theme* →
Configure resource pages) and an admin controls its region and order. The block delegates to the
theme ([IWAC-theme](https://github.com/fmadore/IWAC-theme)) for the UI (its `common/citation`
partial + styling + JS) while this module owns the citation data, so the resource-class → kind
mapping is never duplicated. The block reads its data through the **`iwacCitation($item)`** view
helper, which returns:

- a formatted **Chicago** (default), **APA** and **MLA** reference — switchable, one-click copy —
  produced by `CitationFormatter`. It is **hand-rolled** (no CSL-processor dependency; the module
  ships no bundled `vendor/`) and **bilingual**: connectives and month names follow the site
  language, so the French site reads *Dans*, *sous la dir. de*, *7 décembre 2018*;
- **downloads** at **`/cite/{item-id}/{format}`** — **BibTeX** (`.bib`), **RIS** (`.ris`) and
  **CSL-JSON** (`.json`), served by `CitationController` as an `attachment` whose filename is the
  `iwac-` accession id; and
- the **Zotero RDF** link (the `/unapi` endpoint above) for the Connector-eligible kinds.

This is the single-item replacement for `Daniel-KM/Omeka-s-module-BulkExport`. All three
formatters and serialisers read **one** normalized record from `CitationData`, which reuses the
`CitationMeta` field conventions (container in `dcterms:publisher`, a chapter's book title in
`dcterms:alternative`, NumericDataTypes dates, person-vs-institution creators). Authority records
(person, place, organisation, event, subject) are **not** citable works — the helper returns
`null` and the panel is hidden. The panel and downloads share the citation kill-switch with the
meta tags (the `iwac_seo_citation_meta` setting).

---

## Bilingual SEO (hreflang)

IWAC is the same collection under two Omeka sites — `afrique_ouest` (fr) and `westafrica`
(en). The module ties them together the way Google expects:

- **Canonicals are self-referential per language.** The French page canonicals to its own
  `…/afrique_ouest/…` URL, the English page to `…/westafrica/…`. It never points one
  language at the other (that would drop a language from the index).
- **`rel="alternate" hreflang`** links every page to its counterpart — with a self link and
  an `x-default` (the French site, matching the host-root redirect) — so Google serves the
  right language and treats the pair as one set instead of duplicate content.
  - **Resources** (items, item sets, media) are shared across both sites under the same
    `o:id`, so the alternate is just the same path under the other site slug — fully automatic.
  - **Static pages** have different slugs per language (`accueil`/`home`, `a-propos`/`about` …);
    they are mapped by the `iwac_seo.hreflang.page_pairs` table in `config/module.config.php`.
    A page with no entry simply gets no alternate (never a broken one). Update the table when
    pages are added or renamed.
- **`og:locale` + `og:locale:alternate`** advertise both languages to social platforms.
- The **item / item-set sitemaps** carry `<xhtml:link rel="alternate" hreflang>` for each
  language, so both versions are discoverable from the single (default-site) `<loc>` entries.

Configure the language map, `x_default` and page pairs under `iwac_seo.hreflang` (override via
`config/local.config.php`); set `enabled => false` to turn it all off.

## Sitemap

* `/sitemap.xml` — sitemap **index** listing the children below.
* `/sitemap-pages.xml` — the home page + all public site pages (driven by the site
  navigation, so menu order and depth set the priority).
* `/sitemap-item-sets.xml` — public item-set browse pages.
* `/sitemap-items-{n}.xml` — public items, chunked at 50,000 URLs per file (IWAC's ~22,600
  public items fit in a single chunk).

Each item entry also carries an **`<image:image>`** element (the primary media's large
thumbnail — the page scan or cover) so Google Images can index the scans; disable via
`iwac_seo.sitemap.include_images` in a local config override.

Resource ids + modified timestamps are read with one lean DBAL query per type (public
resources scoped to the site), so the whole sitemap renders in well under a second. Output is
cached under `files/iwac-seo-cache/` and served with `Cache-Control` / `Last-Modified`
headers; the cache is invalidated when an item or page changes, and any cache failure falls
back to live generation.

---

## IndexNow ping — and the bulk-import caveat

When **Ping IndexNow** is on, public item/page **create** and **update** events queue the
changed URL — and **delete** events too, so engines recrawl and drop the URL; a background
job submits the queue to IndexNow at most once every 15 minutes.

> **IWAC content arrives mainly through bulk imports that write thousands of items at once.**
> The queue is therefore capped (200) and the job *skips* pinging when it looks like a bulk
> change — those URLs are discovered through the sitemap instead. IndexNow is meant for
> occasional **manual** edits. If in doubt, leave it **off** and rely on the sitemap +
> Search Console.

Google is **not** pinged: its sitemap-ping endpoint was retired in 2023. Google discovers
content via the robots.txt `Sitemap:` line and Search Console.

---

## Project layout

```
IwacSeo/
├── Module.php                       # listeners, ACL, install/uninstall, config form
├── composer.json                    # type omeka-module; PSR-4 IwacSeo\ -> src/ (no runtime deps)
├── config/
│   ├── module.ini
│   └── module.config.php            # routes, services, navigation, iwac_seo defaults
├── src/
│   ├── Controller/
│   │   ├── SitemapController.php     # /sitemap*.xml, /robots.txt, /{key}.txt
│   │   ├── UnapiController.php       # /unapi -> Zotero RDF
│   │   ├── CitationController.php    # /cite/{id}/{format} -> BibTeX/RIS/CSL-JSON
│   │   └── Admin/SeoController.php   # dashboard + static-page table
│   ├── Form/{ConfigForm,PageSeoForm}.php
│   ├── Job/PingSearchEngines.php
│   ├── View/Helper/Citation.php      # iwacCitation() -> "How to cite" view-model
│   ├── Site/ResourcePageBlockLayout/Citation.php  # "How to cite" resource page block
│   └── Service/
│       ├── HeadMetadata.php          # computes + injects all <head> SEO
│       ├── StructuredData.php        # schema.org JSON-LD (by resource class)
│       ├── CitationMeta.php          # Highwire + Dublin Core citation tags
│       ├── ZoteroRdf.php             # Zotero RDF (served via unAPI)
│       ├── CitationData.php          # normalized citation record (shared source of truth)
│       ├── CitationFormatter.php     # Chicago / APA / MLA text (hand-rolled, bilingual)
│       ├── CitationExport.php        # BibTeX / RIS / CSL-JSON serialisers
│       ├── Concern/ResourceValueReader.php  # shared value-readers (trait)
│       ├── SitemapGenerator.php      # lean queries + XML + cache
│       ├── PageSeoStore.php          # per-page overrides (site setting)
│       ├── Pinger.php                # IndexNow submit
│       └── *Factory.php
├── view/iwac-seo/admin/seo/{dashboard,pages}.phtml
├── asset/css/admin.css
└── language/template.pot
```

---

## Verifying a deployment

1. `curl -s https://islam.zmo.de/sitemap.xml | head` → a `<sitemapindex>`; the child sitemaps
   list `<url>` entries with `<lastmod>`.
2. `curl -s https://islam.zmo.de/robots.txt` → `Disallow: /admin/` and a `Sitemap:` line.
3. View-source an item page (`/s/afrique_ouest/item/2231`): confirm `<title>`, `description`,
   `og:*`, `twitter:*`, `<link rel="canonical">` and an `application/ld+json` block. Validate
   the JSON-LD with the [Rich Results Test](https://search.google.com/test/rich-results).
4. Open a reference page (e.g. a journal article) with the **Zotero Connector** and confirm it
   saves as the right item type with authors, date, container and DOI; open a newspaper article
   and confirm it saves as a *newspaper article* with the newspaper as the publication.
5. Home page: `og:site_name` and a `WebSite` JSON-LD block; the GSC tag once a token is set.
6. In Search Console: add the property, verify via the meta tag, submit `/sitemap.xml`.

---

## Roadmap

* **COinS** (`<span class="Z3988">`) as a complementary reference-embedding signal, and to
  expose references on list pages.
* unAPI + per-item **BibTeX / RIS** export links ("Cite / Export").
* Per-URL hreflang in the *pages* sitemap too (static-page alternates are already emitted
  on-page; only the item / item-set sitemaps carry `<xhtml:link>` so far).
* Optional nginx-level caching of `/sitemap*.xml` (the module already emits
  `Cache-Control`/`Last-Modified`; the image-sitemap entries shipped in 0.6.0).

---

## Uninstalling

Removes every `iwac_seo_*` global setting, the per-site `iwac_seo_pages` overrides and the
sitemap cache directory (`files/iwac-seo-cache/`).

---

## Development

No production dependencies; `composer install` pulls PHPUnit only. Run the test suite with:

```sh
composer install
vendor/bin/phpunit
```

The suite covers the pure logic that regresses most silently — the Chicago/APA/MLA
formatter, the BibTeX/RIS/CSL-JSON serialisers, hreflang resolution and the text
utilities. GitHub Actions (`.github/workflows/ci.yml`) runs a syntax check plus the suite
on PHP 8.2–8.4 for every push and pull request. `ROADMAP.md` documents the refactoring
plan behind 0.6.0 and what remains deliberately out of scope.

---

## Licence

GPL-3.0-or-later. © Frédérick Madore.
