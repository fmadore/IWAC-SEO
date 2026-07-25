<?php
declare(strict_types=1);

namespace IwacSeo\Service;

/**
 * The closed set of citation kinds IWAC resources are dispatched to, and the
 * export-format type mappings that are a pure function of the kind.
 *
 * A kind is not a resource class and not a resource template: IWAC's templates
 * are not 1:1 with RDF classes (template 8 historically held both newspaper
 * articles and Islamic-publication issues), so every citation service resolves
 * a *resource class id* to one of these kinds through the configured
 * `iwac_seo.citation.class_kinds` map — see {@see CitationKindMap}.
 *
 * The type tables used to live as six separate const arrays spread across
 * CitationData (CSL), CitationExport (BibTeX + RIS), CitationMeta (Zotero via
 * DC.type) and ZoteroRdf (Zotero via z:itemType), with `ENTITY_KINDS` copied
 * verbatim in two of them. They are facets of one vocabulary, so they live here
 * — a new kind cannot now be added without the compiler asking for its types.
 *
 * What deliberately does NOT live here is *policy*: which kinds get a DC.type
 * override (a CitationMeta concern) and which are served over unAPI (a ZoteroRdf
 * concern) are decisions about those services, not facts about the kind.
 */
enum CitationKind: string
{
    // Authority / index entities — descriptive records, not citable works.
    case Person = 'person';
    case Place = 'place';
    case Organization = 'organization';
    case Event = 'event';
    case Subject = 'subject';

    // Primary sources.
    case Newspaper = 'newspaper';
    case Magazine = 'magazine';
    case Av = 'av';
    case Document = 'document';
    case Photo = 'photo';

    // Bibliographic references.
    case Article = 'article';
    case Review = 'review';
    case Chapter = 'chapter';
    case Book = 'book';
    case Thesis = 'thesis';
    case Report = 'report';
    case Communication = 'communication';
    case Post = 'post';

    /** The fallback for a resource class with no mapping. */
    case Item = 'item';

    /**
     * Descriptive authority records (person / place / organisation / event /
     * subject) are not citable works: no Highwire tags, no "How to cite" panel,
     * no citation record.
     */
    public function isAuthorityRecord(): bool
    {
        return match ($this) {
            self::Person, self::Place, self::Organization, self::Event, self::Subject => true,
            default => false,
        };
    }

    /**
     * Works published *inside* a container. Drives title treatment: quoted
     * (Chicago/MLA) or plain (APA) with an italic container, rather than an
     * italic standalone title.
     */
    public function isPartOfWork(): bool
    {
        return match ($this) {
            self::Newspaper, self::Magazine, self::Article, self::Review,
            self::Chapter, self::Post, self::Communication => true,
            default => false,
        };
    }

    /** CSL item type — drives CSL-JSON export and downstream typing. */
    public function cslType(): string
    {
        return match ($this) {
            self::Newspaper => 'article-newspaper',
            self::Magazine => 'article-magazine',
            self::Article => 'article-journal',
            self::Review => 'review',
            self::Chapter => 'chapter',
            self::Book => 'book',
            self::Thesis => 'thesis',
            self::Report => 'report',
            self::Post => 'post-weblog',
            self::Av => 'motion_picture',
            self::Communication => 'speech',
            self::Photo => 'graphic',
            default => 'document',
        };
    }

    /** BibTeX entry type (@article, @incollection, …). */
    public function bibtexType(): string
    {
        return match ($this) {
            self::Article, self::Review, self::Newspaper, self::Magazine => 'article',
            self::Chapter => 'incollection',
            self::Book => 'book',
            self::Thesis => 'phdthesis',
            self::Report => 'techreport',
            self::Communication => 'inproceedings',
            self::Post => 'online',
            default => 'misc',
        };
    }

    /** RIS reference type (the TY tag). */
    public function risType(): string
    {
        return match ($this) {
            self::Newspaper => 'NEWS',
            self::Magazine => 'MGZN',
            self::Article, self::Review => 'JOUR',
            self::Chapter => 'CHAP',
            self::Book => 'BOOK',
            self::Thesis => 'THES',
            self::Report => 'RPRT',
            self::Communication => 'CONF',
            self::Post => 'BLOG',
            self::Av => 'VIDEO',
            self::Photo => 'ART',
            default => 'GEN',
        };
    }

    /**
     * The Zotero item-type id, where the kind maps to one Zotero cannot infer
     * on its own. Consumed two ways — as `z:itemType` in the Zotero RDF, and as
     * a forced `DC.type` in the meta tags — which is why the value must be a
     * valid Zotero type id and not a display label.
     */
    public function zoteroItemType(): ?string
    {
        return match ($this) {
            self::Newspaper => 'newspaperArticle',
            self::Magazine => 'magazineArticle',
            self::Post => 'blogPost',
            self::Av => 'videoRecording',
            self::Communication => 'presentation',
            self::Photo => 'artwork',
            self::Book => 'book',
            self::Document => 'document',
            default => null,
        };
    }
}
