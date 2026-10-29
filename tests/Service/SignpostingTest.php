<?php
declare(strict_types=1);

namespace IwacSeo\Test\Service;

use IwacSeo\Service\Signposting;
use PHPUnit\Framework\TestCase;

/**
 * The Signposting `Link` header: RFC 8288 syntax, always a valid header value,
 * and never so long that nginx refuses the response. The link set itself
 * needs Omeka's representations and is tested in tests/Integration.
 */
final class SignpostingTest extends TestCase
{
    public function testTheHeaderCarriesTheLinksInRfc8288SyntaxAndIsAlwaysAValidHeaderValue(): void
    {
        $header = Signposting::header([
            ['rel' => 'cite-as', 'href' => 'https://islam.zmo.de/s/westafrica/item/42'],
            ['rel' => 'describedby', 'href' => 'https://islam.zmo.de/cite/42/ris?site=westafrica', 'type' => 'application/x-research-info-systems'],
            ['rel' => 'item', 'href' => 'https://islam.zmo.de/files/original/Fraternité <1>.pdf', 'type' => 'application/pdf'],
        ]);

        $this->assertSame(
            '<https://islam.zmo.de/s/westafrica/item/42>; rel="cite-as", '
                . '<https://islam.zmo.de/cite/42/ris?site=westafrica>; rel="describedby"; type="application/x-research-info-systems", '
                . '<https://islam.zmo.de/files/original/Fraternit%C3%A9%20%3C1%3E.pdf>; rel="item"; type="application/pdf"',
            $header
        );
        $this->assertMatchesRegularExpression('/\A[\x20-\x7E]+\z/', $header, 'printable ASCII only');
    }

    public function testRelationAndMediaTypesCannotBreakOutOfTheirQuotes(): void
    {
        $header = Signposting::header([
            ['rel' => 'item"; rel="evil', 'href' => 'https://islam.zmo.de/x', 'type' => "text/plain\r\nSet-Cookie: a=b"],
        ]);

        $this->assertSame('<https://islam.zmo.de/x>; rel="itemrelevil"; type="text/plainSet-Cookieab"', $header);
    }

    public function testNoLinksIsNoHeader(): void
    {
        $this->assertSame('', Signposting::header([]));
    }

    public function testLinksBeyondTheBudgetAreLeftOutAndShorterOnesStillFit(): void
    {
        $links = [
            ['rel' => 'cite-as', 'href' => 'https://islam.zmo.de/s/westafrica/item/42'],
            ['rel' => 'item', 'href' => 'https://islam.zmo.de/files/original/' . str_repeat('a', 200) . '.pdf', 'type' => 'application/pdf'],
            ['rel' => 'collection', 'href' => 'https://islam.zmo.de/s/westafrica/item-set/7'],
        ];

        $header = Signposting::header($links, 140);

        $this->assertSame(
            '<https://islam.zmo.de/s/westafrica/item/42>; rel="cite-as", <https://islam.zmo.de/s/westafrica/item-set/7>; rel="collection"',
            $header
        );
        $this->assertLessThanOrEqual(140, strlen($header));
    }

    public function testTheDefaultBudgetHoldsAFullRecordWellInsideNginxsBuffer(): void
    {
        $links = [['rel' => 'cite-as', 'href' => 'https://islam.zmo.de/s/westafrica/item/42']];
        for ($i = 0; $i < 60; $i++) {
            $links[] = ['rel' => 'item', 'href' => 'https://islam.zmo.de/files/original/' . sha1((string) $i) . '.jpg', 'type' => 'image/jpeg'];
        }

        $header = Signposting::header($links);

        $this->assertLessThanOrEqual(Signposting::HEADER_BUDGET, strlen($header));
        $this->assertLessThan(4096, Signposting::HEADER_BUDGET, "nginx's default fastcgi_buffer_size is one 4 KB page");
        $this->assertStringStartsWith('<https://islam.zmo.de/s/westafrica/item/42>; rel="cite-as"', $header);
    }
}
