<?php

declare(strict_types=1);

/*
 * This file is part of the Second package.
 *
 * © Second <contact@scnd.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace EasyCorp\Bundle\EasyAdminBundle\Pagination;

use Pagerfanta\Adapter\AdapterInterface;
use Second\Shared\Domain\Collection\Paginator;

class PaginatorAdapter implements AdapterInterface
{
    public function __construct(
        private readonly Paginator $paginator,
    ) {
    }

    public function getNbResults(): int
    {
        return $this->paginator->count();
    }

    public function getSlice(int $offset, int $length): iterable
    {
        return $this->paginator->getIterator();
    }
}
