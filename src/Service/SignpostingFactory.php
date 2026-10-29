<?php
declare(strict_types=1);

namespace IwacSeo\Service;

use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

final class SignpostingFactory implements FactoryInterface
{
    /**
     * @param string $requestedName
     * @param array<mixed>|null $options
     */
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null): Signposting
    {
        $config = $container->get('Config')['iwac_seo']['citation'] ?? [];
        return new Signposting(
            $container->get(StructuredData::class),
            $container->get(CitationData::class),
            $container->get(ZoteroRdf::class),
            $container->get(SettingsGate::class),
            // The formats the "How to cite" panel offers, in its order.
            $config['formats'] ?? ['bibtex', 'ris', 'csljson'],
        );
    }
}
