<?php
declare(strict_types=1);

namespace IwacSeo\Service\ViewHelper;

use IwacSeo\Service\CitationData;
use IwacSeo\Service\CitationExport;
use IwacSeo\Service\CitationFormatter;
use IwacSeo\Service\ZoteroRdf;
use IwacSeo\View\Helper\Citation;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

final class CitationFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null): Citation
    {
        $config = $container->get('Config')['iwac_seo']['citation'] ?? [];
        $settings = $container->get('Omeka\Settings');

        // The panel shares the citation kill-switch with the Highwire/DC meta
        // tags. Same truthiness contract as SettingsReader::boolSetting().
        $value = $settings->get('iwac_seo_citation_meta', '1');
        $enabled = $value === '1' || $value === 1 || $value === true;

        return new Citation(
            $container->get(CitationData::class),
            $container->get(CitationFormatter::class),
            $container->get(CitationExport::class),
            $container->get(ZoteroRdf::class),
            $config['default_style'] ?? 'chicago',
            $config['styles'] ?? ['chicago' => 'Chicago', 'apa' => 'APA', 'mla' => 'MLA'],
            $config['formats'] ?? ['bibtex', 'ris', 'csljson'],
            $enabled,
        );
    }
}
