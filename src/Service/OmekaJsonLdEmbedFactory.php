<?php
declare(strict_types=1);

namespace IwacSeo\Service;

use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

final class OmekaJsonLdEmbedFactory implements FactoryInterface
{
    /**
     * @param string $requestedName
     * @param array<mixed>|null $options
     */
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null): OmekaJsonLdEmbed
    {
        return new OmekaJsonLdEmbed(
            $container->get(SettingsGate::class),
            $container->get(SiteResolver::class),
            $container->get('Omeka\Settings\Site'),
        );
    }
}
