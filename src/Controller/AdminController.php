<?php

/*
 * This file is part of the Second package.
 *
 * © Second <contact@scnd.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace EasyCorp\Bundle\EasyAdminBundle\Controller;

use BadMethodCallException;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Event\EasyAdminEvents;
use EasyCorp\Bundle\EasyAdminBundle\Exception\EntityRemoveException;
use EasyCorp\Bundle\EasyAdminBundle\Exception\ForbiddenActionException;
use EasyCorp\Bundle\EasyAdminBundle\Exception\NoEntitiesConfiguredException;
use EasyCorp\Bundle\EasyAdminBundle\Exception\UndefinedEntityException;
use EasyCorp\Bundle\EasyAdminBundle\Form\Util\LegacyFormHelper;
use EasyCorp\Bundle\EasyAdminBundle\Pagination\PaginatorAdapter;
use EasyCorp\Bundle\EasyAdminBundle\Search\Paginator as EasyAdminPaginator;
use Exception;
use Pagerfanta\Pagerfanta;
use RuntimeException;
use Second\Shared\Domain\Collection\Paginator;
use Symfony\Component\EventDispatcher\GenericEvent;
use Symfony\Component\Form\Form;
use Symfony\Component\Form\FormBuilder;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\Routing\Annotation\Route;
use UnexpectedValueException;

/**
 * The controller used to render all the default EasyAdmin actions.
 *
 * @author Javier Eguiluz <javier.eguiluz@gmail.com>
 */
class AdminController extends AbstractController
{
    /**
     * @var array The full configuration of the entire backend
     */
    protected $config;
    /**
     * @var array The full configuration of the current entity
     */
    protected $entity = [];
    /**
     * @var Request The instance of the current Symfony request
     */
    protected $request;
    /**
     * @var EntityManager The Doctrine entity manager for the current entity
     */
    protected $em;

    /**
     * @Route("/", name="easyadmin")
     * @Route("/", name="admin")
     *
     * The 'admin' route is deprecated since version 1.8.0 and it will be removed in 2.0.
     */
    public function indexAction(Request $request): RedirectResponse|Response
    {
        if ('admin' === $request->attributes->get('_route')) {
            trigger_error(
                sprintf(
                    'The "admin" route is deprecated since version 1.8.0 and it will be removed in 2.0. Use the "easyadmin" route instead.'
                ),
                E_USER_DEPRECATED
            );
        }

        $this->initialize($request);

        if (null === $request->query->get('entity')) {
            return $this->redirectToBackendHomepage();
        }

        $action = $request->query->get('action', 'list');
        if (!$this->isActionAllowed($action)) {
            throw new ForbiddenActionException(['action' => $action, 'entity_name' => $this->entity['name']]);
        }

        return $this->executeDynamicMethod($action.'<EntityName>Action');
    }

    public function resolveSubject(string $class, string|int $id): ?object
    {
        return null;
    }

    /**
     * Utility method which initializes the configuration of the entity on which
     * the user is performing the action.
     */
    protected function initialize(Request $request): void
    {
        $this->dispatch(EasyAdminEvents::PRE_INITIALIZE);

        $this->config = $this->get('easyadmin.config.manager')->getBackendConfig();

        if (0 === count($this->config['entities'])) {
            throw new NoEntitiesConfiguredException();
        }

        // this condition happens when accessing the backend homepage and before
        // redirecting to the default page set as the homepage
        if (null === $entityName = $request->query->get('entity')) {
            return;
        }

        if (!array_key_exists($entityName, $this->config['entities'])) {
            throw new UndefinedEntityException(['entity_name' => $entityName]);
        }

        $this->entity = $this->get('easyadmin.config.manager')->getEntityConfiguration($entityName);

        $action = $request->query->get('action', 'list');
        if (!$request->query->has('sortField')) {
            $sortField = $this->entity[$action]['sort']['field'] ?? $this->entity['primary_key_field_name'];
            $request->query->set('sortField', $sortField);
        }
        if (!$request->query->has('sortDirection')) {
            $sortDirection = $this->entity[$action]['sort']['direction'] ?? 'DESC';
            $request->query->set('sortDirection', $sortDirection);
        }

        if (isset($this->entity['class'])) {
            $this->em = $this->get('doctrine')->getManagerForClass($this->entity['class']);
        }
        $this->request = $request;

        $this->dispatch(EasyAdminEvents::POST_INITIALIZE);
    }

    protected function dispatch(string $eventName, array $arguments = []): void
    {
        $arguments = array_replace([
            'config' => $this->config,
            'em' => $this->em,
            'entity' => $this->entity,
            'request' => $this->request,
            'controller' => $this,
        ], $arguments);

        $subject = $arguments['paginator'] ?? $arguments['entity'];
        $event = new GenericEvent($subject, $arguments);

        $this->get('event_dispatcher')?->dispatch($event, $eventName);
    }

    /**
     * The method that returns the values displayed by an autocomplete field
     * based on the user's input.
     */
    protected function autocompleteAction(): JsonResponse
    {
        $results = $this->get('easyadmin.autocomplete')->find(
            $this->request->query->get('entity'),
            $this->request->query->get('query'),
            $this->request->query->get('page', 1)
        );

        return new JsonResponse($results);
    }

    /**
     * The method that is executed when the user performs a 'list' action on an entity.
     */
    protected function listAction(): Response
    {
        $this->dispatch(EasyAdminEvents::PRE_LIST);

        $fields = $this->entity['list']['fields'];
        $paginator = $this->findAll(
            $this->entity['class'],
            $this->request->query->get('page', 1),
            $this->entity['list']['max_results'],
            $this->request->query->get('sortField'),
            $this->request->query->get('sortDirection'),
            $this->entity['list']['dql_filter']
        );

        $this->dispatch(EasyAdminEvents::POST_LIST, ['paginator' => $paginator]);

        $parameters = [
            'paginator' => $paginator,
            'fields' => $fields,
            'delete_form_template' => $this->createDeleteForm($this->entity['name'], '__id__')->createView(),
        ];

        return $this->executeDynamicMethod(
            'render<EntityName>Template',
            ['list', $this->entity['templates']['list'], $parameters]
        );
    }

    /**
     * The method that is executed when the user performs a 'edit' action on an entity.
     */
    protected function editAction(): Response|RedirectResponse
    {
        $this->dispatch(EasyAdminEvents::PRE_EDIT);

        $id = $this->request->query->get('id');
        $easyadmin = $this->request->attributes->get('easyadmin');
        $entity = $easyadmin['item'];

        if ($this->request->isXmlHttpRequest() && $property = $this->request->query->get('property')) {
            $newValue = 'true' === mb_strtolower($this->request->query->get('newValue'));
            $fieldsMetadata = $this->entity['list']['fields'];

            if (!isset($fieldsMetadata[$property]) || 'toggle' !== $fieldsMetadata[$property]['dataType']) {
                throw new RuntimeException(sprintf('The type of the "%s" property is not "toggle".', $property));
            }

            $this->updateEntityProperty($entity, $property, $newValue);

            // cast to integer instead of string to avoid sending empty responses for 'false'
            return new Response((int) $newValue);
        }

        $fields = $this->entity['edit']['fields'];

        $editForm = $this->executeDynamicMethod('create<EntityName>EditForm', [$entity, $fields]);
        $deleteForm = $this->createDeleteForm($this->entity['name'], $id);

        $editForm->handleRequest($this->request);
        if ($editForm->isSubmitted() && $editForm->isValid()) {
            $this->dispatch(EasyAdminEvents::PRE_UPDATE, ['entity' => $entity]);

            $this->executeDynamicMethod('preUpdate<EntityName>Entity', [$entity, true]);
            $this->executeDynamicMethod('update<EntityName>Entity', [$entity, $editForm]);

            $this->dispatch(EasyAdminEvents::POST_UPDATE, ['entity' => $entity]);

            return $this->redirectToReferrer();
        }

        $this->dispatch(EasyAdminEvents::POST_EDIT);

        $parameters = [
            'form' => $editForm->createView(),
            'entity_fields' => $fields,
            'entity' => $entity,
            'delete_form' => $deleteForm->createView(),
        ];

        return $this->executeDynamicMethod(
            'render<EntityName>Template',
            ['edit', $this->entity['templates']['edit'], $parameters]
        );
    }

    /**
     * The method that is executed when the user performs a 'show' action on an entity.
     */
    protected function showAction(): Response
    {
        $this->dispatch(EasyAdminEvents::PRE_SHOW);

        $id = $this->request->query->get('id');
        $easyadmin = $this->request->attributes->get('easyadmin');
        $entity = $easyadmin['item'];

        $fields = $this->entity['show']['fields'];
        $deleteForm = $this->createDeleteForm($this->entity['name'], $id);

        $this->dispatch(EasyAdminEvents::POST_SHOW, [
            'deleteForm' => $deleteForm,
            'fields' => $fields,
            'entity' => $entity,
        ]);

        $parameters = [
            'entity' => $entity,
            'fields' => $fields,
            'delete_form' => $deleteForm->createView(),
        ];

        return $this->executeDynamicMethod(
            'render<EntityName>Template',
            ['show', $this->entity['templates']['show'], $parameters]
        );
    }

    /**
     * The method that is executed when the user performs a 'new' action on an entity.
     */
    protected function newAction(): Response|RedirectResponse
    {
        $this->dispatch(EasyAdminEvents::PRE_NEW);

        $entity = $this->executeDynamicMethod('createNew<EntityName>Entity');

        $easyadmin = $this->request->attributes->get('easyadmin');
        $easyadmin['item'] = $entity;
        $this->request->attributes->set('easyadmin', $easyadmin);

        $fields = $this->entity['new']['fields'];

        $newForm = $this->executeDynamicMethod('create<EntityName>NewForm', [$entity, $fields]);

        $newForm->handleRequest($this->request);
        if ($newForm->isSubmitted() && $newForm->isValid()) {
            $this->dispatch(EasyAdminEvents::PRE_PERSIST, ['entity' => $entity]);

            $this->executeDynamicMethod('prePersist<EntityName>Entity', [$entity, true]);
            $this->executeDynamicMethod('persist<EntityName>Entity', [$entity, $newForm]);

            $this->dispatch(EasyAdminEvents::POST_PERSIST, ['entity' => $entity]);

            return $this->redirectToReferrer();
        }

        $this->dispatch(EasyAdminEvents::POST_NEW, [
            'entity_fields' => $fields,
            'form' => $newForm,
            'entity' => $entity,
        ]);

        $parameters = [
            'form' => $newForm->createView(),
            'entity_fields' => $fields,
            'entity' => $entity,
        ];

        return $this->executeDynamicMethod(
            'render<EntityName>Template',
            ['new', $this->entity['templates']['new'], $parameters]
        );
    }

    /**
     * The method that is executed when the user performs a 'delete' action to
     * remove any entity.
     */
    protected function deleteAction(): Response
    {
        $this->dispatch(EasyAdminEvents::PRE_DELETE);

        if ('DELETE' !== $this->request->getMethod()) {
            return $this->redirect(
                $this->generateUrl('easyadmin', ['action' => 'list', 'entity' => $this->entity['name']])
            );
        }

        $id = $this->request->query->get('id');
        $form = $this->createDeleteForm($this->entity['name'], $id);
        $form->handleRequest($this->request);

        if ($form->isSubmitted() && $form->isValid()) {
            $easyadmin = $this->request->attributes->get('easyadmin');
            $entity = $easyadmin['item'];

            $this->dispatch(EasyAdminEvents::PRE_REMOVE, ['entity' => $entity]);

            $this->executeDynamicMethod('preRemove<EntityName>Entity', [$entity, true]);

            try {
                $this->executeDynamicMethod('remove<EntityName>Entity', [$entity, $form]);
            } catch (ForeignKeyConstraintViolationException $e) {
                throw new EntityRemoveException(
                    ['entity_name' => $this->entity['name'], 'message' => $e->getMessage(),]
                );
            }

            $this->dispatch(EasyAdminEvents::POST_REMOVE, ['entity' => $entity]);
        }

        $this->dispatch(EasyAdminEvents::POST_DELETE);

        return $this->redirectToReferrer();
    }

    /**
     * The method that is executed when the user performs a query on an entity.
     */
    protected function searchAction(): Response
    {
        $this->dispatch(EasyAdminEvents::PRE_SEARCH);

        $query = trim($this->request->query->get('query'));
        // if the search query is empty, redirect to the 'list' action
        if ('' === $query) {
            $queryParameters = array_replace($this->request->query->all(), ['action' => 'list']);
            unset($queryParameters['query']);

            return $this->redirect($this->get('router')?->generate('easyadmin', $queryParameters));
        }

        $paginator = $this->findBy(
            $this->entity['class'] ?? $this->entity['search_class'],
            $query,
            $this->entity['search']['fields'],
            $this->request->query->get('page', 1),
            $this->entity['list']['max_results'],
            $this->request->query->get('sortField', $this->entity['search']['sort']['field'] ?? null),
            $this->request->query->get('sortDirection', $this->entity['search']['sort']['direction'] ?? null),
            $this->entity['search']['dql_filter']
        );

        $fields = $this->entity['list']['fields'];

        $this->dispatch(EasyAdminEvents::POST_SEARCH, [
            'fields' => $fields,
            'paginator' => $paginator,
        ]);

        // Typecast paginator items from Entity to DTO
        if ($dtoClass = $this->entity['dto_class'] ?? null) {
            $dtoCreateMethod = $this->entity['dto_create_method'] ?? 'create';
            $paginatorDTO = new Paginator(iterator_to_array($paginator->getIterator()), $paginator->count());
            $paginator = new Pagerfanta(new PaginatorAdapter($paginatorDTO->map($dtoClass::$dtoCreateMethod(...))));
        }

        $parameters = [
            'paginator' => $paginator,
            'fields' => $fields,
            'delete_form_template' => $this->createDeleteForm($this->entity['name'], '__id__')->createView(),
        ];

        return $this->executeDynamicMethod(
            'render<EntityName>Template',
            ['search', $this->entity['templates']['list'], $parameters]
        );
    }

    /**
     * It updates the value of some property of some entity to the new given value.
     *
     * @param mixed  $entity   The instance of the entity to modify
     * @param string $property The name of the property to change
     * @param bool   $value    The new value of the property
     *
     * @throws RuntimeException
     */
    protected function updateEntityProperty($entity, string $property, bool $value): void
    {
        $entityConfig = $this->entity;

        // the method_exists() check is needed because Symfony 2.3 doesn't have isWritable() method
        if (method_exists($this->get('easy_admin.property_accessor'), 'isWritable')
            && !$this->get('easy_admin.property_accessor')->isWritable($entity, $property)) {
            throw new RuntimeException(
                sprintf('The "%s" property of the "%s" entity is not writable.', $property, $entityConfig['name'])
            );
        }

        $this->get('easy_admin.property_accessor')->setValue($entity, $property, $value);

        $this->dispatch(EasyAdminEvents::PRE_UPDATE, ['entity' => $entity, 'newValue' => $value]);
        $this->executeDynamicMethod('preUpdate<EntityName>Entity', [$entity, true]);

        $this->em->persist($entity);
        $this->em->flush();
        $this->dispatch(EasyAdminEvents::POST_UPDATE, ['entity' => $entity, 'newValue' => $value]);

        $this->dispatch(EasyAdminEvents::POST_EDIT);
    }

    /**
     * Creates a new object of the current managed entity.
     * This method is mostly here for override convenience, because it allows
     * the user to use his own method to customize the entity instantiation.
     */
    protected function createNewEntity(): mixed
    {
        $entityFullyQualifiedClassName = $this->entity['dto_class'] ?? $this->entity['class'];

        return new $entityFullyQualifiedClassName();
    }

    /**
     * Allows applications to modify the entity associated with the item being
     * created before persisting it.
     *
     * @param object $entity
     */
    protected function prePersistEntity($entity /*, bool $ignoreDeprecations = false */): void
    {
        if (func_num_args() > 1 && true === func_get_arg(1)) {
            return;
        }

        @trigger_error(
            sprintf(
                'The %s method is deprecated since EasyAdmin 1.x and will be removed in 2.0. Use persistEntity() instead',
                __METHOD__
            ),
            E_USER_DEPRECATED
        );
    }

    /**
     * Allows applications to modify the entity associated with the item being
     * created while persisting it.
     *
     * @param object $entity
     */
    protected function persistEntity($entity): void
    {
        $this->em->persist($entity);
        $this->em->flush();
    }

    /**
     * Allows applications to modify the entity associated with the item being
     * edited before persisting it.
     *
     * @param object $entity
     */
    protected function preUpdateEntity($entity /*, bool $ignoreDeprecations = false */): void
    {
        if (func_num_args() > 1 && true === func_get_arg(1)) {
            return;
        }

        @trigger_error(
            sprintf(
                'The %s method is deprecated since EasyAdmin 1.x and will be removed in 2.0. Use updateEntity() instead',
                __METHOD__
            ),
            E_USER_DEPRECATED
        );
    }

    /**
     * Allows applications to modify the entity associated with the item being
     * edited before updating it.
     *
     * @param object $entity
     */
    protected function updateEntity($entity): void
    {
        $this->em->persist($entity);
        $this->em->flush();
    }

    /**
     * Allows applications to modify the entity associated with the item being
     * deleted before removing it.
     *
     * @param object $entity
     */
    protected function preRemoveEntity($entity /*, bool $ignoreDeprecations = false */): void
    {
        if (func_num_args() > 1 && true === func_get_arg(1)) {
            return;
        }

        @trigger_error(
            sprintf(
                'The %s method is deprecated since EasyAdmin 1.x and will be removed in 2.0. Use removeEntity() instead',
                __METHOD__
            ),
            E_USER_DEPRECATED
        );
    }

    /**
     * Allows applications to modify the entity associated with the item being
     * deleted before removing it.
     *
     * @param object $entity
     */
    protected function removeEntity($entity): void
    {
        $this->em->remove($entity);
        $this->em->flush();
    }

    /**
     * Performs a database query to get all the records related to the given
     * entity. It supports pagination and field sorting.
     *
     * @return Pagerfanta The paginated query results
     */
    protected function findAll(
        string $entityClass,
        int $page = 1,
        int $maxPerPage = 15,
        ?string $sortField = null,
        ?string $sortDirection = null,
        ?string $dqlFilter = null
    ): Pagerfanta {
        if (empty($sortDirection) || !in_array(strtoupper($sortDirection), ['ASC', 'DESC'])) {
            $sortDirection = 'DESC';
        }

        $queryBuilder = $this->executeDynamicMethod(
            'create<EntityName>ListQueryBuilder',
            [$entityClass, $sortDirection, $sortField, $dqlFilter]
        );

        $this->dispatch(EasyAdminEvents::POST_LIST_QUERY_BUILDER, [
            'query_builder' => $queryBuilder,
            'sort_field' => $sortField,
            'sort_direction' => $sortDirection,
        ]);

        return EasyAdminPaginator::createOrmPaginator($queryBuilder, $page, $maxPerPage);
    }

    /**
     * Creates Query Builder instance for all the records.
     *
     * @return QueryBuilder The Query Builder instance
     */
    protected function createListQueryBuilder(
        string $entityClass,
        string $sortDirection,
        ?string $sortField = null,
        ?string $dqlFilter = null
    ): QueryBuilder {
        return $this->get('easyadmin.query_builder')->createListQueryBuilder(
            $this->entity,
            $sortField,
            $sortDirection,
            $dqlFilter
        );
    }

    /**
     * Performs a database query based on the search query provided by the user.
     * It supports pagination and field sorting.
     *
     * @return Pagerfanta The paginated query results
     */
    protected function findBy(
        string $entityClass,
        string $searchQuery,
        array $searchableFields,
        int $page = 1,
        int $maxPerPage = 15,
        ?string $sortField = null,
        ?string $sortDirection = null,
        ?string $dqlFilter = null
    ): Pagerfanta {
        if (empty($sortDirection) || !in_array(strtoupper($sortDirection), ['ASC', 'DESC'])) {
            $sortDirection = 'DESC';
        }

        $queryBuilder = $this->executeDynamicMethod(
            'create<EntityName>SearchQueryBuilder',
            [$entityClass, $searchQuery, $searchableFields, $sortField, $sortDirection, $dqlFilter]
        );

        $this->dispatch(EasyAdminEvents::POST_SEARCH_QUERY_BUILDER, [
            'query_builder' => $queryBuilder,
            'search_query' => $searchQuery,
            'searchable_fields' => $searchableFields,
        ]);

        return EasyAdminPaginator::createOrmPaginator($queryBuilder, $page, $maxPerPage);
    }

    /**
     * Creates Query Builder instance for search query.
     *
     * @return QueryBuilder The Query Builder instance
     */
    protected function createSearchQueryBuilder(
        string $entityClass,
        string $searchQuery,
        array $searchableFields,
        ?string $sortField = null,
        ?string $sortDirection = null,
        ?string $dqlFilter = null
    ): QueryBuilder {
        return $this->get('easyadmin.query_builder')->createSearchQueryBuilder(
            $this->entity,
            $searchQuery,
            $sortField,
            $sortDirection,
            $dqlFilter
        );
    }

    /**
     * Creates the form used to edit an entity.
     *
     * @param object $entity
     */
    protected function createEditForm($entity, array $entityProperties): FormInterface
    {
        return $this->createEntityForm($entity, $entityProperties, 'edit');
    }

    /**
     * Creates the form used to create an entity.
     *
     * @param object $entity
     */
    protected function createNewForm($entity, array $entityProperties): FormInterface
    {
        return $this->createEntityForm($entity, $entityProperties, 'new');
    }

    /**
     * Creates the form builder of the form used to create or edit the given entity.
     *
     * @param object $entity
     * @param string $view The name of the view where this form is used ('new' or 'edit')
     */
    protected function createEntityFormBuilder($entity, string $view): FormBuilderInterface
    {
        $formOptions = $this->executeDynamicMethod('get<EntityName>EntityFormOptions', [$entity, $view]);

        return $this->get('form.factory')->createNamedBuilder(
            mb_strtolower($this->entity['name']),
            LegacyFormHelper::getType('easyadmin'),
            $entity,
            $formOptions
        );
    }

    /**
     * Retrieves the list of form options before sending them to the form builder.
     * This allows adding dynamic logic to the default form options.
     *
     * @param object $entity
     */
    protected function getEntityFormOptions($entity, string $view): array
    {
        $formOptions = $this->entity[$view]['form_options'];
        $formOptions['entity'] = $this->entity['name'];
        $formOptions['view'] = $view;

        return $formOptions;
    }

    /**
     * Creates the form object used to create or edit the given entity.
     *
     * @throws Exception
     */
    protected function createEntityForm($entity, array $entityProperties, string $view): FormInterface
    {
        if (method_exists($this, $customMethodName = 'create'.$this->entity['name'].'EntityForm')) {
            $form = $this->{$customMethodName}($entity, $entityProperties, $view);
            if (!$form instanceof FormInterface) {
                throw new UnexpectedValueException(
                    sprintf(
                        'The "%s" method must return a FormInterface, "%s" given.',
                        $customMethodName,
                        get_debug_type($form)
                    )
                );
            }

            return $form;
        }

        $formBuilder = $this->executeDynamicMethod('create<EntityName>EntityFormBuilder', [$entity, $view]);

        if (!$formBuilder instanceof FormBuilderInterface) {
            throw new UnexpectedValueException(
                sprintf(
                    'The "%s" method must return a FormBuilderInterface, "%s" given.',
                    'createEntityForm',
                    get_debug_type($formBuilder)
                )
            );
        }

        return $formBuilder->getForm();
    }

    /**
     * Creates the form used to delete an entity. It must be a form because
     * the deletion of the entity are always performed with the 'DELETE' HTTP method,
     * which requires a form to work in the current browsers.
     *
     * @param int|string $entityId When reusing the delete form for multiple entities, a pattern string is passed instead of an integer
     */
    protected function createDeleteForm(string $entityName, int|string $entityId): Form|FormInterface
    {
        /** @var FormBuilder $formBuilder */
        $formBuilder = $this->get('form.factory')->createNamedBuilder('delete_form')
            ->setAction(
                $this->generateUrl('easyadmin', ['action' => 'delete', 'entity' => $entityName, 'id' => $entityId])
            )
            ->setMethod('DELETE');
        $formBuilder->add(
            'submit',
            LegacyFormHelper::getType('submit'),
            ['label' => 'delete_modal.action', 'translation_domain' => 'EasyAdminBundle']
        );
        // needed to avoid submitting empty delete forms (see issue #1409)
        $formBuilder->add('_easyadmin_delete_flag', LegacyFormHelper::getType('hidden'), ['data' => '1']);

        return $formBuilder->getForm();
    }

    /**
     * Utility method that checks if the given action is allowed for
     * the current entity.
     */
    protected function isActionAllowed(string $actionName): bool
    {
        return false === in_array($actionName, $this->entity['disabled_actions'], true);
    }

    /**
     * Utility shortcut to render an error when the requested action is not allowed
     * for the given entity.
     *
     * @deprecated Use the ForbiddenException instead of this method
     */
    protected function renderForbiddenActionError(string $action): Response
    {
        return $this->render(
            '@EasyAdmin/error/forbidden_action.html.twig',
            ['action' => $action],
            new Response('', 403)
        );
    }

    /**
     * Given a method name pattern, it looks for the customized version of that
     * method (based on the entity name) and executes it. If the custom method
     * does not exist, it executes the regular method.
     *
     * For example:
     *   executeDynamicMethod('create<EntityName>Entity') and the entity name is 'User'
     *   if 'createUserEntity()' exists, execute it; otherwise execute 'createEntity()'
     *
     * @param string $methodNamePattern The pattern of the method name (dynamic parts are enclosed with <> angle brackets)
     * @param array  $arguments         The arguments passed to the executed method
     *
     * @throws Exception
     */
    protected function executeDynamicMethod(string $methodNamePattern, array $arguments = []): mixed
    {
        $methodName = str_replace('<EntityName>', $this->entity['name'], $methodNamePattern);

        if (!is_callable([$this, $methodName])) {
            $methodName = str_replace('<EntityName>', '', $methodNamePattern);
        }

        $isDeprecatedMethod = str_starts_with($methodName, 'prePersist')
            || str_starts_with($methodName, 'preUpdate')
            || str_starts_with($methodName, 'preRemove');

        if ($isDeprecatedMethod && isset($arguments[1]) && true !== $arguments[1]) {
            $newMethodName = strtolower(substr($methodName, 3));
            @trigger_error(
                sprintf(
                    'The %s method is deprecated since EasyAdmin 1.x and will be removed in 2.0. Use %s() instead',
                    $methodName,
                    $newMethodName
                ),
                E_USER_DEPRECATED
            );
        }

        if (!method_exists($this, $methodName)) {
            throw new BadMethodCallException(
                sprintf('The "%s()" method does not exist in the %s class', $methodName, get_class($this))
            );
        }

        return call_user_func_array([$this, $methodName], $arguments);
    }

    /**
     * Generates the backend homepage and redirects to it.
     */
    protected function redirectToBackendHomepage(): RedirectResponse
    {
        $config = $this->config['homepage'];

        $url = $config['url'] ?? $this->get('router')->generate($config['route'], $config['params']);

        return $this->redirect($url);
    }

    /**
     * It renders the main CSS applied to the backend design. This controller
     * allows to generate dynamic CSS files that use variables without the need
     * to set up a CSS preprocessing toolchain.
     *
     * @deprecated The CSS styles are no longer rendered at runtime but preprocessed during container compilation. Use the $container['easyadmin.config']['_internal']['custom_css'] variable instead
     */
    public function renderCssAction(): void
    {
        @trigger_error(
            'The %s method is deprecated since EasyAdmin 1.x and will be removed in 2.0. Processed styles are available in the "easyadmin.config._internal.custom_css" container parameter.',
            E_USER_DEPRECATED
        );
    }

    protected function redirectToReferrer(): Response
    {
        $refererUrl = $this->request->query->get('referer', '');
        $refererAction = $this->request->query->get('action');

        // 1. redirect to list if possible
        if ($this->isActionAllowed('list')) {
            if (!empty($refererUrl)) {
                return $this->redirect(urldecode($refererUrl));
            }

            return $this->redirectToRoute('easyadmin', [
                'action' => 'list',
                'entity' => $this->entity['name'],
                'menuIndex' => $this->request->query->get('menuIndex'),
                'submenuIndex' => $this->request->query->get('submenuIndex'),
            ]);
        }

        // 2. from new|edit action, redirect to edit if possible
        if (in_array($refererAction, ['new', 'edit']) && $this->isActionAllowed('edit')) {
            $easyAdminAttributes = $this->request->attributes->get('easyadmin');

            return $this->redirectToRoute('easyadmin', [
                'action' => 'edit',
                'entity' => $this->entity['name'],
                'menuIndex' => $this->request->query->get('menuIndex'),
                'submenuIndex' => $this->request->query->get('submenuIndex'),
                'id' => ('new' === $refererAction)
                    ? PropertyAccess::createPropertyAccessor()->getValue(
                        $easyAdminAttributes['item'],
                        $this->entity['primary_key_field_name']
                    )
                    : $this->request->query->get('id'),
            ]);
        }

        // 3. from new action, redirect to new if possible
        if ('new' === $refererAction && $this->isActionAllowed('new')) {
            return $this->redirectToRoute('easyadmin', [
                'action' => 'new',
                'entity' => $this->entity['name'],
                'menuIndex' => $this->request->query->get('menuIndex'),
                'submenuIndex' => $this->request->query->get('submenuIndex'),
            ]);
        }

        return $this->redirectToBackendHomepage();
    }

    /**
     * Used to add/modify/remove parameters before passing them to the Twig template.
     * Instead of defining a render method per action (list, show, search, etc.) use
     * the $actionName argument to discriminate between actions.
     *
     * @param string $actionName   The name of the current action (list, show, new, etc.)
     * @param string $templatePath The path of the Twig template to render
     * @param array  $parameters   The parameters passed to the template
     */
    protected function renderTemplate(string $actionName, string $templatePath, array $parameters = []): Response
    {
        return $this->render($templatePath, $parameters);
    }
}
