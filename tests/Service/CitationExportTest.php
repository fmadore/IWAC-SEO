<?php
declare(strict_types=1);

namespace IwacSeo\Test\Service;

use IwacSeo\Service\CitationExport;
use IwacSeo\Service\Citation\CitationRecord;
use PHPUnit\Framework\TestCase;

final class CitationExportTest extends TestCase
{
    private CitationExport $export;

    protected function setUp(): void
    {
        $this->export = new CitationExport();
    }

    private function record(array $overrides = []): CitationRecord
    {
        return CitationRecord::fromArray(array_merge([
            'id'        => 123,
            'kind'      => 'newspaper',
            'cslType'   => 'article-newspaper',
            'title'     => 'Islam and the Press',
            'authors'   => [
                ['family' => 'Madore', 'given' => 'Frédérick', 'literal' => 'Frédérick Madore', 'isInstitution' => false],
            ],
            'editors'   => [],
            'issued'    => ['year' => 2018, 'month' => 12, 'day' => 7, 'literal' => '2018-12-07'],
            'container' => 'Fraternité Matin',
            'publisher' => null,
            'bookTitle' => null,
            'volume'    => null,
            'issue'     => '1234',
            'pageFirst' => '3',
            'pageLast'  => '4',
            'doi'       => null,
            'url'       => 'https://islam.zmo.de/s/afrique_ouest/item/123',
            'language'  => 'Français',
            'abstract'  => 'Un résumé.',
            'keywords'  => ['Islam', 'Côte d\'Ivoire'],
            'accession' => 'iwac-article-0000123',
        ], $overrides));
    }

    public function testUnknownFormatReturnsNull(): void
    {
        $this->assertNull($this->export->serialize($this->record(), 'endnote'));
    }

    // ─── BibTeX ──────────────────────────────────────────────────────────────

    public function testBibtexNewspaperArticle(): void
    {
        $bib = $this->export->serialize($this->record(), 'bibtex');

        $this->assertStringStartsWith('@article{iwac-article-0000123,', $bib);
        $this->assertStringContainsString('author', $bib);
        $this->assertStringContainsString('Madore, Frédérick', $bib);
        $this->assertStringContainsString('{Islam and the Press}', $bib);
        $this->assertStringContainsString('journal', $bib);
        // Pages use the TeX en-dash convention.
        $this->assertStringContainsString('3--4', $bib);
        $this->assertMatchesRegularExpression('/year\s*= \{2018\}/', $bib);
    }

    public function testBibtexUrlIsNotLatexEscaped(): void
    {
        $bib = $this->export->serialize($this->record(), 'bibtex');
        // The site slug carries an underscore biber must read literally.
        $this->assertStringContainsString('https://islam.zmo.de/s/afrique_ouest/item/123', $bib);
        $this->assertStringNotContainsString('afrique\_ouest', $bib);
    }

    public function testBibtexEscapesLatexSpecials(): void
    {
        $bib = $this->export->serialize(
            $this->record(['title' => 'Salt & Gold: 100% _profit_ #1', 'url' => null]),
            'bibtex'
        );
        $this->assertStringContainsString('Salt \\& Gold: 100\\% \\_profit\\_ \\#1', $bib);
    }

    public function testBibtexInstitutionalAuthorIsBraced(): void
    {
        $bib = $this->export->serialize($this->record([
            'authors' => [['family' => null, 'given' => null, 'literal' => 'AEEMB', 'isInstitution' => true]],
        ]), 'bibtex');
        $this->assertStringContainsString('{AEEMB}', $bib);
    }

    public function testBibtexTypeMapping(): void
    {
        $this->assertStringStartsWith(
            '@incollection{',
            $this->export->serialize($this->record(['kind' => 'chapter']), 'bibtex')
        );
        $this->assertStringStartsWith(
            '@thesis{',
            $this->export->serialize($this->record(['kind' => 'thesis']), 'bibtex')
        );
        $this->assertStringStartsWith(
            '@misc{',
            $this->export->serialize($this->record(['kind' => 'photo']), 'bibtex')
        );
    }

    // ─── RIS ─────────────────────────────────────────────────────────────────

    public function testRisNewspaperArticle(): void
    {
        $ris = $this->export->serialize($this->record(), 'ris');

        $this->assertStringEndsWith("\r\n", $ris);
        $lines = explode("\r\n", substr($ris, 0, -2)); // drop the final CRLF only
        $this->assertSame('TY  - NEWS', $lines[0]);
        $this->assertContains('AU  - Madore, Frédérick', $lines);
        $this->assertContains('TI  - Islam and the Press', $lines);
        $this->assertContains('T2  - Fraternité Matin', $lines);
        $this->assertContains('PY  - 2018', $lines);
        $this->assertContains('DA  - 2018/12/07', $lines);
        $this->assertContains('SP  - 3', $lines);
        $this->assertContains('EP  - 4', $lines);
        $this->assertContains('KW  - Islam', $lines);
        $this->assertSame('ER  - ', end($lines));
    }

    public function testRisCollapsesNewlinesInValues(): void
    {
        $ris = $this->export->serialize(
            $this->record(['abstract' => "line one\nline two"]),
            'ris'
        );
        $this->assertStringContainsString('AB  - line one line two', $ris);
    }

    // ─── CSL-JSON ────────────────────────────────────────────────────────────

    public function testCslJsonShape(): void
    {
        $json = $this->export->serialize($this->record(), 'csljson');
        $items = json_decode($json, true);

        $this->assertIsArray($items);
        $this->assertCount(1, $items);
        $item = $items[0];

        $this->assertSame('iwac-article-0000123', $item['id']);
        $this->assertSame('article-newspaper', $item['type']);
        $this->assertSame('Islam and the Press', $item['title']);
        $this->assertSame([['family' => 'Madore', 'given' => 'Frédérick']], $item['author']);
        $this->assertSame('Fraternité Matin', $item['container-title']);
        $this->assertSame([[2018, 12, 7]], $item['issued']['date-parts']);
        $this->assertSame('3-4', $item['page']);
    }

    public function testCslJsonInstitutionUsesLiteralName(): void
    {
        $json = $this->export->serialize($this->record([
            'authors' => [['family' => null, 'given' => null, 'literal' => 'AEEMB', 'isInstitution' => true]],
        ]), 'csljson');
        $item = json_decode($json, true)[0];
        $this->assertSame([['literal' => 'AEEMB']], $item['author']);
    }

    // ─── Filenames ───────────────────────────────────────────────────────────

    public function testFilenameUsesAccessionAndExtension(): void
    {
        $this->assertSame('iwac-article-0000123.bib', $this->export->filename($this->record(), 'bibtex'));
        $this->assertSame('iwac-article-0000123.ris', $this->export->filename($this->record(), 'ris'));
        $this->assertSame('iwac-article-0000123.json', $this->export->filename($this->record(), 'csljson'));
    }

    public function testFilenameFallsBackToItemIdAndIsSanitised(): void
    {
        $record = $this->record(['accession' => null]);
        $this->assertSame('item-123.bib', $this->export->filename($record, 'bibtex'));

        $record = $this->record(['accession' => 'evil/../name "x"']);
        $filename = $this->export->filename($record, 'bibtex');
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9._-]+$/', $filename);
        $this->assertStringNotContainsString('/', $filename);
    }

    // ─── biblatex semantics and archive fields ───────────────────────────────

    public function testBibtexNewspaperArticleIsMarkedAsNonAcademic(): void
    {
        $this->assertStringContainsString('entrysubtype = {newspaper}', $this->export->serialize($this->record(), 'bibtex'));
    }

    public function testBibtexWholeIssueNamesThePeriodicalAsItsTitle(): void
    {
        $bib = $this->export->serialize($this->record([
            'kind'      => 'periodical-issue',
            'title'     => 'Al Mawadda #48-49',
            'container' => 'Al Mawadda',
            'issue'     => '48–49',
        ]), 'bibtex');
        $this->assertStringStartsWith('@periodical{', $bib);
        $this->assertMatchesRegularExpression('/^  title\s+= \{\{Al Mawadda\}\},$/m', $bib);
        $this->assertStringContainsString("  issuetitle = {Al Mawadda \\#48-49},\n", $bib);
        $this->assertStringNotContainsString('journal', $bib);
    }

    public function testBibtexMiscKeepsPublisherAndSourceUrlWithoutAPageUrl(): void
    {
        $bib = $this->export->serialize($this->record([
            'kind'      => 'av',
            'container' => null,
            'publisher' => 'RTB - Radiodiffusion Télévision du Burkina',
            'url'       => null,
            'sourceUrl' => 'https://www.youtube.com/watch?v=abcdefghijk',
        ]), 'bibtex');
        $this->assertStringStartsWith('@misc{', $bib);
        $this->assertMatchesRegularExpression('/^  howpublished = \{RTB - Radiodiffusion Télévision du Burkina\},$/m', $bib);
        $this->assertMatchesRegularExpression('/^  url\s+= \{https:\/\/www\.youtube\.com\/watch\?v=abcdefghijk\},$/m', $bib);
    }

    public function testBibtexLanguageUsesBabelNamesForLangidAndLanguage(): void
    {
        $bib = $this->export->serialize($this->record(['language' => 'fr']), 'bibtex');
        $this->assertMatchesRegularExpression('/^  langid\s+= \{french\},$/m', $bib);
        $this->assertMatchesRegularExpression('/^  language\s+= \{french\},$/m', $bib);
        // A language without a babel name stays a literal, with no langid.
        $other = $this->export->serialize($this->record(['language' => 'Mooré']), 'bibtex');
        $this->assertStringNotContainsString('langid', $other);
        $this->assertMatchesRegularExpression('/^  language\s+= \{Mooré\},$/m', $other);
    }

    public function testRisAndCslCarryTheAccessionAsCallNumberAndArchiveLocation(): void
    {
        $record = $this->record(['archive' => 'Islam West Africa Collection']);
        $ris = $this->export->serialize($record, 'ris');
        $this->assertStringContainsString("CN  - iwac-article-0000123\r\n", $ris);
        $this->assertStringContainsString("AN  - iwac-article-0000123\r\n", $ris);
        $this->assertStringContainsString("DB  - Islam West Africa Collection\r\n", $ris);

        $csl = json_decode($this->export->serialize($record, 'csljson'), true, 512, JSON_THROW_ON_ERROR)[0];
        $this->assertSame('iwac-article-0000123', $csl['call-number']);
        $this->assertSame('iwac-article-0000123', $csl['archive_location']);
        $this->assertSame('Islam West Africa Collection', $csl['archive']);
    }
}
