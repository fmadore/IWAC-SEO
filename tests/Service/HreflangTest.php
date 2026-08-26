<?php
declare(strict_types=1);

namespace IwacSeo\Test\Service;

use IwacSeo\Service\Hreflang;
use PHPUnit\Framework\TestCase;

final class HreflangTest extends TestCase
{
    private function config(array $overrides = []): array
    {
        return array_merge([
            'enabled'    => true,
            'sites'      => ['afrique_ouest' => 'fr', 'westafrica' => 'en'],
            'x_default'  => 'afrique_ouest',
            'page_pairs' => [
                ['afrique_ouest' => 'accueil', 'westafrica' => 'home'],
                ['afrique_ouest' => 'a-propos', 'westafrica' => 'about'],
            ],
        ], $overrides);
    }

    public function testEnabledNeedsAtLeastTwoSites(): void
    {
        $this->assertTrue((new Hreflang($this->config()))->isEnabled());
        $this->assertFalse((new Hreflang($this->config(['enabled' => false])))->isEnabled());
        $this->assertFalse((new Hreflang($this->config(['sites' => ['solo' => 'fr']])))->isEnabled());
        $this->assertFalse((new Hreflang([]))->isEnabled());
    }

    public function testSitesPreserveDeclarationOrder(): void
    {
        $hreflang = new Hreflang($this->config());
        $this->assertSame(['afrique_ouest' => 'fr', 'westafrica' => 'en'], $hreflang->sites());
    }

    public function testXDefaultSlug(): void
    {
        $this->assertSame('afrique_ouest', (new Hreflang($this->config()))->xDefaultSlug());
        $this->assertNull((new Hreflang($this->config(['x_default' => null])))->xDefaultSlug());
    }

    public function testCoveredSlugsPerSite(): void
    {
        $hreflang = new Hreflang($this->config());
        $this->assertSame(['accueil', 'a-propos'], $hreflang->coveredSlugs('afrique_ouest'));
        $this->assertSame(['home', 'about'], $hreflang->coveredSlugs('westafrica'));
        $this->assertSame([], $hreflang->coveredSlugs('unknown-site'));
    }

    public function testPartnersForNamesTheOtherSiteOnly(): void
    {
        $hreflang = new Hreflang($this->config());
        $this->assertSame(['westafrica' => 'home'], $hreflang->partnersFor('afrique_ouest', 'accueil'));
        $this->assertSame(['afrique_ouest' => 'accueil'], $hreflang->partnersFor('westafrica', 'home'));
    }

    public function testPartnersForIsEmptyWithoutARow(): void
    {
        $hreflang = new Hreflang($this->config());
        $this->assertSame([], $hreflang->partnersFor('afrique_ouest', 'no-such-page'));
        $this->assertSame([], $hreflang->partnersFor('unknown-site', 'accueil'));
    }

    public function testPartnersForSkipsSitesARowDoesNotCover(): void
    {
        // A one-sided or blank row pairs nothing: the dashboard must not report
        // a counterpart as broken when none was ever configured.
        $hreflang = new Hreflang($this->config(['page_pairs' => [
            ['afrique_ouest' => 'accueil'],
            ['afrique_ouest' => 'a-propos', 'westafrica' => ''],
        ]]));
        $this->assertSame([], $hreflang->partnersFor('afrique_ouest', 'accueil'));
        $this->assertSame([], $hreflang->partnersFor('afrique_ouest', 'a-propos'));
    }

    public function testMalformedConfigDegradesSafely(): void
    {
        $hreflang = new Hreflang(['sites' => 'not-an-array', 'page_pairs' => 'nope']);
        $this->assertFalse($hreflang->isEnabled());
        $this->assertSame([], $hreflang->sites());
        $this->assertSame([], $hreflang->coveredSlugs('afrique_ouest'));
        $this->assertSame([], $hreflang->partnersFor('afrique_ouest', 'accueil'));
    }
}
