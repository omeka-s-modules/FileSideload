<?php
namespace FileSideload\Form;

use Zend\Form\Form;
use Zend\Validator\Callback;

class ConfigForm extends Form
{
    public function init()
    {
        $this->add([
            'name' => 'directory',
            'type' => 'Text',
            'options' => [
                'label' => 'Sideload directory', // @translate
                'info' => 'Files to be sideloaded should be added to this directory. The directory can be anywhere on your server and must be owned by the web server.', // @translate
            ],
            'attributes' => [
                'required' => true,
            ],
        ]);

        $inputFilter = $this->getInputFilter();
        $inputFilter->add([
            'name' => 'directory',
            'required' => true,
            'filters' => [
                ['name' => 'StringTrim'],
            ],
            'validators' => [
                [
                    'name' => 'Callback',
                    'options' => [
                        'messages' => [
                            Callback::INVALID_VALUE => 'The provided directory is not a directory or does not have sufficient permissions.', // @translate
                        ],
                        'callback' => function($dir) {
                            $fileinfo = new \SplFileInfo($dir);
                            return is_dir($dir) && posix_geteuid() === $fileinfo->getOwner();
                        }
                    ],
                ],
            ],
        ]);
    }
}
