<?php
declare(strict_types=1);

namespace IwacSeo\Test\Service;

use IwacSeo\Service\CitationFormatter;
use IwacSeo\Service\Citation\CitationRecord;
use PHPUnit\Framework\TestCase;

final class CitationFormatterTest extends TestCase
{
    private CitationFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new CitationFormatter();
    }

    /** A CitationData::build() record, described as the array form for brevity. */
    /** @param array<string,mixed> $overrides */
    private function record(array $overrides = []): CitationRecord
    {
        return CitationRecord::fromArray(array_merge([
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
        ], $overrides));
    }

    /** @return array<string,mixed> */
    private function person(string $given, string $family): array
    {
        return [
            'family'        => $family,
            'given'         => $given,
            'literal'       => $given . ' ' . $family,
            'isInstitution' => false,
        ];
    }

    /** @return array<string,mixed> */
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
            'Triaud, Jean-Louis. “Muslim Politics.” <em>Journal of African History</em> 42, no. 3 (2001): 185–209.',
            $this->formatter->format($record, 'chicago', 'en')
        );
    }

    private function quotedTitleArticle(string $title): \IwacSeo\Service\Citation\CitationRecord
    {
        return $this->record([
            'kind'      => 'article',
            'title'     => $title,
            'authors'   => [$this->person('Bourahima', 'Diomandé')],
            'container' => 'Mazayu',
            'issue'     => '4',
            'issued'    => ['year' => 2026, 'month' => null, 'day' => null, 'literal' => '2026'],
        ]);
    }

    public function testFrenchQuotesATitleInGuillemetsAndNestsEnglishQuotesInside(): void
    {
        $record = $this->quotedTitleArticle('« Bras ballants » contre « bras croisés » : une relecture (1946 - 1980)');
        $expected = "Diomandé, Bourahima. «\u{00A0}“Bras ballants” contre “bras croisés” : une relecture (1946 - 1980)\u{00A0}». <em>Mazayu</em>";

        $this->assertStringContainsString($expected, $this->formatter->format($record, 'chicago', 'fr'));
        $this->assertStringContainsString($expected, $this->formatter->format($record, 'mla', 'fr'));
        // Straight quotes nest the same way; a final ? stands in for the period.
        $this->assertStringContainsString(
            "«\u{00A0}Le “jihad” ?\u{00A0}» <em>Mazayu</em>",
            $this->formatter->format($this->quotedTitleArticle('Le "jihad" ?'), 'chicago', 'fr')
        );
    }

    public function testGuillemetsInsideAQuotedTitleBecomeSingleQuotesInEnglish(): void
    {
        $record = $this->quotedTitleArticle('« Bras ballants » contre « bras croisés » : une relecture (1946 - 1980)');
        $expected = '“‘Bras ballants’ contre ‘bras croisés’ : une relecture (1946 - 1980).”';

        $this->assertStringContainsString($expected, $this->formatter->format($record, 'chicago', 'en'));
        $this->assertStringContainsString($expected, $this->formatter->format($record, 'mla', 'en'));
        // APA sets an article title plain, so the guillemets stay.
        $this->assertStringContainsString(
            '« Bras ballants » contre « bras croisés » : une relecture (1946 - 1980).',
            $this->formatter->format($record, 'apa', 'en')
        );
    }

    public function testDoubleQuotesInsideAQuotedTitleBecomeSingleQuotes(): void
    {
        $this->assertStringContainsString(
            '“The ‘Ivorian’ Question and ‘Wahhabis.’”',
            $this->formatter->format(
                $this->quotedTitleArticle('The "Ivorian" Question and “Wahhabis”'),
                'chicago',
                'en'
            )
        );
    }

    public function testThePeriodGoesInsideAClosingInnerQuote(): void
    {
        $this->assertStringContainsString(
            '“Le temps du ‘jihad.’”',
            $this->formatter->format($this->quotedTitleArticle('Le temps du « jihad »'), 'chicago', 'en')
        );
        $this->assertStringContainsString(
            '“Penser ‘l’islam.’”',
            $this->formatter->format($this->quotedTitleArticle('Penser « l’islam »'), 'chicago', 'en')
        );
        // A trailing apostrophe is not an inner quote.
        $this->assertStringContainsString(
            '“Les talibés d’aujourd’hui.”',
            $this->formatter->format($this->quotedTitleArticle('Les talibés d’aujourd’hui'), 'chicago', 'en')
        );
    }

    /** Cahiers d'études africaines numbers its issues and carries no volume. */
    private function articleWithoutVolume(): \IwacSeo\Service\Citation\CitationRecord
    {
        return $this->record([
            'kind'      => 'article',
            'title'     => 'Positionnalité',
            'authors'   => [$this->person('Zakaria', 'Soré')],
            'container' => 'Cahiers d’études africaines',
            'issue'     => '264',
            'pageFirst' => '879',
            'pageLast'  => '895',
            'issued'    => ['year' => 2026, 'month' => null, 'day' => null, 'literal' => '2026'],
        ]);
    }

    public function testChicagoJournalArticleWithoutVolumeSetsTheNumberOffByAComma(): void
    {
        $this->assertSame(
            'Soré, Zakaria. “Positionnalité.” <em>Cahiers d’études africaines</em>, no. 264 (2026): 879–895.',
            $this->formatter->format($this->articleWithoutVolume(), 'chicago', 'en')
        );
    }

    public function testApaJournalArticleWithoutVolumeHasNoCommaBeforeTheIssue(): void
    {
        $this->assertSame(
            'Soré, Z. (2026). Positionnalité. <em>Cahiers d’études africaines</em> (264), 879–895.',
            $this->formatter->format($this->articleWithoutVolume(), 'apa', 'en')
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
            . 'edited by David Robinson, 55–80. Brill, 2005.',
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
            'Madore, Frédérick. “Islam politique.” Thesis, Université Laval, 2018.',
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
            'Triaud, J.-L. (2001). Muslim Politics. <em>Journal of African History</em>, <em>42</em>(3), 185–209.',
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
            . '<em>Muslim Societies</em> (pp. 55–80). Brill.',
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

        $this->assertStringContainsString('7 Dec. 2018', $this->formatter->format($record, 'mla', 'en'));
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

    // ─── Dates, pages and whole issues ───────────────────────────────────────

    /** Item 10224's shape: a combined issue over a four-month interval, no volume. */
    private function combinedIssue(): CitationRecord
    {
        return $this->record([
            'kind'      => 'periodical-issue',
            'title'     => 'Al Mawadda #48-49',
            'container' => 'Al Mawadda',
            'issue'     => '48–49',
            'issued'    => [
                'year' => 2009, 'month' => 5, 'day' => null, 'literal' => '2009-05/2009-08',
                'end' => ['year' => 2009, 'month' => 8, 'day' => null, 'literal' => '2009-08'],
            ],
        ]);
    }

    public function testAnIssueTitledAfterItsPeriodicalIsNotRepeated(): void
    {
        // "Al Mawadda #48-49" says nothing the container does not: with no
        // creator, the periodical takes the first slot in every style.
        $issue = $this->combinedIssue();
        $this->assertSame('<em>Al Mawadda</em> (48–49). (2009, May–August).', $this->formatter->format($issue, 'apa', 'en'));
        $this->assertSame('<em>Al Mawadda</em>, no. 48–49, May–August 2009.', $this->formatter->format($issue, 'chicago', 'en'));
        $this->assertSame('<em>Al Mawadda</em>, no. 48–49, May–Aug. 2009.', $this->formatter->format($issue, 'mla', 'en'));
    }

    public function testAnIssueKeepsItsThemeAndCreator(): void
    {
        // Item 11997's shape: the theme in parentheses after "#327" survives.
        $record = $this->record([
            'kind'      => 'periodical-issue',
            'title'     => 'An-Nasr Vendredi #327 (Cohabitation pacifique : le remède de l\'islam)',
            'authors'   => [$this->institution('Niangane')],
            'container' => 'An-Nasr Vendredi',
            'issue'     => '327',
            'issued'    => ['year' => 2010, 'month' => 2, 'day' => 5, 'literal' => '2010-02-05'],
        ]);
        $this->assertSame(
            'Niangane. <em>Cohabitation pacifique : le remède de l&#039;islam</em>. <em>An-Nasr Vendredi</em>, no. 327, February 5, 2010.',
            $this->formatter->format($record, 'chicago', 'en')
        );
        $this->assertSame(
            'Niangane. (2010, February 5). <em>Cohabitation pacifique : le remède de l&#039;islam</em>. <em>An-Nasr Vendredi</em> (327).',
            $this->formatter->format($record, 'apa', 'en')
        );
    }

    public function testAnIssueTitleThatDoesNotRepeatThePeriodicalIsKept(): void
    {
        $record = $this->record([
            'kind' => 'periodical-issue', 'title' => 'Spécial Ramadan',
            'container' => 'Islam Info', 'issue' => '12',
        ]);
        $this->assertStringStartsWith('<em>Spécial Ramadan</em>. <em>Islam Info</em>, no. 12', $this->formatter->format($record, 'chicago', 'en'));
    }

    // ─── Media, presentations, theses, shared authorship ─────────────────────

    /** Item 108854's shape: a YouTube video whose medium is stored in French. */
    private function onlineVideo(): CitationRecord
    {
        return $this->record([
            'kind'      => 'av',
            'title'     => 'Mois de Ramadan',
            'publisher' => 'RTB',
            'medium'    => 'Vidéo sur le web',
            'issued'    => ['year' => 2024, 'month' => 3, 'day' => 25, 'literal' => '2024-03-25'],
        ]);
    }

    public function testAVideoCarriesItsFullDateAndAnUnbracketedMediumInChicagoAndMla(): void
    {
        $this->assertSame('<em>Mois de Ramadan</em>. RTB, March 25, 2024. Online video.', $this->formatter->format($this->onlineVideo(), 'chicago', 'en'));
        $this->assertSame('<em>Mois de Ramadan</em>. RTB, 25 Mar. 2024. Online video.', $this->formatter->format($this->onlineVideo(), 'mla', 'en'));
        $this->assertSame('<em>Mois de Ramadan</em>. RTB, 25 mars 2024. Vidéo sur le web.', $this->formatter->format($this->onlineVideo(), 'mla', 'fr'));
    }

    public function testTheMediumIsTranslatedForTheEnglishSiteOnly(): void
    {
        $this->assertStringContainsString('[Online video]', $this->formatter->format($this->onlineVideo(), 'apa', 'en'));
        $this->assertStringContainsString('[Vidéo sur le web]', $this->formatter->format($this->onlineVideo(), 'apa', 'fr'));
    }

    public function testAPhotographsMediumIsUnbracketedInChicago(): void
    {
        $record = $this->record([
            'kind' => 'photo', 'title' => 'Radio Ridwane', 'authors' => [$this->person('Frédérick', 'Madore')],
            'issued' => ['year' => 2015, 'month' => null, 'day' => null, 'literal' => '2015'],
        ]);
        $this->assertSame('Madore, Frédérick. <em>Radio Ridwane</em>. 2015. Photograph.', $this->formatter->format($record, 'chicago', 'en'));
    }

    /** Item 12805's shape. */
    private function presentation(?string $event = 'Lancement de la Collection'): CitationRecord
    {
        return $this->record([
            'kind'       => 'communication',
            'title'      => 'Digitalisation des données',
            'authors'    => [$this->person('Issouf', 'Binaté')],
            'container'  => 'Chaire Islam contemporain',
            'eventTitle' => $event,
            'eventPlace' => 'Côte d\'Ivoire',
            'issued'     => ['year' => 2023, 'month' => 11, 'day' => 9, 'literal' => '2023-11-09'],
        ]);
    }

    public function testMlaPresentationReadsEventDatePlaceThenPresentation(): void
    {
        $this->assertSame(
            'Binaté, Issouf. “Digitalisation des données.” Lancement de la Collection, 9 Nov. 2023, Côte d&#039;Ivoire. Presentation.',
            $this->formatter->format($this->presentation(), 'mla', 'en')
        );
        $this->assertStringEndsWith('Côte d&#039;Ivoire. Communication.', $this->formatter->format($this->presentation(), 'mla', 'fr'));
    }

    public function testMlaPresentationWithNoEventNamesItsHost(): void
    {
        $this->assertSame(
            'Binaté, Issouf. “Digitalisation des données.” Chaire Islam contemporain, 9 Nov. 2023, Côte d&#039;Ivoire. Presentation.',
            $this->formatter->format($this->presentation(null), 'mla', 'en')
        );
    }

    public function testMlaThesisEndsWithItsDegree(): void
    {
        $record = $this->record([
            'kind' => 'thesis', 'title' => 'Rivalités', 'authors' => [$this->person('Frédérick', 'Madore')],
            'publisher' => 'Université Laval', 'genre' => 'Thèse de doctorat',
            'issued' => ['year' => 2018, 'month' => null, 'day' => null, 'literal' => '2018'],
        ]);
        $this->assertSame('Madore, Frédérick. <em>Rivalités</em>. Université Laval, 2018. Thèse de doctorat.', $this->formatter->format($record, 'mla', 'en'));
        $this->assertStringEndsWith('Université Laval, 2018. Thesis.', $this->formatter->format($this->record([
            'kind' => 'thesis', 'title' => 'X', 'publisher' => 'Université Laval',
            'issued' => ['year' => 2018, 'month' => null, 'day' => null, 'literal' => '2018'],
        ]), 'mla', 'en'));
    }

    /** A chapter in a book with three editors, the first beginning with a vowel. */
    private function chapterWithThreeEditors(): CitationRecord
    {
        return $this->record([
            'kind'      => 'chapter',
            'title'     => 'Le religieux',
            'authors'   => [$this->person('Maud', 'Saint-Lary')],
            'editors'   => [$this->person('Alice', 'Degorce'), $this->person('Ludovic O.', 'Kibora'), $this->person('Katrin', 'Langewiesche')],
            'bookTitle' => 'Rencontres religieuses',
            'publisher' => 'Amalion',
            'issued'    => ['year' => 2019, 'month' => null, 'day' => null, 'literal' => '2019'],
        ]);
    }

    public function testMlaEtAlTakesNoCommaAfterANaturalOrderName(): void
    {
        $this->assertStringContainsString('edited by Alice Degorce et al., Amalion', $this->formatter->format($this->chapterWithThreeEditors(), 'mla', 'en'));
        // An inverted first name keeps it.
        $book = $this->record([
            'kind' => 'book', 'title' => 'Rencontres religieuses', 'editors' => $this->chapterWithThreeEditors()->toArray()['editors'],
        ]);
        $this->assertStringStartsWith('Degorce, Alice, et al., editors.', $this->formatter->format($book, 'mla', 'en'));
    }

    public function testFrenchEditorPhraseElidesBeforeAVowel(): void
    {
        $chapter = $this->chapterWithThreeEditors();
        $this->assertStringContainsString('sous la dir. d’Alice Degorce et al.', $this->formatter->format($chapter, 'mla', 'fr'));
        $this->assertStringContainsString('sous la dir. d’Alice Degorce, Ludovic O. Kibora, et Katrin Langewiesche', $this->formatter->format($chapter, 'chicago', 'fr'));
        $other = $this->record(array_merge($chapter->toArray(), ['editors' => [$this->person('Gilles', 'Holder')]]));
        $this->assertStringContainsString('sous la dir. de Gilles Holder', $this->formatter->format($other, 'chicago', 'fr'));
    }

    /** Item 15761's shape: the Crisis Group is both author and publisher. */
    private function selfPublishedReport(): CitationRecord
    {
        return $this->record([
            'kind'      => 'report',
            'title'     => 'Keeping Jihadists Out',
            'authors'   => [$this->institution('International Crisis Group')],
            'publisher' => 'International Crisis Group',
            'issued'    => ['year' => 2023, 'month' => null, 'day' => null, 'literal' => '2023'],
        ]);
    }

    public function testAnAuthorWhoIsAlsoThePublisherIsNamedOnce(): void
    {
        $this->assertSame('International Crisis Group. (2023). <em>Keeping Jihadists Out</em>.', $this->formatter->format($this->selfPublishedReport(), 'apa', 'en'));
        $this->assertSame('<em>Keeping Jihadists Out</em>. International Crisis Group, 2023.', $this->formatter->format($this->selfPublishedReport(), 'mla', 'en'));
        // CMOS 14.84 lists the organisation as author even when it publishes.
        $this->assertSame('International Crisis Group. <em>Keeping Jihadists Out</em>. International Crisis Group, 2023.', $this->formatter->format($this->selfPublishedReport(), 'chicago', 'en'));
    }

    public function testDateIntervalsReadAsDatesInEveryStyle(): void
    {
        $issue = $this->combinedIssue();
        $this->assertStringContainsString('<em>Al Mawadda</em>, no. 48–49, May–August 2009.', $this->formatter->format($issue, 'chicago', 'en'));
        $this->assertStringContainsString('<em>Al Mawadda</em>, n° 48–49, mai–août 2009.', $this->formatter->format($issue, 'chicago', 'fr'));
        $this->assertStringContainsString('(2009, mai–août).', $this->formatter->format($issue, 'apa', 'fr'));
        // MLA: number before date, English months abbreviated.
        $this->assertStringContainsString('<em>Al Mawadda</em>, no. 48–49, May–Aug. 2009.', $this->formatter->format($issue, 'mla', 'en'));
        foreach (CitationFormatter::STYLES as $style) {
            $this->assertStringNotContainsString('2009-05/2009-08', $this->formatter->format($issue, $style, 'en'));
        }
    }

    public function testDayIntervalsStateSharedPartsOnce(): void
    {
        $range = static fn (string $from, string $to): array => [
            'year' => (int) substr($from, 0, 4), 'month' => (int) substr($from, 5, 2), 'day' => (int) substr($from, 8, 2),
            'literal' => $from . '/' . $to,
            'end' => ['year' => (int) substr($to, 0, 4), 'month' => (int) substr($to, 5, 2), 'day' => (int) substr($to, 8, 2)],
        ];
        $talk = fn (array $issued): CitationRecord => $this->record(['kind' => 'post', 'title' => 'T', 'container' => 'Blog', 'issued' => $issued]);

        $sameMonth = $talk($range('2019-05-08', '2019-05-10'));
        $this->assertStringContainsString('May 8–10, 2019', $this->formatter->format($sameMonth, 'chicago', 'en'));
        $this->assertStringContainsString('8–10 mai 2019', $this->formatter->format($sameMonth, 'chicago', 'fr'));
        $this->assertStringContainsString('(2019, May 8–10)', $this->formatter->format($sameMonth, 'apa', 'en'));
        $this->assertStringContainsString('8–10 May 2019', $this->formatter->format($sameMonth, 'mla', 'en'));

        $acrossMonths = $talk($range('2019-05-30', '2019-06-02'));
        $this->assertStringContainsString('May 30–June 2, 2019', $this->formatter->format($acrossMonths, 'chicago', 'en'));
        $this->assertStringContainsString('(2019, 30 mai–2 juin)', $this->formatter->format($acrossMonths, 'apa', 'fr'));

        $acrossYears = $talk($range('2018-12-30', '2019-01-02'));
        $this->assertStringContainsString('December 30, 2018–January 2, 2019', $this->formatter->format($acrossYears, 'chicago', 'en'));
        $this->assertStringContainsString('(2018, December 30–2019, January 2)', $this->formatter->format($acrossYears, 'apa', 'en'));
    }

    public function testYearIntervalOnAStandaloneWorkIsAYearSpan(): void
    {
        $book = $this->record([
            'kind'   => 'book',
            'title'  => 'T',
            'issued' => ['year' => 2000, 'literal' => '2000/2001', 'end' => ['year' => 2001]],
        ]);
        $this->assertSame('<em>T</em>. (2000–2001).', $this->formatter->format($book, 'apa', 'en'));
        $this->assertSame('<em>T</em>. 2000–2001.', $this->formatter->format($book, 'chicago', 'en'));
    }

    public function testApaDatePutsTheDayBeforeTheMonthInFrench(): void
    {
        $record = $this->record([
            'kind'      => 'newspaper',
            'title'     => 'Titre',
            'container' => 'Sidwaya',
            'issued'    => ['year' => 2018, 'month' => 12, 'day' => 7, 'literal' => '2018-12-07'],
        ]);
        $this->assertStringContainsString('(2018, 7 décembre).', $this->formatter->format($record, 'apa', 'fr'));
        $this->assertStringContainsString('(2018, December 7).', $this->formatter->format($record, 'apa', 'en'));
    }

    public function testMlaUsesPForASinglePageAndPpForARange(): void
    {
        $single = $this->record(['kind' => 'newspaper', 'title' => 'T', 'container' => 'Le Pays', 'pageFirst' => '4']);
        $this->assertStringContainsString('<em>Le Pays</em>, p. 4.', $this->formatter->format($single, 'mla', 'en'));
        $range = $this->record(['kind' => 'newspaper', 'title' => 'T', 'container' => 'Le Pays', 'pageFirst' => '4', 'pageLast' => '5']);
        $this->assertStringContainsString('<em>Le Pays</em>, pp. 4–5.', $this->formatter->format($range, 'mla', 'en'));
    }

    public function testASinglePageDesignatorKeepsItsOwnHyphen(): void
    {
        $record = $this->record(['kind' => 'newspaper', 'title' => 'T', 'container' => 'Le Pays', 'pageFirst' => 'A-12']);
        $this->assertStringContainsString('A-12', $this->formatter->format($record, 'apa', 'en'));
    }
}
