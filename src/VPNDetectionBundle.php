<?php

declare(strict_types=1);

namespace VPNDetection\Symfony;

use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use VPNDetection\Middleware\Core;
use VPNDetection\Middleware\Options;

/**
 * Registers the listener from `config/packages/vpndetection.yaml`.
 *
 * An AbstractBundle, so there is no separate Extension or Configuration class and the
 * whole wiring is this file.
 */
final class VPNDetectionBundle extends AbstractBundle
{
    protected string $extensionAlias = 'vpndetection';

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('api_key')->defaultNull()->end()
                ->scalarNode('base_url')->defaultNull()->end()
                ->floatNode('timeout')->defaultValue(2.5)->end()
                ->integerNode('retries')->defaultValue(0)->end()
                ->booleanNode('fail_closed')->defaultFalse()->end()
                ->enumNode('on_missing_field')
                    ->values(['warn', 'throw', 'ignore'])->defaultValue('warn')->end()
                ->variableNode('block_condition')->defaultNull()->end()
                // A callable cannot live in YAML, so the common case - an edge that
                // writes the address into its own header - is a header NAME instead.
                // Anything more exotic means replacing the vpndetection.listener
                // service, which is the Symfony answer rather than a config key.
                ->scalarNode('client_ip_header')->defaultNull()->end()
            ->end();
    }

    /** @param array<string, mixed> $config */
    public function loadExtension(array $config, $container, ContainerBuilder $builder): void
    {
        $options = new Definition(Options::class);
        $options->setArguments([
            null,
            $config['api_key'] ?? null,
            $config['base_url'] ?? null,
            (float) ($config['timeout'] ?? 2.5),
            (int) ($config['retries'] ?? 0),
            null,
            $config['block_condition'] ?? null,
            (bool) ($config['fail_closed'] ?? false),
            (string) ($config['on_missing_field'] ?? 'warn'),
        ]);

        $header = $config['client_ip_header'] ?? null;
        if ($header === null) {
            $selector = new Definition('Closure');
            $selector->setFactory([Selectors::class, 'default']);
        } else {
            $selector = new Definition('Closure');
            $selector->setFactory([Selectors::class, 'header']);
            $selector->setArguments([$header]);
        }

        $core = new Definition(Core::class);
        $core->setArguments([$options, $selector]);

        $listener = new Definition(VPNDetectionListener::class);
        $listener->setArguments([$core]);
        $listener->addTag('kernel.event_subscriber');
        $listener->setPublic(true);

        $builder->setDefinition('vpndetection.listener', $listener);
    }
}
