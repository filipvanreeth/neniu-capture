<?php
declare(strict_types=1);

// Only explicitly registered locales may be loaded from disk.
$supportedLocales = ['en'];
$requestedLocale = $_GET['lang'] ?? 'en';
$locale = is_string($requestedLocale) && in_array($requestedLocale, $supportedLocales, true)
    ? $requestedLocale : 'en';
$messages = require __DIR__ . '/translations/en.php';
if ($locale !== 'en') {
    $messages = array_replace($messages, require __DIR__ . "/translations/$locale.php");
}

function t(string $key, array $parameters = []): string
{
    global $messages;
    $replacements = [];
    foreach ($parameters as $name => $value) {
        $replacements['{' . $name . '}'] = (string) $value;
    }
    return strtr($messages[$key] ?? $key, $replacements);
}

function e(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
