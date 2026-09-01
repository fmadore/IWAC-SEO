<?php
declare(strict_types=1);

/**
 * IWAC instance data.
 *
 * What this particular archive's resource-class ids *mean* — the schema.org
 * @type per class, the citation kind per class, and the static-page slug
 * translations between the French and English sites. Separated from
 * module.config.php, which is now purely framework wiring (services, routes,
 * controllers, forms), so that:
 *
 *   • adding a page translation or reclassifying a resource class is an edit to
 *     data, not to plumbing;
 *   • another Omeka instance can adopt the module by replacing this one file.
 *
 * Every key is overridable, key by key, from config/local.config.php.
 */

namespace IwacSeo;

return [
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
                // bibo:Event — Événements. Not 'Event': all 243 of these records
                // are "Notice d'autorité", and Google's Event feature is for
                // events "bookable to the general public", requiring offers,
                // performer, organizer and a PostalAddress. A 1997 congress is
                // ineligible by that guideline however complete its metadata,
                // so the type buys 97 errors and ~500 warnings for a rich
                // result that can never appear. DefinedTerm is what the archive
                // itself calls them, and what class 244 already uses.
                54  => 'DefinedTerm',
                244 => 'DefinedTerm',     // fabio:AuthorityFile  — Sujets / Notices d'autorité
                // Primary sources
                36  => 'NewsArticle',     // bibo:Article         — newspaper article
                60  => 'PublicationIssue',// bibo:Issue           — Islamic publication issue
                38  => 'VideoObject',     // bibo:AudioVisualDocument
                49  => 'DigitalDocument', // bibo:Document
                58  => 'ImageObject',     // bibo:Image           — photographs (public since IwacSearch 3.3.0)
                // Bibliographic references
                35  => 'ScholarlyArticle',// bibo:AcademicArticle — Article de revue
                // fabio:BookReview — Compte rendu. Not 'Review': Google's review
                // snippet requires a reviewRating an academic review never has.
                // The reviewed book is emitted as schema:about instead.
                178 => 'ScholarlyArticle',
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
            //
            // This mirrors the Internationalisation module's page mapping, which
            // is the source of truth — the module records each page's counterpart
            // and the REST API exposes it as
            // `o-module-internationalisation:related_page`. A copy drifts, so
            // `composer hreflang:check` fails when the two disagree; see the
            // `hreflang drift` workflow.
            'page_pairs' => [
                ['afrique_ouest' => 'a-propos',                        'westafrica' => 'about'],
                ['afrique_ouest' => 'acces-ia',                        'westafrica' => 'ai-access'],
                ['afrique_ouest' => 'accueil',                         'westafrica' => 'home'],
                ['afrique_ouest' => 'analyse-sentiment',               'westafrica' => 'sentiment-analysis'],
                ['afrique_ouest' => 'audiovisuel',                     'westafrica' => 'audiovisual'],
                ['afrique_ouest' => 'benin',                           'westafrica' => 'benin'],
                ['afrique_ouest' => 'burkina-faso',                    'westafrica' => 'burkina-faso'],
                ['afrique_ouest' => 'communications',                  'westafrica' => 'presentations'],
                ['afrique_ouest' => 'comparaison',                     'westafrica' => 'comparison'],
                ['afrique_ouest' => 'cote-d-ivoire',                   'westafrica' => 'cote-d-ivoire'],
                ['afrique_ouest' => 'droits-d-auteur',                 'westafrica' => 'copyrights_data_reuse'],
                ['afrique_ouest' => 'enrichissement-metadonnees-IA',   'westafrica' => 'ai-metadata-enrichment'],
                ['afrique_ouest' => 'explorateur-d-entites',           'westafrica' => 'entity-index-explorer'],
                ['afrique_ouest' => 'exploration-spatiale',            'westafrica' => 'spatial-exploration'],
                ['afrique_ouest' => 'expositions',                     'westafrica' => 'exhibits'],
                ['afrique_ouest' => 'hadj-bf',                         'westafrica' => 'hajj-bf'],
                ['afrique_ouest' => 'index',                           'westafrica' => 'index'],
                ['afrique_ouest' => 'integrisme-cote-d-ivoire',        'westafrica' => 'integrisme-cote-d-ivoire'],
                ['afrique_ouest' => 'journaux',                        'westafrica' => 'why_newspapers'],
                ['afrique_ouest' => 'laicite',                         'westafrica' => 'secularism'],
                ['afrique_ouest' => 'langue-presse',                   'westafrica' => 'press-language'],
                ['afrique_ouest' => 'langues',                         'westafrica' => 'languages'],
                ['afrique_ouest' => 'militantisme-islamique-etudiant', 'westafrica' => 'student-activism-bf'],
                ['afrique_ouest' => 'mots-dans-le-temps',              'westafrica' => 'words-over-time'],
                ['afrique_ouest' => 'mots-distinctifs',                'westafrica' => 'distinctive-words'],
                ['afrique_ouest' => 'niger',                           'westafrica' => 'niger'],
                ['afrique_ouest' => 'nigeria',                         'westafrica' => 'nigeria'],
                ['afrique_ouest' => 'parcourir',                       'westafrica' => 'browse'],
                ['afrique_ouest' => 'periodiques-islamiques',          'westafrica' => 'islamic-publications'],
                ['afrique_ouest' => 'prix-et-mentions',                'westafrica' => 'award-and-mentions'],
                ['afrique_ouest' => 'references',                      'westafrica' => 'references'],
                ['afrique_ouest' => 'references_visualisations',       'westafrica' => 'references_visualisations'],
                ['afrique_ouest' => 'reseaux-entites',                 'westafrica' => 'entity-networks'],
                ['afrique_ouest' => 'roc',                             'westafrica' => 'ocr'],
                ['afrique_ouest' => 'togo',                            'westafrica' => 'togo'],
                ['afrique_ouest' => 'topic-modelling',                 'westafrica' => 'topic-modelling'],
                ['afrique_ouest' => 'visualisations',                  'westafrica' => 'explore'],
                ['afrique_ouest' => 'vue-d-ensemble',                  'westafrica' => 'collection-overview'],
            ],
        ],
    ],
];
