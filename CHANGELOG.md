# Changelog

All notable changes to the IWAC SEO module. Versions follow
[semantic versioning](https://semver.org/); dates are ISO 8601.

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
