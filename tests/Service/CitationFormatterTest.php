<?php
declare(strict_types=1);

namespace IwacSeo\Test\Service;

use IwacSeo\Service\CitationFormatter;
use PHPUnit\Framework\TestCase;

final class CitationFormatterTest extends TestCase
{
    private CitationFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new CitationFormatter();
    }

    /** A record shaped like CitationData::build() output. */
    private function record(array $overrides = []): array
    {
        return array_merge([
            'id'        => 123,
            'kind'      => 'item',
            'cslType'   => 'document',
            'title'     => null,
            'authors'   => [],
            'editors'   => [],
            'issued'    => ['year' => null, 'month' => null, 'day' => null, 'literal' => null],
            'container' => null,
            'publisher' => null,
            'bookTitle' => null,
            'volume'    => null,
            'issue'     => null,
            'pageFirst' => null,
            'pageLast'  => null,
            'doi'       => null,
            'url'       => null,
            'language'  => null,
            'abstract'  => null,
            'keywords'  => [],
            'accession' => null,
        ], $overrides);
    }

    private function person(string $given, string $family): array
    {
        return [
            'family'        => $family,
            'given'         => $given,
            'literal'       => $given . ' ' . $family,
            'isInstitution' => false,
        ];
    }

    private function institution(string $name): array
    {
        return ['family' => null, 'given' => null, 'literal' => $name, 'isInstitution' => true];
    }

    // ─── Chicago ─────────────────────────────────────────────────────────────

    public function testChicagoNewspaperArticleEnglish(): void
    {
        $record = $this->record([
            'kind'      => 'newspaper',
            'title'     => 'Islam and the Press',
            'authors'   => [$this->person('Frédérick', 'Madore')],
            'container' => 'Fraternité Matin',
            'issued'    => ['year' => 2018, 'month' => 12, 'day' => 7, 'literal' => '2018-12-07'],
            'url'       => 'https://islam.zmo.de/s/westafrica/item/123',
        ]);

        $this->assertSame(
            'Madore, Frédérick. “Islam and the Press.” <em>Fraternité Matin</em>, December 7, 2018. '
            . '<a href="https://islam.zmo.de/s/westafrica/item/123">https://islam.zmo.de/s/westafrica/item/123</a>.',
            $this->formatter->format($record, 'chicago', 'en')
        );
    }

    public function testChicagoNewspaperArticleFrenchDate(): void
    {
        $record = $this->record([
            'kind'      => 'newspaper',
            'title'     => 'Titre',
            'container' => 'Le Pays',
            'issued'    => ['year' => 2018, 'month' => 12, 'day' => 7, 'literal' => '2018-12-07'],
        ]);

        $this->assertStringContainsString('7 décembre 2018', $this->formatter->format($record, 'chicago', 'fr'));
    }

    public function testChicagoJournalArticleWithVolumeIssuePages(): void
    {
        $record = $this->record([
            'kind'      => 'article',
            'title'     => 'Muslim Politics',
            'authors'   => [$this->person('Jean-Louis', 'Triaud')],
            'container' => 'Journal of African History',
            'volume'    => '42',
            'issue'     => '3',
            'pageFirst' => '185',
            'pageLast'  => '209',
            'issued'    => ['year' => 2001, 'month' => null, 'day' => null, 'literal' => '2001'],
        ]);

        $this->assertSame(
            'Triaud, Jean-Louis. “Muslim Politics.” <em>Journal of African History</em> 42, no. 3 (2001): 185-209.',
            $this->formatter->format($record, 'chicago', 'en')
        );
    }

    public function testChicagoBook(): void
    {
        $record = $this->record([
            'kind'      => 'book',
            'title'     => 'La construction de l\'islam',
            'authors'   => [$this->person('Frédérick', 'Madore')],
            'publisher' => 'Presses de l\'Université Laval',
            'issued'    => ['year' => 2016, 'month' => null, 'day' => null, 'literal' => '2016'],
        ]);

        $this->assertSame(
            'Madore, Frédérick. <em>La construction de l&#039;islam</em>. Presses de l&#039;Université Laval, 2016.',
            $this->formatter->format($record, 'chicago', 'en')
        );
    }

    public function testChicagoEditedVolumeUsesEditorsWithRoleLabel(): void
    {
        $record = $this->record([
            'kind'    => 'book',
            'title'   => 'Islam in West Africa',
            'editors' => [$this->person('David', 'Robinson'), $this->person('Jean-Louis', 'Triaud')],
            'issued'  => ['year' => 1997, 'month' => null, 'day' => null, 'literal' => '1997'],
        ]);

        $out = $this->formatter->format($record, 'chicago', 'en');
        $this->assertStringStartsWith('Robinson, David, and Jean-Louis Triaud, eds.', $out);
    }

    public function testChicagoChapterCarriesBookAndEditors(): void
    {
        $record = $this->record([
            'kind'      => 'chapter',
            'title'     => 'The Politics of Piety',
            'authors'   => [$this->person('Marie', 'Miran')],
            'editors'   => [$this->person('David', 'Robinson')],
            'bookTitle' => 'Muslim Societies',
            'publisher' => 'Brill',
            'pageFirst' => '55',
            'pageLast'  => '80',
            'issued'    => ['year' => 2005, 'month' => null, 'day' => null, 'literal' => '2005'],
        ]);

        $this->assertSame(
            'Miran, Marie. “The Politics of Piety.” In <em>Muslim Societies</em>, '
            . 'edited by David Robinson, 55-80. Brill, 2005.',
            $this->formatter->format($record, 'chicago', 'en')
        );
    }

    public function testChicagoThesis(): void
    {
        $record = $this->record([
            'kind'      => 'thesis',
            'title'     => 'Islam politique',
            'authors'   => [$this->person('Frédérick', 'Madore')],
            'publisher' => 'Université Laval',
            'issued'    => ['year' => 2018, 'month' => null, 'day' => null, 'literal' => '2018'],
        ]);

        $this->assertSame(
            'Madore, Frédérick. “Islam politique.” PhD diss., Université Laval, 2018.',
            $this->formatter->format($record, 'chicago', 'en')
        );
    }

    // ─── APA ─────────────────────────────────────────────────────────────────

    public function testApaJournalArticle(): void
    {
        $record = $this->record([
            'kind'      => 'article',
            'title'     => 'Muslim Politics',
            'authors'   => [$this->person('Jean-Louis', 'Triaud')],
            'container' => 'Journal of African History',
            'volume'    => '42',
            'issue'     => '3',
            'pageFirst' => '185',
            'pageLast'  => '209',
            'issued'    => ['year' => 2001, 'month' => null, 'day' => null, 'literal' => '2001'],
        ]);

        $this->assertSame(
            'Triaud, J.-L. (2001). Muslim Politics. <em>Journal of African History</em>, <em>42</em>(3), 185-209.',
            $this->formatter->format($record, 'apa', 'en')
        );
    }

    public function testApaNoCreatorPutsTitleFirst(): void
    {
        $record = $this->record([
            'kind'      => 'newspaper',
            'title'     => 'Editorial',
            'container' => 'Le Pays',
            'issued'    => ['year' => 1995, 'month' => 3, 'day' => null, 'literal' => '1995-03'],
        ]);

        $this->assertSame(
            'Editorial. (1995, March). <em>Le Pays</em>.',
            $this->formatter->format($record, 'apa', 'en')
        );
    }

    public function testApaUndatedUsesNd(): void
    {
        $record = $this->record([
            'kind'    => 'book',
            'title'   => 'Untitled Manuscript',
            'authors' => [$this->person('A', 'Author')],
        ]);

        $this->assertStringContainsString('(n.d.).', $this->formatter->format($record, 'apa', 'en'));
    }

    public function testApaChapterWithEditors(): void
    {
        $record = $this->record([
            'kind'      => 'chapter',
            'title'     => 'The Politics of Piety',
            'authors'   => [$this->person('Marie', 'Miran')],
            'editors'   => [$this->person('David', 'Robinson'), $this->person('Jean-Louis', 'Triaud')],
            'bookTitle' => 'Muslim Societies',
            'publisher' => 'Brill',
            'pageFirst' => '55',
            'pageLast'  => '80',
            'issued'    => ['year' => 2005, 'month' => null, 'day' => null, 'literal' => '2005'],
        ]);

        $this->assertSame(
            'Miran, M. (2005). The Politics of Piety. In D. Robinson & J.-L. Triaud (Eds.), '
            . '<em>Muslim Societies</em> (pp. 55-80). Brill.',
            $this->formatter->format($record, 'apa', 'en')
        );
    }

    // ─── MLA ─────────────────────────────────────────────────────────────────

    public function testMlaThreeOrMoreAuthorsUseEtAl(): void
    {
        $record = $this->record([
            'kind'    => 'book',
            'title'   => 'Collective Work',
            'authors' => [
                $this->person('Alpha', 'Aa'),
                $this->person('Bravo', 'Bb'),
                $this->person('Charlie', 'Cc'),
            ],
            'issued'  => ['year' => 2020, 'month' => null, 'day' => null, 'literal' => '2020'],
        ]);

        $out = $this->formatter->format($record, 'mla', 'en');
        $this->assertStringStartsWith('Aa, Alpha, et al.', $out);
        $this->assertStringNotContainsString('Bravo', $out);
    }

    public function testMlaNewspaperDateOrder(): void
    {
        $record = $this->record([
            'kind'      => 'newspaper',
            'title'     => 'Islam and the Press',
            'container' => 'Fraternité Matin',
            'issued'    => ['year' => 2018, 'month' => 12, 'day' => 7, 'literal' => '2018-12-07'],
        ]);

        $this->assertStringContainsString('7 December 2018', $this->formatter->format($record, 'mla', 'en'));
    }

    // ─── Cross-cutting behaviour ─────────────────────────────────────────────

    public function testInstitutionalAuthorIsNeverInverted(): void
    {
        $record = $this->record([
            'kind'      => 'document',
            'title'     => 'Rapport annuel',
            'authors'   => [$this->institution("Association Islamique d'Al Mawadda")],
            'issued'    => ['year' => 1999, 'month' => null, 'day' => null, 'literal' => '1999'],
        ]);

        foreach (CitationFormatter::STYLES as $style) {
            $this->assertStringContainsString(
                'Association Islamique d&#039;Al Mawadda',
                $this->formatter->format($record, $style, 'en'),
                "style: $style"
            );
        }
    }

    public function testMissingTitleFallsBackToUntitledPerLocale(): void
    {
        $record = $this->record(['kind' => 'document']);
        $this->assertStringContainsString('Untitled', $this->formatter->format($record, 'chicago', 'en'));
        $this->assertStringContainsString('Sans titre', $this->formatter->format($record, 'chicago', 'fr'));
    }

    public function testHtmlInFieldsIsEscaped(): void
    {
        $record = $this->record([
            'kind'  => 'book',
            'title' => '<script>alert(1)</script>',
        ]);
        $out = $this->formatter->format($record, 'chicago', 'en');
        $this->assertStringNotContainsString('<script>', $out);
    }

    public function testDoiIsPreferredOverUrlInLinkSegment(): void
    {
        $record = $this->record([
            'kind'  => 'article',
            'title' => 'T',
            'doi'   => '10.1000/xyz',
            'url'   => 'https://example.org/item/1',
        ]);
        $out = $this->formatter->format($record, 'chicago', 'en');
        $this->assertStringContainsString('https://doi.org/10.1000/xyz', $out);
        $this->assertStringNotContainsString('example.org', $out);
    }

    public function testUnknownStyleAndLocaleFallBack(): void
    {
        $record = $this->record(['kind' => 'book', 'title' => 'T']);
        $this->assertSame(
            $this->formatter->format($record, 'chicago', 'en'),
            $this->formatter->format($record, 'nope', 'de')
        );
    }
}
