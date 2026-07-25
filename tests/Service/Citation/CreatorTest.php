<?php
declare(strict_types=1);

namespace IwacSeo\Test\Service\Citation;

use IwacSeo\Service\Citation\Creator;
use PHPUnit\Framework\TestCase;

/**
 * Name splitting was a private heuristic inside CitationData, reachable only
 * through a live Omeka item. It is the part of the citation pipeline most
 * likely to mangle a real name, so it is now tested directly.
 */
final class CreatorTest extends TestCase
{
    public function testFirstLastIsSplitOnTheLastToken(): void
    {
        $creator = Creator::parse('Frédérick Madore', false);

        $this->assertSame('Madore', $creator->family);
        $this->assertSame('Frédérick', $creator->given);
        $this->assertFalse($creator->isSingleField());
    }

    public function testCompoundGivenNamesStayWithTheGivenName(): void
    {
        $creator = Creator::parse('Jean-Louis Triaud', false);
        $this->assertSame('Triaud', $creator->family);
        $this->assertSame('Jean-Louis', $creator->given);

        $creator = Creator::parse('Muriel Anne Gomez-Perez', false);
        $this->assertSame('Gomez-Perez', $creator->family);
        $this->assertSame('Muriel Anne', $creator->given);
    }

    public function testAlreadyInvertedLiteralsAreDetectedByTheComma(): void
    {
        $creator = Creator::parse('Madore, Frédérick', false);
        $this->assertSame('Madore', $creator->family);
        $this->assertSame('Frédérick', $creator->given);
    }

    public function testSingleTokenNamesBecomeTheFamilyName(): void
    {
        $creator = Creator::parse('Sanogo', false);
        $this->assertSame('Sanogo', $creator->family);
        $this->assertNull($creator->given);
    }

    /**
     * The reason institutions are a distinct case at all: split, this becomes
     * the surname "Faso".
     */
    public function testInstitutionsAreNeverSplit(): void
    {
        $creator = Creator::parse("Association Islamique d'Al Mawadda Burkina Faso", true);

        $this->assertNull($creator->family);
        $this->assertNull($creator->given);
        $this->assertSame("Association Islamique d'Al Mawadda Burkina Faso", $creator->literal);
        $this->assertTrue($creator->isSingleField());
    }

    public function testWhitespaceIsNormalised(): void
    {
        $creator = Creator::parse("  Frédérick   Madore \n", false);
        $this->assertSame('Frédérick Madore', $creator->literal);
        $this->assertSame('Madore', $creator->family);
    }

    public function testRoundTripsThroughTheArrayForm(): void
    {
        foreach ([
            Creator::person('Madore', 'Frédérick', 'Frédérick Madore'),
            Creator::person('Sanogo', null, 'Sanogo'),
            Creator::institution('AEEMB'),
        ] as $creator) {
            $this->assertEquals($creator, Creator::fromArray($creator->toArray()));
        }
    }
}
