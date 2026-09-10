<?php
declare(strict_types=1);

namespace IwacSeo\Service;

use IwacSeo\Service\Citation\CitationRecord;
use IwacSeo\Service\Citation\Creator;
use IwacSeo\Service\Citation\IssuedDate;

/**
 * Serialises a {@see CitationRecord} to the machine-readable
 * citation formats offered on the item page: **BibTeX**, **RIS** and
 * **CSL-JSON**. Together with the formatted-text citation and the existing
 * Zotero-RDF (unAPI) endpoint, these replace the BulkExport block for
 * single-item exports.
 *
 * All three are dependency-free string builders (the module carries no vendor/),
 * and all emit UTF-8 — BibTeX targets biber/biblatex, which reads UTF-8 directly.
 */
final class CitationExport
{
    /** Format id => [extension, MIME type]. */
    public const FORMATS = [
        'bibtex'  => ['bib', 'application/x-bibtex; charset=utf-8'],
        'ris'     => ['ris', 'application/x-research-info-systems; charset=utf-8'],
        'csljson' => ['json', 'application/vnd.citationstyles.csl+json; charset=utf-8'],
    ];

    public function serialize(CitationRecord $record, string $format): ?string
    {
        return match ($format) {
            'bibtex'  => $this->bibtex($record),
            'ris'     => $this->ris($record),
            'csljson' => $this->cslJson($record),
            default   => null,
        };
    }

    /** Download filename stem — the accession id (Cote) if present, else item-<id>. */
    public function filename(CitationRecord $record, string $format): string
    {
        [$ext] = self::FORMATS[$format] ?? ['txt', ''];
        $stem = $record->accession !== null
            ? preg_replace('/[^A-Za-z0-9._-]+/', '-', $record->accession)
            : 'item-' . $record->id;
        return trim((string) $stem, '-') . '.' . $ext;
    }

    // ─── BibTeX ──────────────────────────────────────────────────────────────

    private function bibtex(CitationRecord $record): string
    {
        $kind = $record->kind;
        $type = $kind->bibtexType();
        if ($kind === CitationKind::Thesis && preg_match('/doctor|ph\.?d/i', $record->genre ?? '')) {
            $type = 'phdthesis';
        }
        $key = $this->citeKey($record);

        $fields = [];
        $authors = $this->nameListBibtex($record->authors);
        if ($authors !== '') {
            $fields['author'] = $authors;
        }
        $editors = $this->nameListBibtex($record->editors);
        if ($editors !== '') {
            $fields['editor'] = $editors;
        }
        if ($record->title !== null) {
            // Wrap in an extra pair of braces to protect title-case.
            $fields['title'] = '{' . $this->bibtexEscape($record->title) . '}';
        }

        // Container routes to journal / booktitle / publisher-adjacent fields.
        switch ($kind) {
            case CitationKind::Article:
            case CitationKind::Review:
            case CitationKind::Newspaper:
            case CitationKind::Magazine:
            case CitationKind::PeriodicalIssue:
                $this->addField($fields, 'journal', $record->container);
                break;
            case CitationKind::Chapter:
                $this->addField($fields, 'booktitle', $record->bookTitle);
                $this->addField($fields, 'publisher', $record->publisher);
                break;
            case CitationKind::Thesis:
                $this->addField($fields, 'school', $record->publisher);
                break;
            case CitationKind::Report:
                $this->addField($fields, 'institution', $record->publisher);
                break;
            case CitationKind::Book:
                $this->addField($fields, 'publisher', $record->publisher);
                break;
            default:
                $this->addField($fields, 'howpublished', $record->container);
                break;
        }

        if ($record->issued->hasYear()) {
            $fields['year'] = (string) $record->issued->year;
        }
        $this->addField($fields, 'date', $record->issued->iso());
        if (!$record->issued->hasYear()) {
            $this->addField($fields, 'note', $record->issued->literal);
        }
        foreach (
            ['type' => $record->genre, 'eventtitle' => $record->eventTitle,
            'venue' => $record->eventPlace, 'edition' => $record->edition,
            'isbn' => $record->isbn, 'issn' => $record->issn] as $field => $value
        ) {
            $this->addField($fields, $field, $value);
        }
        $this->addField($fields, 'volume', $record->volume);
        $this->addField($fields, 'number', $record->number ?? $record->issue);
        $pages = $record->pageRange();
        if ($pages !== null) {
            $fields['pages'] = $this->bibtexEscape(preg_replace('/[-–]+/u', '--', $pages) ?? $pages);
        }
        // DOI/URL are verbatim fields — do NOT LaTeX-escape them (the French site
        // slug "afrique_ouest" carries an underscore biber must read literally).
        if ($record->doi !== null) {
            $fields['doi'] = $this->verbatim($record->doi);
        }
        if ($record->url !== null) {
            $fields['url'] = $this->verbatim($record->sourceUrl ?? $record->url);
        }
        $this->addField($fields, 'language', $record->language);
        if ($record->keywords !== []) {
            $fields['keywords'] = $this->bibtexEscape(implode(', ', $record->keywords));
        }
        if ($record->abstract !== null) {
            $fields['abstract'] = $this->bibtexEscape($record->abstract);
        }

        $lines = ["@{$type}{{$key},"];
        $width = 0;
        foreach (array_keys($fields) as $name) {
            $width = max($width, strlen($name));
        }
        foreach ($fields as $name => $value) {
            $lines[] = sprintf('  %-' . $width . 's = {%s},', $name, $value);
        }
        $lines[] = '}';
        return implode("\n", $lines) . "\n";
    }

    private function citeKey(CitationRecord $record): string
    {
        $base = $record->accession ?? ('item-' . $record->id);
        return preg_replace('/[^A-Za-z0-9:_-]+/', '', $base) ?: 'iwac';
    }

    /**
     * BibTeX author/editor list, " and "-joined; institutions kept in braces.
     *
     * @param Creator[] $people
     */
    private function nameListBibtex(array $people): string
    {
        $out = [];
        foreach ($people as $person) {
            if ($person->isSingleField()) {
                $out[] = '{' . $this->bibtexEscape($person->literal) . '}';
            } elseif ($person->given !== null) {
                $out[] = $this->bibtexEscape((string) $person->family)
                    . ', ' . $this->bibtexEscape($person->given);
            } else {
                $out[] = $this->bibtexEscape((string) $person->family);
            }
        }
        return implode(' and ', $out);
    }

    private function bibtexEscape(string $text): string
    {
        // Structural braces are added by the caller, never read from metadata.
        return strtr($text, ['\\' => '\\textbackslash{}', '&' => '\\&', '%' => '\\%',
            '$' => '\\$', '#' => '\\#', '_' => '\\_', '~' => '\\textasciitilde{}',
            '^' => '\\textasciicircum{}', '{' => '\\{', '}' => '\\}']);
    }

    private function verbatim(string $text): string
    {
        return strtr($text, ['{' => '%7B', '}' => '%7D', "\r" => '', "\n" => '', '\\' => '%5C']);
    }

    // ─── RIS ─────────────────────────────────────────────────────────────────

    private function ris(CitationRecord $record): string
    {
        $eol = "\r\n";
        $lines = [];
        $lines[] = $this->risLine('TY', $record->kind->risType());

        foreach ($record->authors as $person) {
            $lines[] = $this->risLine('AU', $this->nameRis($person));
        }
        foreach ($record->editors as $person) {
            $lines[] = $this->risLine('ED', $this->nameRis($person));
        }
        $lines[] = $this->risLine('TI', $record->title);

        // Container: T2 is the generic secondary/container title (journal, book,
        // newspaper, blog) that Zotero/EndNote map correctly for every type.
        $lines[] = $this->risLine('T2', $record->container ?? $record->bookTitle);
        $lines[] = $this->risLine('PB', $record->publisher);

        if ($record->issued->hasYear()) {
            $lines[] = $this->risLine('PY', (string) $record->issued->year);
            $lines[] = $this->risLine('DA', $this->risDate($record->issued));
        }
        if ($record->issued->end !== null || !$record->issued->hasYear()) {
            $lines[] = $this->risLine('N1', $record->issued->literal);
        }
        $lines[] = $this->risLine('M3', $record->genre);
        $lines[] = $this->risLine('C1', $record->eventTitle);
        $lines[] = $this->risLine('CY', $record->eventPlace);
        $lines[] = $this->risLine('M1', $record->number);
        $lines[] = $this->risLine('ET', $record->edition);
        $lines[] = $this->risLine('SN', $record->isbn ?? $record->issn);
        $lines[] = $this->risLine('AN', $record->accession);
        $lines[] = $this->risLine('VL', $record->volume);
        $lines[] = $this->risLine('IS', $record->issue);
        $lines[] = $this->risLine('SP', $record->pageFirst);
        $lines[] = $this->risLine('EP', $record->pageLast);
        $lines[] = $this->risLine('DO', $record->doi);
        $lines[] = $this->risLine('UR', $record->sourceUrl ?? $record->url);
        $lines[] = $this->risLine('LA', $record->language);
        foreach ($record->keywords as $keyword) {
            $lines[] = $this->risLine('KW', $keyword);
        }
        if ($record->abstract !== null) {
            $lines[] = $this->risLine('AB', $record->abstract);
        }
        $lines[] = 'ER  - ';

        return implode($eol, array_filter($lines, static fn ($l) => $l !== null)) . $eol;
    }

    private function risLine(string $tag, ?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        // RIS is line-oriented; collapse any embedded newlines in the value.
        $value = preg_replace('/\s*\R\s*/u', ' ', $value) ?? $value;
        return sprintf('%-2s  - %s', $tag, $value);
    }

    private function nameRis(Creator $person): string
    {
        if ($person->isSingleField()) {
            return $person->literal;
        }
        return $person->given !== null
            ? $person->family . ', ' . $person->given
            : (string) $person->family;
    }

    private function risDate(IssuedDate $issued): ?string
    {
        if (!$issued->hasYear()) {
            return null;
        }
        // RIS DA is YYYY/MM/DD/ (trailing parts optional, slashes kept).
        $out = sprintf('%04d', $issued->year);
        if ($issued->month !== null) {
            $out .= sprintf('/%02d', $issued->month);
            if ($issued->day !== null) {
                $out .= sprintf('/%02d', $issued->day);
            }
        }
        return $out;
    }

    // ─── CSL-JSON ────────────────────────────────────────────────────────────

    private function cslJson(CitationRecord $record): string
    {
        $item = [
            'id'   => $record->accession ?? ('iwac-' . $record->id),
            'type' => $record->cslType(),
        ];
        if ($record->title !== null) {
            $item['title'] = $record->title;
        }
        $authors = $this->nameListCsl($record->authors);
        if ($authors) {
            $item['author'] = $authors;
        }
        $editors = $this->nameListCsl($record->editors);
        if ($editors) {
            $item['editor'] = $editors;
        }

        $container = $record->container ?? $record->bookTitle;
        if ($container !== null) {
            $item['container-title'] = $container;
        }
        if ($record->publisher !== null) {
            $item['publisher'] = $record->publisher;
        }
        if ($record->volume !== null) {
            $item['volume'] = $record->volume;
        }
        if ($record->issue !== null) {
            $item['issue'] = $record->issue;
        }
        $pages = $record->pageRange();
        if ($pages !== null) {
            $item['page'] = $pages;
        }
        $date = $record->issued->csl();
        if ($date) {
            $item['issued'] = $date;
        }
        foreach (
            ['genre' => $record->genre, 'event-title' => $record->eventTitle,
            'event-place' => $record->eventPlace, 'medium' => $record->medium,
            'number' => $record->number, 'edition' => $record->edition,
            'reviewed-title' => $record->reviewedTitle, 'archive' => $record->archive,
            'call-number' => $record->accession, 'ISBN' => $record->isbn, 'ISSN' => $record->issn] as $field => $value
        ) {
            if ($value !== null) {
                $item[$field] = $value;
            }
        }
        if ($record->doi !== null) {
            $item['DOI'] = $record->doi;
        }
        if ($record->sourceUrl !== null || $record->url !== null) {
            $item['URL'] = $record->sourceUrl ?? $record->url;
        }
        if ($record->language !== null) {
            $item['language'] = $record->language;
        }
        if ($record->abstract !== null) {
            $item['abstract'] = $record->abstract;
        }
        if ($record->keywords !== []) {
            $item['keyword'] = implode(', ', $record->keywords);
        }

        // CSL-JSON is an array of item objects.
        return json_encode(
            [$item],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ) . "\n";
    }

    /**
     * @param Creator[] $people
     * @return array<int,array<string,string>>
     */
    private function nameListCsl(array $people): array
    {
        $out = [];
        foreach ($people as $person) {
            if ($person->isSingleField()) {
                $out[] = ['literal' => $person->literal];
                continue;
            }
            $name = ['family' => (string) $person->family];
            if ($person->given !== null) {
                $name['given'] = $person->given;
            }
            $out[] = $name;
        }
        return $out;
    }

    private function addField(array &$fields, string $name, ?string $value): void
    {
        if ($value !== null && $value !== '') {
            $fields[$name] = $this->bibtexEscape($value);
        }
    }
}
