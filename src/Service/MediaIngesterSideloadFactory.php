<?php
namespace FileSideload\Service;

use FileSideload\Media\Ingester\Sideload;
use Zend\ServiceManager\Factory\FactoryInterface;
use Interop\Container\ContainerInterface;

class MediaIngesterSideloadFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $settings = $services->get('Omeka\Settings');
        return new Sideload(
            $settings->get('file_sideload_directory'),
            $settings->get('file_sideload_delete_file'),
            $services->get('Omeka\File\TempFileFactory')
        );
    }
}
