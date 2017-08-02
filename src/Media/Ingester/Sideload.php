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
    protected $directory;

    protected $deleteFile;

    protected $tempFileFactory;

    public function __construct($directory, $deleteFile, $tempFileFactory)
    {
        $this->directory = $directory;
        $this->deleteFile = $deleteFile;
        $this->tempFileFactory = $tempFileFactory;
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
    public function ingest(Media $media, Request $request, ErrorStore $errorStore)
    {
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

        $tempFile = $this->tempFileFactory->build();
        $tempFile->setTempPath($tempPath);
        $tempFile->setSourceName($data['ingest_filename']);

        $media->setStorageId($tempFile->getStorageId());
        $media->setExtension($tempFile->getExtension());
        $media->setMediaType($tempFile->getMediaType());
        $media->setSha256($tempFile->getSha256());
        $hasThumbnails = $tempFile->storeThumbnails();
        $media->setHasThumbnails($hasThumbnails);
        if (!array_key_exists('o:source', $data)) {
            $media->setSource($data['ingest_filename']);
        }
        if (!isset($data['store_original']) || $data['store_original']) {
            $tempFile->storeOriginal();
            $media->setHasOriginal(true);
        }
        if ('yes' === $this->deleteFile) {
            $tempFile->delete();
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
