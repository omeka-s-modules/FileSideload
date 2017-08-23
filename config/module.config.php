<?php
return [
    'media_ingesters' => [
        'factories' => [
            'sideload' => 'FileSideload\Service\MediaIngesterSideloadFactory',
        ],
    ],
    'translator' => [
        'translation_file_patterns' => [
            [
                'type' => 'gettext',
                'base_dir' => OMEKA_PATH . '/modules/FileSideload/language',
                'pattern' => '%s.mo',
                'text_domain' => null,
            ],
        ],
    ],
    'csv_import_media_ingester_adapter' => [
        'sideload'   => 'FileSideload\CSVImport\SideloadMediaIngesterAdapter',
    ],
];
