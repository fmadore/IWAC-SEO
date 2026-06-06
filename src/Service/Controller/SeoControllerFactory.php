<?php
declare(strict_types=1);

namespace IwacSeo\Service\Controller;

use IwacSeo\Controller\Admin\SeoController;
use IwacSeo\Service\PageSeoStore;
use IwacSeo\Service\SitemapGenerator;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

final class SeoControllerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null): SeoController
    {
        return new SeoController(
            $container->get(SitemapGenerator::class),
            $container->get(PageSeoStore::class),
            $container->get('Omeka\ApiManager'),
            $container->get('Omeka\Settings'),
        );
    }
}
