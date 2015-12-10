<?php
namespace FileSideload;

use FileSideload\Form\ConfigForm;
use Omeka\Event\Event as OmekaEvent;
use Omeka\Module\AbstractModule;
use Zend\EventManager\Event;
use Zend\EventManager\SharedEventManagerInterface;
use Zend\Mvc\Controller\AbstractController;
use Zend\ServiceManager\ServiceLocatorInterface;
use Zend\View\Renderer\PhpRenderer;

class Module extends AbstractModule
{
    public function getConfig()
    {
        return include __DIR__ . '/config/module.config.php';
    }

    public function uninstall(ServiceLocatorInterface $serviceLocator)
    {
        $settings = $serviceLocator->get('Omeka/Settings');
        $settings->delete('file_sideload_directory');
    }

    public function attachListeners(SharedEventManagerInterface $sharedEventManager)
    {
        $sharedEventManager->attach(
            'Omeka\Media\Ingester\Manager',
            OmekaEvent::SERVICE_REGISTERED_NAMES,
            /**
             * Remove sideload ingester from list of registered ingesters if
             * there are no available files to sideload.
             *
             * @param Event $event
             */
            function (Event $event) {
                $sideload = new \FileSideload\Media\Ingester\Sideload;
                $sideload->setServiceLocator($this->getServiceLocator());
                $names = $event->getParam('registered_names');
                if (!$sideload->getFiles()) {
                    unset($names[array_search('sideload', $names)]);
                }
                $event->setParam('registered_names', $names);
            }
        );
    }

    public function getConfigForm(PhpRenderer $renderer)
    {
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka/Settings');
        $form = new ConfigForm($this->getServiceLocator());
        $form->setData(['directory' => $settings->get('file_sideload_directory')]);
        return $renderer->formCollection($form, false);
    }

    public function handleConfigForm(AbstractController $controller)
    {
        $services = $this->getServiceLocator();
        $form = new ConfigForm($services);
        $form->setData($controller->params()->fromPost());
        if ($form->isValid()) {
            $settings = $services->get('Omeka/Settings');
            $settings->set('file_sideload_directory', $form->getData()['directory']);
            return true;
        } else {
            $controller->messenger()->addErrors($form->getMessages());
            return false;
        }
    }
}

