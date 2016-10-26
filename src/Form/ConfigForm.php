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
                'label' => 'Sideload Directory', // @translate
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
                            Callback::INVALID_VALUE => 'The provided directory is not valid', // @translate
                        ],
                        'callback' => function($dir) {
                            return is_dir($dir);
                        }
                    ],
                ],
            ],
        ]);
    }
}
