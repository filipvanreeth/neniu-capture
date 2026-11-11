<?php
declare(strict_types=1);

namespace Neniu\Verify;

use InvalidArgumentException;

final class Translator
{
    private string $locale;
    private array $messages;

    public function __construct(
        string $translationDirectory,
        mixed $requestedLocale = 'en',
        array $supportedLocales = ['en'],
    ) {
        // Only explicitly registered, filename-safe locales may be loaded.
        foreach ($supportedLocales as $locale) {
            if (!is_string($locale) || !preg_match('/^[a-z]{2}(?:-[A-Za-z]{2})?$/D', $locale)) {
                throw new InvalidArgumentException('Invalid supported locale');
            }
        }
        $this->locale = is_string($requestedLocale)
            && in_array($requestedLocale, $supportedLocales, true) ? $requestedLocale : 'en';
        $this->messages = require $translationDirectory . '/en.php';
        if ($this->locale !== 'en') {
            $this->messages = array_replace(
                $this->messages,
                require $translationDirectory . '/' . $this->locale . '.php',
            );
        }
    }

    public function locale(): string
    {
        return $this->locale;
    }

    public function messages(): array
    {
        return $this->messages;
    }

    public function translate(string $key, array $parameters = []): string
    {
        $replacements = [];
        foreach ($parameters as $name => $value) {
            $replacements['{' . $name . '}'] = (string) $value;
        }
        return strtr($this->messages[$key] ?? $key, $replacements);
    }
}
