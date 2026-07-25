<?php
declare(strict_types=1);

namespace IwacSeo\Test\Service\Citation;

use IwacSeo\Service\Citation\CitationRecord;
use IwacSeo\Service\Citation\Creator;
use IwacSeo\Service\Citation\IssuedDate;
use IwacSeo\Service\CitationKind;
use PHPUnit\Framework\TestCase;

final class CitationRecordTest extends TestCase
{
    public function testPageRange(): void
    {
        $this->assertSame('185-209', CitationRecord::joinPages('185', '209'));
        $this->assertSame('185', CitationRecord::joinPages('185', null));
        $this->assertSame('209', CitationRecord::joinPages(null, '209'));
        $this->assertSame('7', CitationRecord::joinPages('7', '7'));
        $this->assertNull(CitationRecord::joinPages(null, null));

        $record = new CitationRecord(id: 1, kind: CitationKind::Article, pageFirst: '3', pageLast: '9');
        $this->assertSame('3-9', $record->pageRange());
    }

    public function testLinkPrefersTheDoi(): void
    {
        $record = new CitationRecord(
            id: 1,
            kind: CitationKind::Article,
            doi: '10.1000/xyz',
            url: 'https://islam.zmo.de/s/westafrica/item/1',
        );
        $this->assertSame('https://doi.org/10.1000/xyz', $record->link());

        $record = new CitationRecord(id: 1, kind: CitationKind::Article, url: 'https://example.org/item/1');
        $this->assertSame('https://example.org/item/1', $record->link());

        $this->assertNull((new CitationRecord(id: 1, kind: CitationKind::Item))->link());
    }

    /**
     * The array form is what the theme's partial reads by name, so its key set
     * is a published contract rather than an internal detail.
     */
    public function testToArrayKeepsTheThemeFacingShape(): void
    {
        $record = new CitationRecord(
            id: 123,
            kind: CitationKind::Newspaper,
            title: 'Islam and the Press',
            authors: [Creator::person('Madore', 'Frédérick', 'Frédérick Madore')],
            issued: new IssuedDate(2018, 12, 7, '2018-12-07'),
            container: 'Fraternité Matin',
            keywords: ['Islam'],
            accession: 'iwac-article-0000123',
        );

        $array = $record->toArray();

        $this->assertSame([
            'id', 'kind', 'cslType', 'title', 'authors', 'editors', 'issued', 'container',
            'publisher', 'bookTitle', 'volume', 'issue', 'pageFirst', 'pageLast', 'doi',
            'url', 'language', 'abstract', 'keywords', 'accession',
        ], array_keys($array));

        // The kind is a plain string and the type is resolved, as before.
        $this->assertSame('newspaper', $array['kind']);
        $this->assertSame('article-newspaper', $array['cslType']);
        $this->assertSame(
            ['family' => 'Madore', 'given' => 'Frédérick', 'literal' => 'Frédérick Madore', 'isInstitution' => false],
            $array['authors'][0]
        );
        $this->assertSame(['year' => 2018, 'month' => 12, 'day' => 7, 'literal' => '2018-12-07'], $array['issued']);
    }

    public function testRoundTripsThroughTheArrayForm(): void
    {
        $original = new CitationRecord(
            id: 7,
            kind: CitationKind::Chapter,
            title: 'A Chapter',
            authors: [Creator::person('Triaud', 'Jean-Louis', 'Jean-Louis Triaud')],
            editors: [Creator::institution('AEEMB')],
            issued: new IssuedDate(2012, null, null, '2012'),
            publisher: 'Karthala',
            bookTitle: 'Le Temps des marabouts',
            pageFirst: '185',
            pageLast: '209',
            keywords: ['Islam', 'Burkina Faso'],
            accession: 'iwac-chapter-1',
        );

        $this->assertEquals($original, CitationRecord::fromArray($original->toArray()));
    }

    public function testFromArrayDegradesAnUnknownKind(): void
    {
        $record = CitationRecord::fromArray(['id' => 1, 'kind' => 'newspapper']);
        $this->assertSame(CitationKind::Item, $record->kind);
    }
}
