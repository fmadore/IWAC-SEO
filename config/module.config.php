<?php
declare(strict_types=1);

/**
 * IWAC SEO module configuration.
 *
 * Wires the public sitemap/robots/IndexNow endpoints (top-level routes that
 * fall through to Omeka), the admin SEO dashboard + static-page table, the head
 * metadata / structured-data / sitemap services, and the instance-specific
 * `iwac_seo` config block (overridable, key by key, via config/local.config.php).
 */

namespace IwacSeo;

use Laminas\Router\Http\Literal;
use Laminas\Router\Http\Segment;

// Instance data (the iwac_seo block) lives in its own file. The two have
// disjoint top-level keys, so a plain union is enough — and, unlike
// array_merge_recursive, it holds no surprises.
$instance = include __DIR__ . '/instance.config.php';

return $instance + [
    'service_manager' => [
        'factories' => [
            Service\HeadMetadata::class     => Service\HeadMetadataFactory::class,
            Service\StructuredData::class   => Service\StructuredDataFactory::class,
            Service\CitationKindMap::class  => Service\CitationKindMapFactory::class,
            Service\CitationMeta::class     => Service\CitationMetaFactory::class,
            Service\CitationData::class     => Service\CitationDataFactory::class,
            Service\ZoteroRdf::class        => Service\ZoteroRdfFactory::class,
            Service\SitemapGenerator::class => Service\SitemapGeneratorFactory::class,
            Service\PageSeoStore::class     => Service\PageSeoStoreFactory::class,
            Service\Pinger::class           => Service\PingerFactory::class,
            Service\PingQueue::class       => Service\PingQueueFactory::class,
            Service\Hreflang::class         => Service\HreflangFactory::class,
            Service\SiteResolver::class     => Service\SiteResolverFactory::class,
            Service\SettingsGate::class    => Service\SettingsGateFactory::class,
        ],
        // Dependency-free (no bundled vendor/): plain instantiation.
        'invokables' => [
            Service\CitationFormatter::class => Service\CitationFormatter::class,
            Service\CitationExport::class    => Service\CitationExport::class,
        ],
    ],

    'controllers' => [
        'factories' => [
            Controller\SitemapController::class    => Service\Controller\SitemapControllerFactory::class,
            Controller\UnapiController::class      => Service\Controller\UnapiControllerFactory::class,
            Controller\CitationController::class   => Service\Controller\CitationControllerFactory::class,
            Controller\Admin\SeoController::class  => Service\Controller\SeoControllerFactory::class,
        ],
    ],

    'view_helpers' => [
        'factories' => [
            'iwacCitation' => Service\ViewHelper\CitationFactory::class,
        ],
    ],

    // "How to cite" resource page block — appears in the theme's resource-page
    // configuration (Admin → Themes → Configure resource pages) so its placement
    // is admin-controlled. Renders via the iwacCitation helper + theme partial.
    'resource_page_block_layouts' => [
        'invokables' => [
            'iwacCitation' => Site\ResourcePageBlockLayout\Citation::class,
        ],
    ],

    'form_elements' => [
        'invokables' => [
            Form\ConfigForm::class  => Form\ConfigForm::class,
            Form\PageSeoForm::class => Form\PageSeoForm::class,
        ],
    ],

    'router' => [
        'routes' => [
            // ── Public, host-root endpoints. nginx `try_files … /index.php`
            // routes these to Omeka (the .xml/.txt extensions are not served as
            // static files because no such files exist), so no web-server
            // configuration is required.
            'iwac-seo-sitemap' => [
                'type'    => Literal::class,
                'options' => [
                    'route'    => '/sitemap.xml',
                    'defaults' => ['controller' => Controller\SitemapController::class, 'action' => 'index'],
                ],
            ],
            'iwac-seo-sitemap-pages' => [
                'type'    => Literal::class,
                'options' => [
                    'route'    => '/sitemap-pages.xml',
                    'defaults' => ['controller' => Controller\SitemapController::class, 'action' => 'pages'],
                ],
            ],
            'iwac-seo-sitemap-item-sets' => [
                'type'    => Literal::class,
                'options' => [
                    'route'    => '/sitemap-item-sets.xml',
                    'defaults' => ['controller' => Controller\SitemapController::class, 'action' => 'itemSets'],
                ],
            ],
            'iwac-seo-sitemap-items' => [
                'type'    => Segment::class,
                'options' => [
                    'route'       => '/sitemap-items-:chunk.xml',
                    'constraints' => ['chunk' => '\d+'],
                    'defaults'    => ['controller' => Controller\SitemapController::class, 'action' => 'items'],
                ],
            ],
            'iwac-seo-robots' => [
                'type'    => Literal::class,
                'options' => [
                    'route'    => '/robots.txt',
                    'defaults' => ['controller' => Controller\SitemapController::class, 'action' => 'robots'],
                ],
            ],
            // unAPI resolver. Item pages advertise it via <link rel="unapi-server">
            // + <abbr class="unapi-id">; Zotero fetches ?id=…&format=rdf_zotero and
            // imports the Zotero RDF (CitationMeta meta tags remain the fallback).
            'iwac-seo-unapi' => [
                'type'    => Literal::class,
                'options' => [
                    'route'    => '/unapi',
                    'defaults' => ['controller' => Controller\UnapiController::class, 'action' => 'index'],
                ],
            ],
            // Single-item citation downloads: /cite/{item-id}/{format}. Host-root
            // like /unapi, so nginx's try_files falls it through to Omeka. The
            // item page's "How to cite" panel links here; format is bibtex|ris|
            // csljson (validated against CitationExport::FORMATS in the controller).
            'iwac-seo-cite' => [
                'type'    => Segment::class,
                'options' => [
                    'route'       => '/cite/:id/:format',
                    'constraints' => ['id' => '\d+', 'format' => '[a-z]+'],
                    'defaults'    => ['controller' => Controller\CitationController::class, 'action' => 'index'],
                ],
            ],
            // IndexNow ownership key at /{key}.txt. Constrained to a hex key so
            // it cannot shadow robots.txt; low priority so literals match first.
            'iwac-seo-indexnow' => [
                'type'     => Segment::class,
                'priority' => -100,
                'options'  => [
                    'route'       => '/:key.txt',
                    'constraints' => ['key' => '[A-Fa-f0-9]{8,128}'],
                    'defaults'    => ['controller' => Controller\SitemapController::class, 'action' => 'indexNowKey'],
                ],
            ],

            // ── Admin: SEO dashboard + static-page table.
            'admin' => [
                'child_routes' => [
                    'iwac-seo' => [
                        'type'    => Literal::class,
                        'options' => [
                            'route'    => '/iwac-seo',
                            'defaults' => [
                                '__NAMESPACE__' => 'IwacSeo\Controller\Admin',
                                'controller'    => Controller\Admin\SeoController::class,
                                'action'        => 'dashboard',
                            ],
                        ],
                        'may_terminate' => true,
                        'child_routes'  => [
                            'pages' => [
                                'type'    => Literal::class,
                                'options' => [
                                    'route'    => '/pages',
                                    'defaults' => ['action' => 'pages'],
                                ],
                            ],
                            'regenerate' => [
                                'type'    => Literal::class,
                                'options' => [
                                    'route'    => '/regenerate',
                                    'defaults' => ['action' => 'regenerate'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],

    'view_manager' => [
        'template_path_stack' => [
            __DIR__ . '/../view',
        ],
    ],

    // Sidebar entry under Omeka's "Modules" admin menu.
    'navigation' => [
        'AdminModule' => [
            [
                'label'    => 'SEO', // @translate
                'route'    => 'admin/iwac-seo',
                'resource' => Controller\Admin\SeoController::class,
                'class'    => 'o-icon-search',
                'pages'    => [
                    ['route' => 'admin/iwac-seo/pages', 'label' => 'Static pages'], // @translate
                    ['route' => 'admin/iwac-seo/regenerate', 'visible' => false],
                ],
            ],
        ],
    ],

    // ── Instance data (class maps, page translations) ───────────────────────
    // Kept in its own file: module.config.php is wiring, instance.config.php is
    // what this archive's class ids mean. Both remain overridable, key by key,
    // from config/local.config.php.
];
