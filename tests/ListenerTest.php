<?php

declare(strict_types=1);

namespace VPNDetection\Symfony\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\HttpKernel\KernelEvents;
use VPNDetection\Client;
use VPNDetection\Middleware\Core;
use VPNDetection\Middleware\Options as MwOptions;
use VPNDetection\Options;
use VPNDetection\Symfony\Selectors;
use VPNDetection\Symfony\VPNDetectionBundle;
use VPNDetection\Symfony\VPNDetectionListener;

/**
 * The listener, driven with real Symfony request events, plus the shared corpus.
 *
 * A request with no REMOTE_ADDR resolves to nothing, and one from 127.0.0.1 is a bogon
 * answered locally. Anything that needs a served answer therefore has to arrive
 * wearing a public address, through a selector.
 */
final class ListenerTest extends TestCase
{
    private const PUBLIC_IP = '45.83.91.1';

    /** @return array<string, mixed> */
    private static function corpus(): array
    {
        /** @var array<string, mixed> $data */
        $data = json_decode(
            (string) file_get_contents(__DIR__ . '/../testdata/testdata.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        return $data['middleware'];
    }

    private static function toIdiom(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map([self::class, 'toIdiom'], $value);
        }
        $out = [];
        foreach ($value as $key => $entry) {
            $out[lcfirst(str_replace('_', '', ucwords((string) $key, '_')))] = self::toIdiom($entry);
        }
        return $out;
    }

    /** @param array<string, mixed> $body */
    private static function serving(array $body, int $status = 200): Stub
    {
        $ip = $body['ip'] ?? self::PUBLIC_IP;
        return new Stub(Stub::lookups([$ip => ['status' => $status, 'body' => $body]]));
    }

    private static function client(Stub $stub): Client
    {
        return new Client(new Options(cache: false, retries: 0, httpClient: $stub->client));
    }

    private static function request(array $headers = [], string $ip = '127.0.0.1'): Request
    {
        $server = ['REMOTE_ADDR' => $ip];
        foreach ($headers as $name => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }
        return new Request(server: $server);
    }

    private static function dispatch(VPNDetectionListener $listener, Request $request): ?Response
    {
        $kernel = new class implements HttpKernelInterface {
            public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
            {
                return new Response('ok');
            }
        };
        $event = new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);
        $listener->onKernelRequest($event);
        return $event->getResponse();
    }

    private static function listener(MwOptions $options, mixed $onBlocked = null): VPNDetectionListener
    {
        return new VPNDetectionListener(new Core($options, Selectors::default()), $onBlocked);
    }

    public function testItRunsBeforeTheRouter(): void
    {
        // Not a taste question: refusing after routing would cost a route match on
        // every blocked request, and any router-priority listener would see one.
        $events = VPNDetectionListener::getSubscribedEvents();
        self::assertSame(['onKernelRequest', 33], $events[KernelEvents::REQUEST]);
    }

    public function testEnrichesTheRequestAndLeavesTheDecisionToTheApp(): void
    {
        $stub = self::serving(['ip' => self::PUBLIC_IP, 'is_vpn' => true]);
        $listener = self::listener(new MwOptions(
            client: self::client($stub),
            ipSelector: fn (Request $r): string => self::PUBLIC_IP,
        ));
        $request = self::request();
        self::assertNull(self::dispatch($listener, $request));

        $lookup = VPNDetectionListener::lookup($request);
        self::assertNotNull($lookup);
        self::assertTrue($lookup->result?->isVpn);
        self::assertSame(self::PUBLIC_IP, $lookup->ip);
    }

    public function testBlocksWhenTheConditionMatches(): void
    {
        $stub = self::serving(['ip' => self::PUBLIC_IP, 'is_vpn' => true]);
        $listener = self::listener(new MwOptions(
            client: self::client($stub),
            ipSelector: fn (Request $r): string => self::PUBLIC_IP,
            blockCondition: ['isVpn' => true],
        ));
        $response = self::dispatch($listener, self::request());
        self::assertSame(403, $response?->getStatusCode());
        self::assertSame('{"error":"access denied"}', $response?->getContent());
    }

    public function testOnBlockedReplacesTheRefusal(): void
    {
        $stub = self::serving(['ip' => self::PUBLIC_IP, 'is_vpn' => true, 'vpn' => ['provider' => 'nordvpn']]);
        $listener = self::listener(
            new MwOptions(
                client: self::client($stub),
                ipSelector: fn (Request $r): string => self::PUBLIC_IP,
                blockCondition: ['isVpn' => true],
            ),
            fn (Request $r, $lookup): Response => new JsonResponse(
                ['why' => $lookup->result->vpn->provider],
                451
            ),
        );
        $response = self::dispatch($listener, self::request());
        self::assertSame(451, $response?->getStatusCode());
        self::assertSame('{"why":"nordvpn"}', $response?->getContent());
    }

    public function testASubRequestIsNotClassifiedAgain(): void
    {
        $stub = self::serving(['ip' => self::PUBLIC_IP, 'is_vpn' => true]);
        $listener = self::listener(new MwOptions(
            client: self::client($stub),
            ipSelector: fn (Request $r): string => self::PUBLIC_IP,
        ));
        $kernel = new class implements HttpKernelInterface {
            public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
            {
                return new Response('ok');
            }
        };
        $event = new RequestEvent($kernel, self::request(), HttpKernelInterface::SUB_REQUEST);
        $listener->onKernelRequest($event);

        // Every forward() and ESI fragment is a sub-request for the SAME visitor, so
        // classifying one would multiply the cost of a single page view.
        self::assertSame([], $stub->calls);
    }

    public function testAFailingLookupLetsTheVisitorThrough(): void
    {
        $failing = self::client(self::serving(['ip' => self::PUBLIC_IP, 'rc' => 'boom'], 500));
        $listener = self::listener(new MwOptions(
            client: $failing,
            ipSelector: fn (Request $r): string => self::PUBLIC_IP,
            blockCondition: ['isVpn' => true],
        ));
        $request = self::request();
        self::assertNull(self::dispatch($listener, $request));
        self::assertNotNull(VPNDetectionListener::lookup($request)?->error);
    }

    public function testAForgedXForwardedForIsIgnoredByDefault(): void
    {
        $stub = self::serving(['ip' => self::PUBLIC_IP, 'is_vpn' => true]);
        $listener = self::listener(new MwOptions(client: self::client($stub)));
        $request = self::request(['X-Forwarded-For' => self::PUBLIC_IP]);
        self::dispatch($listener, $request);

        self::assertSame('127.0.0.1', VPNDetectionListener::lookup($request)?->ip);
        self::assertSame([], $stub->calls, 'a bogon is answered locally, so nothing was asked');

        $explicit = self::serving(['ip' => self::PUBLIC_IP, 'is_vpn' => true]);
        $viaSelector = self::listener(new MwOptions(
            client: self::client($explicit),
            ipSelector: Selectors::xff(),
        ));
        $forged = self::request(['X-Forwarded-For' => self::PUBLIC_IP]);
        self::dispatch($viaSelector, $forged);
        self::assertSame(self::PUBLIC_IP, VPNDetectionListener::lookup($forged)?->ip);
    }

    public function testCorpusConditions(): void
    {
        foreach (self::corpus()['conditions'] as $case) {
            $why = "{$case['name']}: {$case['why']}";
            $ip = $case['bogon'] ?? $case['body']['ip'];
            $stub = self::serving($case['body'] ?? ['ip' => $ip]);
            $warnings = [];
            $listener = self::listener(new MwOptions(
                client: self::client($stub),
                ipSelector: fn (Request $r) => $ip,
                blockCondition: self::toIdiom($case['condition']),
                onWarn: function (string $m) use (&$warnings): void {
                    $warnings[] = $m;
                },
            ));
            $response = self::dispatch($listener, self::request());
            self::assertSame($case['expect']['blocked'] ? 403 : null, $response?->getStatusCode(), $why);

            $reported = array_values(array_filter(
                $warnings,
                static fn (string $m): bool => str_contains($m, 'does not include')
            ));
            self::assertCount($case['expect']['missing'] === [] ? 0 : 1, $reported, $why);
        }
    }

    /**
     * The bundle's wiring, compiled for real.
     *
     * A YAML key that silently produces no selector, or one the container cannot
     * instantiate, looks identical to a working config until the first request.
     */
    public function testTheBundleCompilesAndWiresTheConfiguredSelector(): void
    {
        foreach ([null, 'CF-Connecting-IP'] as $header) {
            $builder = new ContainerBuilder();
            $bundle = new VPNDetectionBundle();
            $config = ['api_key' => 'k', 'client_ip_header' => $header];
            $instanceof = [];
            $bundle->loadExtension($config, new ContainerConfigurator(
                $builder,
                new PhpFileLoader($builder, new FileLocator()),
                $instanceof,
                __DIR__,
                'vpndetection'
            ), $builder);
            $builder->compile();

            $listener = $builder->get('vpndetection.listener');
            self::assertInstanceOf(VPNDetectionListener::class, $listener);
        }
    }

    public function testCorpusRefusesAConditionThatConstrainsNothing(): void
    {
        foreach (self::corpus()['invalidConditions'] as $case) {
            $refused = false;
            try {
                self::listener(new MwOptions(blockCondition: self::toIdiom($case['condition'])));
            } catch (\InvalidArgumentException $e) {
                $refused = str_contains($e->getMessage(), 'constrains nothing');
            }
            self::assertTrue($refused, "{$case['name']}: {$case['why']}");
        }
    }
}
