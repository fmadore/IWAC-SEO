<?php
declare(strict_types=1);

namespace IwacSeo\Service;

/**
 * Serialises a normalized {@see CitationData} record to the machine-readable
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

    /** Citation kind => BibTeX entry type. */
    private const BIBTEX_TYPE = [
        'article' => 'article', 'review' => 'article', 'newspaper' => 'article', 'magazine' => 'article',
        'chapter' => 'incollection', 'book' => 'book', 'thesis' => 'phdthesis', 'report' => 'techreport',
        'communication' => 'inproceedings', 'post' => 'online',
        'av' => 'misc', 'photo' => 'misc', 'document' => 'misc', 'item' => 'misc',
    ];

    /** Citation kind => RIS reference type (TY). */
    private const RIS_TYPE = [
        'newspaper' => 'NEWS', 'magazine' => 'MGZN', 'article' => 'JOUR', 'review' => 'JOUR',
        'chapter' => 'CHAP', 'book' => 'BOOK', 'thesis' => 'THES', 'report' => 'RPRT',
        'communication' => 'CONF', 'post' => 'BLOG', 'av' => 'VIDEO', 'photo' => 'ART',
        'document' => 'GEN', 'item' => 'GEN',
    ];

    public function serialize(array $record, string $format): ?string
    {
        return match ($format) {
            'bibtex'  => $this->bibtex($record),
            'ris'     => $this->ris($record),
            'csljson' => $this->cslJson($record),
            default   => null,
        };
    }

    /** Download filename stem — the accession id (Cote) if present, else item-<id>. */
    public function filename(array $record, string $format): string
    {
        [$ext] = self::FORMATS[$format] ?? ['txt', ''];
        $stem = $record['accession'] ?? null;
        $stem = $stem ? preg_replace('/[^A-Za-z0-9._-]+/', '-', $stem) : 'item-' . (int) ($record['id'] ?? 0);
        return trim((string) $stem, '-') . '.' . $ext;
    }

    // ─── BibTeX ──────────────────────────────────────────────────────────────

    private function bibtex(array $record): string
    {
        $type = self::BIBTEX_TYPE[$record['kind']] ?? 'misc';
        $key = $this->citeKey($record);

        $fields = [];
        $authors = $this->nameListBibtex($record['authors'] ?? []);
        if ($authors !== '') {
            $fields['author'] = $authors;
        }
        $editors = $this->nameListBibtex($record['editors'] ?? []);
        if ($editors !== '') {
            $fields['editor'] = $editors;
        }
        if ($record['title']) {
            // Wrap in an extra pair of braces to protect title-case.
            $fields['title'] = '{' . $this->bibtexEscape($record['title']) . '}';
        }

        // Container routes to journal / booktitle / publisher-adjacent fields.
        switch ($record['kind']) {
            case 'article':
            case 'review':
            case 'newspaper':
            case 'magazine':
                $this->addField($fields, 'journal', $record['container']);
                break;
            case 'chapter':
                $this->addField($fields, 'booktitle', $record['bookTitle']);
                $this->addField($fields, 'publisher', $record['publisher']);
                break;
            case 'thesis':
                $this->addField($fields, 'school', $record['publisher']);
                break;
            case 'report':
                $this->addField($fields, 'institution', $record['publisher']);
                break;
            case 'book':
                $this->addField($fields, 'publisher', $record['publisher']);
                break;
            default:
                $this->addField($fields, 'howpublished', $record['container']);
                break;
        }

        if (($record['issued']['year'] ?? null)) {
            $fields['year'] = (string) $record['issued']['year'];
        }
        $this->addField($fields, 'volume', $record['volume']);
        $this->addField($fields, 'number', $record['issue']);
        $pages = CitationData::pageRange($record);
        if ($pages !== null) {
            $fields['pages'] = str_replace('-', '--', $pages);
        }
        // DOI/URL are verbatim fields — do NOT LaTeX-escape them (the French site
        // slug "afrique_ouest" carries an underscore biber must read literally).
        if (!empty($record['doi'])) {
            $fields['doi'] = $record['doi'];
        }
        if (!empty($record['url'])) {
            $fields['url'] = $record['url'];
        }
        $this->addField($fields, 'language', $record['language']);
        if (!empty($record['keywords'])) {
            $fields['keywords'] = $this->bibtexEscape(implode(', ', $record['keywords']));
        }
        if (!empty($record['abstract'])) {
            $fields['abstract'] = $this->bibtexEscape($record['abstract']);
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

    private function citeKey(array $record): string
    {
        $base = $record['accession'] ?? ('item-' . (int) ($record['id'] ?? 0));
        return preg_replace('/[^A-Za-z0-9:_-]+/', '', (string) $base) ?: 'iwac';
    }

    /** BibTeX author/editor list, " and "-joined; institutions kept in braces. */
    private function nameListBibtex(array $people): string
    {
        $out = [];
        foreach ($people as $p) {
            if (!empty($p['isInstitution']) || ($p['family'] ?? null) === null) {
                $out[] = '{' . $this->bibtexEscape($p['literal']) . '}';
            } elseif (($p['given'] ?? '') !== '') {
                $out[] = $this->bibtexEscape($p['family']) . ', ' . $this->bibtexEscape($p['given']);
            } else {
                $out[] = $this->bibtexEscape($p['family']);
            }
        }
        return implode(' and ', $out);
    }

    private function bibtexEscape(string $text): string
    {
        // Escape the LaTeX specials that would otherwise break a value. Braces
        // are left intact (used deliberately around titles / corporate names).
        return str_replace(
            ['\\', '&', '%', '$', '#', '_', '~', '^'],
            ['\\textbackslash{}', '\\&', '\\%', '\\$', '\\#', '\\_', '\\textasciitilde{}', '\\textasciicircum{}'],
            $text
        );
    }

    // ─── RIS ─────────────────────────────────────────────────────────────────

    private function ris(array $record): string
    {
        $eol = "\r\n";
        $lines = [];
        $lines[] = $this->risLine('TY', self::RIS_TYPE[$record['kind']] ?? 'GEN');

        foreach ($record['authors'] ?? [] as $p) {
            $lines[] = $this->risLine('AU', $this->nameRis($p));
        }
        foreach ($record['editors'] ?? [] as $p) {
            $lines[] = $this->risLine('ED', $this->nameRis($p));
        }
        $lines[] = $this->risLine('TI', $record['title']);

        // Container: T2 is the generic secondary/container title (journal, book,
        // newspaper, blog) that Zotero/EndNote map correctly for every type.
        $container = $record['container'] ?? ($record['bookTitle'] ?? null);
        $lines[] = $this->risLine('T2', $container);
        $lines[] = $this->risLine('PB', $record['publisher']);

        if (($record['issued']['year'] ?? null)) {
            $lines[] = $this->risLine('PY', (string) $record['issued']['year']);
            $lines[] = $this->risLine('DA', $this->risDate($record['issued']));
        }
        $lines[] = $this->risLine('VL', $record['volume']);
        $lines[] = $this->risLine('IS', $record['issue']);
        $lines[] = $this->risLine('SP', $record['pageFirst']);
        $lines[] = $this->risLine('EP', $record['pageLast']);
        $lines[] = $this->risLine('DO', $record['doi']);
        $lines[] = $this->risLine('UR', $record['url']);
        $lines[] = $this->risLine('LA', $record['language']);
        foreach ($record['keywords'] ?? [] as $kw) {
            $lines[] = $this->risLine('KW', $kw);
        }
        if (!empty($record['abstract'])) {
            $lines[] = $this->risLine('AB', $record['abstract']);
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

    private function nameRis(array $p): string
    {
        if (!empty($p['isInstitution']) || ($p['family'] ?? null) === null) {
            return (string) $p['literal'];
        }
        return ($p['given'] ?? '') !== '' ? $p['family'] . ', ' . $p['given'] : (string) $p['family'];
    }

    private function risDate(array $issued): ?string
    {
        if (!($issued['year'] ?? null)) {
            return null;
        }
        // RIS DA is YYYY/MM/DD/ (trailing parts optional, slashes kept).
        $out = sprintf('%04d', $issued['year']);
        if ($issued['month'] ?? null) {
            $out .= sprintf('/%02d', $issued['month']);
            if ($issued['day'] ?? null) {
                $out .= sprintf('/%02d', $issued['day']);
            }
        }
        return $out;
    }

    // ─── CSL-JSON ────────────────────────────────────────────────────────────

    private function cslJson(array $record): string
    {
        $item = [
            'id'   => $record['accession'] ?: ('iwac-' . (int) $record['id']),
            'type' => $record['cslType'] ?? 'document',
        ];
        if ($record['title']) {
            $item['title'] = $record['title'];
        }
        $authors = $this->nameListCsl($record['authors'] ?? []);
        if ($authors) {
            $item['author'] = $authors;
        }
        $editors = $this->nameListCsl($record['editors'] ?? []);
        if ($editors) {
            $item['editor'] = $editors;
        }

        $container = $record['container'] ?? ($record['bookTitle'] ?? null);
        if ($container) {
            $item['container-title'] = $container;
        }
        if ($record['publisher']) {
            $item['publisher'] = $record['publisher'];
        }
        if ($record['volume']) {
            $item['volume'] = (string) $record['volume'];
        }
        if ($record['issue']) {
            $item['issue'] = (string) $record['issue'];
        }
        $pages = CitationData::pageRange($record);
        if ($pages !== null) {
            $item['page'] = $pages;
        }
        $dateParts = $this->cslDateParts($record['issued']);
        if ($dateParts) {
            $item['issued'] = ['date-parts' => [$dateParts]];
        }
        if ($record['doi']) {
            $item['DOI'] = $record['doi'];
        }
        if ($record['url']) {
            $item['URL'] = $record['url'];
        }
        if ($record['language']) {
            $item['language'] = $record['language'];
        }
        if (!empty($record['abstract'])) {
            $item['abstract'] = $record['abstract'];
        }
        if (!empty($record['keywords'])) {
            $item['keyword'] = implode(', ', $record['keywords']);
        }

        // CSL-JSON is an array of item objects.
        return json_encode(
            [$item],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ) . "\n";
    }

    /** @return array<int,array<string,string>> */
    private function nameListCsl(array $people): array
    {
        $out = [];
        foreach ($people as $p) {
            if (!empty($p['isInstitution']) || ($p['family'] ?? null) === null) {
                $out[] = ['literal' => (string) $p['literal']];
            } else {
                $name = ['family' => (string) $p['family']];
                if (($p['given'] ?? '') !== '') {
                    $name['given'] = (string) $p['given'];
                }
                $out[] = $name;
            }
        }
        return $out;
    }

    /** @return array<int,int> date-parts [year(,month(,day))] or [] */
    private function cslDateParts(array $issued): array
    {
        if (!($issued['year'] ?? null)) {
            return [];
        }
        $parts = [(int) $issued['year']];
        if ($issued['month'] ?? null) {
            $parts[] = (int) $issued['month'];
            if ($issued['day'] ?? null) {
                $parts[] = (int) $issued['day'];
            }
        }
        return $parts;
    }

    private function addField(array &$fields, string $name, ?string $value): void
    {
        if ($value !== null && $value !== '') {
            $fields[$name] = $this->bibtexEscape($value);
        }
    }
}
