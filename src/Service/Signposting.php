<?php
declare(strict_types=1);

namespace IwacSeo\Service;

use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Api\Representation\ItemSetRepresentation;
use Omeka\Api\Representation\MediaRepresentation;
use Omeka\Api\Representation\ValueRepresentation;

/**
 * FAIR Signposting (signposting.org/FAIR, level 1) for a record page: the
 * identifier to cite, the machine-readable descriptions of the record, what
 * kind of thing the page is about, its authors, its licence, its public files,
 * and the collections it belongs to. F-UJI and other FAIR assessors read
 * these; search engines ignore them.
 *
 * The same set goes out twice: as <link> elements in the page head and as an
 * HTTP `Link` header, which is all many harvesters read.
 *
 * Adapted from DRE-SEO for IWAC, which has no persistent-identifier service:
 *
 *   - `cite-as` is the page's own canonical URL, on the reader's site. RFC
 *     8574 makes it the permalink of the page itself, so a reference's DOI is
 *     not it — the DOI names the publisher's copy, and already travels as
 *     citation_doi. No IWAC record carries an ARK or a Handle.
 *   - `describedby` is the citation downloads at /cite/{id}/{format} for a
 *     citable work, with the reader's site (so the file links the page it
 *     describes), and the Zotero RDF at /unapi for the kinds served there.
 *     Omeka's own API representation is not offered: an authority record's
 *     runs to megabytes with its `@reverse` list.
 *   - `author` is each linked author's ORCID iD when the authority record
 *     has one, else its first authority URI (Wikidata, VIAF …), else its
 *     IWAC page. An author known only by name is not linked.
 *   - `collection` is, from an item, the item sets it belongs to (RFC 6573:
 *     the collection the page is a member of), and from a media page, the
 *     item whose file it shows (FAIR Signposting's content resource back to
 *     its landing page).
 *
 * Only public values, records and files are linked, and every page URL is on
 * the reader's site.
 */
final class Signposting
{
    /**
     * The most a `Link` header may grow to. nginx refuses a FastCGI response
     * whose headers overflow `fastcgi_buffer_size` — one memory page, 4 KB on
     * the usual Linux host — with a 502, and Omeka's own headers (the session
     * cookie, the cache directives) share that buffer. Links beyond the budget
     * stay in the page head only.
     */
    public const HEADER_BUDGET = 2048;

    /** Public files linked as `item`; a record with hundreds of scans links the first few. */
    private const MAX_ITEMS = 10;

    /** Authors linked; a work with a long author list links the first ones. */
    private const MAX_AUTHORS = 20;

    /** Item sets linked as `collection`. */
    private const MAX_COLLECTIONS = 10;

    /** Where a work's authors are, preferred first — the order the JSON-LD `author` reads. */
    private const AUTHOR_TERMS = ['bibo:authorList', 'dcterms:creator'];

    /** The Zotero RDF document's media type, as /unapi advertises it. */
    private const ZOTERO_RDF_TYPE = 'application/rdf+xml';

    /**
     * @param string[] $formats the /cite formats the "How to cite" panel
     *   offers (iwac_seo.citation.formats), in its order
     */
    public function __construct(
        private readonly StructuredData $structuredData,
        private readonly CitationData $citationData,
        private readonly ZoteroRdf $zoteroRdf,
        private readonly SettingsGate $settings,
        private readonly array $formats = ['bibtex', 'ris', 'csljson'],
    ) {
    }

    /**
     * @param string $canonical the page, on the reader's site
     * @param string $siteSlug  the reader's site
     * @param string $origin    scheme and host /cite and /unapi are served from
     * @return array<int,array{rel:string,href:string,type?:string}>
     */
    public function links(
        AbstractResourceEntityRepresentation $resource,
        string $canonical,
        string $siteSlug,
        string $origin
    ): array {
        $origin = rtrim($origin, '/');
        $links = [['rel' => 'cite-as', 'href' => $canonical]];

        if ($resource instanceof ItemRepresentation) {
            foreach ($this->descriptions($resource, $canonical, $siteSlug, $origin) as $description) {
                $links[] = ['rel' => 'describedby'] + $description;
            }
        }

        $links[] = ['rel' => 'type', 'href' => 'https://schema.org/' . $this->schemaType($resource)];
        $links[] = ['rel' => 'type', 'href' => 'https://schema.org/AboutPage'];

        if ($resource instanceof ItemRepresentation) {
            foreach ($this->authors($resource, $siteSlug) as $author) {
                $links[] = ['rel' => 'author', 'href' => $author];
            }
        }

        // rel=license must point at the licence itself: a licence known only
        // by its name gets no link.
        $licence = Licence::of($resource);
        if ($licence !== null && $licence->url !== null) {
            $links[] = ['rel' => 'license', 'href' => $licence->url];
        }

        foreach ($this->files($resource) as $file) {
            $links[] = ['rel' => 'item', 'href' => $file['url'], 'type' => $file['type']];
        }

        foreach ($this->collections($resource, $siteSlug) as $collection) {
            $links[] = ['rel' => 'collection', 'href' => $collection];
        }

        return $links;
    }

    /**
     * The links as one RFC 8288 `Link` header value, within $budget bytes.
     * Anything a header may not carry is percent-encoded in the target, so the
     * value is always valid. Links are taken in order, and one that would
     * take the value over budget is left out — the head carries it still.
     *
     * @param array<int,array{rel:string,href:string,type?:string}> $links
     */
    public static function header(array $links, int $budget = self::HEADER_BUDGET): string
    {
        $value = '';
        foreach ($links as $link) {
            $target = preg_replace_callback(
                '/[^\x21-\x7E]|[<>"]/',
                static fn (array $match): string => rawurlencode($match[0]),
                $link['href']
            );
            $part = '<' . $target . '>; rel="' . self::token($link['rel']) . '"';
            if (isset($link['type']) && $link['type'] !== '') {
                $part .= '; type="' . self::token($link['type']) . '"';
            }
            $candidate = $value === '' ? $part : $value . ', ' . $part;
            if (strlen($candidate) <= $budget) {
                $value = $candidate;
            }
        }
        return $value;
    }

    /**
     * The record's machine-readable descriptions: each /cite format of a
     * citable work, then the Zotero RDF of a kind /unapi serves. Each is
     * offered only while its endpoint is switched on.
     *
     * @return array<int,array{href:string,type:string}>
     */
    private function descriptions(ItemRepresentation $item, string $canonical, string $siteSlug, string $origin): array
    {
        $classId = ResourceUrl::classId($item);
        $out = [];
        // The download route's own conditions: the citation kill-switch it
        // shares with the meta tags, and a work rather than an authority record.
        if ($this->settings->isOn('iwac_seo_citation_meta', true) && $this->citationData->isCitable($classId)) {
            foreach ($this->formats as $format) {
                if (!isset(CitationExport::FORMATS[$format])) {
                    continue;
                }
                $out[] = [
                    'href' => $origin . '/cite/' . $item->id() . '/' . $format . '?site=' . rawurlencode($siteSlug),
                    // Signposting names the media type alone, without its charset.
                    'type' => trim(explode(';', CitationExport::FORMATS[$format][1])[0]),
                ];
            }
        }
        if ($this->settings->isOn('iwac_seo_unapi', true) && $this->zoteroRdf->isEligible($classId)) {
            $out[] = [
                'href' => $origin . '/unapi?id=' . rawurlencode($canonical) . '&format=rdf_zotero',
                'type' => self::ZOTERO_RDF_TYPE,
            ];
        }
        return $out;
    }

    /** What the page is about, as a schema.org type. */
    private function schemaType(AbstractResourceEntityRepresentation $resource): string
    {
        return match (true) {
            $resource instanceof ItemSetRepresentation => 'Collection',
            $resource instanceof MediaRepresentation => 'MediaObject',
            default => $this->structuredData->typeFor($resource),
        };
    }

    /**
     * The work's linked authors, by the identifier a FAIR assessor can follow
     * furthest: the ORCID iD, else the first authority URI the record keeps
     * (Wikidata, VIAF …, read as the JSON-LD's sameAs reads them), else the
     * authority record's own page on the reader's site.
     *
     * @return string[]
     */
    private function authors(ItemRepresentation $item, string $siteSlug): array
    {
        foreach (self::AUTHOR_TERMS as $term) {
            $out = [];
            $populated = false;
            foreach ($item->value($term, ['all' => true]) as $value) {
                // text() is null for a private value or a private linked record.
                if (!$value instanceof ValueRepresentation || MetadataValue::text($value) === null) {
                    continue;
                }
                $populated = true;
                $linked = $value->valueResource();
                if (!$linked instanceof AbstractResourceEntityRepresentation) {
                    continue;
                }
                $sameAs = $this->structuredData->sameAsFor($linked);
                $href = self::orcidOf($sameAs) ?? $sameAs[0] ?? ResourceUrl::forSite($linked, $siteSlug);
                if ($href !== null) {
                    $out[$href] = $href;
                }
                if (count($out) >= self::MAX_AUTHORS) {
                    break;
                }
            }
            if ($populated) {
                return array_values($out); // the first populated role is the authors
            }
        }
        return [];
    }

    /**
     * The public files of an item, or a media page's own file — the work in
     * its own form: a PDF, an image, an audio or video file.
     *
     * @return array<int,array{url:string,type:string}>
     */
    private function files(AbstractResourceEntityRepresentation $resource): array
    {
        $media = match (true) {
            $resource instanceof ItemRepresentation => $resource->media(),
            $resource instanceof MediaRepresentation => [$resource],
            default => [],
        };
        $out = [];
        foreach ($media as $file) {
            if (count($out) >= self::MAX_ITEMS) {
                break;
            }
            if (!$file instanceof MediaRepresentation || !$file->isPublic()) {
                continue;
            }
            $type = (string) $file->mediaType();
            $url = MetadataValue::url($file->originalUrl());
            if ($url !== null && self::isWorkFile($type)) {
                $out[] = ['url' => $url, 'type' => $type];
            }
        }
        return $out;
    }

    /**
     * The pages the record belongs to: an item's public item sets, a media
     * page's item.
     *
     * @return string[]
     */
    private function collections(AbstractResourceEntityRepresentation $resource, string $siteSlug): array
    {
        if ($resource instanceof MediaRepresentation) {
            try {
                $item = $resource->item();
            } catch (\Throwable $e) {
                return [];
            }
            $url = $item->isPublic() ? ResourceUrl::forSite($item, $siteSlug) : null;
            return $url !== null ? [$url] : [];
        }
        if (!$resource instanceof ItemRepresentation) {
            return [];
        }
        $out = [];
        foreach ($resource->itemSets() as $itemSet) {
            if (count($out) >= self::MAX_COLLECTIONS) {
                break;
            }
            if (!$itemSet instanceof ItemSetRepresentation || !$itemSet->isPublic()) {
                continue;
            }
            $url = ResourceUrl::forSite($itemSet, $siteSlug);
            if ($url !== null) {
                $out[$url] = $url;
            }
        }
        return array_values($out);
    }

    private static function isWorkFile(string $mediaType): bool
    {
        return $mediaType === 'application/pdf'
            || str_starts_with($mediaType, 'image/')
            || str_starts_with($mediaType, 'audio/')
            || str_starts_with($mediaType, 'video/');
    }

    /**
     * The ORCID iD among the URIs, if any.
     *
     * @param string[] $uris
     */
    private static function orcidOf(array $uris): ?string
    {
        foreach ($uris as $uri) {
            if (preg_match('~^https?://orcid\.org/\d{4}-\d{4}-\d{4}-\d{3}[\dX]/?$~i', $uri)) {
                return $uri;
            }
        }
        return null;
    }

    /** A relation type or media type, reduced to the characters either may hold. */
    private static function token(string $value): string
    {
        return (string) preg_replace('/[^A-Za-z0-9!#$&^_.+\/-]/', '', $value);
    }
}
