<?php

declare(strict_types=1);

namespace VPNDetection\Symfony\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use VPNDetection\Symfony\VPNDetectionBundle;
use VPNDetection\Symfony\VPNDetectionListener;

/**
 * The setup the README gives, end to end: its `config/packages/vpndetection.yaml`
 * value for the key, read from the environment through the container, and a real
 * HTTP server standing in for the API, asked about a PUBLIC visitor (a private
 * address is answered without asking it). Every other test hands the bundle a
 * client it built, so a key lost between the environment and the request would
 * pass them all, as a plain `%env()%` made every keyless request a 500.
 */
final class ReadmeSetupTest extends TestCase
{
    private const PUBLIC_IP = '146.70.116.130';
    private const KEY = 'k_readme_setup';

    /** @var resource|null */
    private static $server = null;
    private static int $port = 0;
    private static string $dir = '';

    public static function setUpBeforeClass(): void
    {
        self::$dir = sys_get_temp_dir() . '/vpndetection-symfony-' . bin2hex(random_bytes(6));
        mkdir(self::$dir);
        file_put_contents(self::$dir . '/answer.json', json_encode(['ip' => self::PUBLIC_IP, 'is_vpn' => false]));
        self::$port = random_int(20000, 60000);
        self::$server = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . self::$port, __DIR__ . '/fake-api.php'],
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            null,
            ['FAKE_API_DIR' => self::$dir],
        );
        for ($i = 0; $i < 100; $i++) {
            $socket = @fsockopen('127.0.0.1', self::$port);
            if ($socket !== false) {
                fclose($socket);
                return;
            }
            usleep(50_000);
        }
        self::fail('the stand-in API never listened');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$server !== null) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }
    }

    /** The `api_key` value of the README's first `config/packages/vpndetection.yaml`. */
    private static function readmeApiKey(): string
    {
        $readme = (string) file_get_contents(__DIR__ . '/../README.md');
        self::assertSame(
            1,
            preg_match('/```yaml\n# config\/packages\/vpndetection\.yaml\n.*?\n\s+api_key: "([^"]+)"\n.*?```/s', $readme, $m),
            'the README no longer shows the key in config/packages/vpndetection.yaml',
        );
        return $m[1];
    }

    /** The variable the README's `%env(...)%` reads. */
    private static function variable(): string
    {
        self::assertSame(1, preg_match('/^%env\((?:[a-z]*:)*([A-Z][A-Z0-9_]*)\)%$/', self::readmeApiKey(), $m));
        return $m[1];
    }

    private static function setVariable(?string $value): void
    {
        $name = self::variable();
        if ($value === null) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
            return;
        }
        putenv("{$name}={$value}");
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }

    protected function tearDown(): void
    {
        self::setVariable(null);
    }

    /** @return list<array{path: string, authorization: ?string}> */
    private static function visit(): array
    {
        @unlink(self::$dir . '/requests.jsonl');
        $builder = new ContainerBuilder();
        $builder->setParameter('kernel.environment', 'test');
        $builder->setParameter('kernel.build_dir', sys_get_temp_dir());
        // Only what a test needs on top of the README: where the API is.
        (new VPNDetectionBundle())->getContainerExtension()->load([[
            'api_key' => self::readmeApiKey(),
            'base_url' => 'http://127.0.0.1:' . self::$port,
            'cache_pool' => false,
        ]], $builder);
        // A kernel's dumped container reads each `%env()%` when the service is built;
        // resolving them here, with the variable already set or unset, is the same.
        $builder->compile(resolveEnvPlaceholders: true);

        $listener = $builder->get('vpndetection.listener');
        self::assertInstanceOf(VPNDetectionListener::class, $listener);
        $kernel = new class implements HttpKernelInterface {
            public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
            {
                return new Response('ok');
            }
        };
        $event = new RequestEvent($kernel, new Request(server: ['REMOTE_ADDR' => self::PUBLIC_IP]), HttpKernelInterface::MAIN_REQUEST);
        $listener->onKernelRequest($event);
        self::assertNull($event->getResponse(), 'the visitor was refused');

        $log = (string) @file_get_contents(self::$dir . '/requests.jsonl');
        return array_map(
            static fn (string $line): array => json_decode($line, true),
            array_values(array_filter(explode("\n", $log))),
        );
    }

    public function testTheKeyFromTheReadmesConfigReachesTheApi(): void
    {
        self::setVariable(self::KEY);

        self::assertSame(
            [['path' => '/' . self::PUBLIC_IP, 'authorization' => 'Bearer ' . self::KEY]],
            self::visit(),
        );
    }

    public function testWithoutTheVariableTheLookupIsKeyless(): void
    {
        self::setVariable(null);

        self::assertSame(
            [['path' => '/' . self::PUBLIC_IP, 'authorization' => null]],
            self::visit(),
        );
    }
}
