<?php
declare(strict_types=1);

namespace IwacSeo\Service;

use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

final class CitationDataFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null): CitationData
    {
        $config = $container->get('Config')['iwac_seo']['citation'] ?? [];
        return new CitationData(
            $config['class_kinds'] ?? [],
            $config['default_kind'] ?? 'item',
        );
    }
}
