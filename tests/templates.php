<?php

declare(strict_types=1);

use Neniu\Capture\TemplateRenderer;
use Neniu\Capture\Translator;

require dirname(__DIR__) . '/vendor/autoload.php';
$translator = new Translator(dirname(__DIR__) . '/translations');

$expect = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$renderer = new TemplateRenderer(dirname(__DIR__) . '/templates', $translator);
$context = [
    'assets' => ['devClient' => null, 'stylesheet' => '/build/app-test.css', 'capture' => '/build/capture-test.js',
        'verify' => '/build/verify-test.js', 'share' => '/build/share-test.js'],
    'locale' => $translator->locale(),
    'messages' => $translator->messages() + ['test_payload' => '</script><script>alert(1)</script>'],
    'captureUrl' => '/?lang=en',
    'mapTileUrl' => 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
    'record' => null,
    'hashValid' => false,
];
$capture = $renderer->render('capture.html.twig', $context);
$expect(str_contains($capture, 'id="video"') && str_contains($capture, 'id="capture"'), 'Capture controls');
$expect(str_contains($capture, 'type="module" src="/build/capture-test.js"'), 'Capture module');
$expect(str_contains($capture, 'id="capture-config" type="application/json"'), 'Capture configuration');
$expect(!str_contains($capture, '</script><script>alert(1)</script>'), 'Script JSON escaping');
$expect(substr_count($capture, '</script>') === 2, 'Only JSON and module script tags');
$expect(str_contains($capture, '\\u003C'), 'JSON escaped script payload');

$missing = $renderer->render('verify.html.twig', $context);
$expect(str_contains($missing, $translator->translate('not_found')), 'Missing record message');
$expect(!str_contains($missing, '<script>') && !str_contains($missing, 'id="video"'), 'Verify excludes capture UI');
$expect(!str_contains($missing, 'id="location-map"'), 'No map without a record');
$expect(str_contains($missing, 'href="/?lang=en"'), 'Capture link');

$context['record'] = [
    'code' => '<script>alert(1)</script>',
    'created_at' => '2026-11-11T12:00:00.123Z',
    'image' => ['base64' => 'test-image'],
    'location' => ['latitude' => 50, 'longitude' => 4, 'accuracy_meters' => 12],
];
$context['hashValid'] = true;
$valid = $renderer->render('verify.html.twig', $context);
$expect(str_contains($valid, 'id="location-map"'), 'Recorded location map');
$expect(str_contains($valid, 'src="/build/verify-test.js"'), 'Verification map module');
$expect(str_contains($valid, 'data-accuracy="12"'), 'Recorded accuracy passed to map');
$expect(str_contains($valid, 'data:image/jpeg;base64,test-image'), 'Verified photo');
$expect(str_contains($valid, '&lt;script&gt;') && !str_contains($valid, '<script>'), 'Record HTML escaping');
$expect(str_contains($valid, $translator->translate('not_available')), 'Old records tolerate missing metadata');
$context['record']['metadata'] = [
    'request' => ['ip' => ['address' => '192.0.2.15']],
    'browser' => ['camera_label' => '<script>camera</script>'],
];
$withMetadata = $renderer->render('verify.html.twig', $context);
$expect(str_contains($withMetadata, '192.0.2.15'), 'Stored upload IP is displayed');
$expect(str_contains($withMetadata, '&lt;script&gt;camera&lt;/script&gt;'), 'Camera label is HTML escaped');
$context['hashValid'] = false;
$invalid = $renderer->render('verify.html.twig', $context);
$expect(
    !str_contains($invalid, '<img') && str_contains($invalid, $translator->translate('invalid')),
    'Invalid photo hidden'
);
$share = $renderer->render('share.html.twig', $context);
$expect(str_contains($share, 'id="share-whatsapp"'), 'WhatsApp invitation action');
$expect(str_contains($share, 'data-capture-path="/?lang=en"'), 'Share page links to capture');
$expect(!str_contains($share, 'id="video"'), 'Share page must not request camera access');
$expect(str_contains($share, 'src="/build/share-test.js"'), 'Share page module');
echo "Template tests passed\n";
