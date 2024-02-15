<?php

namespace EasyCorp\Bundle\EasyAdminBundle\Event;

use Symfony\Contracts\EventDispatcher\Event;

class PostMenuProcessEvent extends Event
{
    public function __construct(
        private array $menuConfig,
    ) {
    }

    public function getMenuConfig(): array
    {
        return $this->menuConfig;
    }

    public function setMenuConfig(array $menuConfig): void
    {
        $this->menuConfig = $menuConfig;
    }
}
