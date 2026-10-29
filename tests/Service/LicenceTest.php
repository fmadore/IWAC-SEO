<?php
declare(strict_types=1);

namespace IwacSeo\Test\Service;

use IwacSeo\Service\Licence;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** A licence named in words resolves to its canonical URL, or to nothing. */
final class LicenceTest extends TestCase
{
    /** @return array<string,array{0:string,1:?string}> */
    public static function labels(): array
    {
        return [
            'short form' => ['CC-BY-NC-SA-4.0', 'https://creativecommons.org/licenses/by-nc-sa/4.0/'],
            'spaced' => ['CC BY 3.0', 'https://creativecommons.org/licenses/by/3.0/'],
            'full name' => [
                'Creative Commons Attribution-NonCommercial-NoDerivatives 4.0 International',
                'https://creativecommons.org/licenses/by-nc-nd/4.0/',
            ],
            'CC0' => ['CC0 1.0 Universal', 'https://creativecommons.org/publicdomain/zero/1.0/'],
            'not a real combination' => ['CC-NC-4.0', null],
            'a rights statement is not a CC label' => ['In Copyright - Educational Use Permitted', null],
            'free text' => ['Tous droits réservés', null],
        ];
    }

    #[DataProvider('labels')]
    public function testUrlForLabel(string $label, ?string $url): void
    {
        $this->assertSame($url, Licence::urlForLabel($label));
    }
}
