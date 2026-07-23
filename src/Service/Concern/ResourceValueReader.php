<?php
declare(strict_types=1);

namespace IwacSeo\Service\Concern;

use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Api\Representation\ValueRepresentation;

/**
 * Shared leaf value-readers for the metadata services ({@see \IwacSeo\Service\CitationMeta},
 * {@see \IwacSeo\Service\ZoteroRdf}, {@see \IwacSeo\Service\CitationData},
 * {@see \IwacSeo\Service\StructuredData}).
 *
 * These helpers were byte-identical across the services; consolidating them
 * keeps the IWAC field conventions defined once — linked-resource labels via
 * displayTitle, the combined Zotero tag set (Sujet dcterms:subject +
 * Couverture spatiale dcterms:spatial, de-duplicated with subjects first),
 * the "iwac-" accession identifier, the bibo:doi normalisation and the
 * public-PDF lookup.
 */
trait ResourceValueReader
{
    /** Publication-date properties, in preference order. */
    private const DATE_TERMS = ['dcterms:date', 'dcterms:issued', 'dcterms:created'];

    /**
     * Abstract/summary properties in *citation* preference order (the formal
     * abstract first). HeadMetadata's meta description deliberately uses a
     * different order (bibo:shortDescription — the AI summary — first); do
     * not unify the two.
     */
    private const ABSTRACT_TERMS = ['dcterms:abstract', 'bibo:abstract', 'bibo:shortDescription', 'dcterms:description'];

    /** Abstracts are clipped to this many characters before being emitted. */
    private const ABSTRACT_MAX = 5000;
    /**
     * First non-empty literal/label across the given terms, in order, tags stripped.
     *
     * @param string[] $terms
     */
    private function firstString(AbstractResourceEntityRepresentation $resource, array $terms): ?string
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

    /** Label of the first value of $term: a linked resource's title, else the literal. */
    private function firstLabel(AbstractResourceEntityRepresentation $resource, string $term): ?string
    {
        $value = $resource->value($term);
        if (!$value instanceof ValueRepresentation) {
            return null;
        }
        $linked = $value->valueResource();
        $label = $linked ? (string) $linked->displayTitle() : trim(strip_tags((string) $value));
        return $label !== '' ? $label : null;
    }

    /**
     * All distinct labels for $term (linked titles or literals), in document order.
     *
     * @return string[]
     */
    private function labels(AbstractResourceEntityRepresentation $resource, string $term): array
    {
        $out = [];
        foreach ($resource->value($term, ['all' => true]) as $value) {
            if (!$value instanceof ValueRepresentation) {
                continue;
            }
            $linked = $value->valueResource();
            $label = $linked ? (string) $linked->displayTitle() : trim(strip_tags((string) $value));
            if ($label !== '') {
                $out[$label] = $label;
            }
        }
        return array_values($out);
    }

    /**
     * Zotero-style tag set: Sujet (dcterms:subject) then Couverture spatiale
     * (dcterms:spatial), de-duplicated with subjects first.
     *
     * @return string[]
     */
    private function keywords(AbstractResourceEntityRepresentation $resource): array
    {
        $out = [];
        foreach (['dcterms:subject', 'dcterms:spatial'] as $term) {
            foreach ($this->labels($resource, $term) as $label) {
                $out[$label] = $label;
            }
        }
        return array_values($out);
    }

    /** The archive accession number (Cote): a dcterms:identifier value starting "iwac-". */
    private function cote(AbstractResourceEntityRepresentation $resource): ?string
    {
        foreach ($resource->value('dcterms:identifier', ['all' => true]) as $value) {
            if (!$value instanceof ValueRepresentation) {
                continue;
            }
            $text = trim(strip_tags((string) $value));
            if (stripos($text, 'iwac-') === 0) {
                return $text;
            }
        }
        return null;
    }

    /**
     * The bare DOI from bibo:doi (stored as a URI value; a doi.org URL or
     * "doi:" prefix is normalised down to the identifier).
     */
    private function doi(AbstractResourceEntityRepresentation $resource): ?string
    {
        $value = $resource->value('bibo:doi');
        if (!$value instanceof ValueRepresentation) {
            return null;
        }
        $doi = $value->uri() ?: trim(strip_tags((string) $value));
        if ($doi === '') {
            return null;
        }
        $doi = preg_replace('#^(https?://(dx\.)?doi\.org/|doi:)#i', '', $doi);
        return $doi !== '' ? $doi : null;
    }

    /** The original URL of the item's first public PDF media, if any. */
    private function pdfUrl(AbstractResourceEntityRepresentation $resource): ?string
    {
        if (!$resource instanceof ItemRepresentation) {
            return null;
        }
        foreach ($resource->media() as $media) {
            if (method_exists($media, 'isPublic') && !$media->isPublic()) {
                continue;
            }
            if ($media->mediaType() === 'application/pdf') {
                $url = $media->originalUrl();
                if ($url) {
                    return $url;
                }
            }
        }
        return null;
    }

    /**
     * Whether a linked authority record is an Organisation, per the citation
     * kind map (resource class id => kind). Institutional creators keep a
     * single-field name and are never split/inverted.
     *
     * @param array<int,string> $classKinds
     */
    private function isOrganizationClass(?AbstractResourceEntityRepresentation $linked, array $classKinds): bool
    {
        if ($linked === null || !$linked->resourceClass()) {
            return false;
        }
        return ($classKinds[$linked->resourceClass()->id()] ?? null) === 'organization';
    }

    /** Whitespace-normalise, strip tags and clip to $max characters. */
    private function clip(string $text, int $max = self::ABSTRACT_MAX): string
    {
        $text = trim(preg_replace('/\s+/', ' ', strip_tags($text)) ?? '');
        return mb_substr($text, 0, $max);
    }
}
