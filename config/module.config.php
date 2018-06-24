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
                'base_dir' => dirname(__DIR__) . '/language',
                'pattern' => '%s.mo',
                'text_domain' => null,
            ],
        ],
    ],
    'csvimport' => [
        'media_ingester_adapter' => [
            'sideload' => CSVImport\SideloadMediaIngesterAdapter::class,
        ],
        'user_settings' => [
            'csvimport_automap_user_list' => [
                'file' => 'media_source {sideload}',
                'files' => 'media_source {sideload}',
                'upload' => 'media_source {sideload}',
                'sideload' => 'media_source {sideload}',
                'file sideload' => 'media_source {sideload}',
            ],
        ],
    ],
];
