<?php
declare(strict_types=1);

namespace IwacSeo\Service;

use IwacSeo\Service\Concern\ResourceValueReader;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Api\Representation\SiteRepresentation;
use Omeka\Api\Representation\ValueRepresentation;

/**
 * Builds schema.org JSON-LD documents for resource and site pages.
 *
 * The **resource class** id selects the @type (see the 'iwac_seo.structured_data'
 * config map). IWAC dispatches on class, not template: template 8 historically
 * held both newspaper articles (class 36) and Islamic-publication issues
 * (class 60), and the bibliographic references share templates across classes.
 *
 * Authority records (Person / Place / Organization / Event / subject) get an
 * entity shape; everything else gets a "creative work" shape with authors,
 * dates, subjects, language, coverage and containment. Container/publisher
 * sources are class-dependent in IWAC (the journal/newspaper sits in
 * dcterms:publisher; a book chapter's book title sits in dcterms:alternative),
 * so the work decorator branches on the resolved @type. Linked-authority labels
 * come for free via displayTitle().
 */
class StructuredData
{
    use ResourceValueReader;

    /** @type values that are descriptive authority records, not creative works. */
    private const ENTITY_TYPES = ['Person', 'Place', 'Organization', 'Event', 'DefinedTerm'];

    /**
     * @param array<int,string> $classTypes resource class id => schema @type
     */
    public function __construct(
        private readonly array $classTypes,
        private readonly string $defaultType = 'CreativeWork',
    ) {
    }

    /**
     * @param ?string $image     the share image, which falls back to the site default
     * @param ?string $thumbnail the resource's *own* media thumbnail, or null. Kept
     *   apart from $image because schema.org's thumbnailUrl must depict the
     *   resource: the site's default share graphic is an honest og:image and a
     *   dishonest video thumbnail.
     * @return array<mixed>|null
     */
    public function forResource(
        AbstractResourceEntityRepresentation $resource,
        SiteRepresentation $site,
        ?string $canonical,
        ?string $image,
        ?string $thumbnail = null
    ): ?array {
        $type = $this->classTypes[ResourceUrl::classId($resource)] ?? $this->defaultType;

        $data = [
            '@context' => 'https://schema.org',
            '@type'    => $type,
            'name'     => (string) $resource->displayTitle(),
        ];
        if ($canonical) {
            $data['url'] = $canonical;
        }
        if ($image) {
            $data['image'] = $image;
        }
        $description = $this->firstString($resource, [
            'dcterms:abstract', 'bibo:shortDescription', 'dcterms:description', 'bibo:abstract',
        ]);
        if ($description !== null) {
            $data['description'] = $description;
        }

        $sameAs = $this->sameAs($resource);
        if ($sameAs) {
            $data['sameAs'] = $sameAs;
        }

        if (in_array($type, self::ENTITY_TYPES, true)) {
            $this->decorateEntity($data, $type, $resource, $site);
        } else {
            $this->decorateWork($data, $type, $resource, $site, $thumbnail);
        }

        return $data;
    }

    /** @return array<mixed>|null */
    public function breadcrumb(
        PhpRenderer $view,
        AbstractResourceEntityRepresentation $resource,
        SiteRepresentation $site,
        ?string $canonical
    ): ?array {
        if (!$canonical) {
            return null;
        }
        return [
            '@context'        => 'https://schema.org',
            '@type'           => 'BreadcrumbList',
            'itemListElement' => [
                [
                    '@type'    => 'ListItem',
                    'position' => 1,
                    'name'     => $site->title(),
                    'item'     => $this->homeUrl($view, $site),
                ],
                [
                    '@type'    => 'ListItem',
                    'position' => 2,
                    'name'     => (string) $resource->displayTitle(),
                    'item'     => $canonical,
                ],
            ],
        ];
    }

    /** @return array<mixed> */
    public function webSite(PhpRenderer $view, SiteRepresentation $site): array
    {
        $home = $this->homeUrl($view, $site);
        return [
            '@context'        => 'https://schema.org',
            '@type'           => 'WebSite',
            'name'            => $site->title(),
            'url'             => $home,
            'potentialAction' => [
                '@type'       => 'SearchAction',
                'target'      => [
                    '@type'       => 'EntryPoint',
                    'urlTemplate' => $home . 'search?q={search_term_string}',
                ],
                'query-input' => 'required name=search_term_string',
            ],
        ];
    }

    // ─── @type-specific decoration ──────────────────────────────────────────

    /** @param array<mixed> $data */
    private function decorateEntity(
        array &$data,
        string $type,
        AbstractResourceEntityRepresentation $resource,
        SiteRepresentation $site
    ): void {
        if ($type === 'Person') {
            $given = $this->firstString($resource, ['foaf:firstName']);
            if ($given !== null) {
                $data['givenName'] = $given;
            }
            $family = $this->firstString($resource, ['foaf:lastName']);
            if ($family !== null) {
                $data['familyName'] = $family;
            }
            $affiliations = $this->links($resource, ['dcterms:isPartOf'], $site, 'Organization');
            if ($affiliations) {
                $data['affiliation'] = $affiliations;
            }
        } elseif ($type === 'Place') {
            // IWAC stores a place's coordinates as a single "lat, lng" literal
            // in curation:coordinates (not separate schema:latitude/longitude).
            $coords = $this->coordinates($resource);
            if ($coords !== null) {
                $data['geo'] = [
                    '@type'     => 'GeoCoordinates',
                    'latitude'  => $coords[0],
                    'longitude' => $coords[1],
                ];
            }
            $within = $this->firstLink($resource, 'dcterms:isPartOf', $site);
            if ($within) {
                $data['containedInPlace'] = array_filter([
                    '@type' => 'Place',
                    'name'  => $within['name'],
                    'url'   => $within['url'],
                ]);
            }
        } elseif ($type === 'Organization') {
            $parents = $this->links($resource, ['dcterms:isPartOf'], $site, 'Organization');
            if ($parents) {
                $data['parentOrganization'] = $parents;
            }
        } elseif ($type === 'Event') {
            // Unreachable on IWAC, whose events are DefinedTerm — kept because
            // class_types is overridable in local.config.php, and an
            // installation whose events *are* bookable wants this shape.
            $raw = $this->firstString($resource, ['dcterms:date']);
            if ($raw !== null) {
                [$start, $end] = Text::dateRange($raw);
                if ($start !== null) {
                    $data['startDate'] = $start;
                }
                if ($end !== null) {
                    $data['endDate'] = $end;
                }
            }
            $places = $this->labels($resource, 'dcterms:spatial');
            if ($places) {
                $data['location'] = array_map(
                    static fn (string $name) => ['@type' => 'Place', 'name' => $name],
                    $places
                );
            }
        }
        // DefinedTerm (subjects / authority files): name + description + sameAs
        // from the shared base are sufficient.
    }

    /** @param array<mixed> $data */
    private function decorateWork(
        array &$data,
        string $type,
        AbstractResourceEntityRepresentation $resource,
        SiteRepresentation $site,
        ?string $thumbnail = null
    ): void {
        $authors = $this->links($resource, ['bibo:authorList', 'dcterms:creator'], $site, 'Person');
        if ($authors) {
            $data['author'] = $authors;
        }
        $editors = $this->links($resource, ['bibo:editorList'], $site, 'Person');
        if ($editors) {
            $data['editor'] = $editors;
        }
        $contributors = $this->links($resource, ['dcterms:contributor'], $site, 'Person');
        if ($contributors) {
            $data['contributor'] = $contributors;
        }

        $date = $this->firstString($resource, ['dcterms:date', 'dcterms:issued']);
        if ($date !== null) {
            $data['datePublished'] = $date;
        }

        $language = $this->firstLabel($resource, 'dcterms:language');
        if ($language !== null) {
            $data['inLanguage'] = $language;
        }

        $subjects = $this->labels($resource, 'dcterms:subject');
        if ($subjects) {
            $data['keywords'] = implode(', ', $subjects);
        }

        $places = $this->labels($resource, 'dcterms:spatial');
        if ($places) {
            $data['spatialCoverage'] = array_map(
                static fn (string $name) => ['@type' => 'Place', 'name' => $name],
                $places
            );
        }

        // Audiovisual: an ISO-8601 duration and an upload date round out the
        // VideoObject (which schema.org expects for video rich results).
        if ($type === 'VideoObject') {
            $duration = $this->firstString($resource, ['dcterms:extent']);
            if ($duration !== null && str_starts_with($duration, 'P')) {
                $data['duration'] = $duration;
            }
            if ($date !== null) {
                $data['uploadDate'] = $date;
            }
            // thumbnailUrl is required of a VideoObject and is not satisfied by
            // image: only the item's own media depicts the video, whereas image
            // falls back to the site's default share graphic.
            if ($thumbnail !== null) {
                $data['thumbnailUrl'] = $thumbnail;
            }
        }

        // fabio:BookReview records name the book they review in bibo:reviewOf.
        // They are typed ScholarlyArticle, not Review: Google's review snippet
        // requires reviewRating.ratingValue, and an academic book review awards
        // no score — a Review without one is reported invalid in perpetuity, so
        // claiming the type buys an error and no feature. As a scholarly
        // article *about* a book the relationship survives in a form that
        // validates. Keyed on the property rather than the type because the
        // class shares its @type with ordinary journal articles.
        $reviewed = $this->firstString($resource, ['bibo:reviewOf']);
        if ($reviewed !== null) {
            $data['about'] = ['@type' => 'Book', 'name' => $reviewed];
        }

        $this->decorateContainer($data, $type, $resource, $site);

        $publisher = $this->publisherFor($type, $resource);
        if ($publisher !== null) {
            $data['publisher'] = ['@type' => 'Organization', 'name' => $publisher];
        }
    }

    /**
     * The container the work belongs to (schema:isPartOf). IWAC keeps it in
     * different properties per type:
     *   - journal article / book review → dcterms:publisher (the periodical)
     *   - newspaper article / publication issue → dcterms:publisher (the title,
     *     a linked item set with its own page)
     *   - book chapter → dcterms:alternative (the book title)
     *   - communication / talk → dcterms:isPartOf (the event)
     *
     * @param array<mixed> $data
     */
    private function decorateContainer(
        array &$data,
        string $type,
        AbstractResourceEntityRepresentation $resource,
        SiteRepresentation $site
    ): void {
        if (in_array($type, ['ScholarlyArticle', 'Review', 'NewsArticle', 'PublicationIssue'], true)) {
            $periodical = $this->firstLink($resource, 'dcterms:publisher', $site);
            if ($periodical) {
                $data['isPartOf'] = array_filter([
                    '@type' => 'Periodical',
                    'name'  => $periodical['name'],
                    'url'   => $periodical['url'],
                ]);
            }
            if ($type === 'PublicationIssue') {
                $issue = $this->firstString($resource, ['bibo:issue']);
                if ($issue !== null) {
                    $data['issueNumber'] = $issue;
                }
            }
            return;
        }
        if ($type === 'Chapter') {
            $book = $this->firstString($resource, ['dcterms:alternative']);
            if ($book !== null) {
                $data['isPartOf'] = ['@type' => 'Book', 'name' => $book];
            }
            return;
        }
        if ($type === 'CreativeWork') {
            // Personal communication / conference talk → the event it was given
            // at, emitted as a bare URL. isPartOf ranges over CreativeWork or
            // URL, so the Event node this used to build was out of range twice
            // over: wrong for the property, and an Event that IWAC's events can
            // never satisfy (see the class map's note on class 54). Six of the
            // nineteen name a linked event record and keep their cross-link;
            // the ten that hold only a literal title have no in-range form, and
            // a name Google cannot dereference is not worth a range violation.
            $event = $this->firstLink($resource, 'dcterms:isPartOf', $site);
            if ($event && $event['url'] !== null) {
                $data['isPartOf'] = $event['url'];
            }
        }
    }


    /**
     * dcterms:publisher used as the actual publisher/institution (not as the
     * periodical container, which decorateContainer() already handled).
     */
    private function publisherFor(string $type, AbstractResourceEntityRepresentation $resource): ?string
    {
        $publisherTypes = ['Book', 'Chapter', 'Thesis', 'Report', 'BlogPosting', 'VideoObject', 'DigitalDocument'];
        if (!in_array($type, $publisherTypes, true)) {
            return null;
        }
        return $this->firstLabel($resource, 'dcterms:publisher');
    }

    // ─── Value readers ──────────────────────────────────────────────────────
    // firstString(), firstLabel() and labels() live in the shared
    // ResourceValueReader trait; only the JSON-LD-specific readers remain here.

    /**
     * Linked (or literal) resources for the first of $terms that has values.
     *
     * @param string[] $terms
     * @return array<array{'@type': string, name: string, url?: string}>
     */
    private function links(
        AbstractResourceEntityRepresentation $resource,
        array $terms,
        SiteRepresentation $site,
        string $type
    ): array {
        $out = [];
        foreach ($terms as $term) {
            foreach ($resource->value($term, ['all' => true]) as $value) {
                if (!$value instanceof ValueRepresentation) {
                    continue;
                }
                $linked = $value->valueResource();
                if ($linked) {
                    $node = ['@type' => $type, 'name' => (string) $linked->displayTitle()];
                    $url = ResourceUrl::forSite($linked, $site->slug());
                    if ($url !== null) {
                        $node['url'] = $url;
                    }
                    $out[$node['name']] = $node;
                } else {
                    $name = trim(strip_tags((string) $value));
                    if ($name !== '') {
                        $out[$name] = ['@type' => $type, 'name' => $name];
                    }
                }
            }
            if ($out) {
                break; // first populated term wins
            }
        }
        return array_values($out);
    }

    /** @return array{name:string,url:?string}|null */
    private function firstLink(
        AbstractResourceEntityRepresentation $resource,
        string $term,
        SiteRepresentation $site
    ): ?array {
        $value = $resource->value($term);
        if (!$value instanceof ValueRepresentation) {
            return null;
        }
        $linked = $value->valueResource();
        if ($linked) {
            return [
                'name' => (string) $linked->displayTitle(),
                'url'  => ResourceUrl::forSite($linked, $site->slug()),
            ];
        }
        $name = trim(strip_tags((string) $value));
        return $name !== '' ? ['name' => $name, 'url' => null] : null;
    }

    /**
     * Parse IWAC's "lat, lng" coordinate literal (curation:coordinates).
     *
     * @return array{0:float,1:float}|null
     */
    private function coordinates(AbstractResourceEntityRepresentation $resource): ?array
    {
        $raw = $this->firstString($resource, ['curation:coordinates']);
        if ($raw === null) {
            return null;
        }
        $parts = array_map('trim', explode(',', $raw));
        if (count($parts) !== 2 || !is_numeric($parts[0]) || !is_numeric($parts[1])) {
            return null;
        }
        return [(float) $parts[0], (float) $parts[1]];
    }

    /**
     * External authority links for schema:sameAs. IWAC carries these in
     * dcterms:identifier as URI values (Wikidata, GeoNames, VIAF …). The same
     * property also holds opaque internal ids ("iwac-article-0000001") as plain
     * literals, so only URI-typed (or http-looking) values are emitted.
     *
     * @return string[]
     */
    private function sameAs(AbstractResourceEntityRepresentation $resource): array
    {
        $out = [];
        foreach ($resource->value('dcterms:identifier', ['all' => true]) as $value) {
            if (!$value instanceof ValueRepresentation) {
                continue;
            }
            $uri = $value->uri();
            if (!$uri) {
                $candidate = trim((string) $value);
                $uri = preg_match('#^https?://#i', $candidate) ? $candidate : null;
            }
            if ($uri) {
                $out[$uri] = $uri;
            }
        }
        return array_values($out);
    }

    private function homeUrl(PhpRenderer $view, SiteRepresentation $site): string
    {
        $url = $view->url('site', ['site-slug' => $site->slug()], ['force_canonical' => true]);
        return rtrim($url, '/') . '/';
    }
}
