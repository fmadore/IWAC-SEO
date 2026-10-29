<?php
declare(strict_types=1);

namespace IwacSeo\Service;

use IwacSeo\Service\Citation\CitationRecord;
use IwacSeo\Service\Citation\Creator;
use IwacSeo\Service\Citation\IssuedDate;
use IwacSeo\Service\Concern\ResourceValueReader;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Api\Representation\ItemRepresentation;

/**
 * Emits bibliographic <meta> tags so the Zotero Connector (and Google Scholar,
 * Mendeley, etc.) capture a resource page as a properly-typed reference.
 *
 * Two vocabularies, both read by Zotero's "Embedded Metadata" translator:
 *   • Highwire Press  (citation_title, citation_author, citation_journal_title …)
 *     — the richest signal and what drives Zotero's item-type detection for the
 *     scholarly references.
 *   • Dublin Core     (DC.title, DC.creator, DC.date …) — a generic fallback,
 *     emitted for every resource (including authority pages).
 *
 * Dispatch is by **resource class** (config 'iwac_seo.citation.class_kinds'),
 * because IWAC's templates are not 1:1 with RDF classes.
 *
 * IWAC field conventions differ from a typical Omeka site and are baked into the
 * per-kind branches:
 *   • the journal / newspaper / publication title lives in **dcterms:publisher**
 *     (often a linked item set), not dcterms:isPartOf;
 *   • a book chapter's **book title** lives in **dcterms:alternative**;
 *   • DOIs live in **bibo:doi** (a URI value); ISBN/ISSN are not recorded;
 *   • Zotero **tags** come from both **dcterms:subject** (Sujet) and
 *     **dcterms:spatial** (Couverture spatiale) — see {@see keywords()}.
 *
 * Newspaper articles and Islamic-publication issues are the bulk of the archive.
 * Highwire has no container tag for a newspaper/magazine, and any citation_*
 * container tag would force Zotero's type to journalArticle (hwType wins over
 * DC.type in the Embedded Metadata translator). So those two kinds instead force
 * the correct Zotero item type through **DC.type** (a valid Zotero type id, read
 * by the translator's RDF backend when no Highwire container tag is present) and
 * route the publication name through **prism.publicationName** → publicationTitle.
 *
 * A work's tags are written from the {@see CitationRecord} that
 * {@see CitationData} builds — the record the "How to cite" panel formats and
 * the downloads and Zotero RDF serialise — in the page's language, so what
 * Zotero captures cannot disagree with them about a field. A person whose
 * authority record holds their family and given names is sent as "Family,
 * Given", the form Zotero splits on the comma and Scholar accepts, so a
 * multi-word family name (Olivier de Sardan) survives; any other name is sent
 * exactly as the archive writes it ({@see metaName()}). Authority records and
 * media pages are not works: they carry the Dublin Core tags alone, read from
 * the resource.
 */
class CitationMeta
{
    use ResourceValueReader;

    /**
     * Kinds with no Highwire container tag, typed instead through DC.type
     * (carrying the kind's Zotero item-type id — see CitationKind).
     *
     * Fieldwork photographs (class 58) are publicly discoverable since
     * IwacSearch 3.3.0; without the override they captured as a generic
     * document. Books (authored + edited volumes) carry no Highwire container
     * tag either, so the Embedded Metadata translator would default them to
     * journalArticle; citation_publisher is not a container tag, so this
     * override wins and Zotero captures a "book" (with editors, for an edited
     * volume).
     */
    private const DC_TYPE_OVERRIDE_KINDS = [
        CitationKind::Newspaper,
        CitationKind::Magazine,
        CitationKind::PeriodicalIssue,
        CitationKind::Post,
        CitationKind::Av,
        CitationKind::Audio,
        CitationKind::Communication,
        CitationKind::Photo,
        CitationKind::Book,
    ];

    private readonly CitationData $citationData;

    /**
     * @param CitationData|null $citationData the shared record builder; one
     *   over the same kind map, without archive names (which no tag carries),
     *   when null
     */
    public function __construct(private readonly CitationKindMap $kinds, ?CitationData $citationData = null)
    {
        $this->citationData = $citationData ?? new CitationData($kinds);
    }

    /**
     * @param int|null $classId unused: the kind is read from the resource
     *   itself, which also tells a sound recording from a video
     */
    public function apply(
        PhpRenderer $view,
        AbstractResourceEntityRepresentation $resource,
        ?int $classId,
        ?string $canonical
    ): void {
        $headMeta = $view->headMeta();
        $locale = ViewLocale::forCitation($view);

        // A work's citation, as the panel and the downloads read it; null for
        // an authority record or a media page, which are not works.
        $record = $resource instanceof ItemRepresentation
            ? $this->citationData->build($resource, $canonical, $locale)
            : null;
        // DC.date keeps the stored value; citation_publication_date gives the
        // record's reading of it in Scholar's form.
        $date = $this->firstString($resource, MetadataValue::DATE_TERMS);

        // Dublin Core for every resource.
        $this->dublinCore($headMeta, $resource, $canonical, $record, $date, $locale);

        // Highwire only for citable works.
        if ($record !== null) {
            $this->highwire($headMeta, $record, $resource, $canonical);
        }
    }

    // ─── Highwire Press (citation_*) + kind-specific typing ──────────────────

    private function highwire(
        \Laminas\View\Helper\HeadMeta $headMeta,
        CitationRecord $record,
        AbstractResourceEntityRepresentation $resource,
        ?string $canonical
    ): void {
        $kind = $record->kind;
        $this->single($headMeta, 'citation_title', $record->title);

        foreach ($this->metaNames($record->authors, true) as $author) {
            $headMeta->appendName('citation_author', $author);
        }
        // Editors (e.g. a chapter's book editors). Zotero's Embedded Metadata
        // translator maps repeated citation_editor tags to the Editor role.
        foreach ($this->metaNames($record->editors, true) as $editor) {
            $headMeta->appendName('citation_editor', $editor);
        }

        $this->single($headMeta, 'citation_publication_date', $this->scholarDate($record->issued));
        $this->single($headMeta, 'citation_language', $record->language);
        $this->single($headMeta, 'citation_doi', $record->doi);

        if ($record->keywords) {
            $this->single($headMeta, 'citation_keywords', implode('; ', $record->keywords));
        }
        $this->single($headMeta, 'citation_abstract', $record->abstract);
        if ($canonical) {
            $this->single($headMeta, 'citation_public_url', $canonical);
        }
        // Not a record field: only a public file is offered, never a private one.
        $pdf = $this->pdfUrl($resource);
        if ($pdf !== null) {
            $this->single($headMeta, 'citation_pdf_url', $pdf);
        }

        switch ($kind) {
            case CitationKind::Article:   // journal article
            case CitationKind::Review:    // book review (published in a journal)
                $this->single($headMeta, 'citation_journal_title', $record->container);
                $this->single($headMeta, 'citation_volume', $record->volume);
                $this->single($headMeta, 'citation_issue', $record->issue);
                $this->single($headMeta, 'citation_firstpage', $record->pageFirst);
                $this->single($headMeta, 'citation_lastpage', $record->pageLast);
                break;
            case CitationKind::Chapter:
                // IWAC stores the book title in dcterms:alternative.
                $this->single($headMeta, 'citation_inbook_title', $record->bookTitle);
                $this->single($headMeta, 'citation_publisher', $record->publisher);
                $this->single($headMeta, 'citation_firstpage', $record->pageFirst);
                $this->single($headMeta, 'citation_lastpage', $record->pageLast);
                break;
            case CitationKind::Book:
                $this->single($headMeta, 'citation_publisher', $record->publisher);
                break;
            case CitationKind::Thesis:
                $this->single($headMeta, 'citation_dissertation_institution', $record->publisher);
                break;
            case CitationKind::Report:
                $this->single($headMeta, 'citation_technical_report_institution', $record->publisher);
                $this->single($headMeta, 'citation_technical_report_number', $record->number);
                break;
            case CitationKind::Newspaper:
            case CitationKind::Magazine:
            case CitationKind::PeriodicalIssue:
            case CitationKind::Post:
                // No Highwire container tag (it would force journalArticle). The
                // Zotero type is set via DC.type below; the publication name
                // travels through prism.publicationName → publicationTitle.
                $this->single($headMeta, 'prism.publicationName', $record->container);
                $this->single($headMeta, 'prism.volume', $record->volume);
                $this->single($headMeta, 'prism.number', $record->issue);
                $this->single($headMeta, 'citation_firstpage', $record->pageFirst);
                $this->single($headMeta, 'citation_lastpage', $record->pageLast);
                break;
            // 'av', 'communication', 'document', 'item': title/author/date/
            // abstract already cover them; 'av' & 'communication' are typed via
            // the DC.type override below.
        }

        // Force the Zotero item type for kinds Highwire cannot express. This
        // overwrites the generic class-label DC.type set in dublinCore();
        // because no citation_* container tag is present for these kinds, the
        // Embedded Metadata translator's RDF backend honours DC.type.
        $zoteroType = $kind->zoteroItemType();
        if ($zoteroType !== null && in_array($kind, self::DC_TYPE_OVERRIDE_KINDS, true)) {
            $headMeta->setName('DC.type', $zoteroType);
        }
    }

    // ─── Dublin Core (DC.*) ─────────────────────────────────────────────────

    /**
     * The Dublin Core tags: a work's from its record, any other resource's
     * from its own values.
     */
    private function dublinCore(
        \Laminas\View\Helper\HeadMeta $headMeta,
        AbstractResourceEntityRepresentation $resource,
        ?string $canonical,
        ?CitationRecord $record,
        ?string $date,
        string $locale
    ): void {
        if ($record !== null) {
            $title = $record->title;
            // The citation's authors, read from the first populated of
            // bibo:authorList and dcterms:creator; no IWAC record holds both.
            $creators = $this->metaNames($record->authors, false);
            // dcterms:publisher is one container, routed by kind.
            $publisher = $record->container ?? $record->publisher;
            $language = $record->language;
            $keywords = $record->keywords;
            $abstract = $record->abstract;
        } else {
            $title = $this->firstString($resource, ['dcterms:title']);
            $creators = $this->people($resource, ['dcterms:creator', 'bibo:authorList']);
            $publisher = $this->firstLabel($resource, 'dcterms:publisher');
            $language = $this->language($resource);
            $keywords = $this->keywords($resource);
            $abstract = MetadataValue::select($resource, self::ABSTRACT_TERMS, $locale);
            $abstract = $abstract !== null ? $this->clip($abstract) : null;
        }

        $this->single($headMeta, 'DC.title', $title);
        foreach ($creators as $creator) {
            $headMeta->appendName('DC.creator', $creator);
        }
        $this->single($headMeta, 'DC.date', $date);
        $this->single($headMeta, 'DC.publisher', $publisher);
        $this->single($headMeta, 'DC.type', ResourceUrl::classLabel($resource));
        $this->single($headMeta, 'DC.language', $language);

        // DC.identifier is the resource's own (canonical) URL. The DOI is
        // conveyed solely through citation_doi → Zotero's DOI field; emitting it
        // here as well makes Zotero copy it into the Extra field redundantly.
        $this->single($headMeta, 'DC.identifier', $canonical);

        // Sujet (dcterms:subject) + Couverture spatiale (dcterms:spatial). Zotero's
        // Embedded Metadata translator turns dc:subject into the item's tags (via
        // its RDF backend), so both descriptive subjects and spatial coverage are
        // captured as tags. See keywords().
        foreach ($keywords as $subject) {
            $headMeta->appendName('DC.subject', $subject);
        }
        $this->single($headMeta, 'DC.description', $abstract);
    }

    // ─── Value readers ──────────────────────────────────────────────────────
    // firstString(), firstLabel(), labels() and keywords() live in the shared
    // ResourceValueReader trait (used above). Zotero's Embedded Metadata
    // translator builds tags from dc:subject via its RDF backend (pre-empting the
    // citation_keywords fallback), so keywords() — Sujet + Couverture spatiale —
    // is emitted on BOTH DC.subject and citation_keywords; whichever the
    // translator consumes, subjects and places both land as tags.

    /**
     * The names a role's creators are sent under, in order ({@see metaName()}).
     *
     * Zotero's Embedded Metadata translator reads a *lone* citation_author or
     * citation_editor holding a comma as a comma-separated list when both
     * sides of the comma contain a space, so "Olivier de Sardan, Jean Pierre"
     * would become two people. A lone name of that shape is sent as written
     * instead. Dublin Core passes through Zotero's RDF reader, which only
     * splits on the comma, and needs no such care.
     *
     * @param Creator[] $creators
     * @param bool $highwire whether the names go into citation_author/_editor
     * @return string[]
     */
    private function metaNames(array $creators, bool $highwire): array
    {
        $lone = count($creators) === 1;
        $out = [];
        foreach ($creators as $creator) {
            $name = self::metaName($creator);
            if ($highwire && $lone && $name !== $creator->literal && preg_match('/\s.*,.*\s/', $name)) {
                $name = $creator->literal;
            }
            $out[] = $name;
        }
        return $out;
    }

    /**
     * A creator's name for the citation meta tags: "Family, Given" when the
     * authority record holds both names in its own fields; otherwise the name
     * exactly as the archive writes it — an institution, a single word, a
     * label already inverted, or one known only as a label ("Jean-Pierre
     * Olivier de Sardan"). A label's split is a guess, and Zotero and Scholar
     * guess on their own rather than take ours for fact.
     */
    private static function metaName(Creator $creator): string
    {
        if ($creator->structured && !$creator->isInstitution && $creator->family !== null && $creator->given !== null) {
            return $creator->family . ', ' . $creator->given;
        }
        return $creator->literal;
    }

    /**
     * Person names from the first populated role property, in document order.
     *
     * @param string[] $terms
     * @return string[]
     */
    private function people(AbstractResourceEntityRepresentation $resource, array $terms): array
    {
        foreach ($terms as $term) {
            $names = $this->labels($resource, $term);
            if ($names) {
                return $names;
            }
        }
        return [];
    }

    // doi(), pdfUrl() and clip() live in the shared ResourceValueReader trait.

    /**
     * The publication date in the form Google Scholar's inclusion guidelines
     * ask for — "2010/5/12", or the year alone — rather than the stored ISO
     * value. An interval is cited by its start: "2009-05/2009-08" read as a
     * slashed date would be garbage. DC.date keeps the stored value, and a
     * value that is no date passes through as entered.
     */
    private function scholarDate(IssuedDate $date): ?string
    {
        if (!$date->hasYear()) {
            return $date->literal;
        }
        return implode('/', array_filter([$date->year, $date->month, $date->day], static fn ($part) => $part !== null));
    }

    /**
     * The record's first language as a BCP 47 tag — the form Zotero files in
     * its Language field and citation styles read — else its name, which is
     * what both tags carried before ({@see LanguageCode}).
     */
    private function language(AbstractResourceEntityRepresentation $resource): ?string
    {
        return LanguageCode::of($resource)[0] ?? $this->firstLabel($resource, 'dcterms:language');
    }

    private function single(\Laminas\View\Helper\HeadMeta $headMeta, string $name, ?string $content): void
    {
        if ($content !== null && $content !== '') {
            $headMeta->setName($name, $content);
        }
    }
}
