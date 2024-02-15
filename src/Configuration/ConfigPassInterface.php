<?php

/*
 * This file is part of the Second package.
 *
 * © Second <contact@scnd.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace EasyCorp\Bundle\EasyAdminBundle\Configuration;

/**
 * The interface that must be implemented by all the classes that normalize,
 * parse, complete or manipulate in any way the original backend configuration
 * in order to generate the final backend configuration. This allows the
 * end-user to use shortcuts and syntactic sugar to define the backend configuration.
 *
 * @author Javier Eguiluz <javier.eguiluz@gmail.com>
 */
interface ConfigPassInterface
{
    public function process(array $backendConfig): array;
}
