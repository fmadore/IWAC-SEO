<?php
declare(strict_types=1);

namespace IwacSeo\Service;

use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Api\Representation\ValueRepresentation;

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
 */
class CitationMeta
{
    private const ABSTRACT_MAX = 5000;

    /** Kinds that are descriptive authority records, not citable works → Dublin Core only. */
    private const ENTITY_KINDS = ['person', 'place', 'organization', 'event', 'subject'];

    /**
     * Kinds with no Highwire container tag, typed instead via DC.type (a Zotero
     * item-type id). kind => Zotero type id.
     */
    private const DC_TYPE_OVERRIDES = [
        'newspaper'     => 'newspaperArticle',
        'magazine'      => 'magazineArticle',
        'post'          => 'blogPost',
        'av'            => 'videoRecording',
        'communication' => 'presentation',
        // Fieldwork photographs (class 58) — publicly discoverable since
        // IwacSearch 3.3.0; without this they captured as a generic document.
        'photo'         => 'artwork',
    ];

    /**
     * @param array<int,string> $classKinds resource class id => citation kind
     */
    public function __construct(
        private readonly array $classKinds,
        private readonly string $defaultKind = 'item',
    ) {
    }

    public function apply(
        PhpRenderer $view,
        AbstractResourceEntityRepresentation $resource,
        ?int $classId,
        ?string $canonical
    ): void {
        $headMeta = $view->headMeta();
        $kind = $this->classKinds[$classId] ?? $this->defaultKind;

        // Dublin Core for every resource.
        $this->dublinCore($headMeta, $resource, $canonical);

        // Highwire only for citable works.
        if (!in_array($kind, self::ENTITY_KINDS, true)) {
            $this->highwire($headMeta, $resource, $kind, $canonical);
        }
    }

    // ─── Highwire Press (citation_*) + kind-specific typing ──────────────────

    private function highwire(
        \Laminas\View\Helper\HeadMeta $headMeta,
        AbstractResourceEntityRepresentation $resource,
        string $kind,
        ?string $canonical
    ): void {
        $this->single($headMeta, 'citation_title', $this->firstString($resource, ['dcterms:title']));

        foreach ($this->people($resource, ['bibo:authorList', 'dcterms:creator']) as $author) {
            $headMeta->appendName('citation_author', $author);
        }
        // Editors (e.g. a chapter's book editors). Zotero's Embedded Metadata
        // translator maps repeated citation_editor tags to the Editor role.
        foreach ($this->people($resource, ['bibo:editorList']) as $editor) {
            $headMeta->appendName('citation_editor', $editor);
        }

        $this->single($headMeta, 'citation_publication_date',
            $this->firstString($resource, ['dcterms:date', 'dcterms:issued', 'dcterms:created']));
        $this->single($headMeta, 'citation_language', $this->firstLabel($resource, 'dcterms:language'));
        $this->single($headMeta, 'citation_doi', $this->doi($resource));

        $keywords = $this->keywords($resource);
        if ($keywords) {
            $this->single($headMeta, 'citation_keywords', implode('; ', $keywords));
        }

        $abstract = $this->firstString($resource, ['dcterms:abstract', 'bibo:abstract', 'bibo:shortDescription', 'dcterms:description']);
        if ($abstract !== null) {
            $this->single($headMeta, 'citation_abstract', $this->clip($abstract));
        }
        if ($canonical) {
            $this->single($headMeta, 'citation_public_url', $canonical);
        }
        $pdf = $this->pdfUrl($resource);
        if ($pdf !== null) {
            $this->single($headMeta, 'citation_pdf_url', $pdf);
        }

        // The periodical / publisher / institution — all in dcterms:publisher.
        $container = $this->firstLabel($resource, 'dcterms:publisher');
        $firstPage = $this->firstString($resource, ['bibo:pageStart']);
        $lastPage = $this->firstString($resource, ['bibo:pageEnd']);

        switch ($kind) {
            case 'article':   // journal article
            case 'review':    // book review (published in a journal)
                $this->single($headMeta, 'citation_journal_title', $container);
                $this->single($headMeta, 'citation_volume', $this->firstString($resource, ['bibo:volume']));
                $this->single($headMeta, 'citation_issue', $this->firstString($resource, ['bibo:issue']));
                $this->single($headMeta, 'citation_firstpage', $firstPage);
                $this->single($headMeta, 'citation_lastpage', $lastPage);
                break;
            case 'chapter':
                // IWAC stores the book title in dcterms:alternative.
                $this->single($headMeta, 'citation_inbook_title', $this->firstString($resource, ['dcterms:alternative']));
                $this->single($headMeta, 'citation_publisher', $container);
                $this->single($headMeta, 'citation_firstpage', $firstPage);
                $this->single($headMeta, 'citation_lastpage', $lastPage);
                break;
            case 'book':
                $this->single($headMeta, 'citation_publisher', $container);
                break;
            case 'thesis':
                $this->single($headMeta, 'citation_dissertation_institution', $container);
                break;
            case 'report':
                $this->single($headMeta, 'citation_technical_report_institution', $container);
                break;
            case 'newspaper':
            case 'magazine':
            case 'post':
                // No Highwire container tag (it would force journalArticle). The
                // Zotero type is set via DC.type below; the publication name
                // travels through prism.publicationName → publicationTitle.
                $this->single($headMeta, 'prism.publicationName', $container);
                break;
            // 'av', 'communication', 'document', 'item': title/author/date/
            // abstract already cover them; 'av' & 'communication' are typed via
            // the DC.type override below.
        }

        // Force the Zotero item type for kinds Highwire cannot express. This
        // overwrites the generic class-label DC.type set in dublinCore();
        // because no citation_* container tag is present for these kinds, the
        // Embedded Metadata translator's RDF backend honours DC.type.
        if (isset(self::DC_TYPE_OVERRIDES[$kind])) {
            $headMeta->setName('DC.type', self::DC_TYPE_OVERRIDES[$kind]);
        }
    }

    // ─── Dublin Core (DC.*) ─────────────────────────────────────────────────

    private function dublinCore(
        \Laminas\View\Helper\HeadMeta $headMeta,
        AbstractResourceEntityRepresentation $resource,
        ?string $canonical
    ): void {
        $this->single($headMeta, 'DC.title', $this->firstString($resource, ['dcterms:title']));
        foreach ($this->people($resource, ['dcterms:creator', 'bibo:authorList']) as $creator) {
            $headMeta->appendName('DC.creator', $creator);
        }
        $this->single($headMeta, 'DC.date',
            $this->firstString($resource, ['dcterms:date', 'dcterms:issued', 'dcterms:created']));
        $this->single($headMeta, 'DC.publisher', $this->firstLabel($resource, 'dcterms:publisher'));
        $this->single($headMeta, 'DC.type',
            $resource->resourceClass() ? $resource->resourceClass()->label() : null);
        $this->single($headMeta, 'DC.language', $this->firstLabel($resource, 'dcterms:language'));

        // DC.identifier is the resource's own (canonical) URL. The DOI is
        // conveyed solely through citation_doi → Zotero's DOI field; emitting it
        // here as well makes Zotero copy it into the Extra field redundantly.
        $this->single($headMeta, 'DC.identifier', $canonical);

        // Sujet (dcterms:subject) + Couverture spatiale (dcterms:spatial). Zotero's
        // Embedded Metadata translator turns dc:subject into the item's tags (via
        // its RDF backend), so both descriptive subjects and spatial coverage are
        // captured as tags. See keywords().
        foreach ($this->keywords($resource) as $subject) {
            $headMeta->appendName('DC.subject', $subject);
        }
        $description = $this->firstString($resource, ['dcterms:abstract', 'bibo:shortDescription', 'dcterms:description']);
        if ($description !== null) {
            $this->single($headMeta, 'DC.description', $this->clip($description));
        }
    }

    // ─── Value readers ──────────────────────────────────────────────────────

    /** @param string[] $terms */
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

    /** @return string[] */
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
     * Zotero tag labels: descriptive subjects (dcterms:subject — "Sujet")
     * followed by spatial coverage (dcterms:spatial — "Couverture spatiale"),
     * de-duplicated with subjects first.
     *
     * Zotero's Embedded Metadata translator builds tags from dc:subject through
     * its RDF backend, which pre-empts the citation_keywords fallback (that only
     * fires when no tag was found). So the combined set is emitted on BOTH
     * channels — DC.subject (Dublin Core) and citation_keywords (Highwire) — and
     * whichever the translator consumes, subjects and places both land as tags.
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

    private function doi(AbstractResourceEntityRepresentation $resource): ?string
    {
        $value = $resource->value('bibo:doi');
        if (!$value instanceof ValueRepresentation) {
            return null;
        }
        // bibo:doi is stored as a URI value; prefer the URI, fall back to text.
        $doi = $value->uri() ?: trim(strip_tags((string) $value));
        if ($doi === '') {
            return null;
        }
        // Normalise a DOI URL or "doi:" prefix down to the bare identifier.
        $doi = preg_replace('#^(https?://(dx\.)?doi\.org/|doi:)#i', '', $doi);
        return $doi !== '' ? $doi : null;
    }

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

    private function clip(string $text): string
    {
        $text = trim(preg_replace('/\s+/', ' ', strip_tags($text)) ?? '');
        return mb_substr($text, 0, self::ABSTRACT_MAX);
    }

    private function single(\Laminas\View\Helper\HeadMeta $headMeta, string $name, ?string $content): void
    {
        if ($content !== null && $content !== '') {
            $headMeta->setName($name, $content);
        }
    }
}
