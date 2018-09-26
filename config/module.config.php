<?php
namespace FileSideload;

return [
    'media_ingesters' => [
        'factories' => [
            'sideload' => Service\MediaIngesterSideloadFactory::class,
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
