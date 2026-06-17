<?php
declare(strict_types=1);

namespace IwacSeo\Service;

use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

final class HeadMetadataFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null): HeadMetadata
    {
        return new HeadMetadata(
            $container->get('Omeka\Settings'),
            $container->get(StructuredData::class),
            $container->get(CitationMeta::class),
            $container->get(Hreflang::class),
            $container->get(ZoteroRdf::class),
        );
    }
}
