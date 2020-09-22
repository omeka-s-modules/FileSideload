<?php
namespace FileSideload\Service\File\Store;

use FileSideload\File\Store\LocalHardLink;
use Interop\Container\ContainerInterface;
use Zend\ServiceManager\Factory\FactoryInterface;

/**
 * Service factory for the improved Local file store.
 */
class LocalHardLinkFactory implements FactoryInterface
{
    /**
     * Create and return the LocalHardLink file store
     *
     * @return LocalHardLink
     */
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $config = $services->get('Config');

        $basePath = $config['file_store']['local']['base_path'];
        if (null === $basePath) {
            $basePath = OMEKA_PATH . '/files';
        }

        $baseUri = $config['file_store']['local']['base_uri'];
        if (null === $baseUri) {
            $helpers = $services->get('ViewHelperManager');
            $serverUrlHelper = $helpers->get('ServerUrl');
            $basePathHelper = $helpers->get('BasePath');
            $baseUri = $serverUrlHelper($basePathHelper('files'));
        }
        return new LocalHardLink($basePath, $baseUri, $services->get('Omeka\Logger'));
    }
}
