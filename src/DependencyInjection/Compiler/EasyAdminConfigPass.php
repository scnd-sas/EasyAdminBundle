<?php

/*
 * This file is part of the Second package.
 *
 * © Second <contact@scnd.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace EasyCorp\Bundle\EasyAdminBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * @author Konstantin Grachev <me@grachevko.ru>
 */
final class EasyAdminConfigPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $configPasses = $this->findAndSortTaggedServices('easyadmin.config_pass', $container);
        $definition = $container->getDefinition('easyadmin.config.manager');

        foreach ($configPasses as $service) {
            $definition->addMethodCall('addConfigPass', [$service]);
        }
    }

    /**
     * BC for PHP 5.3
     * To be replaced by the usage of the \Symfony\Component\DependencyInjection\Compiler\PriorityTaggedServiceTrait
     * when 5.3 support is dropped.
     *
     * @param $tagName
     *
     * @return array
     */
    private function findAndSortTaggedServices($tagName, ContainerBuilder $container)
    {
        $services = [];

        foreach ($container->findTaggedServiceIds($tagName, true) as $serviceId => $attributes) {
            $priority = isset($attributes[0]['priority']) ? $attributes[0]['priority'] : 0;
            $services[$priority][] = new Reference($serviceId);
        }

        if ($services) {
            krsort($services);
            $services = call_user_func_array('array_merge', $services);
        }

        return $services;
    }
}
