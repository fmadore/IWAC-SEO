<?php
declare(strict_types=1);

namespace IwacSeo\Form;

use Laminas\Form\Element;
use Laminas\Form\Form;

/**
 * Carries just the CSRF token for the static-page SEO table. The per-page
 * fields are rendered by hand in the view (one row per site page) and read
 * straight from the POST, so they are not declared as form elements here.
 */
class PageSeoForm extends Form
{
    public function init(): void
    {
        $this->add([
            'name'    => 'csrf',
            'type'    => Element\Csrf::class,
            'options' => ['csrf_options' => ['timeout' => 3600]],
        ]);
    }
}
