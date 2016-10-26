<?php
namespace FileSideload\Service;

use FileSideload\Media\Ingester\Sideload;
use Zend\ServiceManager\Factory\FactoryInterface;
use Interop\Container\ContainerInterface;

class MediaIngesterSideloadFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        return new Sideload($services);
    }
}
