<?php
declare(strict_types=1);

namespace IwacSeo\Test\Service;

use IwacSeo\Service\LanguageCode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LanguageCodeTest extends TestCase
{
    /** @return array<string,array{0:string,1:?string}> */
    public static function codes(): array
    {
        return [
            'ISO 639-1 as stored' => ['fr', 'fr'],
            'ISO 639-2 terminology form' => ['deu', 'de'],
            'ISO 639-2 bibliographic form' => ['fre', 'fr'],
            'ISO 639-3 with no two-letter code' => ['ddn', 'ddn'],
            'upper case' => ['HAU', 'ha'],
            'region subtag' => ['en-gb', 'en-GB'],
            'script subtag' => ['sr-latn', 'sr-Latn'],
            // What IWAC's Mooré and Kabiyè records hold where a code belongs.
            'a name is not a code' => ['Moore', null],
            'nor a longer one' => ['Kabiye', null],
            'nothing' => ['', null],
        ];
    }

    #[DataProvider('codes')]
    public function testToBcp47(string $code, ?string $tag): void
    {
        $this->assertSame($tag, LanguageCode::toBcp47($code));
    }

    /** @return array<string,array{0:string,1:?string}> */
    public static function names(): array
    {
        return [
            'French name' => ['Français', 'fr'],
            'English name' => ['Hausa', 'ha'],
            'no code recorded' => ['Espagnol', 'es'],
            'a three-letter tag' => ['Mooré', 'mos'],
            'unaccented' => ['Kabiye', 'kbp'],
            'spacing and case' => ['  SLOVÈNE ', 'sl'],
            'a language the archive does not hold' => ['Fulfulde', null],
        ];
    }

    #[DataProvider('names')]
    public function testFromName(string $name, ?string $tag): void
    {
        $this->assertSame($tag, LanguageCode::fromName($name));
    }
}
