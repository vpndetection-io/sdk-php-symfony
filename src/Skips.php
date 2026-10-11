<?php

declare(strict_types=1);

namespace VPNDetection\Symfony;

use Closure;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestMatcher\PathRequestMatcher;

/**
 * The `skip` that the bundle's `skip_paths` configures, since a callable cannot
 * live in YAML.
 */
final class Skips
{
    /**
     * A request whose path matches any of `$patterns` is never looked up. Each is
     * a regular expression matched against the path the way `access_control`'s
     * `path` is, so `^/healthz` skips that path and everything under it.
     *
     * @param list<string> $patterns
     */
    public static function paths(array $patterns): Closure
    {
        $matchers = array_map(static fn (string $pattern) => new PathRequestMatcher($pattern), $patterns);
        return static function (Request $request) use ($matchers): bool {
            foreach ($matchers as $matcher) {
                if ($matcher->matches($request)) {
                    return true;
                }
            }
            return false;
        };
    }
}
