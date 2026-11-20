<?php
declare(strict_types=1);

namespace IwacSeo\Test\Integration;

use IwacSeo\Site\CitationPartial;
use Laminas\ServiceManager\ServiceManager;
use Laminas\View\Helper\HeadScript;
use Laminas\View\HelperPluginManager;
use Laminas\View\Renderer\PhpRenderer;
use Laminas\View\Resolver\TemplateMapResolver;
use PHPUnit\Framework\TestCase;

/**
 * Both citation blocks render the theme's partial through Laminas's real
 * renderer and resolver. A theme without the partial gets nothing, not an
 * exception that takes the page down.
 */
final class CitationPartialTest extends TestCase
{
    private ?string $template = null;

    protected function tearDown(): void
    {
        if ($this->template !== null) {
            @unlink($this->template);
        }
    }

    public function testAThemeWithoutThePartialGetsNothing(): void
    {
        $view = $this->renderer([]);

        self::assertSame('', CitationPartial::render($view, ['chicago' => 'X']));
        self::assertStringNotContainsString('citation.js', $view->headScript()->toString());
    }

    public function testAThemeWithThePartialRendersItAndItsScript(): void
    {
        $this->template = sys_get_temp_dir() . '/iwac-cite-' . bin2hex(random_bytes(4)) . '.phtml';
        file_put_contents($this->template, '<p><?= $this->escapeHtml($citation["chicago"]) ?></p>');
        $view = $this->renderer([CitationPartial::TEMPLATE => $this->template]);
        $view->getHelperPluginManager()->setService(
            'escapeHtml',
            static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES)
        );

        self::assertSame('<p>A &amp; B</p>', CitationPartial::render($view, ['chicago' => 'A & B']));
        self::assertStringContainsString('/themes/iwac/asset/js/citation.js', html_entity_decode($view->headScript()->toString()));
    }

    /** @param array<string,string> $map */
    private function renderer(array $map): PhpRenderer
    {
        $view = new PhpRenderer();
        $view->setResolver(new TemplateMapResolver($map));
        $helpers = new HelperPluginManager(new ServiceManager());
        $headScript = new HeadScript();
        $headScript->setView($view);
        $helpers->setService('headScript', $headScript);
        $helpers->setService('assetUrl', static fn (string $file): string => '/themes/iwac/asset/' . $file);
        $helpers->setService('partial', new \Laminas\View\Helper\Partial());
        $helpers->get('partial')->setView($view);
        $view->setHelperPluginManager($helpers);
        return $view;
    }
}
