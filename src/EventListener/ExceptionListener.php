<?php

/*
 * This file is part of the EasyAdminBundle.
 *
 * (c) Javier Eguiluz <javier.eguiluz@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace EasyCorp\Bundle\EasyAdminBundle\EventListener;

use EasyCorp\Bundle\EasyAdminBundle\Exception\BaseException;
use EasyCorp\Bundle\EasyAdminBundle\Exception\FlattenException;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\EventListener\ErrorListener;
use Throwable;
use Twig\Environment;

/**
 * This listener allows to display customized error pages in the production
 * environment.
 *
 * @author Javier Eguiluz <javier.eguiluz@gmail.com>
 * @author Maxime Steinhausser <maxime.steinhausser@gmail.com>
 */
class ExceptionListener extends ErrorListener
{
    /**
     * @var Environment
     */
    private $twig;

    /**
     * @var array
     */
    private $easyAdminConfig;

    private $currentEntityName;

    public function __construct(Environment $twig, array $easyAdminConfig, $controller, LoggerInterface $logger = null)
    {
        $this->twig = $twig;
        $this->easyAdminConfig = $easyAdminConfig;

        parent::__construct($controller, $logger);
    }

    public function onKernelException(ExceptionEvent $event, string $eventName = null, EventDispatcherInterface $eventDispatcher = null)
    {
        $exception = $event->getThrowable();
        $this->currentEntityName = $event->getRequest()->query->get('entity');

        if (!$exception instanceof BaseException) {
            return;
        }

        parent::onKernelException($event);
    }

    public function showExceptionPageAction(FlattenException $exception): Response
    {
        $entityConfig = $this->easyAdminConfig['entities'][$this->currentEntityName] ?? null;
        $exceptionTemplatePath = $entityConfig['templates']['exception'] ?? (
                $this->easyAdminConfig['design']['templates']['exception'] ?? '@EasyAdmin/default/exception.html.twig'
            );
        $exceptionLayoutTemplatePath = $entityConfig['templates']['layout'] ?? (
                $this->easyAdminConfig['design']['templates']['layout'] ?? '@EasyAdmin/default/layout.html.twig'
            );

        return new Response($this->twig->render($exceptionTemplatePath, [
            'exception' => $exception,
            'layout_template_path' => $exceptionLayoutTemplatePath,
        ]), $exception->getStatusCode());
    }

    protected function logException(Throwable $exception, string $message, string $logLevel = null): void
    {
        if (!$exception instanceof BaseException) {
            parent::logException($exception, $message);

            return;
        }

        if (null !== $this->logger) {
            if ($exception->getStatusCode() >= 500) {
                $this->logger->critical($message, ['exception' => $exception]);
            } else {
                $this->logger->error($message, ['exception' => $exception]);
            }
        }
    }
}
