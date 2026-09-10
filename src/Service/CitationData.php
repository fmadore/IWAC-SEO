<?php
declare(strict_types=1);

namespace IwacSeo\Service;

use IwacSeo\Service\Citation\CitationRecord;
use IwacSeo\Service\Citation\Creator;
use IwacSeo\Service\Citation\IssuedDate;
use IwacSeo\Service\Concern\ResourceValueReader;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Api\Representation\ValueRepresentation;

/**
 * Builds a {@see CitationRecord} from an IWAC item — the single source of truth
 * consumed by {@see CitationFormatter} (Chicago/APA/MLA text),
 * {@see CitationExport} (BibTeX/RIS/CSL-JSON) and the Citation view helper.
 *
 * This class knows the *archive*: which Omeka property holds which citation
 * field. The record it returns knows nothing about Omeka, and the formatters
 * that read it know nothing about either.
 *
 * Dispatch is by **resource class** via the same {@see CitationKindMap} that
 * CitationMeta and ZoteroRdf use, and the same IWAC field conventions apply:
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

    public function __construct(private readonly CitationKindMap $kinds)
    {
    }

    public function kind(?int $classId): CitationKind
    {
        return $this->kinds->forClassId($classId);
    }

    /** Whether an item of this resource class is a citable work (not an authority record). */
    public function isCitable(?int $classId): bool
    {
        return !$this->kind($classId)->isAuthorityRecord();
    }

    /**
     * Normalized citation record, or null for a non-citable authority record.
     *
     * @param string|null $url the item's public (canonical) page URL
     */
    public function build(ItemRepresentation $item, ?string $url = null, ?string $locale = null): ?CitationRecord
    {
        $kind = $this->kinds->forResource($item);
        if ($kind->isAuthorityRecord()) {
            return null;
        }

        // dcterms:publisher is the one container field; route it to the slot the
        // kind needs, mirroring CitationMeta's per-kind branches.
        $container = $this->firstLabel($item, 'dcterms:publisher');
        $abstract = MetadataValue::select($item, self::ABSTRACT_TERMS, $locale);

        return new CitationRecord(
            id: $item->id(),
            kind: $kind,
            title: $this->firstString($item, ['dcterms:title']),
            authors: $this->people($item, ['bibo:authorList', 'dcterms:creator']),
            editors: $this->people($item, ['bibo:editorList']),
            issued: $this->issued($item),
            container: $this->containerSlot($kind) === 'container' ? $container : null,
            publisher: $this->containerSlot($kind) === 'publisher' ? $container : null,
            bookTitle: $kind === CitationKind::Chapter
                ? $this->firstString($item, ['dcterms:alternative'])
                : null,
            volume: $this->firstString($item, ['bibo:volume']),
            issue: implode('–', $this->labels($item, 'bibo:issue')) ?: null,
            pageFirst: $this->firstString($item, ['bibo:pageStart']),
            pageLast: $this->firstString($item, ['bibo:pageEnd']),
            doi: $this->doi($item),
            url: ($url !== null && $url !== '') ? $url : null,
            language: MetadataValue::language($this->firstLabel($item, 'dcterms:language')),
            abstract: $abstract !== null ? $this->clip($abstract) : null,
            keywords: $this->keywords($item),
            accession: $this->cote($item),
            genre: $this->firstLabel($item, 'dcterms:type'),
            eventTitle: $kind === CitationKind::Communication ? $this->firstLabel($item, 'dcterms:isPartOf') : null,
            eventPlace: $kind === CitationKind::Communication ? $this->firstLabel($item, 'dcterms:spatial') : null,
            sourceUrl: MetadataValue::url($this->firstString($item, ['fabio:hasURL'])),
            medium: $this->firstLabel($item, 'dcterms:medium'),
            number: $this->firstString($item, ['bibo:number']),
            edition: $this->firstString($item, ['bibo:edition']),
            reviewedTitle: $this->firstLabel($item, 'bibo:reviewOf'),
            archive: $this->cote($item) !== null ? 'Islam West Africa Collection' : null,
            isbn: $this->firstString($item, ['bibo:isbn13', 'bibo:isbn10', 'bibo:isbn']),
            issn: $this->firstString($item, ['bibo:issn']),
        );
    }

    /**
     * Which record slot dcterms:publisher fills for this kind. A chapter's
     * publisher really is a publisher (its container is the book title, which
     * lives elsewhere); a book, thesis or report names a publisher or awarding
     * institution; everything else names a periodical or site.
     *
     * @return 'container'|'publisher'
     */
    private function containerSlot(CitationKind $kind): string
    {
        return match ($kind) {
            CitationKind::Chapter, CitationKind::Book,
            CitationKind::Thesis, CitationKind::Report, CitationKind::Av, CitationKind::Audio,
            CitationKind::Photo, CitationKind::Document => 'publisher',
            default => 'container',
        };
    }

    // ─── Creators ────────────────────────────────────────────────────────────

    /**
     * Structured creators from the first populated role property, in document
     * order. Institutions (authors linked to an Organisation authority record)
     * keep a single-field literal name; everyone else is split into given/family.
     *
     * @param string[] $terms
     * @return Creator[]
     */
    private function people(ItemRepresentation $item, array $terms): array
    {
        foreach ($terms as $term) {
            $out = [];
            foreach ($item->value($term, ['all' => true]) as $value) {
                if (!$value instanceof ValueRepresentation) {
                    continue;
                }
                $creator = MetadataValue::creator($value, $this->kinds);
                if ($creator !== null) {
                    $out[] = $creator;
                }
            }
            if ($out) {
                return $out;
            }
        }
        return [];
    }

    // ─── Field readers ───────────────────────────────────────────────────────

    /**
     * The publication date. Prefer ->value() (the raw stored form) over the
     * localized rendering, so month and day survive.
     */
    private function issued(ItemRepresentation $item): IssuedDate
    {
        return IssuedDate::parse(MetadataValue::select($item, MetadataValue::DATE_TERMS) ?? '');
    }

    // doi(), cote() and clip() live in the shared ResourceValueReader trait.
}
