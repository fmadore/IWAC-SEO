<?php
declare(strict_types=1);

namespace IwacSeo\Service;

use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

final class StructuredDataFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null): StructuredData
    {
        $config = $container->get('Config')['iwac_seo']['structured_data'] ?? [];
        return new StructuredData(
            $config['class_types'] ?? [],
            $config['default_type'] ?? 'CreativeWork',
        );
    }
}
