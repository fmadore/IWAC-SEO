<?php
declare(strict_types=1);

namespace IwacSeo\Service;

use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

final class ZoteroRdfFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null): ZoteroRdf
    {
        // Shares the kind map with CitationData and CitationMeta, so all three
        // dispatch on the same IWAC resource-class conventions.
        return new ZoteroRdf($container->get(CitationKindMap::class));
    }
}
