<?php
namespace FileSideload\Media\Ingester;

use Omeka\Api\Request;
use Omeka\Entity\Media;
use Omeka\Media\Ingester\AbstractIngester;
use Omeka\Stdlib\ErrorStore;
use Zend\Form\Element\Select;
use Zend\View\Renderer\PhpRenderer;

class Sideload extends AbstractIngester
{
    /**
     * {@inheritDoc}
     */
    public function getLabel()
    {
        $translator = $this->getServiceLocator()->get('MvcTranslator');
        return $translator->translate('Sideload');
    }

    /**
     * {@inheritDoc}
     */
    public function getRenderer()
    {
        return 'file';
    }

    /**
     * Ingest from a URL.
     *
     * Accepts the following non-prefixed keys:
     *
     * + ingest_filename: (required) The filename to ingest.
     * + store_original: (optional, default true) Store the original file?
     *
     * {@inheritDoc}
     */
    public function ingest(Media $media, Request $request,
        ErrorStore $errorStore
    ) {
        $data = $request->getContent();
        if (!isset($data['ingest_filename'])) {
            $errorStore->addError('ingest_filename', 'No ingest filename specified');
            return;
        }

        if (false) {
            $errorStore->addError('ingest_filename', 'Invalid ingest filename');
            return;
        }

        $file = $this->getServiceLocator()->get('Omeka\File');
        $file->setSourceName($data['ingest_filename']);
        $file->setTempPath($this->getFilesDir() . '/' . $data['ingest_filename']);

        $fileManager = $this->getServiceLocator()->get('Omeka\File\Manager');
        $hasThumbnails = $fileManager->storeThumbnails($file);
        $media->setHasThumbnails($hasThumbnails);

        if (!isset($data['store_original']) || $data['store_original']) {
            $fileManager->storeOriginal($file);
            $media->setHasOriginal(true);
        }

        $media->setFilename($file->getStorageName());
        $media->setMediaType($file->getMediaType());

        if (!array_key_exists('o:source', $data)) {
            $media->setSource($data['ingest_filename']);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function form(PhpRenderer $view, array $options = [])
    {
        $select = new Select('o:media[__index__][ingest_filename]');
        $select->setOptions([
            'label' => $view->translate('File'),
            'value_options' => $this->getFiles(),
            'empty_option' => 'Select a file to sideload ...',
            'info' => $view->translate('The filename.'),
        ]);
        $select->setAttributes([
            'id' => 'media-sideload-ingest-filename-__index__',
            'required' => true
        ]);
        return $view->formRow($select);
    }

    /**
     * Get all files available to sideload.
     *
     * @return array
     */
    public function getFiles()
    {
        $files = [];
        $iterator = new \DirectoryIterator($this->getFilesDir());
        foreach ($iterator as $fileinfo) {
            if ($this->canSideload($fileinfo)) {
                $files[$fileinfo->getFilename()] = $fileinfo->getFilename();
            }
        }
        return $files;
    }

    public function getFilesDir()
    {
        return OMEKA_PATH . '/modules/FileSideload/files';
    }

    /**
     * Can a file be sideloaded?
     *
     * The file must be a regular file, readable, and owned by the web server
     * (for LocalStore:put() to sucessfully chmod).
     *
     * @param SplFileInfo $fileinfo
     * @return bool
     */
    public function canSideload(\SplFileInfo $fileinfo)
    {
        return $fileinfo->isFile()
            && $fileinfo->isReadable()
            && ($fileinfo->getOwner() === posix_geteuid());
    }
}
