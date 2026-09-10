<?php
declare(strict_types=1);

namespace IwacSeo\Test\Service\Citation;

use IwacSeo\Service\Citation\IssuedDate;
use PHPUnit\Framework\TestCase;

/**
 * Date precision is meaningful: a newspaper article cites to the day, a book to
 * the year, and an unparseable value still has to show something.
 */
final class IssuedDateTest extends TestCase
{
    public function testParsesEachStoredPrecision(): void
    {
        $date = IssuedDate::parse('2018-12-07');
        $this->assertSame([2018, 12, 7], [$date->year, $date->month, $date->day]);

        $date = IssuedDate::parse('2018-12');
        $this->assertSame([2018, 12, null], [$date->year, $date->month, $date->day]);

        $date = IssuedDate::parse('2018');
        $this->assertSame([2018, null, null], [$date->year, $date->month, $date->day]);
    }

    public function testUnparseableValuesSurviveAsLiterals(): void
    {
        $date = IssuedDate::parse('n.d.');

        $this->assertFalse($date->hasYear());
        $this->assertSame('n.d.', $date->literal);
        $this->assertSame('n.d.', $date->yearOrLiteral());
    }

    public function testEmptyInputIsUnknown(): void
    {
        $date = IssuedDate::parse('   ');

        $this->assertFalse($date->hasYear());
        $this->assertNull($date->literal);
        $this->assertNull($date->yearOrLiteral());
    }

    public function testYearWinsOverLiteral(): void
    {
        $this->assertSame('2018', IssuedDate::parse('2018-12-07')->yearOrLiteral());
    }

    public function testRangesRoundTripWithoutLosingPrecision(): void
    {
        $date = IssuedDate::parse('2009-05/2009-08');
        self::assertSame(['date-parts' => [[2009, 5], [2009, 8]]], $date->csl());
        self::assertSame('2009-05/2009-08', IssuedDate::fromArray($date->toArray())->iso());
    }

    public function testInvalidDatesRemainLiteral(): void
    {
        foreach (['2023-02-29', '2024-13', '2009/2008', 'circa 1990', '1990?'] as $raw) {
            $date = IssuedDate::parse($raw);
            self::assertNull($date->iso());
            self::assertSame(['literal' => $raw], $date->csl());
        }
        self::assertSame('2024-02-29', IssuedDate::parse('2024-02-29')->iso());
    }
}
