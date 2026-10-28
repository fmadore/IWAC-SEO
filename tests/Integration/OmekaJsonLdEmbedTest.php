<?php
declare(strict_types=1);

namespace IwacSeo\Test\Integration;

use IwacSeo\Service\OmekaJsonLdEmbed;
use IwacSeo\Service\SettingsGate;
use IwacSeo\Service\SiteResolver;
use Laminas\Form\Form;
use Laminas\ServiceManager\ServiceManager;
use Laminas\View\Helper\EscapeHtml;
use Laminas\View\HelperPluginManager;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Representation\SiteRepresentation;
use Omeka\Settings\Settings;
use Omeka\Settings\SiteSettings;
use PHPUnit\Framework\TestCase;

/**
 * The configuration form's reminder that Omeka's own JSON-LD embed is still on
 * — the 2–4 MB block whose truncation Search Console reported in 1.0.1. Read
 * per site through the real SiteSettings signature (a target id per call), and
 * rendered through Laminas' real escaper.
 */
final class OmekaJsonLdEmbedTest extends TestCase
{
    /** @var array<int,bool> site id => disable_jsonld_embed */
    private array $disabled = [1 => false, 2 => false];

    /** @var array<string,mixed> */
    private array $settings = ['iwac_seo_jsonld_enabled' => '1'];

    private bool $siteSettingsFail = false;

    public function testEverySiteStillEmbeddingIsNamed(): void
    {
        $titles = array_map(static fn (SiteRepresentation $site): string => $site->title(), $this->embed()->sitesStillEmbedding());

        self::assertSame(["Collection Islam Afrique de l'Ouest", 'Islam West Africa Collection'], $titles);
    }

    public function testASiteWithTheBoxTickedIsLeftOut(): void
    {
        $this->disabled[1] = true;

        $titles = array_map(static fn (SiteRepresentation $site): string => $site->title(), $this->embed()->sitesStillEmbedding());

        self::assertSame(['Islam West Africa Collection'], $titles);
    }

    public function testNothingToSayOnceEverySiteHasTheBoxTicked(): void
    {
        $this->disabled = [1 => true, 2 => true];

        self::assertSame([], $this->embed()->sitesStillEmbedding());
    }

    /** With this module's JSON-LD off, Omeka's embed competes with nothing here. */
    public function testNothingToSayWhileThisModulesJsonLdIsOff(): void
    {
        $this->settings['iwac_seo_jsonld_enabled'] = '0';

        self::assertSame([], $this->embed()->sitesStillEmbedding());
    }

    public function testAFailedLookupSaysNothingRatherThanBreakingTheForm(): void
    {
        $this->siteSettingsFail = true;

        self::assertSame([], $this->embed()->sitesStillEmbedding());
    }

    public function testTheConfigFormOpensWithAWarningNamingEachSite(): void
    {
        $this->disabled[2] = true;

        $html = $this->configForm();

        self::assertStringStartsWith('<ul class="messages"><li class="warning">Omeka still embeds its own JSON-LD', $html);
        self::assertStringContainsString('pages of <em>Collection Islam Afrique de l&#039;Ouest</em>:', $html);
        self::assertStringNotContainsString('Islam West Africa Collection', $html);
        self::assertStringContainsString('“Disable JSON-LD embed”', $html);
        self::assertStringEndsWith('</li></ul><form></form>', $html);
    }

    public function testTheConfigFormIsUnchangedWhenNoSiteEmbeds(): void
    {
        $this->disabled = [1 => true, 2 => true];

        self::assertSame('<form></form>', $this->configForm());
    }

    /** Private sites are not crawled, so their embed is nobody's concern here. */
    public function testOnlyPublicSitesAreAskedForAndOnlyOnce(): void
    {
        $response = $this->createStub(\Omeka\Api\Response::class);
        $response->method('getContent')->willReturn([]);
        $api = $this->createMock(\Omeka\Api\Manager::class);
        $api->expects(self::once())->method('search')->with('sites', ['is_public' => true])->willReturn($response);

        $resolver = new SiteResolver($api, $this->createStub(Settings::class));
        $resolver->publicSites();
        $resolver->publicSites();
    }

    private function embed(): OmekaJsonLdEmbed
    {
        $settings = $this->createStub(Settings::class);
        $settings->method('get')->willReturnCallback(
            fn (string $id, $default = null) => $this->settings[$id] ?? $default
        );

        $sites = [];
        foreach ([1 => "Collection Islam Afrique de l'Ouest", 2 => 'Islam West Africa Collection'] as $id => $title) {
            $site = $this->createStub(SiteRepresentation::class);
            $site->method('id')->willReturn($id);
            $site->method('title')->willReturn($title);
            $sites[] = $site;
        }
        $resolver = $this->createStub(SiteResolver::class);
        $resolver->method('publicSites')->willReturn($sites);

        $siteSettings = $this->createStub(SiteSettings::class);
        $siteSettings->method('get')->willReturnCallback(
            function (string $id, $default = null, $targetId = null) {
                if ($this->siteSettingsFail) {
                    throw new \RuntimeException('Cannot manage settings when no target ID is set.');
                }
                return $id === 'disable_jsonld_embed' ? ($this->disabled[$targetId] ? '1' : '0') : $default;
            }
        );

        return new OmekaJsonLdEmbed(new SettingsGate($settings), $resolver, $siteSettings);
    }

    private function configForm(): string
    {
        require_once dirname(__DIR__, 2) . '/Module.php';

        $settings = $this->createStub(Settings::class);
        $settings->method('get')->willReturnArgument(1);
        $forms = new ServiceManager();
        $forms->setService(\IwacSeo\Form\ConfigForm::class, new Form());

        $services = new ServiceManager();
        $services->setService('Omeka\Settings', $settings);
        $services->setService('FormElementManager', $forms);
        $services->setService(OmekaJsonLdEmbed::class, $this->embed());

        $view = new PhpRenderer();
        $helpers = new HelperPluginManager(new ServiceManager());
        $escape = new EscapeHtml();
        $escape->setView($view);
        $helpers->setService('escapeHtml', $escape);
        // No translator here: the source string is what an English admin reads.
        $helpers->setService('translate', static fn (string $text): string => $text);
        $helpers->setService('formCollection', static fn (): string => '<form></form>');
        $view->setHelperPluginManager($helpers);

        $module = new \IwacSeo\Module();
        $module->setServiceLocator($services);
        return $module->getConfigForm($view);
    }
}
