<?php
declare(strict_types=1);

namespace IwacSeo\Test\Service;

use IwacSeo\Service\Citation\CitationRecord;
use IwacSeo\Service\Citation\IssuedDate;
use IwacSeo\Service\CitationDiagnostics;
use IwacSeo\Service\CitationExport;
use IwacSeo\Service\CitationFormatter;
use IwacSeo\Service\CitationKind;
use IwacSeo\Service\PageCitationData;
use PHPUnit\Framework\TestCase;

/**
 * A static site page (an online exhibit) cited as a web page. Building the
 * record needs a live SitePageRepresentation and is covered by the integration
 * suite; the formatting, the exports and the Authors-field syntax are pure.
 */
final class PageCitationTest extends TestCase
{
    private const URL_EN = 'https://islam.zmo.de/s/westafrica/page/hajj-bf';
    private const URL_FR = 'https://islam.zmo.de/s/afrique_ouest/page/hadj-bf';

    public function testChicagoSetsTheWebsiteRomanThenItsOwnerAndTheDate(): void
    {
        self::assertSame(
            'Madore, Frédérick. “Hajj (pilgrimage to Mecca) in Burkina Faso.” Islam West Africa Collection. '
            . 'Leibniz-Zentrum Moderner Orient, October 6, 2022. ' . $this->link(self::URL_EN) . '.',
            (new CitationFormatter())->format($this->english(), 'chicago', 'en')
        );
    }

    public function testApaItalicisesThePageAndNamesOnlyTheSite(): void
    {
        self::assertSame(
            'Madore, F. (2022, October 6). <em>Hajj (pilgrimage to Mecca) in Burkina Faso</em>. '
            . 'Islam West Africa Collection. ' . $this->link(self::URL_EN),
            (new CitationFormatter())->format($this->english(), 'apa', 'en')
        );
    }

    public function testMlaItalicisesTheWebsiteAsTheContainer(): void
    {
        self::assertSame(
            'Madore, Frédérick. “Hajj (pilgrimage to Mecca) in Burkina Faso.” <em>Islam West Africa Collection</em>, '
            . 'Leibniz-Zentrum Moderner Orient, 6 Oct. 2022. ' . $this->link(self::URL_EN) . '.',
            (new CitationFormatter())->format($this->english(), 'mla', 'en')
        );
    }

    public function testTheFrenchPageCitesItsOwnTitleSiteNameUrlAndDate(): void
    {
        $formatter = new CitationFormatter();
        self::assertSame(
            'Madore, Frédérick. “Hadj au Burkina Faso.” Collection Islam Afrique de l&#039;Ouest. '
            . 'Leibniz-Zentrum Moderner Orient, 6 octobre 2022. ' . $this->link(self::URL_FR) . '.',
            $formatter->format($this->french(), 'chicago', 'fr')
        );
        self::assertSame(
            'Madore, F. (2022, 6 octobre). <em>Hadj au Burkina Faso</em>. '
            . 'Collection Islam Afrique de l&#039;Ouest. ' . $this->link(self::URL_FR),
            $formatter->format($this->french(), 'apa', 'fr')
        );
    }

    public function testWithoutAPublisherChicagoStillDatesThePage(): void
    {
        $record = new CitationRecord(
            1,
            CitationKind::Webpage,
            'About',
            issued: IssuedDate::parse('2024'),
            container: 'Islam West Africa Collection',
        );
        self::assertSame('“About.” Islam West Africa Collection. 2024.', (new CitationFormatter())->format($record, 'chicago'));
    }

    public function testExportsTypeThePageAsAWebPageNamedByItsSlug(): void
    {
        $export = new CitationExport();
        $record = $this->english();

        $bibtex = $export->serialize($record, 'bibtex');
        self::assertStringStartsWith('@online{hajj-bf,', (string) $bibtex);
        self::assertStringContainsString('organization = {Islam West Africa Collection}', (string) $bibtex);
        self::assertStringContainsString('url          = {' . self::URL_EN . '}', (string) $bibtex);

        $ris = (string) $export->serialize($record, 'ris');
        self::assertStringStartsWith("TY  - ELEC\r\n", $ris);
        self::assertStringContainsString("T2  - Islam West Africa Collection\r\n", $ris);
        self::assertStringContainsString("PB  - Leibniz-Zentrum Moderner Orient\r\n", $ris);
        self::assertStringNotContainsString('AN  -', $ris); // a page has no accession number

        $csl = json_decode((string) $export->serialize($record, 'csljson'), true, 512, JSON_THROW_ON_ERROR)[0];
        self::assertSame('hajj-bf', $csl['id']);
        self::assertSame('webpage', $csl['type']);
        self::assertSame('Islam West Africa Collection', $csl['container-title']);
        self::assertSame([[2022, 10, 6]], $csl['issued']['date-parts']);
        self::assertArrayNotHasKey('call-number', $csl);

        self::assertSame('hajj-bf.bib', $export->filename($record, 'bibtex'));
        self::assertSame('hajj-bf.json', $export->filename($record, 'csljson'));
    }

    public function testTheKeyTravelsThroughTheThemeFacingArray(): void
    {
        $array = $this->english()->toArray();
        self::assertSame('webpage', $array['kind']);
        self::assertSame('webpage', $array['cslType']);
        self::assertSame('hajj-bf', $array['key']);
        self::assertSame('hajj-bf', CitationRecord::fromArray($array)->key);
    }

    public function testAPageWithoutAWebsiteNameIsReportedIncomplete(): void
    {
        $record = new CitationRecord(1, CitationKind::Webpage, 'About', issued: IssuedDate::parse('2024'));
        self::assertSame(['container'], CitationDiagnostics::missing($record));
    }

    public function testTheWebpageKindMapsToEveryExportVocabulary(): void
    {
        self::assertTrue(CitationKind::Webpage->isPartOfWork());
        self::assertFalse(CitationKind::Webpage->isAuthorityRecord());
        self::assertSame('online', CitationKind::Webpage->bibtexType());
        self::assertSame('ELEC', CitationKind::Webpage->risType());
        self::assertSame('webpage', CitationKind::Webpage->zoteroItemType());
    }

    public function testAuthorsFieldTakesOneNamePerLineAndBracesForAnInstitution(): void
    {
        $authors = PageCitationData::parseAuthors("Frédérick Madore\r\n\n  Triaud, Jean-Louis \n{Leibniz-Zentrum Moderner Orient}\n{ }");

        self::assertCount(3, $authors);
        self::assertSame(['Madore', 'Frédérick'], [$authors[0]->family, $authors[0]->given]);
        self::assertSame(['Triaud', 'Jean-Louis'], [$authors[1]->family, $authors[1]->given]);
        self::assertTrue($authors[2]->isInstitution);
        self::assertSame('Leibniz-Zentrum Moderner Orient', $authors[2]->literal);
        self::assertSame([], PageCitationData::parseAuthors(" \n "));
    }

    public function testTheDateFieldAcceptsWhatTheCitationCanRead(): void
    {
        foreach (['2022', '2022-10', '2022-10-06'] as $date) {
            self::assertTrue(PageCitationData::isValidDate($date), $date);
        }
        foreach (['6 October 2022', '2022-13-01', 'soon'] as $date) {
            self::assertFalse(PageCitationData::isValidDate($date), $date);
        }
    }

    private function english(): CitationRecord
    {
        return new CitationRecord(
            12,
            CitationKind::Webpage,
            'Hajj (pilgrimage to Mecca) in Burkina Faso',
            PageCitationData::parseAuthors('Frédérick Madore'),
            issued: IssuedDate::parse('2022-10-06'),
            container: 'Islam West Africa Collection',
            publisher: 'Leibniz-Zentrum Moderner Orient',
            url: self::URL_EN,
            language: 'en',
            key: 'hajj-bf',
        );
    }

    private function french(): CitationRecord
    {
        return new CitationRecord(
            13,
            CitationKind::Webpage,
            'Hadj au Burkina Faso',
            PageCitationData::parseAuthors('Frédérick Madore'),
            issued: IssuedDate::parse('2022-10-06'),
            container: "Collection Islam Afrique de l'Ouest",
            publisher: 'Leibniz-Zentrum Moderner Orient',
            url: self::URL_FR,
            language: 'fr',
            key: 'hadj-bf',
        );
    }

    private function link(string $url): string
    {
        return '<a href="' . $url . '">' . $url . '</a>';
    }
}
