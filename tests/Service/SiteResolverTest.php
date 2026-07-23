<?php
declare(strict_types=1);

namespace IwacSeo\Test\Service;

use IwacSeo\Service\SiteResolver;
use PHPUnit\Framework\TestCase;

final class SiteResolverTest extends TestCase
{
    public function testHostFromUrl(): void
    {
        $this->assertSame(
            'https://islam.zmo.de',
            SiteResolver::hostFromUrl('https://islam.zmo.de/s/afrique_ouest/page/accueil')
        );
        $this->assertSame(
            'http://localhost:8080',
            SiteResolver::hostFromUrl('http://localhost:8080/s/test')
        );
    }
}
