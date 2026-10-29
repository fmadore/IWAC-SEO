<?php
declare(strict_types=1);

namespace IwacSeo\Service;

use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

final class ImageDimensionsFactory implements FactoryInterface
{
    /**
     * The local store's directory, resolved as Omeka's own Local store factory
     * resolves it: the configured base_path, else OMEKA_PATH/files.
     *
     * @param string $requestedName
     * @param array<mixed>|null $options
     */
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null): ImageDimensions
    {
        $basePath = $container->get('Config')['file_store']['local']['base_path'] ?? null;
        if (!$basePath && defined('OMEKA_PATH')) {
            $basePath = OMEKA_PATH . '/files';
        }
        return new ImageDimensions(is_string($basePath) && $basePath !== '' ? $basePath : null);
    }
}
