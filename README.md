# DRE SEO

Search-engine optimisation and XML sitemap for the **Africa Multiple DRE** Omeka S
instance ([data.africamultiple.uni-bayreuth.de](https://data.africamultiple.uni-bayreuth.de)).

The site ships almost no SEO metadata out of the box: pages have a `<title>` but no
description, no Open Graph / Twitter cards, no canonical link, no structured data, no
Google Search Console verification, and there is no `sitemap.xml` or `robots.txt`. This
module fills all of that in — **manually for static pages, automatically for every
resource page** — and adds a sitemap, a robots file, and an optional IndexNow ping.

It is a self-contained, settings-only module in the DRE family (alongside `DRESearch`,
`ResourceVisualizations`, `DRE-theme`). No third-party Composer dependencies, no database
tables, no theme edits.

---

## What it does

| Area | Detail |
|---|---|
| **Meta tags** | `<title>`, `<meta name="description">`, canonical link on every public page. |
| **Open Graph / Twitter** | `og:title/description/image/type/url/site_name/locale` + `twitter:card/title/description/image/site` so shared links render a rich preview. |
| **schema.org JSON-LD** | Per-resource structured data typed from the resource template (Person, Place, Organization, ResearchProject, CreativeWork, Dataset, scholarly types …), plus `WebSite` + `SearchAction` on the home page and `BreadcrumbList` on resource pages. |
| **Citation metadata (Zotero)** | Highwire Press `citation_*` + Dublin Core `DC.*` `<meta>` tags so the **Zotero Connector**, Google Scholar, Mendeley and other reference managers capture each item/publication as a properly-typed reference (with authors, date, container, DOI, PDF …). |
| **og:image** | The first image attached to an item; falls back to a site-wide default share image. |
| **XML sitemap** | `/sitemap.xml` index → `/sitemap-pages.xml`, `/sitemap-item-sets.xml`, `/sitemap-items-{n}.xml` (chunked at 50k). Public resources only, with `<lastmod>`, `<changefreq>`, `<priority>`. Cached. |
| **robots.txt** | `/robots.txt` disallowing `/admin` and pointing crawlers at the sitemap. A staging switch can `Disallow: /` the whole site. |
| **Google Search Console** | Paste the verification snippet in the module config; the `<meta name="google-site-verification">` tag is injected site-wide. |
| **IndexNow ping** | Optionally notifies Bing/Yandex when public content changes (throttled; skips bulk syncs). |

Static-page SEO is **set by hand** (a central admin table); resource-page SEO is **derived
automatically** from each item's metadata, with the configurable defaults filling any gaps.

---

## Installation

The module folder **must be named `DRESeo`** inside Omeka's `modules/` directory (the folder
name has to match the namespace, exactly like `DRESearch`). Deploy it the same way as the
other DRE modules (the `omeka-s-docker` sideload / `EXTRA_MODULES` mechanism or the
ansible-bootstrap copy), then:

1. **Admin → Modules → DRE SEO → Install.**
2. **Configure** (see below) — at minimum paste your Google Search Console snippet and pick a
   default share image.
3. Visit `/sitemap.xml` and `/robots.txt` to confirm they render.

No web-server changes are needed: nginx's `try_files … /index.php` already routes the
`.xml`/`.txt` endpoints to Omeka because no such static files exist.

There are no third-party dependencies, so `composer install` is **not** required — Omeka
autoloads the `DRESeo\` namespace from `src/`.

**Requirements:** Omeka S `^4.2.0`, PHP `>= 8.2`.

---

## Configuration

**Modules → DRE SEO → Configure.** Every field is optional; sensible defaults are applied on
install.

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
| **Serve /sitemap.xml & /robots.txt** | Toggle the sitemap/robots endpoints. On by default. |
| **Sitemap cache lifetime** | Seconds before the cached sitemap is rebuilt. Default 86400 (24h). |
| **Ping IndexNow when content changes** | Off by default. See the caveat below. |
| **IndexNow key** | A hex key you choose (`openssl rand -hex 16`); served at `/{key}.txt`. |

### Per-page SEO

**Admin → SEO → Static pages** lists every site page with editable **meta title**, **meta
description**, **share image** (asset ID) and **indexing** (default / index / noindex). Blank
fields fall back to the page's own title and the site-wide defaults. Resource pages are not
listed — their SEO is automatic.

### Admin dashboard

**Admin → SEO** shows what is configured, the sitemap/robots URLs with public-resource counts,
and a **Regenerate** button that clears the sitemap cache so it rebuilds on the next request.

---

## How the metadata is produced

The head signals are written into Omeka's request-global head placeholder helpers
(`headTitle`, `headMeta`, `headLink`, `headScript`), which the theme already echoes in
`<head>` — so **no theme template needs to change**.

* **Resource pages** (`view.show.after` on the Item/ItemSet/Media controllers): title from
  `displayTitle()`, description from `dcterms:description` / `dcterms:abstract` (truncated to
  ~160 chars), canonical from the resource's site URL, `og:image` from the primary media, and
  JSON-LD from the resource template.
* **Static pages** (`view.show.after` on the Page controller): the editor's per-page
  overrides, else defaults; `WebSite` JSON-LD on the home page.
* **Browse/search pages** (`view.browse.after`): self-referential canonical and optional
  `noindex`.
* **Every page** (`view.layout`): site-wide constants (`og:site_name`, `og:locale`,
  `twitter:card`, verification tags) and gap-fills for anything not already set. Resource
  values always win because the resource listeners run before the layout listener.

### schema.org `@type` map

Driven by `config/module.config.php → dre_seo.structured_data.template_types` (overridable via
`config/local.config.php`), keyed by Omeka resource-template id:

| Template | `@type` |
|---|---|
| Organisation (2) | `Organization` |
| Location (3) | `Place` (+ `geo` when coordinates exist) |
| Persons (4) | `Person` (+ `affiliation`, `sameAs` ← `dre:wisskiUrl` / `dre:rdspaceHandle`) |
| Projects (5) | `ResearchProject` |
| Research sections (7) | `Collection` |
| Research items (10) | `CreativeWork` |
| Publications (11–20) | `ScholarlyArticle` / `Book` / `Chapter` / `Thesis` / `Dataset` / `Review` / `BlogPosting` … |

Creative works also carry `author`/`contributor` (from `bibo:authorList`, `dcterms:creator`,
`marcrel:*`), `datePublished`/`dateCreated`, `inLanguage`, `keywords`, `spatialCoverage`,
`isPartOf` and `publisher` when present.

---

## Citation metadata (Zotero, Google Scholar, …)

So that the **Zotero Connector** and similar tools capture a resource page as a reference,
each item/publication page also emits embedded bibliographic `<meta>` tags — the two
vocabularies Zotero's *Embedded Metadata* translator reads:

* **Highwire Press** (`citation_title`, repeated `citation_author`, `citation_publication_date`,
  `citation_journal_title` / `citation_inbook_title` / `citation_conference_title`,
  `citation_volume`, `citation_issue`, `citation_firstpage`/`citation_lastpage`,
  `citation_doi`, `citation_isbn`, `citation_issn`, `citation_publisher`, `citation_language`,
  `citation_keywords`, `citation_abstract`, `citation_pdf_url`, `citation_public_url`). The tag
  set is chosen per resource template so Zotero infers the right **item type** (journal article,
  book section, book, conference paper, thesis, report, dataset, …).
* **Dublin Core** (`DC.title`, `DC.creator`, `DC.date`, `DC.publisher`, `DC.type`,
  `DC.language`, `DC.identifier`, `DC.subject`, `DC.description`) — emitted for **every**
  resource, including person/place/organisation pages, as a generic fallback.

The Highwire item-type mapping lives in `dre_seo.citation.template_kinds`
(`config/module.config.php`) and is overridable. `citation_pdf_url` is emitted only for a
**public** PDF media, so restricted bitstreams are never exposed. Toggle the whole feature with
**Emit citation meta tags** in Configure (on by default). The same tags double as
[Google Scholar](https://scholar.google.com) indexing signals.

---

## Sitemap

* `/sitemap.xml` — sitemap **index** listing the children below.
* `/sitemap-pages.xml` — the home page + all public site pages.
* `/sitemap-item-sets.xml` — public item-set browse pages.
* `/sitemap-items-{n}.xml` — public items, chunked at 50,000 URLs per file.

Resource ids + modified timestamps are read with one lean DBAL query per type (public
resources scoped to the site), so even ~9k items render in well under a second. Output is
cached under `files/dre-seo-cache/`; any cache failure falls back to live generation.

---

## IndexNow ping — and the bulk-sync caveat

When **Ping IndexNow** is on, public item/page **create** and **update** events queue the
changed URL; a background job submits the queue to IndexNow at most once every 15 minutes.

> **This site's content arrives mainly through the `MongoDB2OmekaS` bulk sync, which writes
> thousands of items.** The queue is therefore capped (200) and the job *skips* pinging when
> it looks like a bulk change — those URLs are discovered through the sitemap instead.
> IndexNow is meant for occasional **manual** edits. If in doubt, leave it **off** and rely on
> the sitemap + Search Console.

Google is **not** pinged: its sitemap-ping endpoint was retired in 2023. Google discovers
content via the robots.txt `Sitemap:` line and Search Console.

---

## Project layout

```
DRESeo/
├── Module.php                       # listeners, ACL, install/uninstall, config form
├── composer.json                    # type omeka-module; PSR-4 DRESeo\ -> src/ (no runtime deps)
├── config/
│   ├── module.ini
│   └── module.config.php            # routes, services, navigation, dre_seo defaults
├── src/
│   ├── Controller/
│   │   ├── SitemapController.php     # /sitemap*.xml, /robots.txt, /{key}.txt
│   │   └── Admin/SeoController.php   # dashboard + static-page table
│   ├── Form/{ConfigForm,PageSeoForm}.php
│   ├── Job/PingSearchEngines.php
│   └── Service/
│       ├── HeadMetadata.php          # computes + injects all <head> SEO
│       ├── StructuredData.php        # schema.org JSON-LD
│       ├── SitemapGenerator.php      # lean queries + XML + cache
│       ├── PageSeoStore.php          # per-page overrides (site setting)
│       ├── Pinger.php                # IndexNow submit
│       └── *Factory.php
├── view/dre-seo/admin/seo/{dashboard,pages}.phtml
├── asset/css/admin.css
└── language/template.pot
```

---

## Verifying a deployment

1. `curl -s https://HOST/sitemap.xml | head` → a `<sitemapindex>`; the child sitemaps list
   `<url>` entries with `<lastmod>`.
2. `curl -s https://HOST/robots.txt` → `Disallow: /admin/` and a `Sitemap:` line.
3. View-source an item page (`/s/amira/item/100`): confirm `<title>`, `description`, `og:*`,
   `twitter:*`, `<link rel="canonical">` and a `application/ld+json` block. Validate the JSON-LD
   with the [Rich Results Test](https://search.google.com/test/rich-results).
4. Home page: `og:site_name` and a `WebSite` JSON-LD block; the GSC tag once a token is set.
5. **Admin → SEO → Static pages**: set a title/description on a page → confirm it appears in
   that page's `<head>`.
6. In Search Console: add the property, verify via the meta tag, submit `/sitemap.xml`.

---

## Roadmap

* **COinS** (`<span class="Z3988">`) as a complementary reference-embedding signal for
  translators that prefer it, and to expose multiple references on list pages.
* **unAPI** + per-item **BibTeX / RIS** export links ("Cite / Export") for one-click download.
* Structured-data & citation enrichment: `sameAs` ORCID / GND / VIAF on persons, `geo` from the
  geoloc data on places, `Dataset` `distribution` / `license` for research data.
* Per-static-page SEO fields **inline in the page editor** (currently a central table).
* `hreflang` / multilingual metadata once localized page variants exist.
* Image / news sitemap extensions; optional nginx-level caching of `/sitemap*.xml`.

---

## Uninstalling

Removes every `dre_seo_*` global setting and the per-site `dre_seo_pages` overrides. The
sitemap cache directory (`files/dre-seo-cache/`) can be deleted manually if desired.

---

## Licence

GPL-3.0-or-later. © Frédérick Madore / Africa Multiple Cluster of Excellence, University of
Bayreuth.
