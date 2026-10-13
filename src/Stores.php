<?php

declare(strict_types=1);

namespace VPNDetection\Symfony;

use Psr\Cache\CacheItemPoolInterface;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Cache\Psr16Cache;

/**
 * @internal
 *
 * The cache pool the client keeps its answers in, as the PSR-16 store it takes.
 */
final class Stores
{
    public static function psr16(?CacheItemPoolInterface $pool): ?CacheInterface
    {
        return $pool === null ? null : new Psr16Cache($pool);
    }
}
