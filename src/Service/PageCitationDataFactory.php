<?php
declare(strict_types=1);

namespace IwacSeo\Service;

use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

final class PageCitationDataFactory implements FactoryInterface
{
    /**
     * @param string $requestedName
     * @param array<mixed>|null $options
     */
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null): PageCitationData
    {
        $citation = $container->get('Config')['iwac_seo']['citation'] ?? [];
        $page = is_array($citation['page'] ?? null) ? $citation['page'] : [];
        $list = static fn ($value): array => is_array($value) ? array_values(array_filter($value, 'is_string')) : [];

        return new PageCitationData(
            is_array($citation['archive_names'] ?? null) ? $citation['archive_names'] : [],
            is_array($page['publisher'] ?? null) ? $page['publisher'] : [],
            $list($page['authors'] ?? []),
        );
    }
}
