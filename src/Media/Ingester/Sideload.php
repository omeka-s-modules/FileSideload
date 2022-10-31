<?php
namespace FileSideload\Media\Ingester;

use Omeka\Api\Request;
use Omeka\Entity\Media;
use Omeka\File\TempFileFactory;
use Omeka\File\Validator;
use Omeka\Media\Ingester\IngesterInterface;
use Omeka\Stdlib\ErrorStore;
use Laminas\Form\Element\Select;
use Laminas\View\Renderer\PhpRenderer;

class Sideload implements IngesterInterface
{
    /**
     * @var string
     */
    protected $directory;

    /**
     * @var string
     */
    protected $userDirectory;

    /**
     * @var bool
     */
    protected $deleteFile;

    /**
     * @var TempFileFactory
     */
    protected $tempFileFactory;

    /**
     * @var Validator
     */
    protected $validator;

    /**
     * @var int
     */
    protected $maxFiles;

    /**
     * @var bool
     */
    protected $hasMoreFiles = false;

    /**
     * @param string $directory
     * @param bool $deleteFile
     * @param TempFileFactory $tempFileFactory
     * @param Validator $validator
     * @param int $maxFiles
     * @param string $userDirectory
     */
    public function __construct(
        $directory,
        $deleteFile,
        TempFileFactory $tempFileFactory,
        Validator $validator,
        $maxFiles,
        $userDirectory
    ) {
        // Only work on the resolved real directory path.
        $this->directory = $directory ? realpath($directory) : '';
        // The user directory is stored as a sub-path of the main directory.
        // The main directory may have been updated.
        $userDir = realpath($this->directory . DIRECTORY_SEPARATOR . $userDirectory);
        $this->userDirectory = $this->directory && mb_strpos($userDirectory, '..') === false && strlen($userDirectory) && is_dir($userDir) && is_readable($userDir)
            ? $userDir
            : $this->directory;
        $this->deleteFile = $deleteFile;
        $this->tempFileFactory = $tempFileFactory;
        $this->validator = $validator;
        $this->maxFiles = $maxFiles;
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
            $errorStore->addError('ingest_filename', 'No ingest filename specified'); // @translate
            return;
        }

        $isAbsolutePathInsideDir = $this->directory && strpos($data['ingest_filename'], $this->directory) === 0;
        $filepath = $isAbsolutePathInsideDir
            ? $data['ingest_filename']
            : $this->directory . DIRECTORY_SEPARATOR . $data['ingest_filename'];
        $fileinfo = new \SplFileInfo($filepath);
        $realPath = $this->verifyFile($fileinfo);
        if (false === $realPath) {
            $errorStore->addError('ingest_filename', sprintf(
                'Cannot sideload file "%s". File does not exist or does not have sufficient permissions', // @translate
                $filepath
            ));
            return;
        }

        $tempFile = $this->tempFileFactory->build();
        $tempFile->setSourceName($data['ingest_filename']);

        // Copy the file to a temp path, so it is managed as a real temp file (#14).
        copy($realPath, $tempFile->getTempPath());

        if (!$this->validator->validate($tempFile, $errorStore)) {
            return;
        }

        if (!array_key_exists('o:source', $data)) {
            $media->setSource($data['ingest_filename']);
        }
        $storeOriginal = (!isset($data['store_original']) || $data['store_original']);
        $tempFile->mediaIngestFile($media, $request, $errorStore, $storeOriginal, true, true, true);

        if ($this->deleteFile) {
            unlink($realPath);
        }
    }

    public function form(PhpRenderer $view, array $options = [])
    {
        // When the user dir is different from the main dir, prepend the main
        // dir path to simplify hydration.
        $prependPath = $this->userDirectory === $this->directory
            ? ''
            : mb_substr($this->userDirectory, mb_strlen($this->directory) + 1) . DIRECTORY_SEPARATOR;

        $mainDirectory = $this->directory;
        $this->directory = $this->userDirectory;
        $files = $this->getFiles($prependPath);
        $this->directory = $mainDirectory;

        $isEmpty = empty($files);

        if ($isEmpty) {
            $emptyOption = 'No file: add files in the directory or check its path'; // @translate
        } elseif ($this->hasMoreFiles) {
            $emptyOption = 'Select a file to sideload… (only first ones are listed)'; // @translate
        } else {
            $emptyOption = 'Select a file to sideload…'; // @translate
        }

        $select = new Select('o:media[__index__][ingest_filename]');
        $select->setOptions([
            'label' => 'File', // @translate
            'value_options' => $files,
            'empty_option' => '',
        ]);
        $select->setAttributes([
            'id' => 'media-sideload-ingest-filename-__index__',
            'required' => true,
            'class' => 'media-sideload-select chosen-select',
            'data-placeholder' => $emptyOption,
        ]);
        return $view->formRow($select)
            // Ideally should be in a js file of the module or Omeka.
            . '<script>$(".media-sideload-select").chosen(window.chosenOptions);</script>';
    }

    /**
     * Get all files available to sideload.
     *
     * @return array
     */
    public function getFiles(string $prependPath = '')
    {
        $files = [];
        $count = 0;
        $dir = new \SplFileInfo($this->directory);
        if ($dir->isDir()) {
            $lengthDir = strlen($this->directory) + 1;
            $dir = new \RecursiveDirectoryIterator($this->directory);
            // Prevent UnexpectedValueException "Permission denied" by excluding
            // directories that are not executable or readable.
            $dir = new \RecursiveCallbackFilterIterator($dir, function ($current, $key, $iterator) {
                if ($iterator->isDir() && (!$iterator->isExecutable() || !$iterator->isReadable())) {
                    return false;
                }
                return true;
            });
            $iterator = new \RecursiveIteratorIterator($dir);
            foreach ($iterator as $filepath => $file) {
                if ($this->verifyFile($file)) {
                    // For security, don't display the full path to the user.
                    $relativePath = substr($filepath, $lengthDir);
                    $files[$prependPath . $relativePath] = $relativePath;
                    if ($this->maxFiles && ++$count >= $this->maxFiles) {
                        $this->hasMoreFiles = true;
                        break;
                    }
                }
            }
        }

        // Don't mix directories and files, but list directories first as usual.
        $alphabeticAndDirFirst = function ($a, $b) {
            if ($a === $b) {
                return 0;
            }
            $aInRoot = strpos($a, '/') === false;
            $bInRoot = strpos($b, '/') === false;
            if (($aInRoot && $bInRoot) || (!$aInRoot && !$bInRoot)) {
                return strcasecmp($a, $b);
            }
            return $bInRoot ? -1 : 1;
        };
        uasort($files, $alphabeticAndDirFirst);

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
        if ($this->deleteFile && !$fileinfo->getPathInfo()->isWritable()) {
            return false;
        }
        if (!$fileinfo->isFile() || !$fileinfo->isReadable()) {
            return false;
        }
        return $realPath;
    }
}
