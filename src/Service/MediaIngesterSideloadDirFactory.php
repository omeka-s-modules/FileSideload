<?php declare(strict_types=1);

namespace FileSideload\Service;

use FileSideload\Media\Ingester\SideloadDir;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class MediaIngesterSideloadDirFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $settings = $services->get('Omeka\Settings');
        return new SideloadDir(
            $settings->get('file_sideload_directory'),
            $settings->get('file_sideload_delete_file') === 'yes',
            $services->get('Omeka\File\TempFileFactory'),
            $services->get('Omeka\File\Validator'),
            (int) $settings->get('file_sideload_max_directories')
        );
    }
}
