<?php
return [
    'media_ingesters' => [
        'factories' => [
            'sideload' => 'FileSideload\Service\MediaIngesterSideloadFactory',
        ],
    ],
    'csv_import_media_ingester_adapter' => [
        'sideload'   => 'FileSideload\CSVImport\SideloadMediaIngesterAdapter',
    ],
];
