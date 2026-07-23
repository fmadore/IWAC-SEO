<?php
declare(strict_types=1);

namespace IwacSeo\Service;

use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

final class SiteResolverFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null): SiteResolver
    {
        return new SiteResolver(
            $container->get('Omeka\ApiManager'),
            $container->get('Omeka\Settings'),
        );
    }
}
