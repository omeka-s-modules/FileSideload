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
        $settings->delete('file_sideload_mode');
    }

    public function getConfigForm(PhpRenderer $renderer)
    {
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');
        $form = $services->get('FormElementManager')->get(ConfigForm::class);
        $form->init();
        $form->setData([
            'directory' => $settings->get('file_sideload_directory'),
            'delete_file' => $settings->get('file_sideload_delete_file', 'no'),
            'filesideload_mode' => $settings->get('file_sideload_mode', 'copy'),
        ]);

        return $renderer->formCollection($form, false);
    }

    public function handleConfigForm(AbstractController $controller)
    {
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');
        $form = $services->get('FormElementManager')->get(ConfigForm::class);
        $form->init();
        $form->setData($controller->params()->fromPost());
        if (!$form->isValid()) {
            $controller->messenger()->addErrors($form->getMessages());
            return false;
        }

        $formData = $form->getData();
        $settings->set('file_sideload_directory', $formData['directory']);
        $settings->set('file_sideload_delete_file', $formData['delete_file']);
        $settings->set('file_sideload_mode', $formData['filesideload_mode']);
        return true;
    }
}
