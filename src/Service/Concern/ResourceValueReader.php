<?php
declare(strict_types=1);

namespace IwacSeo\Service\Concern;

use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Api\Representation\ValueRepresentation;

/**
 * Shared leaf value-readers for the citation services ({@see \IwacSeo\Service\CitationMeta},
 * {@see \IwacSeo\Service\ZoteroRdf}, {@see \IwacSeo\Service\CitationData}).
 *
 * These four helpers were byte-identical across the services; consolidating them
 * keeps the IWAC field conventions defined once — linked-resource labels via
 * displayTitle, and the combined Zotero tag set (Sujet dcterms:subject +
 * Couverture spatiale dcterms:spatial, de-duplicated with subjects first).
 *
 * Trait constants are used by the individual services (each keeps its own clip()
 * so ABSTRACT_MAX stays where it is consumed).
 */
trait ResourceValueReader
{
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
}
