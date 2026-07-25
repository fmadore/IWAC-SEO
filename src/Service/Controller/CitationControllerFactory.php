<?php
declare(strict_types=1);

namespace IwacSeo\Service\Controller;

use IwacSeo\Controller\CitationController;
use IwacSeo\Service\CitationData;
use IwacSeo\Service\CitationExport;
use IwacSeo\Service\SiteResolver;
use IwacSeo\Service\SettingsGate;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

final class CitationControllerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null): CitationController
    {
        return new CitationController(
            $container->get(CitationData::class),
            $container->get(CitationExport::class),
            $container->get('Omeka\ApiManager'),
            $container->get(SettingsGate::class),
            $container->get(SiteResolver::class),
        );
    }
}
