<?php
declare(strict_types=1);

namespace IwacSeo\Test\Integration;

use Doctrine\DBAL\DriverManager;
use Laminas\ServiceManager\ServiceManager;
use Omeka\Settings\Settings;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class ModuleLifecycleTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testUpgradeWithoutActiveModuleAutoloading(): void
    {
        $this->runModuleLifecycle(true);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testInstallWithoutActiveModuleAutoloading(): void
    {
        $this->runModuleLifecycle(false);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testLifecycleLoaderResolvesAnyModuleClassFromSrc(): void
    {
        require_once dirname(__DIR__, 2) . '/Module.php';
        // Anything that reaches the blocker means the module's loader missed it.
        $blocker = static function (string $class): void {
            if (str_starts_with($class, 'IwacSeo\\') && $class !== 'IwacSeo\\Missing') {
                throw new \RuntimeException('Inactive module cannot autoload ' . $class);
            }
        };
        spl_autoload_register($blocker, true, true);
        try {
            $module = new \IwacSeo\Module();
            (new \ReflectionMethod($module, 'loadLifecycleDependencies'))->invoke($module);
            // Not only the classes install/upgrade name today: any file under src/.
            foreach (['Service\\PingOutboxInterface', 'Service\\Text', 'Service\\Citation\\CitationRecord'] as $class) {
                self::assertTrue(
                    class_exists('IwacSeo\\' . $class) || interface_exists('IwacSeo\\' . $class),
                    $class
                );
            }
            self::assertFalse(class_exists('IwacSeo\\Missing'));
        } finally {
            spl_autoload_unregister($blocker);
        }
    }

    /**
     * Omeka allows uninstalling an inactive module: no IwacSeo class is
     * autoloadable and no IwacSeo service registered. The sitemap cache must
     * still go, removed by path.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testUninstallOfAnInactiveModuleRemovesTheSitemapCache(): void
    {
        require_once dirname(__DIR__, 2) . '/Module.php';
        $files = sys_get_temp_dir() . '/iwac-seo-uninstall-' . bin2hex(random_bytes(4));
        $cache = $files . '/iwac-seo-cache';
        mkdir($cache, 0777, true);
        file_put_contents($cache . '/generation', 'abc');
        file_put_contents($cache . '/sitemap-items-1.xml', '<urlset/>');

        $services = new ServiceManager();
        $services->setService('Omeka\\Connection', DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]));
        $services->setService('Omeka\\Settings', $this->getMockBuilder(Settings::class)->disableOriginalConstructor()->getMock());
        $services->setService('Config', ['file_store' => ['local' => ['base_path' => $files]]]);
        $blocker = static function (string $class): void {
            if (str_starts_with($class, 'IwacSeo\\Service\\')) {
                throw new \RuntimeException('Inactive module cannot autoload ' . $class);
            }
        };
        spl_autoload_register($blocker, true, true);
        try {
            (new \IwacSeo\Module())->uninstall($services);
            self::assertDirectoryDoesNotExist($cache);
        } finally {
            spl_autoload_unregister($blocker);
            array_map('unlink', glob($cache . '/*') ?: []);
            @rmdir($cache);
            @rmdir($files);
        }
    }

    private function runModuleLifecycle(bool $upgrade): void
    {
        require_once dirname(__DIR__, 2) . '/Module.php';
        self::assertFalse(class_exists('IwacSeo\\Service\\PingRepository', false));
        self::assertFalse(class_exists('IwacSeo\\Service\\MetadataValue', false));
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $settings = $this->getMockBuilder(Settings::class)->disableOriginalConstructor()->getMock();
        $settings->method('get')->willReturnCallback(
            static fn ($key, $default = null) => $key === 'iwac_seo_ping_pending'
                ? ['https://example.test/item/1'] : $default
        );
        $settings->expects($upgrade ? self::exactly(2) : self::never())
            ->method('delete')->with('iwac_seo_ping_pending');
        $services = new ServiceManager();
        $services->setService('Omeka\\Connection', $connection);
        $services->setService('Omeka\\Settings', $settings);
        // Composer in the test harness otherwise hides Omeka's inactive-module boundary.
        $blocker = static function (string $class): void {
            if (str_starts_with($class, 'IwacSeo\\Service\\')) {
                throw new \RuntimeException('Inactive module cannot autoload ' . $class);
            }
        };
        spl_autoload_register($blocker, true, true);
        try {
            $module = new \IwacSeo\Module();
            for ($attempt = 0; $attempt < 2; ++$attempt) {
                if ($upgrade) {
                    $module->upgrade('1.0.5', '1.1.1', $services);
                } else {
                    $module->install($services);
                }
            }
            self::assertSame($upgrade ? 1 : 0, (int) $connection->fetchOne('SELECT COUNT(*) FROM iwac_seo_ping'));
            if ($upgrade) {
                self::assertSame('https://example.test/item/1', $connection->fetchOne('SELECT url FROM iwac_seo_ping'));
            }
        } finally {
            spl_autoload_unregister($blocker);
        }
    }
}
