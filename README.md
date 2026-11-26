# [<img src="https://s3.vpndetection.io/vpndetection-public/brand/mark.svg" alt="VPNDetection" height="28"/>](https://vpndetection.io/) VPNDetection Symfony Bundle

[![Packagist](https://img.shields.io/packagist/v/vpndetection/symfony.svg)](https://packagist.org/packages/vpndetection/symfony)
[![license](https://img.shields.io/packagist/l/vpndetection/symfony.svg)](LICENSE)

The official Symfony bundle for the [VPNDetection](https://vpndetection.io) API: it checks each visitor's IP for VPN, Tor and proxy use, and can block them with a 403.

It classifies the visitor behind each request as VPN, residential proxy, Tor, hosting, CDN or relay, and hands the answer to your code. Blocking is opt-in.

## Getting Started

```bash
composer require vpndetection/symfony
```

Requires PHP 8.2 or newer and Symfony 6.4, 7 or 8.

No API key needed to start. Without one, a lookup is on Unlimited, the free plan, which answers VPN, Tor and relay detection with no daily or monthly cap. A paid plan's key adds the hosting, CDN and proxy flags and the provider behind each flag: create one in the [console](https://app.vpndetection.io). A free account tries every field.

Register the bundle and configure it:

```php
// config/bundles.php
VPNDetection\Symfony\VPNDetectionBundle::class => ["all" => true],
```

```yaml
# config/packages/vpndetection.yaml
vpndetection:
    api_key: "%env(default::VPNDETECTION_API_KEY)%"
```

`default::` reads an unset `VPNDETECTION_API_KEY` as no key, so the bundle runs keyless until you set one; a plain `%env(VPNDETECTION_API_KEY)%` makes every request a 500 while the variable is unset.

```php
use VPNDetection\Symfony\VPNDetectionListener;

#[Route("/")]
public function index(Request $request): Response
{
    $lookup = VPNDetectionListener::lookup($request);
    return new Response($lookup?->result?->isVpn ? "Hello, VPN user" : "Hello");
}
```

The listener runs just before Symfony's router, so a blocked request costs no route match, and sub-requests are not classified again: every `forward()` and ESI fragment is the same visitor.

By default nothing is blocked. Every request gets an answer and your own code decides what to do with it, since whether a VPN visitor is a problem depends on what they're doing.

## Blocking

Set a `block_condition` and a matching request is answered with `403` and never reaches your code.

```php
'block_condition' => ['isVpn' => true],
```

A condition is written in the shape of a `Result`, and only the members you name are considered. That lets it reach the evidence, not just the flags:

```php
['isVpn' => true, 'vpn' => ['provider' => 'nordvpn']]        // one provider
['isResproxy' => true, 'resproxy' => ['hits' => ['gte' => 5]]]  // a numeric threshold
['vpn' => ['confidence' => ['high', 'medium']]]              // any of these
[['isTor' => true], ['isResproxy' => true]]                  // a list is OR
```

Values are matched by equality, strings without regard to case. A LIST means any-of. A map of `gte`/`gt`/`lte`/`lt` compares numbers, and every bound you give must hold, so two of them are a range. Members set to `false` or `null` are ignored, so a condition states the signals you act on; one that constrains nothing would match every request, and is refused when the middleware is built rather than silently blocking all your traffic.

## Let a verified crawler through

Set `allow_bots` and the bundle sends each request's own `User-Agent` with its lookup when it names a crawler, so the answer says whose bot it names and whether this address bears that out. A request whose verdict is `verified`, a crawler its operator vouches for here, is never refused, whatever `block_condition` matches; its answer is still attached:

```yaml
# config/packages/vpndetection.yaml
vpndetection:
    api_key: "%env(default::VPNDETECTION_API_KEY)%"
    allow_bots: true
    block_condition: { isHosting: true }
```

With it on, a condition can also refuse a forged crawler: `{ userAgent: { verdict: spoofed } }`. Any other agent, or none, is not sent, since its verdict could only be `not_bot`. One address is looked up with at most four crawler agents an hour: past those its lookup sends none, and a request passes as a crawler only where the answer's `bot.verified` is true. Only an answer with every field carries the verdict, so a keyless client never gets one and nothing is let through by it; the bundle warns once when an agent was sent and the answer had no verdict. Off by default, since on, answers are cached per address and user agent rather than per address.

## Where the client address comes from

Get this setting right, or none of the above works.

By default the listener uses `$request->getClientIp()`, which honours Symfony's trusted-proxy configuration. That is the right fix behind a load balancer: set `framework.trusted_proxies` to the proxies actually in front of you and Symfony walks the chain for you. Without it, and behind one, every visitor looks like the load balancer, and that is the address that gets checked: VPN visitors pass unnoticed, and if it is a hosting address, a hosting rule blocks everyone. A load balancer in your own network usually has a private address, which the listener answers locally without a lookup, flagging nobody and logging a warning.

For an edge that writes the address into its own header, name the header:

```yaml
# config/packages/vpndetection.yaml
vpndetection:
    client_ip_header: CF-Connecting-IP
```

A callable cannot live in YAML, so a header NAME is the config key. Anything more exotic, a chain depth or your own logic, means replacing the `vpndetection.listener` service, which is Symfony's own answer rather than a config key we would have to keep inventing.

`\VPNDetection\Symfony\Selectors::xff()` reads `X-Forwarded-For` directly, and `Selectors::xff(1)` counts one trusted hop from the right. The left-most entry is whatever the caller sent, since proxies append to that header, so trust it only when an edge you control overwrites it. Both are for a replaced listener service; prefer `framework.trusted_proxies`.

If the address resolves to a private one, the middleware says so once each time the application boots, which under PHP-FPM is every request. That is expected locally and is the signal to fix your configuration anywhere else.

## When a lookup fails

The request is let through, and the reason is on the answer's `error`. Our outage should not become yours, so a network failure, an exhausted quota or a rejected key all fail open. Set `fail_closed` to block instead. Private addresses are answered locally and never fail, so this will not lock you out in development.

## Cost and latency

Answers are cached for an hour in your app's `cache.app` pool, keyed by a hash of your API key and the visitor's address, so a returning visitor costs nothing even under PHP-FPM, where the application is built afresh for every request. Private addresses never leave the process. A lookup is one request to our API, bounded at 2.5 seconds by default and not retried: on a request path, failing open fast beats holding a visitor while we retry.

Name another pool with `cache_pool`, or set it to `false` to keep answers in the client alone, which under PHP-FPM lasts one request:

```yaml
vpndetection:
    cache_pool: cache.vpndetection
```

Skip the paths that never need an answer, such as a health check or static assets. Each is a regular expression matched against the path, the way `access_control`'s `path` is, and a skipped request carries no lookup:

```yaml
vpndetection:
    skip_paths: ["^/healthz", "^/assets/"]
```

Beyond a few million distinct visitors a day, stop calling the API per request: [download the dataset](https://vpndetection.io/#databases) and look addresses up locally instead.

## Absent is not false

Unlimited, with a key or without one, leaves out `isHosting`, `isCdn` and the four proxy flags, and of the detail behind each flag it answers only Tor's and relay's. A property your plan does not include is `null`, which means "not in your plan" rather than "checked, and no".

```php
$lookup->result->isHosting ?? false   // when you only want the flag
```

A `block_condition` naming a member your plan does not serve can never match, so the middleware warns, once each time the application boots, instead of failing silently. Set `on_missing_field` to `throw` to make it an error.

## Other Libraries

There are official VPNDetection client libraries available for many languages including PHP, Python, Go, Java, Ruby, and many popular frameworks such as Django, Rails, and Laravel. See our GitHub at https://github.com/vpndetection-io for more.

## About VPNDetection

VPN Detection API: Accurate anonymity detection identifying VPNs, residential proxies, hosting servers, Tor nodes, CDNs, relays and more.

[<picture><source media="(prefers-color-scheme: dark)" srcset="https://s3.vpndetection.io/vpndetection-public/brand/logo-dark.svg"><img src="https://s3.vpndetection.io/vpndetection-public/brand/logo.svg" alt="VPNDetection" height="40"/></picture>](https://vpndetection.io/)

## License

This project is licensed under the [MIT License](LICENSE).
