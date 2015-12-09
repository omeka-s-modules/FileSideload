<?php
namespace FileSideload;

use Omeka\Event\Event as OmekaEvent;
use Omeka\Module\AbstractModule;
use Zend\EventManager\Event;
use Zend\EventManager\SharedEventManagerInterface;

class Module extends AbstractModule
{
    public function getConfig()
    {
        return include __DIR__ . '/config/module.config.php';
    }

    public function attachListeners(SharedEventManagerInterface $sharedEventManager)
    {
        $sharedEventManager->attach(
            'Omeka\Media\Ingester\Manager',
            OmekaEvent::SERVICE_REGISTERED_NAMES,
            function (Event $event) {
                $names = $event->getParam('registered_names');
                $event->setParam('registered_names', $names);
            }
        );
    }
}

