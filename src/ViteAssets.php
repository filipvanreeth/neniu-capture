<?php
declare(strict_types=1);

namespace Neniu\Verify;

use RuntimeException;

final class ViteAssets
{
    public function __construct(
        private string $buildDirectory,
        private string $basePath = '',
        private ?string $devServer = null,
    ) {
    }

    public function forPage(bool $capture): array
    {
        if ($this->devServer !== null && $this->devServer !== '') {
            $server = rtrim($this->devServer, '/');
            return [
                'devClient' => $server . '/@vite/client',
                'stylesheet' => $server . '/assets/css/app.css',
                'capture' => $capture ? $server . '/assets/js/capture.js' : null,
            ];
        }
        $path = $this->buildDirectory . '/.vite/manifest.json';
        if (!is_file($path)) {
            throw new RuntimeException('Frontend build missing. Run npm ci and npm run build.');
        }
        $manifest = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $url = function (string $entry) use ($manifest): string {
            if (!isset($manifest[$entry]['file'])) {
                throw new RuntimeException('Missing Vite manifest entry: ' . $entry);
            }
            return $this->basePath . '/public/build/' . $manifest[$entry]['file'];
        };
        return [
            'devClient' => null,
            'stylesheet' => $url('assets/css/app.css'),
            'capture' => $capture ? $url('assets/js/capture.js') : null,
        ];
    }
}
