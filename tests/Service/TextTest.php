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
