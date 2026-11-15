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
 * The bundle configured as the README says, `api_key: "%env(VPNDETECTION_API_KEY)%"`
 * and nothing else but where the API is, serving a visitor at a PUBLIC address to a
 * real HTTP origin. A loopback or documentation-range visitor is a bogon, answered
 * with no request, so a bundle that dropped the key on its way to the core would
 * pass such a test.
 */
final class ReadmeKeyTest extends TestCase
{
    private const PUBLIC_IP = '45.83.91.1';

    /** @var resource|null */
    private $server = null;

    private string $dir = '';

    private int $port = 0;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/vpnd-symfony-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
        file_put_contents($this->dir . '/router.php', <<<'PHP'
            <?php
            file_put_contents(__DIR__ . '/requests.log', json_encode([
                'path' => parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH),
                'authorization' => $_SERVER['HTTP_AUTHORIZATION'] ?? null,
            ]) . "\n", FILE_APPEND);
            header('content-type: application/json');
            echo '{"ip":"45.83.91.1","is_vpn":true}';
            PHP);
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $this->port = (int) substr((string) stream_socket_get_name($probe, false), strlen('127.0.0.1:'));
        fclose($probe);
        $this->server = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$this->port}", $this->dir . '/router.php'],
            [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']],
            $pipes,
        );
        for ($i = 0; $i < 100 && @fsockopen('127.0.0.1', $this->port) === false; $i++) {
            usleep(20_000);
        }
        putenv('VPNDETECTION_API_KEY=secret-key');
        $_ENV['VPNDETECTION_API_KEY'] = $_SERVER['VPNDETECTION_API_KEY'] = 'secret-key';
    }

    protected function tearDown(): void
    {
        putenv('VPNDETECTION_API_KEY');
        unset($_ENV['VPNDETECTION_API_KEY'], $_SERVER['VPNDETECTION_API_KEY']);
        if ($this->server !== null) {
            proc_terminate($this->server);
            proc_close($this->server);
        }
        @unlink($this->dir . '/requests.log');
        unlink($this->dir . '/router.php');
        rmdir($this->dir);
    }

    public function testTheReadmesKeyReachesTheApiForAPublicVisitor(): void
    {
        $builder = new ContainerBuilder();
        $builder->setParameter('kernel.environment', 'test');
        $builder->setParameter('kernel.build_dir', sys_get_temp_dir());
        (new VPNDetectionBundle())->getContainerExtension()->load([
            ['api_key' => '%env(VPNDETECTION_API_KEY)%'],
            ['base_url' => "http://127.0.0.1:{$this->port}"],
        ], $builder);
        $builder->compile(true);
        $listener = $builder->get('vpndetection.listener');
        self::assertInstanceOf(VPNDetectionListener::class, $listener);

        $kernel = new class implements HttpKernelInterface {
            public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
            {
                return new Response('ok');
            }
        };
        $request = new Request(server: ['REMOTE_ADDR' => self::PUBLIC_IP]);
        $listener->onKernelRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));

        $log = array_values(array_filter(explode("\n", (string) @file_get_contents($this->dir . '/requests.log'))));
        self::assertCount(1, $log, 'a public visitor is looked up once');
        $seen = json_decode($log[0], true);
        self::assertStringEndsWith('/' . self::PUBLIC_IP, $seen['path']);
        self::assertSame('Bearer secret-key', $seen['authorization']);
    }
}
