<?php

namespace EasyCorp\Bundle\EasyAdminBundle\Form\Type;

use ArrayObject;
use Closure;
use function count;
use EasyCorp\Bundle\EasyAdminBundle\Configuration\ConfigManager;
use EasyCorp\Bundle\EasyAdminBundle\Form\EventListener\EasyAdminTabSubscriber;
use EasyCorp\Bundle\EasyAdminBundle\Form\Type\Configurator\TypeConfiguratorInterface;
use EasyCorp\Bundle\EasyAdminBundle\Form\Util\LegacyFormHelper;
use function in_array;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;

class EasyAdminCocoricoFormType extends AbstractType
{
    private ConfigManager $configManager;

    /**
     * @var TypeConfiguratorInterface[]
     */
    private array $configurators;

    /**
     * @param TypeConfiguratorInterface[] $configurators
     */
    public function __construct(ConfigManager $configManager, array $configurators = [])
    {
        $this->configManager = $configManager;
        $this->configurators = $configurators;
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $entity = $options['entity'];
        $view = $options['view'];
        $entityConfig = $options['entityConfig'] ?? $this->configManager->getEntityConfig($entity);
        $entityProperties = $entityConfig[$view]['fields'] ?? [];
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
            if (
                in_array(
                    $formFieldType,
                    [
                        'easyadmin_group',
                        EasyAdminGroupType::class,
                    ]
                )
            ) {
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

        if (count($formTabs)) {
            $builder->addEventSubscriber(new EasyAdminTabSubscriber());
        }
    }

    public function finishView(FormView $view, FormInterface $form, array $options): void
    {
        $view->vars['easyadmin_form_tabs'] = $form->getConfig()->getAttribute('easyadmin_form_tabs');
        $view->vars['easyadmin_form_groups'] = $form->getConfig()->getAttribute('easyadmin_form_groups');
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $configManager = $this->configManager;

        $resolver
            ->setDefaults(
                [
                    'allow_extra_fields' => true,
                    'data_class' => static function (Options $options) use ($configManager) {
                        $entity = $options['entity'];
                        $entityConfig = $configManager->getEntityConfig($entity);

                        return $entityConfig['class'];
                    },
                    'entityConfig' => [],
                ]
            )
            ->setRequired(['entity', 'view']);

        $resolver->setNormalizer('attr', $this->getAttributesNormalizer());
    }

    public function getBlockPrefix(): string
    {
        return 'easyadmin';
    }

    /**
     * Returns a closure normalizing the form html attributes.
     */
    private function getAttributesNormalizer(): Closure
    {
        return static function (Options $options, $value): ?array {
            return array_replace(
                [
                    'id' => sprintf('%s-%s-form', $options['view'], mb_strtolower($options['entity'])),
                ],
                $value
            );
        };
    }
}
