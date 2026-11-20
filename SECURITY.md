# Security policy

## Supported versions

Only the latest tagged release and the current `main` receive fixes. This is a
single-deployment research module; there are no maintained back-branches.

## Reporting a vulnerability

Please report privately through GitHub's
[private vulnerability reporting](https://github.com/fmadore/IWAC-SEO/security/advisories/new)
rather than opening a public issue. Include the affected version, reproduction
steps and impact. Expect an acknowledgement within a few working days, and
allow a reasonable remediation window before disclosure.

## What is security-relevant here

The module publishes metadata about a public archive. Its one real duty is not
to publish the parts that are not public.

- **Private records and values stay private.** The sitemaps select only public
  resources, pages and media (`Sitemap\SitemapRepository`). The citation
  endpoints (`/cite/…`, `/unapi`) answer only for a public item, or a public
  page of a public site. Values for the head metadata, JSON-LD and citations
  are read through `MetadataValue`, which drops a private value and a value
  linking to a private record. Anything that gets a private record,
  value or page into a public response is in scope.
- **Public endpoints are read-only.** `/sitemap*.xml`, `/robots.txt`,
  `/{key}.txt`, `/cite/…` and `/unapi` are open to anonymous visitors and change
  nothing. The admin SEO screens are restricted by Omeka's ACL to editors and
  above (`Module::onBootstrap()`).
- **Archive text is untrusted.** Titles, descriptions and names are curated
  content written into HTML attributes, JSON-LD and XML. JSON-LD is encoded with
  `JSON_HEX_TAG`, so a value cannot close its `<script>` element; head values go
  through Laminas's escaping helpers.

## Not vulnerabilities

- **The IndexNow key is public by design.** IndexNow verifies ownership by
  fetching `/{key}.txt` from the site, so the key is served to anyone who asks
  for it. It authorises nothing beyond asking engines to recrawl the site's own
  URLs.
- **`robots.txt` is advice, not access control.** A disallowed URL is still
  served to anyone who requests it; the rules exist for crawl budget.
