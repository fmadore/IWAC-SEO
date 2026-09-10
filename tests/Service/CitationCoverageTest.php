<?php
declare(strict_types=1);

namespace IwacSeo\Test\Service;

use IwacSeo\Service\Citation\CitationRecord;
use IwacSeo\Service\Citation\Creator;
use IwacSeo\Service\Citation\IssuedDate;
use IwacSeo\Service\CitationDiagnostics;
use IwacSeo\Service\CitationExport;
use IwacSeo\Service\CitationFormatter;
use IwacSeo\Service\CitationKind;
use PHPUnit\Framework\TestCase;

final class CitationCoverageTest extends TestCase
{
    public function testApaPhotographIncludesMediumAndHoldingCollection(): void
    {
        $record = new CitationRecord(
            1,
            CitationKind::Photo,
            'Mosque in Lomé',
            [Creator::person('Diallo', 'Aminata', 'Aminata Diallo')],
            issued: IssuedDate::parse('2024'),
            accession: 'iwac-photo-1',
            archive: 'Islam West Africa Collection'
        );
        self::assertSame(
            'Diallo, A. (2024). <em>Mosque in Lomé</em> [Photograph]. Islam West Africa Collection, iwac-photo-1.',
            (new CitationFormatter())->format($record, 'apa')
        );
    }

    public function testApaReportNumberAndCorporateAuthor(): void
    {
        $record = new CitationRecord(
            1,
            CitationKind::Report,
            'Annual report',
            [Creator::institution('Association des étudiants')],
            issued: IssuedDate::parse('2024'),
            number: '7'
        );
        self::assertSame(
            'Association des étudiants. (2024). <em>Annual report</em> (no. 7).',
            (new CitationFormatter())->format($record, 'apa')
        );
    }

    public function testPresentationPreservesEventAndDayInExports(): void
    {
        $record = new CitationRecord(
            1,
            CitationKind::Communication,
            'Islam in West Africa',
            issued: IssuedDate::parse('2023-11-09'),
            eventTitle: 'Annual conference',
            eventPlace: 'Bayreuth'
        );
        $export = new CitationExport();
        $csl = json_decode($export->serialize($record, 'csljson'), true, 512, JSON_THROW_ON_ERROR)[0];
        self::assertSame('speech', $csl['type']);
        self::assertSame('Annual conference', $csl['event-title']);
        self::assertSame([[2023, 11, 9]], $csl['issued']['date-parts']);
        self::assertStringStartsWith('@misc{', $export->serialize($record, 'bibtex'));
        self::assertStringContainsString(
            'Annual conference, Bayreuth, November 9, 2023',
            (new CitationFormatter())->format($record, 'chicago')
        );
    }

    public function testThesisDegreeIsNeverInferred(): void
    {
        $unknown = new CitationRecord(1, CitationKind::Thesis, 'Muslim communities', publisher: 'University');
        self::assertContains('degree / thesis type', CitationDiagnostics::missing($unknown));
        self::assertStringStartsWith('@thesis{', (new CitationExport())->serialize($unknown, 'bibtex'));
        self::assertStringNotContainsString('PhD', (new CitationFormatter())->format($unknown, 'chicago'));
        $known = new CitationRecord(2, CitationKind::Thesis, 'Muslim communities', publisher: 'University', genre: 'Doctoral dissertation');
        self::assertStringStartsWith('@phdthesis{', (new CitationExport())->serialize($known, 'bibtex'));
        self::assertStringContainsString('[Doctoral dissertation, University]', (new CitationFormatter())->format($known, 'apa'));
    }

    public function testUnknownDateAndEditionAreLocalised(): void
    {
        $record = new CitationRecord(1, CitationKind::Book, 'Histoire du Sahel', edition: '2');
        self::assertSame('<em>Histoire du Sahel</em> (2e éd.). (s. d.).', (new CitationFormatter())->format($record, 'apa', 'fr'));
    }

    public function testExportEscapingCannotInjectAdditionalRisRecordsOrHtml(): void
    {
        $record = new CitationRecord(
            1,
            CitationKind::Document,
            "Title\nER  -\nTY  - BOOK <script>x</script>",
            url: 'javascript:alert(1)'
        );
        $ris = (new CitationExport())->serialize($record, 'ris');
        self::assertSame(1, preg_match_all('/^TY  - /m', $ris));
        $html = (new CitationFormatter())->format($record, 'apa');
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('<a ', $html);
    }
}
