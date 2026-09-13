<?php

declare(strict_types=1);

namespace VPNDetection\Symfony;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use VPNDetection\Middleware\Core;
use VPNDetection\Middleware\Lookup;

/**
 * Classify the visitor, and optionally refuse the request.
 *
 * Subscribes to `kernel.request` just above the router, so a refusal costs no routing
 * and an enriched request reaches every controller.
 *
 * Without a `block_condition` this only enriches the request and never refuses one:
 * the answer is on `$request->attributes->get('vpndetection')`, or
 * `VPNDetectionListener::lookup($request)`.
 */
final class VPNDetectionListener implements EventSubscriberInterface
{
    public const ATTRIBUTE = 'vpndetection';

    public function __construct(private readonly Core $core, private readonly mixed $onBlocked = null)
    {
    }

    /** @return array<string, array{0: string, 1: int}> */
    public static function getSubscribedEvents(): array
    {
        // Symfony's RouterListener is priority 32 (checked, not assumed), so 33 runs
        // just before it: a blocked request is refused without routing it first, and
        // an enriched one still reaches every controller.
        return [KernelEvents::REQUEST => ['onKernelRequest', 33]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            // A sub-request is the same visitor, already classified, and looking them
            // up again would double the cost of every forward() and ESI fragment.
            return;
        }
        $request = $event->getRequest();
        $lookup = $this->core->evaluate($request);
        if ($lookup === null) {
            return;
        }
        $request->attributes->set(self::ATTRIBUTE, $lookup);
        if ($lookup->blocked) {
            $event->setResponse($this->refuse($request, $lookup));
        }
    }

    /** What the listener found out about this visitor, or null when it did not run. */
    public static function lookup(Request $request): ?Lookup
    {
        $found = $request->attributes->get(self::ATTRIBUTE);
        return $found instanceof Lookup ? $found : null;
    }

    private function refuse(Request $request, Lookup $lookup): Response
    {
        if ($this->onBlocked !== null) {
            return ($this->onBlocked)($request, $lookup);
        }
        return new JsonResponse(['error' => 'access denied'], 403);
    }
}
