<?php
declare(strict_types=1);

namespace IwacSeo\Service;

use IwacSeo\Service\Concern\ResourceValueReader;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Api\Representation\ValueRepresentation;

/**
 * Builds a normalized, CSL-shaped citation record from an IWAC item — the single
 * source of truth consumed by {@see CitationFormatter} (Chicago/APA/MLA text),
 * {@see CitationExport} (BibTeX/RIS/CSL-JSON) and the Citation view helper.
 *
 * Dispatch is by **resource class** via the same map CitationMeta uses (config
 * `iwac_seo.citation.class_kinds`), and the same IWAC field conventions apply:
 *   • the container (journal / newspaper / publisher / institution) lives in
 *     **dcterms:publisher** — often a linked item set or item — not isPartOf;
 *   • a book chapter's **book title** lives in **dcterms:alternative**;
 *   • dates are **NumericDataTypes timestamps** (YYYY, YYYY-MM or YYYY-MM-DD);
 *   • the archive accession id (Cote) is the **"iwac-"** dcterms:identifier;
 *   • DOIs live in **bibo:doi** (a URI value);
 *   • authors/editors are linked person records ("First Last") or literals; an
 *     author linked to an **Organisation** authority is treated as an institution
 *     (single-field name, never inverted or split).
 *
 * Authority records (person / place / organization / event / subject) are not
 * citable works: {@see build()} returns null for them and {@see isCitable()} is
 * false, so the theme hides the "How to cite" panel on those pages.
 */
final class CitationData
{
    use ResourceValueReader;

    /** Descriptive authority records — not citable works. */
    private const ENTITY_KINDS = ['person', 'place', 'organization', 'event', 'subject'];

    /** Citation kind → CSL item type (drives CSL-JSON export + downstream typing). */
    private const CSL_TYPE = [
        'newspaper'     => 'article-newspaper',
        'magazine'      => 'article-magazine',
        'article'       => 'article-journal',
        'review'        => 'review',
        'chapter'       => 'chapter',
        'book'          => 'book',
        'thesis'        => 'thesis',
        'report'        => 'report',
        'post'          => 'post-weblog',
        'av'            => 'motion_picture',
        'communication' => 'speech',
        'document'      => 'document',
        'photo'         => 'graphic',
    ];

    /**
     * @param array<int,string> $classKinds resource class id => citation kind
     */
    public function __construct(
        private readonly array $classKinds,
        private readonly string $defaultKind = 'item',
    ) {
    }

    public function kind(?int $classId): string
    {
        return $this->classKinds[$classId] ?? $this->defaultKind;
    }

    /** Whether an item of this resource class is a citable work (not an authority record). */
    public function isCitable(?int $classId): bool
    {
        return !in_array($this->kind($classId), self::ENTITY_KINDS, true);
    }

    /**
     * Normalized citation record, or null for a non-citable authority record.
     *
     * @param string|null $url the item's public (canonical) page URL
     * @return array<string,mixed>|null
     */
    public function build(ItemRepresentation $item, ?string $url = null): ?array
    {
        $classId = $item->resourceClass() ? $item->resourceClass()->id() : null;
        $kind = $this->kind($classId);
        if (in_array($kind, self::ENTITY_KINDS, true)) {
            return null;
        }

        // dcterms:publisher is the one container field; route it to the slot the
        // kind needs, mirroring CitationMeta's per-kind branches.
        $container = $this->firstLabel($item, 'dcterms:publisher');

        $record = [
            'id'        => $item->id(),
            'kind'      => $kind,
            'cslType'   => self::CSL_TYPE[$kind] ?? 'document',
            'title'     => $this->firstString($item, ['dcterms:title']),
            'authors'   => $this->people($item, ['bibo:authorList', 'dcterms:creator']),
            'editors'   => $this->people($item, ['bibo:editorList']),
            'issued'    => $this->dateParts($item),
            'container' => null,   // periodical / journal / blog title
            'publisher' => null,   // book publisher / report or thesis institution
            'bookTitle' => null,   // a chapter's containing book
            'volume'    => $this->firstString($item, ['bibo:volume']),
            'issue'     => $this->firstString($item, ['bibo:issue']),
            'pageFirst' => $this->firstString($item, ['bibo:pageStart']),
            'pageLast'  => $this->firstString($item, ['bibo:pageEnd']),
            'doi'       => $this->doi($item),
            'url'       => ($url !== null && $url !== '') ? $url : null,
            'language'  => $this->firstLabel($item, 'dcterms:language'),
            'abstract'  => null,
            'keywords'  => $this->keywords($item),
            'accession' => $this->cote($item),
        ];

        switch ($kind) {
            case 'chapter':
                $record['bookTitle'] = $this->firstString($item, ['dcterms:alternative']);
                $record['publisher'] = $container;
                break;
            case 'book':
            case 'thesis':
            case 'report':
                $record['publisher'] = $container;
                break;
            default:
                // newspaper, magazine, article, review, post, av, communication,
                // document, photo, item: the container is a periodical/site name.
                $record['container'] = $container;
                break;
        }

        $abstract = $this->firstString($item, self::ABSTRACT_TERMS);
        if ($abstract !== null) {
            $record['abstract'] = $this->clip($abstract);
        }

        return $record;
    }

    /** The page range "185-209", or a single page, or null. */
    public static function pageRange(array $record): ?string
    {
        $first = $record['pageFirst'] ?? null;
        $last = $record['pageLast'] ?? null;
        if ($first === null && $last === null) {
            return null;
        }
        if ($first !== null && $last !== null && $last !== $first) {
            return $first . '-' . $last;
        }
        return $first ?? $last;
    }

    // ─── Creators ────────────────────────────────────────────────────────────

    /**
     * Structured creators from the first populated role property, in document
     * order. Institutions (authors linked to an Organisation authority record)
     * keep a single-field literal name; everyone else is split into given/family.
     *
     * @param string[] $terms
     * @return array<int,array{family:?string,given:?string,literal:string,isInstitution:bool}>
     */
    private function people(ItemRepresentation $item, array $terms): array
    {
        foreach ($terms as $term) {
            $out = [];
            foreach ($item->value($term, ['all' => true]) as $value) {
                if (!$value instanceof ValueRepresentation) {
                    continue;
                }
                $linked = $value->valueResource();
                $label = $linked ? (string) $linked->displayTitle() : trim(strip_tags((string) $value));
                if ($label === '') {
                    continue;
                }
                $out[] = $this->parseName($label, $this->isOrganizationClass($linked, $this->classKinds));
            }
            if ($out) {
                return $out;
            }
        }
        return [];
    }

    /**
     * Split a display name into given/family. IWAC person records display as
     * "First Last", so the last whitespace-delimited token is the family name;
     * an already-inverted "Family, Given" literal is detected by the comma. A
     * heuristic, correct for the overwhelming majority of the corpus's
     * francophone and transliterated names; institutions stay single-field.
     *
     * @return array{family:?string,given:?string,literal:string,isInstitution:bool}
     */
    private function parseName(string $label, bool $isInstitution): array
    {
        $label = trim(preg_replace('/\s+/', ' ', $label) ?? $label);
        if ($isInstitution) {
            return ['family' => null, 'given' => null, 'literal' => $label, 'isInstitution' => true];
        }
        if (str_contains($label, ',')) {
            $bits = array_map('trim', explode(',', $label, 2));
            return [
                'family'        => $bits[0] !== '' ? $bits[0] : $label,
                'given'         => ($bits[1] ?? '') !== '' ? $bits[1] : null,
                'literal'       => $label,
                'isInstitution' => false,
            ];
        }
        $parts = explode(' ', $label);
        if (count($parts) === 1) {
            return ['family' => $label, 'given' => null, 'literal' => $label, 'isInstitution' => false];
        }
        $family = array_pop($parts);
        return [
            'family'        => $family,
            'given'         => implode(' ', $parts),
            'literal'       => $label,
            'isInstitution' => false,
        ];
    }

    // ─── Field readers ───────────────────────────────────────────────────────

    /**
     * Parse the NumericDataTypes timestamp into parts. The stored value is an
     * ISO string (YYYY, YYYY-MM or YYYY-MM-DD); prefer ->value() (the raw stored
     * form) over the localized rendering so month/day survive.
     *
     * @return array{year:?int,month:?int,day:?int,literal:?string}
     */
    private function dateParts(ItemRepresentation $item): array
    {
        foreach (self::DATE_TERMS as $term) {
            $value = $item->value($term);
            if (!$value instanceof ValueRepresentation) {
                continue;
            }
            $raw = trim((string) $value->value());
            if ($raw === '') {
                $raw = trim(strip_tags((string) $value));
            }
            if ($raw === '') {
                continue;
            }
            if (preg_match('/(\d{4})(?:-(\d{1,2})(?:-(\d{1,2}))?)?/', $raw, $m)) {
                return [
                    'year'    => (int) $m[1],
                    'month'   => (isset($m[2]) && $m[2] !== '') ? (int) $m[2] : null,
                    'day'     => (isset($m[3]) && $m[3] !== '') ? (int) $m[3] : null,
                    'literal' => $raw,
                ];
            }
            return ['year' => null, 'month' => null, 'day' => null, 'literal' => $raw];
        }
        return ['year' => null, 'month' => null, 'day' => null, 'literal' => null];
    }

    // doi(), cote() and clip() live in the shared ResourceValueReader trait.
}
