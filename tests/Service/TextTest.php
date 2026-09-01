<?php
declare(strict_types=1);

namespace IwacSeo\Test\Service;

use IwacSeo\Service\Text;
use PHPUnit\Framework\TestCase;

final class TextTest extends TestCase
{
    public function testShortTextPassesThroughNormalised(): void
    {
        $this->assertSame('Une phrase courte.', Text::truncate("Une   phrase\ncourte.", 160));
    }

    public function testTruncatesOnWordBoundaryWithEllipsis(): void
    {
        $out = Text::truncate(str_repeat('mot ', 100), 160);
        $this->assertLessThanOrEqual(160, mb_strlen($out));
        $this->assertStringEndsWith('…', $out);
        $this->assertStringNotContainsString('  ', $out);
        // No dangling half-word before the ellipsis.
        $this->assertMatchesRegularExpression('/mot…$/u', $out);
    }

    public function testTruncateStripsTrailingPunctuationBeforeEllipsis(): void
    {
        $text = str_repeat('a', 150) . ' word, and more text that will be cut off for sure';
        $out = Text::truncate($text, 160);
        $this->assertStringEndsWith('…', $out);
        $this->assertDoesNotMatchRegularExpression('/[ ,.;:]…$/u', $out);
    }

    public function testTruncateIsMultibyteSafe(): void
    {
        $text = str_repeat('é', 200);
        $out = Text::truncate($text, 160);
        $this->assertLessThanOrEqual(160, mb_strlen($out));
        $this->assertStringEndsWith('…', $out);
    }

    public function testDateRangeReturnsASingleDateWithNoEnd(): void
    {
        $this->assertSame(['1997', null], Text::dateRange('1997'));
        $this->assertSame(['2022-09-16', null], Text::dateRange('2022-09-16'));
        $this->assertSame(['2014-10', null], Text::dateRange('2014-10'));
    }

    public function testDateRangeSplitsAnIso8601Interval(): void
    {
        // The three interval shapes actually present in the event records.
        $this->assertSame(['2000', '2001'], Text::dateRange('2000/2001'));
        $this->assertSame(['2019-01-01', '2019-01-02'], Text::dateRange('2019-01-01/2019-01-02'));
        $this->assertSame(['1979-11-04', '1981-01-20'], Text::dateRange('1979-11-04/1981-01-20'));
    }

    public function testDateRangeKeepsATimeComponent(): void
    {
        $this->assertSame(['2019-01-01T09:30:00Z', null], Text::dateRange('2019-01-01T09:30:00Z'));
    }

    public function testDateRangeToleratesSurroundingSpace(): void
    {
        $this->assertSame(['2000', '2001'], Text::dateRange(' 2000 / 2001 '));
    }

    public function testDateRangeRejectsWhatAValidatorCouldNotRead(): void
    {
        // Dropped rather than passed through: an unreadable startDate
        // invalidates the whole Event node, so none is the better answer.
        $this->assertSame([null, null], Text::dateRange('vers 1997'));
        $this->assertSame([null, null], Text::dateRange('n.d.'));
        $this->assertSame([null, null], Text::dateRange(''));
        $this->assertSame(['1997', null], Text::dateRange('1997/sans date'));
        $this->assertSame([null, '2001'], Text::dateRange('circa/2001'));
    }

    public function testDateRangeIgnoresAThirdSegment(): void
    {
        // explode's limit keeps a stray separator out of the end date rather
        // than silently producing "2001/2002" as one value.
        $this->assertSame(['2000', null], Text::dateRange('2000/2001/2002'));
    }

    public function testWithoutQueryLeavesACleanUrlUntouched(): void
    {
        $url = 'https://islam.zmo.de/s/afrique_ouest/search';
        $this->assertSame($url, Text::withoutQuery($url));
    }

    public function testWithoutQueryStripsTheQueryString(): void
    {
        $this->assertSame(
            'https://islam.zmo.de/s/afrique_ouest/search',
            Text::withoutQuery('https://islam.zmo.de/s/afrique_ouest/search?page=3&sort_by=title')
        );
    }

    public function testWithoutQueryHandlesTheLegacyFacetUrls(): void
    {
        // As Google actually crawls them: raw spaces, square brackets and an
        // apostrophe in the query.
        $this->assertSame(
            'https://islam.zmo.de/s/afrique_ouest/search',
            Text::withoutQuery(
                "https://islam.zmo.de/s/afrique_ouest/search"
                . "?facet[dcterms_type_ss][9]=Article d'encyclopédie&page=2"
            )
        );
    }

    public function testWithoutQueryKeepsAnEmptyQueryMarkerOut(): void
    {
        $this->assertSame('https://islam.zmo.de/search', Text::withoutQuery('https://islam.zmo.de/search?'));
    }

    public function testExtractTokenFromFullMetaSnippet(): void
    {
        $snippet = '<meta name="google-site-verification" content="AbC123xyz" />';
        $this->assertSame('AbC123xyz', Text::extractToken($snippet));
    }

    public function testExtractTokenFromBareToken(): void
    {
        $this->assertSame('AbC123xyz', Text::extractToken('  AbC123xyz  '));
    }

    public function testExtractTokenStripsAccidentalQuotes(): void
    {
        $this->assertSame('AbC123xyz', Text::extractToken('"AbC123xyz"'));
    }

    public function testExtractTokenEmptyInput(): void
    {
        $this->assertSame('', Text::extractToken('   '));
    }
}
