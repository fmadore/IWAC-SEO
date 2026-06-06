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

return [
    'service_manager' => [
        'factories' => [
            Service\HeadMetadata::class     => Service\HeadMetadataFactory::class,
            Service\StructuredData::class   => Service\StructuredDataFactory::class,
            Service\CitationMeta::class     => Service\CitationMetaFactory::class,
            Service\SitemapGenerator::class => Service\SitemapGeneratorFactory::class,
            Service\PageSeoStore::class     => Service\PageSeoStoreFactory::class,
            Service\Pinger::class           => Service\PingerFactory::class,
        ],
    ],

    'controllers' => [
        'factories' => [
            Controller\SitemapController::class    => Service\Controller\SitemapControllerFactory::class,
            Controller\Admin\SeoController::class  => Service\Controller\SeoControllerFactory::class,
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

    // ── Instance configuration (override via config/local.config.php) ───────
    'iwac_seo' => [
        'sitemap' => [
            'item_chunk_size' => 50000,
            'priority' => [
                'home'    => '1.0',
                'section' => '0.8', // item sets (collections) + top-level menu pages
                'item'    => '0.6',
                'page'    => '0.5',
                'browse'  => '0.4',
            ],
            'changefreq' => [
                'home'   => 'daily',
                'item'   => 'monthly',
                'page'   => 'monthly',
                'browse' => 'weekly',
            ],
        ],
        'structured_data' => [
            // schema.org @type per Omeka **resource class** id. IWAC dispatches
            // on class, not template: template 8 historically held both newspaper
            // articles (class 36) and Islamic-publication issues (class 60), and
            // the references share templates across classes. See the iwac-data
            // skill (omeka-structure.md) for the class catalogue.
            'default_type' => 'CreativeWork',
            'class_types'  => [
                // Authority / index entities
                94  => 'Person',          // foaf:Person          — Personnes
                9   => 'Place',           // dcterms:Location     — Lieux
                96  => 'Organization',    // foaf:Organization    — Organisations
                54  => 'Event',           // bibo:Event           — Événements
                244 => 'DefinedTerm',     // fabio:AuthorityFile  — Sujets / Notices d'autorité
                // Primary sources
                36  => 'NewsArticle',     // bibo:Article         — newspaper article
                60  => 'PublicationIssue',// bibo:Issue           — Islamic publication issue
                38  => 'VideoObject',     // bibo:AudioVisualDocument
                49  => 'DigitalDocument', // bibo:Document
                58  => 'ImageObject',     // bibo:Image           — photographs (not published)
                // Bibliographic references
                35  => 'ScholarlyArticle',// bibo:AcademicArticle — Article de revue
                178 => 'Review',          // fabio:BookReview     — Compte rendu
                43  => 'Chapter',         // bibo:Chapter         — Chapitre
                40  => 'Book',            // bibo:Book            — Livre
                52  => 'Book',            // bibo:EditedBook      — Ouvrage collectif
                88  => 'Thesis',          // bibo:Thesis          — Thèse
                82  => 'Report',          // bibo:Report          — Rapport
                77  => 'CreativeWork',    // bibo:PersonalCommunication — Communication
                305 => 'BlogPosting',     // fabio:BlogPost       — Article de blog
            ],
        ],
        'citation' => [
            // Highwire Press / Dublin Core citation kind per Omeka **resource
            // class** id. Entity kinds (person/place/organization/event/subject)
            // emit Dublin Core only; the rest emit kind-specific citation_* tags
            // so the Zotero Connector and Google Scholar capture a typed
            // reference. The newspaper/magazine kinds force the Zotero item type
            // through DC.type + prism.publicationName (see CitationMeta).
            'default_kind' => 'item',
            'class_kinds'  => [
                // Authority / index entities → Dublin Core only
                94  => 'person',
                9   => 'place',
                96  => 'organization',
                54  => 'event',
                244 => 'subject',
                // Primary sources
                36  => 'newspaper',   // newspaper article
                60  => 'magazine',    // Islamic-publication issue (periodical)
                38  => 'av',          // audiovisual document
                49  => 'document',
                // Bibliographic references
                35  => 'article',     // journal article (container in dcterms:publisher)
                178 => 'review',      // book review (container in dcterms:publisher)
                43  => 'chapter',     // book chapter (book title in dcterms:alternative)
                40  => 'book',
                52  => 'book',        // edited book
                88  => 'thesis',      // institution in dcterms:publisher
                82  => 'report',      // institution in dcterms:publisher
                77  => 'communication',
                305 => 'post',        // blog post
            ],
        ],
    ],
];
