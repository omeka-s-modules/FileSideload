<?php
namespace FileSideload;

use FileSideload\Form\ConfigForm;
use FileSideload\Media\Ingester\Sideload;
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
        $settings = $serviceLocator->get('Omeka\Settings');
        $settings->delete('file_sideload_directory');
        $settings->delete('file_sideload_delete_file');
    }

    public function attachListeners(SharedEventManagerInterface $sharedEventManager)
    {
        // Remove sideload ingester from list of registered ingesters if there
        // are no available files to sideload.
        $sharedEventManager->attach(
            'Omeka\Media\Ingester\Manager',
            'service.registered_names',
            function (Event $event) {
                $sideload = new Sideload($this->getServiceLocator());
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
        $settings = $this->getServiceLocator()->get('Omeka\Settings');
        $form = new ConfigForm;
        $form->init();
        $form->setData([
            'directory' => $settings->get('file_sideload_directory'),
            'delete_file' => $settings->get('file_sideload_delete_file', 'no'),
        ]);
        return $renderer->formCollection($form, false);
    }

    public function handleConfigForm(AbstractController $controller)
    {
        $settings = $this->getServiceLocator()->get('Omeka\Settings');
        $form = new ConfigForm;
        $form->init();
        $form->setData($controller->params()->fromPost());
        if (!$form->isValid()) {
            $controller->messenger()->addErrors($form->getMessages());
            return false;
        }
        $formData = $form->getData();
        $settings->set('file_sideload_directory', $formData['directory']);
        $settings->set('file_sideload_delete_file', $formData['delete_file']);
        return true;
    }
}

