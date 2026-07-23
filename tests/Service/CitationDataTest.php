<?php
declare(strict_types=1);

namespace IwacSeo\Test\Service;

use IwacSeo\Service\CitationData;
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
            [36 => 'newspaper', 94 => 'person', 40 => 'book'],
            'item'
        );
    }

    public function testKindDispatchAndDefault(): void
    {
        $this->assertSame('newspaper', $this->data->kind(36));
        $this->assertSame('item', $this->data->kind(999));
        $this->assertSame('item', $this->data->kind(null));
    }

    public function testAuthorityRecordsAreNotCitable(): void
    {
        $this->assertFalse($this->data->isCitable(94));  // person
        $this->assertTrue($this->data->isCitable(36));   // newspaper
        $this->assertTrue($this->data->isCitable(null)); // default kind
    }

    public function testPageRange(): void
    {
        $this->assertSame('185-209', CitationData::pageRange(['pageFirst' => '185', 'pageLast' => '209']));
        $this->assertSame('185', CitationData::pageRange(['pageFirst' => '185', 'pageLast' => null]));
        $this->assertSame('209', CitationData::pageRange(['pageFirst' => null, 'pageLast' => '209']));
        $this->assertSame('7', CitationData::pageRange(['pageFirst' => '7', 'pageLast' => '7']));
        $this->assertNull(CitationData::pageRange(['pageFirst' => null, 'pageLast' => null]));
        $this->assertNull(CitationData::pageRange([]));
    }
}
