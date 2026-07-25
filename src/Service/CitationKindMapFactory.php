<?php
declare(strict_types=1);

namespace IwacSeo\Service;

use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

/**
 * Builds the single resource-class => citation-kind map. CitationData,
 * CitationMeta, ZoteroRdf and the citation view helper all share it, so the
 * config block is read — and its fallback decided — exactly once.
 */
final class CitationKindMapFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null): CitationKindMap
    {
        $config = $container->get('Config')['iwac_seo']['citation'] ?? [];
        $classKinds = $config['class_kinds'] ?? [];

        return new CitationKindMap(
            is_array($classKinds) ? $classKinds : [],
            (string) ($config['default_kind'] ?? 'item'),
        );
    }
}
