<?php
namespace FileSideload\Form;

use Omeka\Form\AbstractForm;
use Zend\Validator\Callback;

class ConfigForm extends AbstractForm
{
    public function buildForm()
    {
        $translator = $this->getTranslator();

        $this->add([
            'name' => 'directory',
            'type' => 'Text',
            'options' => [
                'label' => $translator->translate('Sideload Directory'),
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
                            Callback::INVALID_VALUE => $translator->translate('The provided directory is not valid'),
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
