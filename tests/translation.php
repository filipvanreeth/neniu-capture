<?php

declare(strict_types=1);

use Neniu\Verify\Translator;

require dirname(__DIR__) . '/vendor/autoload.php';

$directory = sys_get_temp_dir() . '/translation-test-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
try {
    file_put_contents($directory . '/en.php', "<?php return ['greeting' => 'Hello {name}', 'fallback' => 'English'];");
    file_put_contents($directory . '/nl.php', "<?php return ['greeting' => 'Dag {name}'];");
    $english = new Translator($directory);
    $dutch = new Translator($directory, 'nl', ['en', 'nl']);
    if (
        $english->locale() !== 'en' || $dutch->locale() !== 'nl'
        || $dutch->translate('greeting', ['name' => 'Filip']) !== 'Dag Filip'
        || $dutch->translate('fallback') !== 'English'
        || $dutch->translate('unknown') !== 'unknown'
        || $english->translate('greeting', ['name' => 'Filip']) !== 'Hello Filip'
        || $dutch->messages()['greeting'] !== 'Dag {name}'
    ) {
        throw new RuntimeException('Translation, fallback, interpolation or instance isolation failed');
    }
    foreach (['fr', '../nl', ['nl'], null] as $invalid) {
        if ((new Translator($directory, $invalid, ['en', 'nl']))->locale() !== 'en') {
            throw new RuntimeException('Invalid requested locale did not fall back');
        }
    }
    try {
        new Translator($directory, '../nl', ['en', '../nl']);
        throw new RuntimeException('Unsafe configured locale accepted');
    } catch (InvalidArgumentException $expected) {
    }
    echo "Translation tests passed\n";
} finally {
    unlink($directory . '/en.php');
    unlink($directory . '/nl.php');
    rmdir($directory);
}
