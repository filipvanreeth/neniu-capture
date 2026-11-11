<?php

declare(strict_types=1);

namespace Neniu\Capture;

final class ClientIpResolver
{
    public function __construct(private array $trustedProxies = [])
    {
    }

    public function resolve(array $server): array
    {
        $peer = $server['REMOTE_ADDR'] ?? '';
        if (!filter_var($peer, FILTER_VALIDATE_IP)) {
            return ['address' => null, 'source' => 'remote_addr'];
        }
        $chain = explode(',', $server['HTTP_X_FORWARDED_FOR'] ?? '');
        $address = $peer;
        $source = 'remote_addr';
        while ($chain && in_array($address, $this->trustedProxies, true)) {
            $candidate = trim(array_pop($chain));
            if (!filter_var($candidate, FILTER_VALIDATE_IP)) {
                return ['address' => $peer, 'source' => 'remote_addr'];
            }
            $address = $candidate;
            $source = 'trusted_proxy_x_forwarded_for';
        }
        return ['address' => $address, 'source' => $source];
    }
}
