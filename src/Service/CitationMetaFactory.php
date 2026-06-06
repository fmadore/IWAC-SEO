<?php
declare(strict_types=1);

namespace IwacSeo\Service;

use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

final class CitationMetaFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null): CitationMeta
    {
        $config = $container->get('Config')['iwac_seo']['citation'] ?? [];
        return new CitationMeta(
            $config['class_kinds'] ?? [],
            $config['default_kind'] ?? 'item',
        );
    }
}
