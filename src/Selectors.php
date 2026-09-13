<?php

declare(strict_types=1);

namespace VPNDetection\Symfony;

use Symfony\Component\HttpFoundation\Request;
use VPNDetection\Middleware\RequestView;
use VPNDetection\Middleware\Selectors as CoreSelectors;

/**
 * The client-address selectors, bound to a Symfony request.
 *
 * There is no portable default, so this is yours to choose - but Symfony already has
 * an answer for the common case, and it is the one to reach for first.
 */
final class Selectors
{
    private static ?CoreSelectors $bound = null;

    private static function bound(): CoreSelectors
    {
        return self::$bound ??= new CoreSelectors(
            static fn (Request $request): RequestView => new RequestView(
                header: static fn (string $name): ?string => $request->headers->get($name),
                frameworkIp: static fn (): ?string => $request->getClientIp(),
            )
        );
    }

    /**
     * `$request->getClientIp()`, which honours Symfony's trusted-proxy configuration.
     *
     * That is the right fix behind a load balancer: set `framework.trusted_proxies`
     * to the proxies actually in front of you and Symfony walks the chain for you.
     * Without it, and behind one, every visitor looks like the load balancer - a
     * datacenter address a hosting rule would block them all for.
     */
    public static function default(): callable
    {
        return self::bound()->default();
    }

    /**
     * An address from `X-Forwarded-For`, ignoring the trusted-proxy configuration.
     *
     * The LEFT-MOST entry (depth 0) is whatever the caller sent, because proxies
     * append to this header. Prefer `trusted_proxies`; reach for this only when you
     * cannot express your topology there.
     */
    public static function xff(int $depth = 0): callable
    {
        return self::bound()->xff($depth);
    }

    /**
     * An address from a single-value header your edge writes -
     * `Selectors::header('CF-Connecting-IP')` behind Cloudflare. Falls back to
     * `getClientIp()` when the header is absent.
     */
    public static function header(string $name): callable
    {
        return self::bound()->header($name);
    }
}
