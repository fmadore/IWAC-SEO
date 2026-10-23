<?php
declare(strict_types=1);

namespace IwacSeo\Service;

use IwacSeo\Service\Citation\CitationRecord;
use IwacSeo\Service\Citation\Creator;
use IwacSeo\Service\Citation\IssuedDate;
use Omeka\Api\Representation\SitePageRepresentation;

/**
 * Builds a {@see CitationRecord} for a static site page — an online exhibit
 * such as "Hajj in Burkina Faso" — so it can be cited exactly as an item is:
 * the same Chicago / APA / MLA formatter, the same BibTeX / RIS / CSL-JSON
 * serialisers, the same theme partial.
 *
 * Omeka pages carry no authorship or publication metadata, so every field has
 * an editor override in the static-page SEO table (`iwac_seo_pages`, keys
 * `citation_*`) and a default here:
 *   • title     — the "Citation title" override, else the page title;
 *   • authors   — the "Authors" override (one per line, `{Name}` for an
 *                 institution), else `iwac_seo.citation.page.authors`;
 *   • date      — the "Date" override (YYYY, YYYY-MM or YYYY-MM-DD), else the
 *                 day the page was created;
 *   • website   — the collection's name in the page's language
 *                 (`archive_names`, shared with item citations), else the
 *                 site title;
 *   • publisher — `iwac_seo.citation.page.publisher`, by language;
 *   • URL       — the page on its own site. A page belongs to one site, so
 *                 each language version cites itself, in its own language.
 *
 * A page is citable only when it carries the "How to cite this page" block
 * ({@see BLOCK_LAYOUT}): the editor's placement is the opt-in, so the download
 * route never serves a citation for a page nobody chose to make citable.
 */
final class PageCitationData
{
    /** The block layout name that marks a page citable and renders its panel. */
    public const BLOCK_LAYOUT = 'iwacPageCitation';

    /**
     * @param array<string,string> $archiveNames   language => website name
     * @param array<string,string> $publisherNames language => publisher name
     * @param string[]             $defaultAuthors names, as the Authors field takes them
     */
    public function __construct(
        private readonly array $archiveNames = [],
        private readonly array $publisherNames = [],
        private readonly array $defaultAuthors = [],
    ) {
    }

    /** Whether the page carries the "How to cite this page" block. */
    public static function isCitable(SitePageRepresentation $page): bool
    {
        foreach ($page->blocks() as $block) {
            if ($block->layout() === self::BLOCK_LAYOUT) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string,mixed> $overrides the page's row of the static-page SEO table
     */
    public function build(SitePageRepresentation $page, ?string $url, string $locale, array $overrides = []): CitationRecord
    {
        $title = $this->text($overrides['citation_title'] ?? null) ?? (string) $page->title();
        $authors = self::parseAuthors((string) ($overrides['citation_authors'] ?? ''))
            ?: self::parseAuthors(implode("\n", $this->defaultAuthors));

        return new CitationRecord(
            id: $page->id(),
            kind: CitationKind::Webpage,
            title: $title !== '' ? $title : null,
            authors: $authors,
            issued: $this->issued($page, $overrides),
            container: $this->localised($this->archiveNames, $locale) ?? $this->text($page->site()->title()),
            publisher: $this->localised($this->publisherNames, $locale),
            url: ($url !== null && $url !== '') ? $url : null,
            language: $locale,
            abstract: $this->text($overrides['description'] ?? null),
            key: $this->text((string) $page->slug()),
        );
    }

    /**
     * The Authors field: one name per line, "First Last" or "Last, First";
     * a name in braces — `{Leibniz-Zentrum Moderner Orient}` — is an
     * institution, kept whole rather than split into given and family names.
     *
     * @return Creator[]
     */
    public static function parseAuthors(string $field): array
    {
        $out = [];
        foreach (preg_split('/\R/u', $field) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (preg_match('/^\{(.+)\}$/u', $line, $m)) {
                $name = trim($m[1]);
                if ($name !== '') {
                    $out[] = Creator::institution($name);
                }
                continue;
            }
            $out[] = Creator::parse($line, false);
        }
        return $out;
    }

    /** Whether a typed date is one {@see IssuedDate} reads as a date. */
    public static function isValidDate(string $value): bool
    {
        return IssuedDate::parse($value)->hasYear();
    }

    /** @param array<string,mixed> $overrides */
    private function issued(SitePageRepresentation $page, array $overrides): IssuedDate
    {
        $typed = IssuedDate::parse((string) ($overrides['citation_date'] ?? ''));
        if ($typed->hasYear()) {
            return $typed;
        }
        $created = $page->created();
        return $created instanceof \DateTimeInterface
            ? IssuedDate::parse($created->format('Y-m-d'))
            : IssuedDate::unknown();
    }

    /**
     * The name in the citation's language, then English, then any.
     *
     * @param array<string,string> $names
     */
    private function localised(array $names, string $locale): ?string
    {
        return $this->text($names[$locale] ?? $names['en'] ?? array_values($names)[0] ?? null);
    }

    private function text(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        return $value !== '' ? $value : null;
    }
}
