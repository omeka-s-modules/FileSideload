<?php
namespace FileSideload\Media\Ingester;

use Omeka\Api\Request;
use Omeka\Entity\Media;
use Omeka\File\File;
use Omeka\Media\Ingester\IngesterInterface;
use Omeka\Stdlib\ErrorStore;
use Zend\Form\Element\Select;
use Zend\View\Renderer\PhpRenderer;

class Sideload implements IngesterInterface
{
    protected $fileManager;

    protected $directory;

    protected $deleteFile;

    public function __construct($services)
    {
        $this->fileManager = $services->get('Omeka\File\Manager');

        $settings = $services->get('Omeka\Settings');
        $this->directory = $settings->get('file_sideload_directory');
        $this->deleteFile = $settings->get('file_sideload_delete_file');
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
            $errorStore->addError('ingest_filename', 'No ingest filename specified'); // @translate;
            return;
        }

        $tempPath = sprintf('%s/%s', $this->directory, $data['ingest_filename']);
        if (!$this->canSideload(new \SplFileInfo($tempPath))) {
            $errorStore->addError('ingest_filename', sprintf(
                'Cannot sideload file "%s". File does not exist or does not have sufficient permissions', // @translate
                $tempPath
            ));
            return;
        }

        $file = new File($tempPath);
        $file->setSourceName($data['ingest_filename']);

        $media->setStorageId($file->getStorageId());
        $media->setExtension($file->getExtension($this->fileManager));
        $media->setMediaType($file->getMediaType());
        $media->setSha256($file->getSha256());
        $media->setHasThumbnails($this->fileManager->storeThumbnails($file));

        if (!isset($data['store_original']) || $data['store_original']) {
            $this->fileManager->storeOriginal($file);
            $media->setHasOriginal(true);
        }
        if (!array_key_exists('o:source', $data)) {
            $media->setSource($data['ingest_filename']);
        }

        if ('yes' === $this->deleteFile) {
            $file->delete();
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
        $dir = new \SplFileInfo($this->directory);
        if ($dir->isDir()) {
            $iterator = new \DirectoryIterator($dir);
            foreach ($iterator as $file) {
                if ($this->canSideload($file)) {
                    $files[$file->getFilename()] = $file->getFilename();
                }
            }
        }
        asort($files);
        return $files;
    }

    /**
     * Can a file be sideloaded?
     *
     * @param SplFileInfo $fileinfo
     * @return bool
     */
    public function canSideload(\SplFileInfo $file)
    {
        if ('yes' === $this->deleteFile && !$file->getPathInfo()->isWritable()) {
            // The parent directory must be server-writable to delete the file.
            return false;
        }
        return $file->isFile() && $file->isReadable();
    }
}
