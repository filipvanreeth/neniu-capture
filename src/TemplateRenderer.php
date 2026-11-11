<?php

declare(strict_types=1);

namespace Neniu\Verify;

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

final class TemplateRenderer
{
    private Environment $twig;

    public function __construct(string $templateDirectory, Translator $translator)
    {
        $this->twig = new Environment(new FilesystemLoader($templateDirectory), [
            'autoescape' => 'html',
            'strict_variables' => true,
        ]);
        $this->twig->addFunction(new TwigFunction('t', [$translator, 'translate']));
        // JSON embedded in a script must not be able to close its script tag.
        $this->twig->addFunction(new TwigFunction('json_script', static function (mixed $value): string {
            return json_encode($value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS
                | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
        }, ['is_safe' => ['html']]));
    }

    public function render(string $template, array $context): string
    {
        return $this->twig->render($template, $context);
    }
}
