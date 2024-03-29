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

use Symfony\Component\DependencyInjection\ContainerInterface;
use Twig\Environment as Twig;

/**
 * Processes the custom CSS styles applied to the backend design based on the
 * value of the design configuration options.
 *
 * @author Javier Eguiluz <javier.eguiluz@gmail.com>
 */
class DesignConfigPass implements ConfigPassInterface
{

    private Twig $twig;
    /**
     * @var bool
     */
    private $kernelDebug;
    /**
     * @var string
     */
    private $locale;

    /**
     * @var ContainerInterface to prevent ServiceCircularReferenceException
     * @var bool
     * @var string
     */
    public function __construct(Twig $twig, $kernelDebug, $locale)
    {
        $this->twig = $twig;
        $this->kernelDebug = $kernelDebug;
        $this->locale = $locale;
    }

    public function process(array $backendConfig): array
    {
        $backendConfig = $this->processRtlLanguages($backendConfig);
        $backendConfig = $this->processCustomCss($backendConfig);

        return $backendConfig;
    }

    private function processRtlLanguages(array $backendConfig): array
    {
        if (!isset($backendConfig['design']['rtl'])) {
            // ar = Arabic, fa = Persian, he = Hebrew
            if (in_array(substr($this->locale, 0, 2), ['ar', 'fa', 'he'])) {
                $backendConfig['design']['rtl'] = true;
            } else {
                $backendConfig['design']['rtl'] = false;
            }
        }

        return $backendConfig;
    }

    private function processCustomCss(array $backendConfig): array
    {
        $customCssContent = $this->twig->render('@EasyAdmin/css/easyadmin.css.twig', [
            'brand_color' => $backendConfig['design']['brand_color'],
            'color_scheme' => $backendConfig['design']['color_scheme'],
            'kernel_debug' => $this->kernelDebug,
        ]);

        $minifiedCss = preg_replace(['/\n/', '/\s{2,}/'], ' ', $customCssContent);
        $backendConfig['_internal']['custom_css'] = $minifiedCss;

        return $backendConfig;
    }
}
