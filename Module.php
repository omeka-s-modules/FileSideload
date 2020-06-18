<?php
namespace FileSideload;

use FileSideload\Form\ConfigForm;
use Omeka\Module\AbstractModule;
use Laminas\Mvc\Controller\AbstractController;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Laminas\View\Renderer\PhpRenderer;

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
