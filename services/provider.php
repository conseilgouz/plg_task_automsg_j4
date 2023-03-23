<?php

/** Automsg Task
* Version			: 1.0.1
* Package			: Joomla 4.x
* copyright 		: Copyright (C) 2023 ConseilGouz. All rights reserved.
* license    		: http://www.gnu.org/licenses/gpl-2.0.html GNU/GPL
*
*/
defined('_JEXEC') or die;

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Event\DispatcherInterface;
use ConseilGouz\Plugin\Task\AutoMsg\Extension\AutoMsg;

return new class implements ServiceProviderInterface
{
    /**
     * Registers the service provider with a DI container.
     *
     * @param   Container  $container  The DI container.
     *
     * @return  void
     *
     * @since   4.2.0
     */
    public function register(Container $container)
    {
        $container->set(
            PluginInterface::class,
            function (Container $container) {
                $plugin = new AutoMsg(
                    $container->get(DispatcherInterface::class),
                    (array) PluginHelper::getPlugin('task', 'automsg'),
                    JPATH_ROOT . '/images/'
                );
                $plugin->setApplication(Factory::getApplication());

                return $plugin;
            }
        );
    }
};
