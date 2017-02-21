<?php
namespace FileSideload\CSVImport;

use CSVImport\MediaIngesterAdapter\MediaIngesterAdapterInterface;

class SideloadMediaIngesterAdapter implements MediaIngesterAdapterInterface
{
    public function getJson($mediaDatum)
    {
        $mediaJson['ingest_filename'] = $mediaDatum;
        return $mediaJson;
    }
}
