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
            Service\CitationData::class     => Service\CitationDataFactory::class,
            Service\ZoteroRdf::class        => Service\ZoteroRdfFactory::class,
            Service\SitemapGenerator::class => Service\SitemapGeneratorFactory::class,
            Service\PageSeoStore::class     => Service\PageSeoStoreFactory::class,
            Service\Pinger::class           => Service\PingerFactory::class,
            Service\Hreflang::class         => Service\HreflangFactory::class,
            Service\SiteResolver::class     => Service\SiteResolverFactory::class,
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

    // ── Instance configuration (override via config/local.config.php) ───────
    'iwac_seo' => [
        'sitemap' => [
            'item_chunk_size' => 50000,
            // Emit an <image:image> entry (the item's primary-media large
            // thumbnail) per item for Google Images.
            'include_images'  => true,
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
                58  => 'ImageObject',     // bibo:Image           — photographs (public since IwacSearch 3.3.0)
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
                58  => 'photo',       // fieldwork photograph → Zotero artwork
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
            // Item-page "How to cite" panel — the theme renders the UI via the
            // `iwacCitation` view helper; downloads are served by CitationController.
            // Chicago (notes–bibliography) leads for the history / area-studies
            // audience; APA + MLA are switchable. Formats mirror CitationExport.
            'default_style' => 'chicago',
            'styles'        => ['chicago' => 'Chicago', 'apa' => 'APA', 'mla' => 'MLA'],
            'formats'       => ['bibtex', 'ris', 'csljson'],
        ],
        'hreflang' => [
            // Bilingual cross-language alternates. IWAC publishes the same
            // collection as two Omeka sites; each public page declares the other
            // language version via <link rel="alternate" hreflang> (and the items
            // sitemap via <xhtml:link>). Canonicals stay self-referential per
            // language — this only adds the reciprocal alternate links.
            'enabled'   => true,
            // Site slug => hreflang language code (BCP-47), in declaration order.
            'sites'     => [
                'afrique_ouest' => 'fr', // Collection Islam Afrique de l'Ouest (CIAO)
                'westafrica'    => 'en', // Islam West Africa Collection (IWAC)
            ],
            // Which site is the hreflang x-default (the host root redirects here).
            'x_default' => 'afrique_ouest',
            // Static-page slug translations across the two sites. Resources
            // (items / item sets / media) need NO entry — they share an o:id, so
            // an alternate is just the same path under the other site slug. Only
            // static pages, whose slugs differ per language, are listed here.
            // A page with no entry gets no page-level alternate (safe: no broken
            // hreflang). Update this when site pages are added or renamed.
            'page_pairs' => [
                ['afrique_ouest' => 'accueil',                         'westafrica' => 'home'],
                ['afrique_ouest' => 'sous-collections',               'westafrica' => 'sub-collections'],
                ['afrique_ouest' => 'expositions',                    'westafrica' => 'exhibits'],
                ['afrique_ouest' => 'hadj-bf',                        'westafrica' => 'hajj-bf'],
                ['afrique_ouest' => 'militantisme-islamique-etudiant','westafrica' => 'student-activism-bf'],
                ['afrique_ouest' => 'references_visualisations',      'westafrica' => 'references_visualisations'],
                ['afrique_ouest' => 'visualisations-benin',           'westafrica' => 'visualisations-bj'],
                ['afrique_ouest' => 'visualisations-burkina-faso',    'westafrica' => 'visualisations-bf'],
                ['afrique_ouest' => 'visualisations-cote-d-ivoire',   'westafrica' => 'visualisations-ci'],
                ['afrique_ouest' => 'visualisations-niger',           'westafrica' => 'visualisations-ne'],
                ['afrique_ouest' => 'visualisations-nigeria',         'westafrica' => 'visualisations-ng'],
                ['afrique_ouest' => 'visualisations-togo',            'westafrica' => 'visualisations-togo'],
                ['afrique_ouest' => 'soumettre',                      'westafrica' => 'submit-a-reference'],
                ['afrique_ouest' => 'article',                        'westafrica' => 'article'],
                ['afrique_ouest' => 'livre',                          'westafrica' => 'book'],
                ['afrique_ouest' => 'index',                          'westafrica' => 'index'],
                ['afrique_ouest' => 'humanites-numeriques-ia',        'westafrica' => 'digital-humanities-ai'],
                ['afrique_ouest' => 'explorateur-mots-cles-iwac',     'westafrica' => 'iwac-keyword-explorer'],
                ['afrique_ouest' => 'topic-modelling',               'westafrica' => 'topic-modelling'],
                ['afrique_ouest' => 'sentiment-analysis',            'westafrica' => 'sentiment-analysis'],
                ['afrique_ouest' => 'visualisation-spatiale-reseaux', 'westafrica' => 'spatial-network-visualisation'],
                ['afrique_ouest' => 'a-propos',                       'westafrica' => 'about'],
                ['afrique_ouest' => 'journaux',                       'westafrica' => 'why_newspapers'],
                ['afrique_ouest' => 'langues',                        'westafrica' => 'languages'],
                ['afrique_ouest' => 'communications',                 'westafrica' => 'presentations'],
                ['afrique_ouest' => 'prix-et-mentions',               'westafrica' => 'award-and-mentions'],
                ['afrique_ouest' => 'droits-d-auteur',               'westafrica' => 'copyrights_data_reuse'],
                ['afrique_ouest' => 'nouvelles',                      'westafrica' => 'news'],
                ['afrique_ouest' => 'roc',                            'westafrica' => 'ocr'],
                ['afrique_ouest' => 'carte',                          'westafrica' => 'map-browse'],
                ['afrique_ouest' => 'iwac-chatbot',                  'westafrica' => 'iwac-chatbot'],
                ['afrique_ouest' => 'integrisme-cote-d-ivoire',      'westafrica' => 'integrisme-cote-d-ivoire'],
                ['afrique_ouest' => 'comparaison',                    'westafrica' => 'comparison'],
            ],
        ],
    ],
];
