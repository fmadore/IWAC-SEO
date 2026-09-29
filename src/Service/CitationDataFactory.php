<?php
declare(strict_types=1);

namespace IwacSeo\Service;

use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

final class CitationDataFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null): CitationData
    {
        $names = $container->get('Config')['iwac_seo']['citation']['archive_names'] ?? [];
        return new CitationData($container->get(CitationKindMap::class), is_array($names) ? $names : []);
    }
}
