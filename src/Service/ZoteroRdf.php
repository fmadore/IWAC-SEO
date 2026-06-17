<?php
declare(strict_types=1);

namespace IwacSeo\Service;

use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Api\Representation\ItemRepresentation;
use Omeka\Api\Representation\ValueRepresentation;

/**
 * Serialises an IWAC item to **Zotero RDF**, served from the unAPI endpoint
 * (see {@see \IwacSeo\Controller\UnapiController}).
 *
 * Why a second format alongside the Highwire / Dublin Core <meta> tags that
 * {@see CitationMeta} emits: Zotero's *Embedded Metadata* translator — which
 * reads those meta tags — cannot express two things the archive needs.
 *   • A **call number** (French Zotero: *Cote*): Zotero only fills `callNumber`
 *     from a *typed* RDF node (dcterms:LCC), never from a flat meta tag; a plain
 *     dc:identifier that is not an ISBN/ISSN/DOI is dropped entirely.
 *   • A **single-field institutional creator**: a literal author is always run
 *     through cleanAuthor() and split into first/last name — so "Association
 *     Islamique d'Al Mawadda Burkina Faso" becomes "… Burkina / Faso".
 *
 * unAPI outranks Embedded Metadata in Zotero (translator priority 300 vs 400),
 * so for the primary-source kinds the item page advertises this endpoint and the
 * Connector imports the RDF instead of scraping the meta tags — giving exact
 * control over every field. The meta tags stay in place for Google Scholar and
 * as a fallback (and still serve every other kind).
 *
 * The RDF mirrors what Zotero's own RDF *import* translator reads (verified
 * against translators/RDF.js):
 *   • `z:itemType`            → the exact Zotero item type (overrides all else);
 *   • `dcterms:creator`       → authors. A literal value is split (persons); a
 *     `foaf:Person` node carrying only `foaf:surname` imports as a single-field
 *     creator (institutions). Linking through `dcterms:creator` also makes
 *     RDF.js' getNodes() skip the creator node, so it never becomes a stray item.
 *   • literal `dc:subject`    → tags (Sujet + Couverture spatiale);
 *   • `dc:subject → dcterms:LCC → rdf:value` → `callNumber` (the iwac- id);
 *   • `prism:publicationName` → publicationTitle; `prism:volume`, `prism:number`
 *     (issue), `bib:pages`, `dc:publisher`, `dc:date`, `dc:language`,
 *     `dcterms:abstract`, `dc:rights` map to the obvious fields;
 *   • `dc:identifier → dcterms:URI → rdf:value` → the item URL;
 *   • `eprints:document_url`  → a "Full Text PDF" attachment (public PDF only).
 */
class ZoteroRdf
{
    /** Namespaces, exactly as RDF.js expects them. */
    private const NS = [
        'rdf'     => 'http://www.w3.org/1999/02/22-rdf-syntax-ns#',
        'z'       => 'http://www.zotero.org/namespaces/export#',
        'dc'      => 'http://purl.org/dc/elements/1.1/',
        'dcterms' => 'http://purl.org/dc/terms/',
        'bib'     => 'http://purl.org/net/biblio#',
        'foaf'    => 'http://xmlns.com/foaf/0.1/',
        'prism'   => 'http://prismstandard.org/namespaces/1.2/basic/',
        'eprints' => 'http://purl.org/eprint/terms/',
    ];

    private const ABSTRACT_MAX = 5000;

    /** Citation kinds (CitationMeta vocabulary) that get a unAPI / Zotero-RDF record. */
    private const ELIGIBLE_KINDS = ['newspaper', 'magazine', 'av', 'document', 'photo'];

    /** kind => Zotero item-type id (z:itemType). */
    private const ZOTERO_TYPE = [
        'newspaper' => 'newspaperArticle',
        'magazine'  => 'magazineArticle',
        'av'        => 'videoRecording',
        'document'  => 'document',
        'photo'     => 'artwork',
    ];

    /** kind => biblio-ontology class (rdf:type wrapper) — a sane fallback; z:itemType wins. */
    private const BIB_TYPE = [
        'newspaper' => 'Article',
        'magazine'  => 'Article',
        'av'        => 'Document',
        'document'  => 'Document',
        'photo'     => 'Image',
    ];

    /**
     * @param array<int,string> $classKinds resource class id => citation kind
     */
    public function __construct(
        private readonly array $classKinds,
    ) {
    }

    /** Whether an item of this resource class is served via unAPI. */
    public function isEligible(?int $classId): bool
    {
        return in_array($this->classKinds[$classId] ?? null, self::ELIGIBLE_KINDS, true);
    }

    /**
     * Render the item as a Zotero-RDF document, or null for an ineligible kind.
     * $canonical is the item's public page URL (also the unAPI id).
     */
    public function render(ItemRepresentation $item, string $canonical): ?string
    {
        $classId = $item->resourceClass() ? $item->resourceClass()->id() : null;
        $kind = $this->classKinds[$classId] ?? null;
        if (!in_array($kind, self::ELIGIBLE_KINDS, true)) {
            return null;
        }

        $bibType = self::BIB_TYPE[$kind] ?? 'Document';
        $props = [];

        $props[] = $this->el('z:itemType', self::ZOTERO_TYPE[$kind]);
        $props[] = $this->el('dc:title', $this->firstString($item, ['dcterms:title']));

        foreach ($this->creators($item) as $creator) {
            $props[] = $creator;
        }

        $props[] = $this->el('dc:date',
            $this->firstString($item, ['dcterms:date', 'dcterms:issued', 'dcterms:created']));
        $props[] = $this->el('dc:language', $this->firstLabel($item, 'dcterms:language'));

        // Only the periodical kinds carry a container (publication) + issue/pages.
        if ($kind === 'newspaper' || $kind === 'magazine') {
            $props[] = $this->el('prism:publicationName', $this->firstLabel($item, 'dcterms:publisher'));
            $props[] = $this->el('prism:volume', $this->firstString($item, ['bibo:volume']));
            $props[] = $this->el('prism:number', $this->firstString($item, ['bibo:issue']));
            $props[] = $this->el('bib:pages', $this->pageRange($item));
        }

        $abstract = $this->firstString($item,
            ['dcterms:abstract', 'bibo:abstract', 'bibo:shortDescription', 'dcterms:description']);
        if ($abstract !== null) {
            $props[] = $this->el('dcterms:abstract', $this->clip($abstract));
        }
        $props[] = $this->el('dc:rights', $this->firstLabel($item, 'dcterms:rights'));

        // Tags: Sujet (dcterms:subject) + Couverture spatiale (dcterms:spatial).
        foreach ($this->keywords($item) as $tag) {
            $props[] = $this->el('dc:subject', $tag);
        }

        // Cote / call number: the iwac- accession number, via a dcterms:LCC node.
        $cote = $this->cote($item);
        if ($cote !== null) {
            $props[] = sprintf(
                '<dc:subject><dcterms:LCC><rdf:value>%s</rdf:value></dcterms:LCC></dc:subject>',
                $this->esc($cote)
            );
        }

        // The item's own URL.
        $props[] = sprintf(
            '<dc:identifier><dcterms:URI><rdf:value>%s</rdf:value></dcterms:URI></dc:identifier>',
            $this->esc($canonical)
        );

        // Public full-text PDF → a "Full Text PDF" attachment.
        $pdf = $this->pdfUrl($item);
        if ($pdf !== null) {
            $props[] = $this->el('eprints:document_url', $pdf);
        }

        $props = array_values(array_filter($props, static fn ($p) => $p !== null && $p !== ''));

        $xmlns = '';
        foreach (self::NS as $prefix => $uri) {
            $xmlns .= sprintf("\n         xmlns:%s=\"%s\"", $prefix, $uri);
        }

        return sprintf(
            "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
            . "<rdf:RDF%s>\n"
            . "  <bib:%s rdf:about=\"%s\">\n    %s\n  </bib:%s>\n"
            . "</rdf:RDF>\n",
            $xmlns,
            $bibType,
            $this->esc($canonical),
            implode("\n    ", $props),
            $bibType
        );
    }

    // ─── Creators ────────────────────────────────────────────────────────────

    /**
     * Author fragments, from the first populated role property, in document
     * order. Institutions (creators linked to an Organization authority record)
     * are emitted as a single-field foaf:Person; everyone else is a literal that
     * Zotero splits into first/last name.
     *
     * @return string[]
     */
    private function creators(ItemRepresentation $item): array
    {
        foreach (['bibo:authorList', 'dcterms:creator'] as $term) {
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
                if ($this->isOrganization($linked)) {
                    // foaf:Person with only a surname → fieldMode 1 (not split).
                    $out[] = sprintf(
                        '<dcterms:creator><foaf:Person><foaf:surname>%s</foaf:surname></foaf:Person></dcterms:creator>',
                        $this->esc($label)
                    );
                } else {
                    // Literal → Zotero's cleanAuthor splits it (persons), exactly
                    // as the citation_author / DC.creator meta path does.
                    $out[] = $this->el('dcterms:creator', $label);
                }
            }
            if ($out) {
                return $out;
            }
        }
        return [];
    }

    private function isOrganization(?AbstractResourceEntityRepresentation $linked): bool
    {
        if ($linked === null || !$linked->resourceClass()) {
            return false;
        }
        return ($this->classKinds[$linked->resourceClass()->id()] ?? null) === 'organization';
    }

    // ─── Field readers ───────────────────────────────────────────────────────

    /** Sujet (dcterms:subject) + Couverture spatiale (dcterms:spatial), de-duplicated. */
    private function keywords(ItemRepresentation $item): array
    {
        $out = [];
        foreach (['dcterms:subject', 'dcterms:spatial'] as $term) {
            foreach ($this->labels($item, $term) as $label) {
                $out[$label] = $label;
            }
        }
        return array_values($out);
    }

    /** The archive accession number: a dcterms:identifier value starting "iwac-". */
    private function cote(ItemRepresentation $item): ?string
    {
        foreach ($item->value('dcterms:identifier', ['all' => true]) as $value) {
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

    private function pageRange(ItemRepresentation $item): ?string
    {
        $first = $this->firstString($item, ['bibo:pageStart']);
        $last = $this->firstString($item, ['bibo:pageEnd']);
        if ($first === null && $last === null) {
            return null;
        }
        if ($first !== null && $last !== null && $last !== $first) {
            return $first . '-' . $last;
        }
        return $first ?? $last;
    }

    private function pdfUrl(ItemRepresentation $item): ?string
    {
        foreach ($item->media() as $media) {
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

    // ─── XML helpers ─────────────────────────────────────────────────────────

    private function el(string $qname, ?string $content): ?string
    {
        if ($content === null || $content === '') {
            return null;
        }
        return sprintf('<%s>%s</%s>', $qname, $this->esc($content), $qname);
    }

    private function esc(string $text): string
    {
        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function clip(string $text): string
    {
        $text = trim(preg_replace('/\s+/', ' ', strip_tags($text)) ?? '');
        return mb_substr($text, 0, self::ABSTRACT_MAX);
    }
}
