<?php
declare(strict_types=1);

namespace DRESeo\Service;

use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Api\Representation\SiteRepresentation;
use Omeka\Api\Representation\ValueRepresentation;

/**
 * Builds schema.org JSON-LD documents for resource and site pages.
 *
 * The resource template id selects the @type (see the 'dre_seo.structured_data'
 * config map). Entity templates (Person / Place / Organization) get an entity
 * shape; everything else gets a scholarly "creative work" shape with authors,
 * dates, subjects, language, coverage and containment. All values are read
 * through the representation API, so labels of linked authority items come for
 * free via displayTitle().
 */
class StructuredData
{
    private const ENTITY_TYPES = ['Person', 'Place', 'Organization'];

    /**
     * @param array<int,string> $templateTypes resource template id => schema @type
     * @param array<int,bool> $datasetTemplates template ids that are datasets
     */
    public function __construct(
        private readonly array $templateTypes,
        private readonly string $defaultType = 'CreativeWork',
    ) {
    }

    /** @return array<mixed>|null */
    public function forResource(
        PhpRenderer $view,
        AbstractResourceEntityRepresentation $resource,
        SiteRepresentation $site,
        ?string $canonical,
        ?string $image
    ): ?array {
        $templateId = $resource->resourceTemplate() ? $resource->resourceTemplate()->id() : null;
        $type = $this->templateTypes[$templateId] ?? $this->defaultType;

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
        $description = $this->firstLiteral($resource, ['dcterms:description', 'dcterms:abstract', 'bibo:abstract']);
        if ($description !== null) {
            $data['description'] = $description;
        }

        $sameAs = $this->sameAs($resource);
        if ($sameAs) {
            $data['sameAs'] = $sameAs;
        }

        if (in_array($type, self::ENTITY_TYPES, true)) {
            $this->decorateEntity($data, $type, $resource, $site, $view);
        } else {
            $this->decorateWork($data, $resource, $site, $view);
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
                    'urlTemplate' => $home . 'dre-search?q={search_term_string}',
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
        SiteRepresentation $site,
        PhpRenderer $view
    ): void {
        if ($type === 'Person') {
            $affiliation = $this->firstLink($resource, 'dcterms:isPartOf', $site);
            if ($affiliation) {
                $data['affiliation'] = ['@type' => 'Organization', 'name' => $affiliation['name']];
            }
        }
        if ($type === 'Place') {
            $lat = $this->firstLiteral($resource, ['schema:latitude', 'geo:lat']);
            $lng = $this->firstLiteral($resource, ['schema:longitude', 'geo:long']);
            if ($lat !== null && $lng !== null) {
                $data['geo'] = ['@type' => 'GeoCoordinates', 'latitude' => $lat, 'longitude' => $lng];
            }
        }
    }

    /** @param array<mixed> $data */
    private function decorateWork(
        array &$data,
        AbstractResourceEntityRepresentation $resource,
        SiteRepresentation $site,
        PhpRenderer $view
    ): void {
        // marcrel:hst / :spk are the podcast host / guest(s); they trail the
        // scholarly author roles so they only fill in when those are absent.
        $authors = $this->links($resource, ['bibo:authorList', 'dcterms:creator', 'marcrel:aut', 'marcrel:hst', 'marcrel:spk'], $site, 'Person');
        if ($authors) {
            $data['author'] = $authors;
        }
        // marcrel:sde is the podcast sound engineer (a production contributor).
        $contributors = $this->links($resource, ['dcterms:contributor', 'bibo:editorList', 'marcrel:edt', 'marcrel:sde'], $site, 'Person');
        if ($contributors) {
            $data['contributor'] = $contributors;
        }

        $date = $this->firstLiteral($resource, ['dcterms:issued', 'dcterms:date']);
        if ($date !== null) {
            $data['datePublished'] = $date;
        } else {
            $created = $this->firstLiteral($resource, ['dcterms:created']);
            if ($created !== null) {
                $data['dateCreated'] = $created;
            }
        }

        $language = $this->firstValueLabel($resource, 'dcterms:language');
        if ($language !== null) {
            $data['inLanguage'] = $language;
        }

        $subjects = $this->valueLabels($resource, 'dcterms:subject');
        if ($subjects) {
            $data['keywords'] = implode(', ', $subjects);
        }

        $places = $this->valueLabels($resource, 'dcterms:spatial');
        if ($places) {
            $data['spatialCoverage'] = array_map(
                static fn (string $name) => ['@type' => 'Place', 'name' => $name],
                $places
            );
        }

        $partOf = $this->firstLink($resource, 'dcterms:isPartOf', $site);
        if ($partOf) {
            $data['isPartOf'] = array_filter([
                '@type' => 'CreativeWork',
                'name'  => $partOf['name'],
                'url'   => $partOf['url'],
            ]);
        }

        $publisher = $this->firstLiteralOrLabel($resource, 'dcterms:publisher');
        if ($publisher !== null) {
            $data['publisher'] = ['@type' => 'Organization', 'name' => $publisher];
        }
    }

    // ─── Value readers ──────────────────────────────────────────────────────

    /** @param string[] $terms @return string|null */
    private function firstLiteral(AbstractResourceEntityRepresentation $resource, array $terms): ?string
    {
        foreach ($terms as $term) {
            $value = $resource->value($term);
            if ($value instanceof ValueRepresentation) {
                $text = trim(strip_tags((string) $value));
                if ($text !== '') {
                    return $text;
                }
            }
        }
        return null;
    }

    private function firstValueLabel(AbstractResourceEntityRepresentation $resource, string $term): ?string
    {
        $value = $resource->value($term);
        if (!$value instanceof ValueRepresentation) {
            return null;
        }
        $linked = $value->valueResource();
        if ($linked) {
            return (string) $linked->displayTitle();
        }
        $text = trim(strip_tags((string) $value));
        return $text !== '' ? $text : null;
    }

    private function firstLiteralOrLabel(AbstractResourceEntityRepresentation $resource, string $term): ?string
    {
        return $this->firstValueLabel($resource, $term);
    }

    /** @return string[] */
    private function valueLabels(AbstractResourceEntityRepresentation $resource, string $term): array
    {
        $labels = [];
        foreach ($resource->value($term, ['all' => true]) as $value) {
            if (!$value instanceof ValueRepresentation) {
                continue;
            }
            $linked = $value->valueResource();
            $label = $linked ? (string) $linked->displayTitle() : trim(strip_tags((string) $value));
            if ($label !== '') {
                $labels[$label] = $label;
            }
        }
        return array_values($labels);
    }

    /**
     * Linked resources for the first of $terms that has values.
     *
     * @param string[] $terms
     * @return array<array{@type:string,name:string,url?:string}>
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
                    try {
                        $node['url'] = $linked->siteUrl($site->slug(), true);
                    } catch (\Throwable $e) {
                        // no url
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
            $url = null;
            try {
                $url = $linked->siteUrl($site->slug(), true);
            } catch (\Throwable $e) {
                // ignore
            }
            return ['name' => (string) $linked->displayTitle(), 'url' => $url];
        }
        $name = trim(strip_tags((string) $value));
        return $name !== '' ? ['name' => $name, 'url' => null] : null;
    }

    /** @return string[] */
    private function sameAs(AbstractResourceEntityRepresentation $resource): array
    {
        $out = [];
        $wisski = $resource->value('dre:wisskiUrl');
        if ($wisski instanceof ValueRepresentation) {
            $uri = $wisski->uri() ?: trim((string) $wisski);
            if ($uri !== '') {
                $out[] = $uri;
            }
        }
        $handle = $resource->value('dre:rdspaceHandle');
        if ($handle instanceof ValueRepresentation) {
            $h = trim((string) $handle);
            if ($h !== '') {
                $out[] = str_starts_with($h, 'http') ? $h : 'https://hdl.handle.net/' . ltrim($h, '/');
            }
        }
        return $out;
    }

    private function homeUrl(PhpRenderer $view, SiteRepresentation $site): string
    {
        $url = $view->url('site', ['site-slug' => $site->slug()], ['force_canonical' => true]);
        return rtrim($url, '/') . '/';
    }
}
