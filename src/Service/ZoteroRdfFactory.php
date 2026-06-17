<?php
declare(strict_types=1);

namespace IwacSeo\Service;

use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

final class ZoteroRdfFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null): ZoteroRdf
    {
        // Shares the citation kind map (resource class id => kind) with
        // CitationMeta, so both dispatch on the same IWAC class conventions.
        $config = $container->get('Config')['iwac_seo']['citation'] ?? [];
        return new ZoteroRdf($config['class_kinds'] ?? []);
    }
}
