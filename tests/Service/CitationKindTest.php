<?php
declare(strict_types=1);

namespace IwacSeo\Test\Service;

use IwacSeo\Service\CitationKind;
use IwacSeo\Service\CitationKindMap;
use PHPUnit\Framework\TestCase;

/**
 * The kind vocabulary and its export-type tables. These used to be six const
 * arrays in four classes; the point of the enum is that every kind answers
 * every question, so the completeness assertions below are the real test.
 */
final class CitationKindTest extends TestCase
{
    public function testEveryKindHasAnExportTypeForEveryFormat(): void
    {
        foreach (CitationKind::cases() as $kind) {
            $this->assertNotSame('', $kind->cslType(), $kind->value);
            $this->assertNotSame('', $kind->bibtexType(), $kind->value);
            $this->assertNotSame('', $kind->risType(), $kind->value);
        }
    }

    public function testAuthorityRecordsAreExactlyTheIndexEntities(): void
    {
        $entities = array_values(array_map(
            static fn (CitationKind $k) => $k->value,
            array_filter(CitationKind::cases(), static fn (CitationKind $k) => $k->isAuthorityRecord())
        ));
        $this->assertSame(['person', 'place', 'organization', 'event', 'subject'], $entities);
    }

    public function testPartOfWorksTakeQuotedTitles(): void
    {
        $this->assertTrue(CitationKind::Newspaper->isPartOfWork());
        $this->assertTrue(CitationKind::Chapter->isPartOfWork());
        $this->assertTrue(CitationKind::Article->isPartOfWork());
        // Standalone works.
        $this->assertFalse(CitationKind::Book->isPartOfWork());
        $this->assertFalse(CitationKind::Thesis->isPartOfWork());
        $this->assertFalse(CitationKind::Photo->isPartOfWork());
    }

    /**
     * The Zotero item type is consumed both as z:itemType in the RDF and as a
     * forced DC.type in the meta tags, so it must be a Zotero type id — never a
     * display label — and the two consumers must agree.
     */
    public function testZoteroItemTypes(): void
    {
        $this->assertSame('newspaperArticle', CitationKind::Newspaper->zoteroItemType());
        $this->assertSame('magazineArticle', CitationKind::Magazine->zoteroItemType());
        $this->assertSame('artwork', CitationKind::Photo->zoteroItemType());
        $this->assertSame('blogPost', CitationKind::Post->zoteroItemType());
        $this->assertSame('presentation', CitationKind::Communication->zoteroItemType());
        $this->assertSame('videoRecording', CitationKind::Av->zoteroItemType());
        // Kinds Highwire can express on its own get no override.
        $this->assertNull(CitationKind::Article->zoteroItemType());
        $this->assertNull(CitationKind::Thesis->zoteroItemType());
    }

    public function testMapResolvesClassIdsAndFallsBackOnce(): void
    {
        $map = new CitationKindMap([36 => 'newspaper', 94 => 'person'], 'item');

        $this->assertSame(CitationKind::Newspaper, $map->forClassId(36));
        $this->assertSame(CitationKind::Person, $map->forClassId(94));
        $this->assertSame(CitationKind::Item, $map->forClassId(999));
        $this->assertSame(CitationKind::Item, $map->forClassId(null));
    }

    public function testMapIgnoresUnknownKindNames(): void
    {
        // A config typo must not produce a kind nothing handles.
        $map = new CitationKindMap([36 => 'newspapper'], 'item');
        $this->assertSame(CitationKind::Item, $map->forClassId(36));

        $map = new CitationKindMap([36 => 'newspaper'], 'nonsense');
        $this->assertSame(CitationKind::Item, $map->forClassId(999));
    }

    public function testMapAcceptsStringClassIdsFromConfig(): void
    {
        $map = new CitationKindMap(['36' => 'newspaper'], 'item');
        $this->assertSame(CitationKind::Newspaper, $map->forClassId(36));
    }
}
