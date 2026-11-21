<?php
declare(strict_types=1);

namespace IwacSeo\Test\PHPStan;

use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\FunctionVariant;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\MethodsClassReflectionExtension;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\Generic\TemplateTypeMap;
use PHPStan\Type\ObjectType;

/**
 * Types the view helpers and controller plugins the module calls by name.
 *
 * Laminas resolves `$view->translate()` and `$this->messenger()` through
 * __call(), so PHPStan knows only what a class annotates — and PhpRenderer
 * and AbstractController annotate Laminas's own helpers, not the ones Omeka S
 * (or this module) registers. Each call below is typed from the real helper
 * class: its __invoke(), which __call() runs, or the helper itself when it has
 * none, which __call() then returns. Arguments and return values stay checked.
 *
 * A stub file cannot do this: PHPStan validates stubs on their own, where no
 * Omeka class is visible. Add a row when the module calls another helper.
 */
final class PluginMethodsExtension implements MethodsClassReflectionExtension
{
    /** Name => helper class, per object that resolves names through __call(). */
    private const PLUGINS = [
        \Laminas\View\Renderer\PhpRenderer::class => [
            'translate' => \Laminas\I18n\View\Helper\Translate::class,
            'formCollection' => \Laminas\Form\View\Helper\FormCollection::class,
            'currentSite' => \Omeka\View\Helper\CurrentSite::class,
            'api' => \Omeka\View\Helper\Api::class,
            'lang' => \Omeka\View\Helper\Lang::class,
            'siteSetting' => \Omeka\View\Helper\Setting::class,
            'hyperlink' => \Omeka\View\Helper\Hyperlink::class,
            'assetUrl' => \Omeka\View\Helper\AssetUrl::class,
            'iwacCitation' => \IwacSeo\View\Helper\Citation::class,
        ],
        \Laminas\Mvc\Controller\AbstractController::class => [
            'messenger' => \Omeka\Mvc\Controller\Plugin\Messenger::class,
            'getForm' => \Omeka\Mvc\Controller\Plugin\GetForm::class,
            'translate' => \Omeka\Mvc\Controller\Plugin\Translate::class,
        ],
    ];

    public function __construct(private readonly ReflectionProvider $reflectionProvider)
    {
    }

    public function hasMethod(ClassReflection $classReflection, string $methodName): bool
    {
        return $this->helperFor($classReflection, $methodName) !== null;
    }

    public function getMethod(ClassReflection $classReflection, string $methodName): MethodReflection
    {
        $helper = $this->reflectionProvider->getClass((string) $this->helperFor($classReflection, $methodName));
        if ($helper->hasNativeMethod('__invoke')) {
            $invoke = $helper->getNativeMethod('__invoke');
            return new PluginMethod($classReflection, $methodName, $invoke->getVariants(), $invoke->getDocComment());
        }
        $returnsHelper = new FunctionVariant(
            TemplateTypeMap::createEmpty(),
            null,
            [],
            false,
            new ObjectType($helper->getName())
        );
        return new PluginMethod($classReflection, $methodName, [$returnsHelper], null);
    }

    private function helperFor(ClassReflection $classReflection, string $methodName): ?string
    {
        foreach (self::PLUGINS as $owner => $plugins) {
            if (
                ($classReflection->getName() === $owner || $classReflection->isSubclassOf($owner))
                && isset($plugins[$methodName])
                && $this->reflectionProvider->hasClass($plugins[$methodName])
            ) {
                return $plugins[$methodName];
            }
        }
        return null;
    }
}
