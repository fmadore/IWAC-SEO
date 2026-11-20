<?php
declare(strict_types=1);

namespace IwacSeo\Service;

use IwacSeo\Service\Sitemap\SitemapRepository;
use IwacSeo\Service\Sitemap\UrlsetWriter;
use IwacSeo\Service\Sitemap\XmlCache;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

final class SitemapGeneratorFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null): SitemapGenerator
    {
        $config = $container->get('Config');
        $sitemapConfig = $config['iwac_seo']['sitemap'] ?? [];

        $cacheDir = self::cacheDirectory($config);

        // Public base URI of stored files, when configured; the generator
        // falls back to deriving "{site base}/files" from the site URL.
        $fileBaseUri = $config['file_store']['local']['base_uri'] ?? null;

        return new SitemapGenerator(
            new SitemapRepository($container->get('Omeka\Connection')),
            new UrlsetWriter(),
            new XmlCache($cacheDir),
            is_array($sitemapConfig) ? $sitemapConfig : [],
            is_string($fileBaseUri) && $fileBaseUri !== '' ? $fileBaseUri : null,
            $container->get(PageSeoStore::class),
        );
    }

    /**
     * Where the sitemap cache lives (files/iwac-seo-cache). Public so that
     * uninstall can remove it by path: an inactive module's services are not
     * registered, so the generator cannot be asked to.
     *
     * @param array<string,mixed> $config the merged application config
     */
    public static function cacheDirectory(array $config): string
    {
        $basePath = $config['file_store']['local']['base_path']
            ?? (defined('OMEKA_PATH') ? OMEKA_PATH . '/files' : sys_get_temp_dir());
        return rtrim((string) $basePath, '/\\') . '/iwac-seo-cache';
    }
}
