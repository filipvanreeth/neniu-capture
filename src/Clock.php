<?php

declare(strict_types=1);

namespace Neniu\Capture;

use DateTimeImmutable;
use DateTimeZone;

final class Clock
{
    public function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\\TH:i:s.v\\Z');
    }

    public function fromBrowserTimestamp(mixed $value): ?string
    {
        if (!is_string($value) || !preg_match('/^[0-9]{1,15}$/D', $value)) {
            return null;
        }
        $milliseconds = (int) $value;
        if ($milliseconds <= 0 || $milliseconds > 253402300799999) {
            return null;
        }
        return gmdate('Y-m-d\\TH:i:s', intdiv($milliseconds, 1000))
            . sprintf('.%03dZ', $milliseconds % 1000);
    }
}
