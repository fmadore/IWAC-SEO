<?php
declare(strict_types=1);

namespace IwacSeo\Test\Integration;

use IwacSeo\Controller\Admin\SeoController;
use IwacSeo\Controller\CitationController;
use IwacSeo\Controller\SitemapController;
use IwacSeo\Controller\UnapiController;
use IwacSeo\Service\CitationData;
use IwacSeo\Service\CitationExport;
use IwacSeo\Service\CitationMeta;
use IwacSeo\Service\HeadMetadata;
use IwacSeo\Service\Hreflang;
use IwacSeo\Service\PageSeoStore;
use IwacSeo\Service\SettingsGate;
use IwacSeo\Service\SitemapGenerator;
use IwacSeo\Service\SiteResolver;
use IwacSeo\Service\ZoteroRdf;
use Laminas\Http\PhpEnvironment\Request;
use Laminas\Mvc\Controller\ControllerManager;
use Laminas\Router\Http\TreeRouteStack;
use Laminas\ServiceManager\ServiceManager;
use Omeka\Api\Manager as ApiManager;
use Omeka\Settings\Settings;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class WiringIntegrationTest extends TestCase
{
    /** @var array<string,mixed> */
    private array $config;

    protected function setUp(): void
    {
        $this->config = require dirname(__DIR__, 2) . '/config/module.config.php';
    }

    public function testMetadataServicesResolveThroughRealServiceManager(): void
    {
        $settings = $this->getMockBuilder(Settings::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get'])
            ->getMock();
        $settings->method('get')->willReturnArgument(1);

        $services = new ServiceManager($this->config['service_manager']);
        $services->setService('Config', $this->config);
        $services->setService('Omeka\Settings', $settings);

        self::assertInstanceOf(CitationMeta::class, $services->get(CitationMeta::class));
        self::assertInstanceOf(ZoteroRdf::class, $services->get(ZoteroRdf::class));
        self::assertInstanceOf(HeadMetadata::class, $services->get(HeadMetadata::class));
        self::assertSame($services->get(HeadMetadata::class), $services->get(HeadMetadata::class));
    }

    public function testControllersResolveThroughRealControllerManager(): void
    {
        $services = new ServiceManager();
        $dependencies = [
            CitationData::class,
            CitationExport::class,
            SitemapGenerator::class,
            PageSeoStore::class,
            SettingsGate::class,
            SiteResolver::class,
            Hreflang::class,
            ZoteroRdf::class,
        ];
        foreach ($dependencies as $class) {
            $services->setService($class, $this->withoutConstructor($class));
        }
        $services->setService('Omeka\ApiManager', $this->withoutConstructor(ApiManager::class));

        $controllers = new ControllerManager($services, $this->config['controllers']);
        $controllerClasses = [
            SitemapController::class,
            UnapiController::class,
            CitationController::class,
            SeoController::class,
        ];
        foreach ($controllerClasses as $controller) {
            self::assertInstanceOf($controller, $controllers->get($controller));
        }
    }

    public function testPublicRoutesMatchTheirConfiguredControllers(): void
    {
        $routes = $this->config['router']['routes'];
        unset($routes['admin']); // Omeka core owns the parent admin route.
        $router = TreeRouteStack::factory(['routes' => $routes]);

        $cases = [
            '/sitemap.xml' => [SitemapController::class, 'index'],
            '/sitemap-items-3.xml' => [SitemapController::class, 'items'],
            '/robots.txt' => [SitemapController::class, 'robots'],
            '/unapi' => [UnapiController::class, 'index'],
            '/cite/42/ris' => [CitationController::class, 'index'],
        ];
        foreach ($cases as $path => [$controller, $action]) {
            $request = new Request();
            $request->setUri('https://example.test' . $path);
            $match = $router->match($request);

            self::assertNotNull($match, $path);
            self::assertSame($controller, $match->getParam('controller'), $path);
            self::assertSame($action, $match->getParam('action'), $path);
        }
    }

    /** @param class-string $class */
    private function withoutConstructor(string $class): object
    {
        return (new ReflectionClass($class))->newInstanceWithoutConstructor();
    }
}
