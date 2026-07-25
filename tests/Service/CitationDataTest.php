<?php
declare(strict_types=1);

namespace IwacSeo\Test\Service;

use IwacSeo\Service\CitationData;
use IwacSeo\Service\CitationKind;
use IwacSeo\Service\CitationKindMap;
use PHPUnit\Framework\TestCase;

/**
 * CitationData::build() needs live Omeka representations; the parts that are
 * pure — kind dispatch, citability, page ranges — are covered here.
 */
final class CitationDataTest extends TestCase
{
    private CitationData $data;

    protected function setUp(): void
    {
        $this->data = new CitationData(
            new CitationKindMap([36 => 'newspaper', 94 => 'person', 40 => 'book'], 'item')
        );
    }

    public function testKindDispatchAndDefault(): void
    {
        $this->assertSame(CitationKind::Newspaper, $this->data->kind(36));
        $this->assertSame(CitationKind::Item, $this->data->kind(999));
        $this->assertSame(CitationKind::Item, $this->data->kind(null));
    }

    public function testAuthorityRecordsAreNotCitable(): void
    {
        $this->assertFalse($this->data->isCitable(94));  // person
        $this->assertTrue($this->data->isCitable(36));   // newspaper
        $this->assertTrue($this->data->isCitable(null)); // default kind
    }
}
