<?php
namespace FileSideload;

use FileSideload\Form\ConfigForm;
use Omeka\Module\AbstractModule;
use Zend\EventManager\Event;
use Zend\EventManager\SharedEventManagerInterface;
use Zend\Mvc\Controller\AbstractController;
use Zend\ServiceManager\ServiceLocatorInterface;
use Zend\View\Renderer\PhpRenderer;

class Module extends AbstractModule
{
    /**
     * @var ConfigForm
     */
    protected $configForm;

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
            'service.registered.names',
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
        if (!$this->configForm) {
            $services = $this->getServiceLocator();
            $settings = $services->get('Omeka/Settings');
            $this->configForm = new ConfigForm($services);
            $this->configForm->setData(['directory' => $settings->get('file_sideload_directory')]);
        }
        return $renderer->formCollection($this->configForm, false);
    }

    public function handleConfigForm(AbstractController $controller)
    {
        $services = $this->getServiceLocator();
        $this->configForm = new ConfigForm($services);
        $this->configForm->setData($controller->params()->fromPost());
        if ($this->configForm->isValid()) {
            $services->get('Omeka/Settings')->set(
                'file_sideload_directory',
                $this->configForm->getData()['directory']
            );
            return true;
        }
        return false;
    }
}

