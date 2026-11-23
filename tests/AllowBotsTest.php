<?php

declare(strict_types=1);

namespace VPNDetection\Symfony\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Controller\ArgumentResolver;
use Symfony\Component\HttpKernel\Controller\ControllerResolverInterface;
use Symfony\Component\HttpKernel\HttpKernel;
use VPNDetection\Client;
use VPNDetection\Options;
use VPNDetection\Symfony\VPNDetectionBundle;
use VPNDetection\Symfony\VPNDetectionListener;

/**
 * `allow_bots` as `config/packages/vpndetection.yaml` gives it: through the bundle's
 * config tree and compiled container, then a request handled by a real HttpKernel
 * from a public peer read by the default selector.
 */
final class AllowBotsTest extends TestCase
{
    private const PEER = '66.249.66.1';

    private const GOOGLEBOT = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';

    private Stub $stub;

    /** @var list<string> */
    private array $warnings = [];

    /** @param array<string, mixed> $config */
    private function kernel(?string $verdict, array $config): HttpKernel
    {
        $body = ['ip' => self::PEER, 'is_vpn' => false, 'is_hosting' => true];
        if ($verdict !== null) {
            $body['user_agent'] = ['agent' => 'Googlebot', 'kind' => 'bot', 'verdict' => $verdict];
        }
        $this->stub = new Stub(Stub::lookups([self::PEER => ['status' => 200, 'body' => $body]]));

        $builder = new ContainerBuilder();
        $builder->setParameter('kernel.environment', 'test');
        $builder->setParameter('kernel.build_dir', sys_get_temp_dir());
        (new VPNDetectionBundle())->getContainerExtension()->load([[...$config, 'cache_pool' => false]], $builder);
        // YAML cannot name the client answering, the stub here, nor a warning sink.
        $builder->register('test.client', Client::class)->setSynthetic(true)->setPublic(true);
        $builder->register('test.on_warn', \Closure::class)->setSynthetic(true)->setPublic(true);
        $options = $builder->getDefinition('vpndetection.listener')->getArgument(0)->getArgument(0);
        $options->replaceArgument(0, new Reference('test.client'));
        $options->replaceArgument(10, new Reference('test.on_warn'));
        $builder->compile();
        $builder->set('test.client', new Client(new Options(cache: false, retries: 0, httpClient: $this->stub->client)));
        $builder->set('test.on_warn', function (string $m): void {
            $this->warnings[] = $m;
        });
        $listener = $builder->get('vpndetection.listener');
        self::assertInstanceOf(VPNDetectionListener::class, $listener);

        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber($listener);
        $controllers = new class implements ControllerResolverInterface {
            public function getController(Request $request): callable|false
            {
                return static fn (): Response => new Response('ok');
            }
        };
        return new HttpKernel($dispatcher, $controllers, new RequestStack(), new ArgumentResolver());
    }

    private static function visit(HttpKernel $kernel, string $agent = self::GOOGLEBOT): int
    {
        return $kernel->handle(Request::create('/', server: ['REMOTE_ADDR' => self::PEER, 'HTTP_USER_AGENT' => $agent]))
            ->getStatusCode();
    }

    /** @return array<string, mixed> */
    private function sent(): array
    {
        self::assertSame(['/' . self::PEER], $this->stub->calls, 'the public peer, looked up once');
        parse_str($this->stub->requests[0]->getUri()->getQuery(), $query);
        return $query;
    }

    public function testTheRequestsOwnUserAgentReachesTheOrigin(): void
    {
        self::assertSame(200, self::visit($this->kernel('verified', ['allow_bots' => true])));
        self::assertSame(['user_agent' => self::GOOGLEBOT], $this->sent());
    }

    /** @return array<string, array{0: string, 1: int}> */
    public static function verdicts(): array
    {
        return ['verified' => ['verified', 200], 'spoofed' => ['spoofed', 403], 'not_bot' => ['not_bot', 403]];
    }

    #[DataProvider('verdicts')]
    public function testAVerifiedCrawlerPassesAConditionItMeetsAndOthersAreRefused(string $verdict, int $status): void
    {
        $kernel = $this->kernel($verdict, ['allow_bots' => true, 'block_condition' => ['isHosting' => true]]);

        self::assertSame($status, self::visit($kernel));
        self::assertSame(['user_agent' => self::GOOGLEBOT], $this->sent());
    }

    public function testOffByDefaultNoUserAgentIsSentAndTheConditionHolds(): void
    {
        self::assertSame(403, self::visit($this->kernel('verified', ['block_condition' => ['isHosting' => true]])));
        self::assertSame([], $this->sent());
    }

    public function testAnEmptyUserAgentSendsNone(): void
    {
        self::assertSame(200, self::visit($this->kernel(null, ['allow_bots' => true]), ''));
        self::assertSame([], $this->sent());
    }

    public function testAConditionOnTheVerdictRefusesAForgedCrawler(): void
    {
        $kernel = $this->kernel('spoofed', [
            'allow_bots' => true,
            'block_condition' => ['userAgent' => ['verdict' => 'spoofed']],
        ]);

        self::assertSame(403, self::visit($kernel));
        self::assertSame(['user_agent' => self::GOOGLEBOT], $this->sent());
    }

    public function testAnAnswerWithoutAVerdictWarnsOnce(): void
    {
        $kernel = $this->kernel(null, ['allow_bots' => true]);

        self::assertSame(200, self::visit($kernel));
        self::assertSame(200, self::visit($kernel));
        self::assertCount(1, $this->warnings);
        self::assertStringContainsString('user_agent', $this->warnings[0]);
    }
}
