# IWAC SEO 1.1 operations

Upgrade the module through Omeka's module manager. Version 1.1 creates the
`iwac_seo_ping` outbox and migrates pending URLs from the old setting. Keep a normal
database backup before upgrading. This repository change does not deploy the module,
change Google permissions, or install a server cron job.

## Public origin

Set `IWAC_SEO_PUBLIC_ORIGIN=https://islam.zmo.de` in the PHP web **and CLI** environment.
This pins canonical URLs, resource identities, hreflang links and sitemap origins
independently of incoming Host headers. Without it, Omeka's configured URL generation
is used. Configure the reverse proxy to accept only the production hostname; use
authentication for a private staging site. `robots.txt` permits fetching pages carrying
`noindex`, since a disallowed URL prevents Google from seeing that directive.

Clean pagination has its own canonical and remains indexable. Tracking parameters
(`utm_*`, `gclid`, `fbclid`, `msclkid`) are removed. Search/filter/sort variants are
self-canonical and `noindex, follow` when the existing browse setting is on. Resource
pages retain their resource canonical even when a linked-record widget is paginated.

## Sitemap and queue

The index includes the other configured public language site's page sitemap, including
pages with no translation. Static pages marked `noindex` are excluded. Item files
default to 5,000 URLs and fail rather than publish a document exceeding 50,000 URLs or
50 MiB. Index entries omit unverified `lastmod`; resource entries retain database
modification dates. The cache uses atomic publication, bounded build-lock waits and
generation invalidation. A database failure yields HTTP 503, with a five-minute bounded
stale fallback where a still-valid cached generation exists.

Install this cron entry as the Omeka operating-system user, adjusting both paths:

```cron
*/5 * * * * OMEKA_PATH=/var/www/html IWAC_SEO_PUBLIC_ORIGIN=https://islam.zmo.de /usr/bin/php /var/www/html/modules/IwacSeo/scripts/drain-indexnow.php
```

The existing content-save dispatch remains throttled to 15 minutes; cron drains the
remaining work independently of another edit. Each job leases up to 200 URLs, groups
them by origin and acknowledges only accepted submissions. Failed submissions retry
with exponential backoff; abandoned leases expire after ten minutes. A newer edit
survives an older job's acknowledgement. Bulk queues are processed in batches rather
than discarded. Five failed attempts leave the URL visible in the dashboard's exhausted
count. After fixing the transport/key problem, run the same command with
`--retry-failed` once. No Google Indexing API or retired sitemap-ping endpoint is used.

## Search Console in GitHub Actions

The scheduled workflow is `.github/workflows/search-console.yml`. It is separate from
PR CI and disabled until repository variable `GSC_ENABLED` is `true`. Its manifest
contains 28 public sentinel URLs: both languages for all 14 mapped work classes.
Add/remove URLs in `config/search-console-urls.json` as records change. The limit is
250 URLs per run, deliberately below Google's per-property daily inspection quota.
This is a sentinel monitor, not a complete crawl of the archive.

The independent live-HTML job can be enabled with `LIVE_SEO_ENABLED=true`, without
Google credentials. It checks response redirects, canonical uniqueness, descriptions,
HTML/HTTP noindex directives, JSON-LD syntax and reciprocity between sampled hreflang
pages. Enable it after deploying 1.1. Run it locally with
`python3 .github/scripts/live-seo.py`; results go to `live-seo-results/`.

1. Enable the Search Console API in a Google Cloud project and create a service account.
2. Grant that account access to the **exact Search Console property** being monitored.
   A verification tag in Omeka does not grant this access.
3. Configure a Workload Identity Federation provider for GitHub OIDC. Restrict its
   attribute condition and service-account impersonation binding to this repository
   and its trusted default branch. Permit service-account impersonation using
   `roles/iam.workloadIdentityUser`. Use the current Google action instructions for
   your organisation's identity configuration.
4. Set GitHub repository variables:

   | Variable | Value |
   |---|---|
   | `GSC_PROPERTY` | Exact property, e.g. `sc-domain:islam.zmo.de` or `https://islam.zmo.de/` |
   | `GSC_WORKLOAD_IDENTITY_PROVIDER` | Full `projects/.../locations/global/workloadIdentityPools/.../providers/...` name |
   | `GSC_SERVICE_ACCOUNT` | Service-account email |
   | `GSC_ENABLED` | `true`, after the preceding configuration |

5. Run **Search Console monitor → Run workflow** on the default branch. Inspect the
   job summary and `search-console-inspection` artifact. Configure GitHub Actions
   notifications in your own GitHub notification settings.

The action requests only `webmasters.readonly` and uses short-lived tokens, with no
JSON credential file or long-lived private key. Repository workflows require only
contents/actions read and OIDC token permissions. PR tests use fixtures and no Google
credentials. Current action versions were verified against their upstream releases
on 10 September 2026.

The monitor records indexing status, Google canonical mismatches, rich-result errors,
and submitted-sitemap errors. A finding must repeat on two observations before it
causes a failure notification. A confirmed unchanged finding does not fail every run;
it remains in the report. Recoveries appear in the next summary. API/authentication
failures have their own failure status and never imply recovery. State is carried in
artifacts retained for 30 days; an absent or older-than-seven-days baseline is explicitly
treated as a new baseline. Retained state survives an authentication failure when the
prior artifact could be restored. Branch scoping avoids mixing test runs into the
default branch's baseline.

Google's API does **not** expose the Search Console notification inbox, manual-action
report or complete video-indexing report. URL Inspection reports Google's indexed
snapshot, not a live test; findings can persist until Google recrawls. No source-code
change can guarantee indexing or rich-result eligibility. A video needs an actual
recorded upload date, a representative accessible thumbnail and a playable, prominent
video on the public page. Catalogue dates are never converted into invented upload times.

Sources: [Search Console API services](https://developers.google.com/webmaster-tools/v1/api_reference_index),
[URL Inspection](https://developers.google.com/webmaster-tools/v1/urlInspection.index/inspect),
[Google GitHub authentication action](https://github.com/google-github-actions/auth),
[video structured data](https://developers.google.com/search/docs/appearance/structured-data/video),
[pagination](https://developers.google.com/search/docs/specialty/ecommerce/pagination-and-incremental-page-loading).

## Citation policy and editorial review

Admin → SEO → Citation preview shows Chicago bibliography, APA reference-list and MLA
works-cited entries in English and French, with missing catalogue fields. It reads
records without changing them. Citation display preserves source title casing and
proper names; it does not guess which words should be lowercased for APA sentence case.
The French output is a documented localised rendering, rather than a separate claim
that all three publishers prescribe an identical French adaptation.

The shared reader selects public values, prefers the requested language, reads raw
stored date strings, retains institution names and prefers structured authority names.
It preserves both endpoints of date ranges, combined issues, thesis genre, event,
medium, report number, edition, reviewed title, archive identifier, ISBN/ISSN and source
URL where catalogued. Missing degree, creator role, reviewed author or publication
status remains missing; editors should resolve catalogue gaps rather than guess them.

| Kind | Important treatment |
|---|---|
| Newspaper / magazine article | Full date, publication and available issue/page metadata |
| Whole periodical issue | Standalone title, combined issue numbers, full date interval; CSL `periodical` |
| Video / audio | Distinct media kinds; source URL, medium and public media only |
| Document / photograph | Responsible creator, medium, archive and call number where present |
| Journal article / book review | Volume, issue even without volume, pages, DOI; review relationship without a rating |
| Chapter | Chapter author, actual book title, editors, publisher and pages |
| Book / edited book | Author or labelled editors, publisher, edition, identifiers |
| Thesis | Actual degree/genre and institution; unknown degree is never called a doctorate |
| Report | Institution, corporate creator and report number |
| Presentation | Event, place and precise date; does not claim published proceedings |
| Blog post | Blog title, full date and original source URL |

CSL-JSON is the richest interchange format. The `.bib` export supports BibLaTeX fields
and types (`date`, `periodical`, generic `thesis`); legacy BibTeX styles may ignore them.
RIS cannot represent a date interval losslessly as structured date parts, so its
original interval is retained in a note. Corporate names in RIS and whole periodical
issues in Zotero have importer-specific limitations: use CSL-JSON for structured
institutions and the exact periodical type. Zotero has no whole-issue item type; its
fallback is `document`. No end-to-end Zotero Connector/importer round trip has been
performed by this implementation's automated tests.

Checks cover all 14 work classes in all three styles and both languages; targeted
golden examples cover medium/holding collection, corporate report, presentation,
degree, edition and escaping. Real Omeka representation and database tests cover
language/privacy selection, ranges, audio, timestamp provenance, stale admin writes,
outbox acknowledgement and lease expiry. Tests validate the available metadata; they
cannot establish the accuracy of uncatalogued facts.

Local release validation on 10 September 2026: `composer check` passed on PHP 8.2.33
(160 unit tests, 665 assertions; lint, PHPStan and translation freshness). Omeka S
4.2.1 integration passed 43 tests / 407 assertions. All seven Python tests, script
syntax checks and workflow YAML parsing passed. The PHP 8.3–8.5 matrix remains a CI
check; it was not executed locally. Neither the authenticated Google monitor nor the
production cron was activated during implementation.
