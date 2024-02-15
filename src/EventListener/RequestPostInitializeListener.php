<?php

namespace EasyCorp\Bundle\EasyAdminBundle\EventListener;

use Doctrine\Bundle\DoctrineBundle\Registry;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AdminController;
use EasyCorp\Bundle\EasyAdminBundle\Exception\EntityNotFoundException;
use RuntimeException;
use Symfony\Component\EventDispatcher\GenericEvent;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Adds some custom attributes to the request object to store information
 * related to EasyAdmin.
 *
 * @author Maxime Steinhausser <maxime.steinhausser@gmail.com>
 */
class RequestPostInitializeListener
{
    protected $request;
    protected $requestStack;
    protected $doctrine;

    public function __construct(
        Registry $doctrine,
        RequestStack $requestStack = null
    ) {
        $this->doctrine = $doctrine;
        $this->requestStack = $requestStack;
    }

    /**
     * Adds to the request some attributes with useful information, such as the
     * current entity and the selected item, if any.
     */
    public function initializeRequest(GenericEvent $event): void
    {
        if (null !== $this->requestStack) {
            $this->request = $this->requestStack->getCurrentRequest();
        }

        if (null === $this->request) {
            return;
        }
        $id = $this->request->query->get('id');
        $action = $this->request->query->get('action', 'list');

        $this->request->attributes->set(
            'easyadmin',
            [
                'entity' => $entity = $event->getArgument('entity'),
                'view' => $action,
                'item' => $id ? $this->resolveItem($entity, $id, $event->getArgument('controller')) : null,
            ]
        );
    }

    /**
     * Looks for the object that corresponds to the selected 'id' of the current entity.
     *
     * @param string|int $itemId
     *
     * @return object|null The entity or DTO
     *
     * @throws EntityNotFoundException
     */
    protected function resolveItem(array $entityConfig, string|int $itemId, AdminController $controller): ?object
    {
        if (isset($entityConfig['dto_class'])) {
            return $controller->resolveSubject($entityConfig['dto_class'], $itemId);
        }

        if (!$itemId) {
            return null;
        }

        return $this->findCurrentItem($entityConfig, $itemId);
    }

    private function findCurrentItem(array $entityConfig, string|int $itemId): object
    {
        $class = $entityConfig['class'];
        if (null === $manager = $this->doctrine->getManagerForClass($class)) {
            throw new RuntimeException(
                sprintf('There is no Doctrine Entity Manager defined for the "%s" class', $class)
            );
        }

        if (null === $entity = $manager->getRepository($class)->find($itemId)) {
            throw new EntityNotFoundException(
                [
                    'entity_name' => $entityConfig['name'],
                    'entity_id_name' => $entityConfig['primary_key_field_name'],
                    'entity_id_value' => $itemId,
                ]
            );
        }

        return $entity;
    }
}
