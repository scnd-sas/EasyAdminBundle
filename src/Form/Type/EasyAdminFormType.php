<?php

/*
 * This file is part of the Second package.
 *
 * © Second <contact@scnd.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace EasyCorp\Bundle\EasyAdminBundle\Form\Type;

use ArrayObject;
use Closure;
use EasyCorp\Bundle\EasyAdminBundle\Configuration\ConfigManager;
use EasyCorp\Bundle\EasyAdminBundle\Form\EventListener\EasyAdminTabSubscriber;
use EasyCorp\Bundle\EasyAdminBundle\Form\Type\Configurator\TypeConfiguratorInterface;
use EasyCorp\Bundle\EasyAdminBundle\Form\Util\LegacyFormHelper;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Custom form type that deals with some of the logic used to render the
 * forms used to create and edit EasyAdmin entities.
 *
 * @author Maxime Steinhausser <maxime.steinhausser@gmail.com>
 */
class EasyAdminFormType extends AbstractType
{
    /**
     * @var ConfigManager
     */
    private $configManager;

    /**
     * @var TypeConfiguratorInterface[]
     */
    private $configurators;

    /**
     * @param TypeConfiguratorInterface[] $configurators
     */
    public function __construct(ConfigManager $configManager, array $configurators = [])
    {
        $this->configManager = $configManager;
        $this->configurators = $configurators;
    }

    /**
     * {@inheritdoc}
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $entity = $options['entity'];
        $view = $options['view'];
        $entityConfig = $this->configManager->getEntityConfig($entity);
        $entityProperties = isset($entityConfig[$view]['fields']) ? $entityConfig[$view]['fields'] : [];
        $formTabs = [];
        $currentFormTab = null;
        $formGroups = [];
        $currentFormGroup = null;

        foreach ($entityProperties as $name => $metadata) {
            $formFieldOptions = $metadata['type_options'];

            // Configure options using the list of registered type configurators:
            foreach ($this->configurators as $configurator) {
                if ($configurator->supports($metadata['fieldType'], $formFieldOptions, $metadata)) {
                    $formFieldOptions = $configurator->configure($name, $formFieldOptions, $metadata, $builder);
                }
            }

            $formFieldType = LegacyFormHelper::getType($metadata['fieldType']);

            // if the form field is a special 'group' design element, don't add it
            // to the form. Instead, consider it the current form group (this is
            // applied to the form fields defined after it) and store its details
            // in a property to get them in form template
            if (in_array($formFieldType, ['easyadmin_group', 'EasyCorp\\Bundle\\EasyAdminBundle\\Form\\Type\\EasyAdminGroupType'])) {
                $metadata['form_tab'] = $currentFormTab ?: null;
                $currentFormGroup = $metadata['fieldName'];
                $formGroups[$currentFormGroup] = $metadata;

                continue;
            }

            // if the form field is a special 'tab' design element, don't add it
            // to the form. Instead, consider it the current form group (this is
            // applied to the form fields defined after it) and store its details
            // in a property to get them in form template
            if (
                in_array(
                    $formFieldType,
                    [
                        'easyadmin_tab',
                        EasyAdminTabType::class,
                    ]
                )
            ) {
                // The first tab should be marked as active by default
                $metadata['active'] = 0 === count($formTabs);
                $metadata['errors'] = 0;
                $currentFormTab = $metadata['fieldName'];

                // plain arrays are not enough for tabs because they are modified in the
                // lifecycle of a form (e.g. add info about form errors). Use an ArrayObject instead.
                $formTabs[$currentFormTab] = new ArrayObject($metadata);

                continue;
            }

            // 'divider' and 'section' are 'fake' form fields used to create the design
            // elements of the complex form layouts: define them as unmapped and non-required
            if (0 === strpos($metadata['property'], '_easyadmin_form_design_element_')) {
                $formFieldOptions['mapped'] = false;
                $formFieldOptions['required'] = false;
            }

            $formField = $builder->getFormFactory()->createNamedBuilder($name, $formFieldType, null, $formFieldOptions);
            $formField->setAttribute('easyadmin_form_tab', $currentFormTab);
            $formField->setAttribute('easyadmin_form_group', $currentFormGroup);

            $builder->add($formField);
        }

        $builder->setAttribute('easyadmin_form_tabs', $formTabs);
        $builder->setAttribute('easyadmin_form_groups', $formGroups);

        if (count($formTabs) > 0) {
            $builder->addEventSubscriber(new EasyAdminTabSubscriber());
        }
    }

    /**
     * {@inheritdoc}
     */
    public function finishView(FormView $view, FormInterface $form, array $options)
    {
        $view->vars['easyadmin_form_tabs'] = $form->getConfig()->getAttribute('easyadmin_form_tabs');
        $view->vars['easyadmin_form_groups'] = $form->getConfig()->getAttribute('easyadmin_form_groups');
    }

    /**
     * {@inheritdoc}
     */
    public function configureOptions(OptionsResolver $resolver)
    {
        $configManager = $this->configManager;

        $resolver
            ->setDefaults([
                'allow_extra_fields' => true,
                'data_class' => function (Options $options) use ($configManager) {
                    $entity = $options['entity'];
                    $entityConfig = $configManager->getEntityConfig($entity);

                    return $entityConfig['class'] ?? $entityConfig['dto_class'];
                },
            ])
            ->setRequired(['entity', 'view']);

        // setNormalizer() is available since Symfony 2.6
        if (method_exists($resolver, 'setNormalizer')) {
            $resolver->setNormalizer('attr', $this->getAttributesNormalizer());
        } else {
            // BC for Symfony < 2.6
            $resolver->setNormalizers(['attr' => $this->getAttributesNormalizer()]);
        }
    }

    public function getBlockPrefix(): string
    {
        return 'easyadmin';
    }

    public function getName(): string
    {
        return $this->getBlockPrefix();
    }

    /**
     * Returns a closure normalizing the form html attributes.
     *
     * @return Closure
     */
    private function getAttributesNormalizer()
    {
        return function (Options $options, $value) {
            return array_replace([
                'id' => sprintf('%s-%s-form', $options['view'], mb_strtolower($options['entity'])),
            ], $value);
        };
    }
}
