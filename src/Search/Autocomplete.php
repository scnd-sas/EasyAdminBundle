<?php

/*
 * This file is part of the Second package.
 *
 * © Second <contact@scnd.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace EasyCorp\Bundle\EasyAdminBundle\Search;

use EasyCorp\Bundle\EasyAdminBundle\Configuration\ConfigManager;
use InvalidArgumentException;
use Symfony\Component\PropertyAccess\PropertyAccessor;

/**
 * It looks for the values of entity which match the given query. It's used for
 * the autocomplete field types.
 *
 * @author Javier Eguiluz <javier.eguiluz@gmail.com>
 * @author Yonel Ceruto <yonelceruto@gmail.com>
 */
class Autocomplete
{
    /**
     * @var ConfigManager
     */
    private $configManager;
    /**
     * @var Finder
     */
    private $finder;
    /**
     * @var PropertyAccessor
     */
    private $propertyAccessor;

    public function __construct(ConfigManager $configManager, Finder $finder, PropertyAccessor $propertyAccessor)
    {
        $this->configManager = $configManager;
        $this->finder = $finder;
        $this->propertyAccessor = $propertyAccessor;
    }

    /**
     * Finds the values of the given entity which match the query provided.
     *
     * @param string $entity
     * @param string $query
     * @param int    $page
     *
     * @return array
     */
    public function find($entity, $query, $page = 1)
    {
        if (empty($entity) || empty($query)) {
            return ['results' => []];
        }

        $backendConfig = $this->configManager->getBackendConfig();
        if (!isset($backendConfig['entities'][$entity])) {
            throw new InvalidArgumentException(sprintf('The "entity" argument must contain the name of an entity managed by EasyAdmin ("%s" given).', $entity));
        }

        $paginator = $this->finder->findByAllProperties($backendConfig['entities'][$entity], $query, $page, $backendConfig['show']['max_results']);

        return [
            'results' => $this->processResults($paginator->getCurrentPageResults(), $backendConfig['entities'][$entity]),
            'has_next_page' => $paginator->hasNextPage(),
        ];
    }

    private function processResults($entities, array $targetEntityConfig): array
    {
        $results = [];

        foreach ($entities as $entity) {
            $results[] = [
                'id' => $this->propertyAccessor->getValue($entity, $targetEntityConfig['primary_key_field_name']),
                'text' => (string) $entity,
            ];
        }

        return $results;
    }
}
