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
    protected $directory;

    protected $deleteFile;

    protected $tempFileFactory;

    public function __construct($directory, $deleteFile, $tempFileFactory)
    {
        // Only work on the resolved real directory path.
        $this->directory = realpath($directory);
        $this->deleteFile = $deleteFile;
        $this->tempFileFactory = $tempFileFactory;
    }

    public function getLabel()
    {
        return 'Sideload'; // @translate
    }

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

        $isAbsolutePathInsideDir = $this->directory && strpos($data['ingest_filename'], $this->directory) === 0;
        $filepath = $isAbsolutePathInsideDir
            ? $data['ingest_filename']
            : $this->directory . DIRECTORY_SEPARATOR . $data['ingest_filename'];
        $fileinfo = new \SplFileInfo($filepath);
        $tempPath = $this->verifyFile($fileinfo);
        if (false === $tempPath) {
            $errorStore->addError('ingest_filename', sprintf(
                'Cannot sideload file "%s". File does not exist or does not have sufficient permissions', // @translate
                $filepath
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
        $media->setSize($tempFile->getSize());
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

    public function form(PhpRenderer $view, array $options = [])
    {
        $files = $this->getFiles();
        $isEmpty = empty($files);

        $select = new Select('o:media[__index__][ingest_filename]');
        $select->setOptions([
            'label' => 'File', // @translate
            'value_options' => $files,
            'empty_option' => $isEmpty
                ? 'No file: add files in the directory or check its path' // @translate
                : 'Select a file to sideload...', // @translate
            'info' => 'The filename.', // @translate
        ]);
        $select->setAttributes([
            'id' => 'media-sideload-ingest-filename-__index__',
            'required' => true,
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
                if ($this->verifyFile($file)) {
                    $files[$file->getFilename()] = $file->getFilename();
                }
            }
        }
        asort($files);
        return $files;
    }

    /**
     * Verify the passed file.
     *
     * Working off the "real" base directory and "real" filepath: both must
     * exist and have sufficient permissions; the filepath must begin with the
     * base directory path to avoid problems with symlinks; the base directory
     * must be server-writable to delete the file; and the file must be a
     * readable regular file.
     *
     * @param \SplFileInfo $fileinfo
     * @return string|false The real file path or false if the file is invalid
     */
    public function verifyFile(\SplFileInfo $fileinfo)
    {
        if (false === $this->directory) {
            return false;
        }
        $realPath = $fileinfo->getRealPath();
        if (false === $realPath) {
            return false;
        }
        if (0 !== strpos($realPath, $this->directory)) {
            return false;
        }
        if ('yes' === $this->deleteFile && !$fileinfo->getPathInfo()->isWritable()) {
            return false;
        }
        if (!$fileinfo->isFile() || !$fileinfo->isReadable()) {
            return false;
        }
        return $realPath;
    }
}
