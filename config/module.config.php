<?php
namespace FileSideload;

return [
    'service_manager' => [
        'factories' => [
            File\Store\LocalHardLink::class => Service\File\Store\LocalHardLinkFactory::class,
        ],
    ],
    'media_ingesters' => [
        'factories' => [
            'sideload' => Service\MediaIngesterSideloadFactory::class,
        ],
    ],
    'form_elements' => [
        'factories' => [
            Form\ConfigForm::class => Service\Form\ConfigFormFactory::class,
        ],
    ],
    'translator' => [
        'translation_file_patterns' => [
            [
                'type' => 'gettext',
                'base_dir' => __DIR__ . '/../language',
                'pattern' => '%s.mo',
                'text_domain' => null,
            ],
        ],
    ],
    'csv_import' => [
        'media_ingester_adapter' => [
            'sideload' => CSVImport\SideloadMediaIngesterAdapter::class,
        ],
    ],
];
