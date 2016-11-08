<?php
namespace FileSideload\Media\Ingester;

use Omeka\Api\Request;
use Omeka\Entity\Media;
use Omeka\Media\Ingester\IngesterInterface;
use Omeka\Stdlib\ErrorStore;
use Zend\Form\Element\Select;
use Zend\View\Renderer\PhpRenderer;

class Sideload implements IngesterInterface
{
    protected $services;

    public function __construct($services)
    {
        $this->services = $services;
    }

    /**
     * {@inheritDoc}
     */
    public function getLabel()
    {
        return 'Sideload'; // @translate
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

        $tempPath = $this->getDirectory() . '/' . $data['ingest_filename'];
        if (!is_file($tempPath)) {
            $errorStore->addError('ingest_filename', 'Invalid ingest filename');
            return;
        }

        $fileManager = $this->services->get('Omeka\File\Manager');
        $file = $fileManager->getTempFile();
        $file->setTempPath($tempPath);
        $file->setSourceName($data['ingest_filename']);

        $media->setStorageId($file->getStorageId());
        $media->setExtension($file->getExtension($fileManager));
        $media->setMediaType($file->getMediaType());
        $media->setSha256($file->getSha256());
        $media->setHasThumbnails($fileManager->storeThumbnails($file));

        if (!isset($data['store_original']) || $data['store_original']) {
            $fileManager->storeOriginal($file);
            $media->setHasOriginal(true);
        }
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
            'label' => 'File', // @translate
            'value_options' => $this->getFiles(),
            'empty_option' => 'Select a file to sideload...', // @translate
            'info' => 'The filename.', // @translate
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
        $directory = $this->getDirectory();
        if (is_dir($directory)) {
            $iterator = new \DirectoryIterator($directory);
            foreach ($iterator as $fileinfo) {
                if ($this->canSideload($fileinfo)) {
                    $files[$fileinfo->getFilename()] = $fileinfo->getFilename();
                }
            }
        }
        asort($files);
        return $files;
    }

    /**
     * Get the sideload directory.
     *
     * @return string
     */
    public function getDirectory()
    {
        $settings = $this->services->get('Omeka\Settings');
        return $settings->get('file_sideload_directory');
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
