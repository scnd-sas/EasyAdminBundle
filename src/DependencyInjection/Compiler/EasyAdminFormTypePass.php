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

use InvalidArgumentException;
use ReflectionClass;
use SplPriorityQueue;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * @author Maxime Steinhausser <maxime.steinhausser@gmail.com>
 */
class EasyAdminFormTypePass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $this->configureTypeGuesserChain($container);
        $this->registerTypeConfigurators($container);
    }

    private function configureTypeGuesserChain(ContainerBuilder $container): void
    {
        $definition = $container->getDefinition('easyadmin.form.type_guesser_chain');
        $guesserIds = array_keys($container->findTaggedServiceIds('form.type_guesser'));
        $guessers = array_map(function ($id) {
            return new Reference($id);
        }, $guesserIds);
        $definition->replaceArgument(0, $guessers);
    }

    private function registerTypeConfigurators(ContainerBuilder $container): void
    {
        $configurators = new SplPriorityQueue();
        foreach ($container->findTaggedServiceIds('easyadmin.form.type.configurator') as $id => $tags) {
            $configuratorClass = new ReflectionClass($container->getDefinition($id)->getClass());
            $typeConfiguratorInterface = 'EasyCorp\Bundle\EasyAdminBundle\Form\Type\Configurator\TypeConfiguratorInterface';
            if (!$configuratorClass->implementsInterface($typeConfiguratorInterface)) {
                throw new InvalidArgumentException(sprintf('Service "%s" must implement interface "%s".', $id, $typeConfiguratorInterface));
            }

            // Register the Ivory CKEditor type configurator only if the bundle
            // is installed and no default configuration is provided.
            if ('easyadmin.form.type.configurator.ivory_ckeditor' === $id
                && !(
                    $container->has('ivory_ck_editor.config_manager')
                    && null === $container->get('ivory_ck_editor.config_manager')->getDefaultConfig()
                )
            ) {
                $container->removeDefinition('easyadmin.form.type.configurator.ivory_ckeditor');
                continue;
            }

            foreach ($tags as $tag) {
                $priority = isset($tag['priority']) ? $tag['priority'] : 0;
                $configurators->insert(new Reference($id), $priority);
            }
        }

        $container->getDefinition('easyadmin.form.type')->replaceArgument(1, iterator_to_array($configurators));
    }
}
