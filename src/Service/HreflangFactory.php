<?php
declare(strict_types=1);

namespace IwacSeo\Service;

use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

final class HreflangFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null): Hreflang
    {
        $config = $container->get('Config')['iwac_seo']['hreflang'] ?? [];
        return new Hreflang(is_array($config) ? $config : []);
    }
}
