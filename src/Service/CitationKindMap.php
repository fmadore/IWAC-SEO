<?php
declare(strict_types=1);

namespace IwacSeo\Service;

use Omeka\Api\Representation\AbstractResourceRepresentation;

/**
 * Resolves an Omeka **resource class** id to a {@see CitationKind}, from the
 * configured `iwac_seo.citation.class_kinds` map.
 *
 * One shared instance replaces the raw `array<int,string> $classKinds` +
 * `string $defaultKind` pair that used to be threaded through four factories
 * into CitationData, CitationMeta and ZoteroRdf. That threading had already
 * drifted: ZoteroRdf fell back to `null` for an unmapped class while the other
 * two fell back to `'item'` — harmless only because `'item'` happens not to be
 * unAPI-eligible. There is now one fallback, in one place.
 *
 * A class_kinds value that is not a known kind (a config typo) resolves to the
 * default rather than to a kind nothing handles.
 */
final class CitationKindMap
{
    /** @var array<int,CitationKind> */
    private array $byClassId = [];

    private readonly CitationKind $default;

    /**
     * @param array<int|string,string> $classKinds resource class id => kind name
     */
    public function __construct(array $classKinds, string $defaultKind = 'item')
    {
        $this->default = CitationKind::tryFrom($defaultKind) ?? CitationKind::Item;
        foreach ($classKinds as $classId => $kind) {
            $resolved = CitationKind::tryFrom((string) $kind);
            if ($resolved !== null) {
                $this->byClassId[(int) $classId] = $resolved;
            }
        }
    }

    /** The kind for a resource class id; the default for null/unmapped/unknown. */
    public function forClassId(?int $classId): CitationKind
    {
        return $classId === null ? $this->default : ($this->byClassId[$classId] ?? $this->default);
    }

    /** The kind for a resource, read from its resource class. */
    public function forResource(?AbstractResourceRepresentation $resource): CitationKind
    {
        return $this->forClassId(ResourceUrl::classId($resource));
    }

    /**
     * Whether a linked authority record is an Organisation. Institutional
     * creators keep a single-field name and are never split or inverted.
     */
    public function isOrganization(?AbstractResourceRepresentation $linked): bool
    {
        return $linked !== null && $this->forResource($linked) === CitationKind::Organization;
    }
}
