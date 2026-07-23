<?php
declare(strict_types=1);

namespace IwacSeo\Service\Controller;

use IwacSeo\Controller\SitemapController;
use IwacSeo\Service\Hreflang;
use IwacSeo\Service\SitemapGenerator;
use IwacSeo\Service\SiteResolver;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

final class SitemapControllerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null): SitemapController
    {
        return new SitemapController(
            $container->get(SitemapGenerator::class),
            $container->get(SiteResolver::class),
            $container->get('Omeka\Settings'),
            $container->get(Hreflang::class),
        );
    }
}
