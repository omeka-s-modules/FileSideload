<?php

namespace FileSideload\Media\Ingester;

use Omeka\Api\Request;
use Omeka\Entity\Media;
use Omeka\File\TempFileFactory;
use Omeka\File\Validator;
use Omeka\Media\Ingester\IngesterInterface;
use Omeka\Stdlib\ErrorStore;
use Omeka\Stdlib\Message;
use Laminas\Form\Element;
use Laminas\View\Renderer\PhpRenderer;

class SideloadDir implements IngesterInterface
{
    /**
     * @var string
     */
    protected $directory;

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
    protected $maxDirectories;

    /**
     * @var array
     */
    protected $listDirs = [];

    /**
     * @var bool
     */
    protected $hasMoreDirs = false;

    /**
     * @param string $directory
     * @param bool $deleteFile
     * @param TempFileFactory $tempFileFactory
     * @param Validator $validator
     * @param int $maxDirectories
     */
    public function __construct(
        $directory,
        $deleteFile,
        TempFileFactory $tempFileFactory,
        Validator $validator,
        $maxDirectories
    ) {
        // Only work on the resolved real directory path.
        $this->directory = $directory ? realpath($directory) : '';
        $this->deleteFile = $deleteFile;
        $this->tempFileFactory = $tempFileFactory;
        $this->validator = $validator;
        $this->maxDirectories = $maxDirectories;
    }

    public function getLabel()
    {
        return 'Sideload directory'; // @translate
    }

    public function getRenderer()
    {
        return 'file';
    }

    /**
     * Ingest from a directory on the server.
     *
     * Accepts the following non-prefixed keys:
     * - ingest_directory: (required) The source directory where the file to ingest is.
     * - ingest_filename: (required) The filename to ingest.
     * - ingest_directory_recursively: (optional, default false) Ingest directory recursively?
     * - store_original: (optional, default true) Store the original file?
     *
     * {@inheritDoc}
     */
    public function ingest(Media $media, Request $request, ErrorStore $errorStore)
    {
        $data = $request->getContent();

        // Checks are already done during pre-hydration, but another check is
        // needed when the ingester is called directly.

        if (!isset($data['ingest_directory'])) {
            $errorStore->addError('ingest_directory', 'No ingest directory specified'); // @translate
            return;
        }

        // This is the checked full real path inside the main directory.
        $realIngestDirectory = $this->checkIngestDir((string) $data['ingest_directory'], $errorStore);
        if (is_null($realIngestDirectory)) {
            return;
        }

        if (!isset($data['ingest_filename'])) {
            $errorStore->addError('ingest_filename', 'No ingest filename specified'); // @translate
            return;
        }

        // The check is done against the directory, but the file is relative to the
        // main directory.
        $isAbsolutePathInsideDir = strpos((string) $data['ingest_filename'], $realIngestDirectory) === 0;
        $filepath = $isAbsolutePathInsideDir
            ? $data['ingest_filename']
            : $this->directory . DIRECTORY_SEPARATOR . $data['ingest_filename'];
        $fileinfo = new \SplFileInfo($filepath);
        $realPath = $this->verifyFileOrDir($fileinfo);
        if (is_null($realPath)) {
            $errorStore->addError('ingest_filename', new Message(
                'Cannot sideload file "%s". File does not exist or is not inside main directory or does not have sufficient permissions', // @translate
                $data['ingest_filename']
            ));
            return;
        }

        // When recursive is not set, check if the file is a root file.
        if (empty($data['ingest_directory_recursively']) && pathinfo($realPath, PATHINFO_DIRNAME) !== $realIngestDirectory) {
            $errorStore->addError('ingest_filename', new Message(
                'Cannot sideload file "%s": ingestion of directory "%s" is not set recursive', // @translate
                $data['ingest_filename'],
                $data['ingest_directory']
            ));
        }

        // Processing ingestion.

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

        if (!$this->deleteFile) {
            return;
        }
        unlink($realPath);

        // Check if this is the last file of the ingest directory.
        if (!$this->dirHasNoFileAndIsRemovable($realIngestDirectory)) {
            return;
        }
        // The ingest directory may have empty directories, so recursive remove it.
        $this->rrmdir($realIngestDirectory);
    }

    public function form(PhpRenderer $view, array $options = [])
    {
        $this->listDirs();

        $isEmptyDirs = !count($this->listDirs);
        if ($isEmptyDirs) {
            $emptyOptionDir = 'No directory: add directories in the directory or check its path'; // @translate
        } elseif ($this->hasMoreDirs) {
            $emptyOptionDir = 'Select a directory to sideload all files inside… (only first ones are listed)'; // @translate
        } else {
            $emptyOptionDir = 'Select a directory to sideload all files inside…'; // @translate
        }

        $select = new Element\Select('o:media[__index__][ingest_directory]');
        $select
            ->setOptions([
                'label' => 'Directory', // @translate
                'info' => 'Directories and files without sufficient permissions are skipped.', // @translate
                'value_options' => $this->listDirs,
                'empty_option' => $emptyOptionDir,
            ])
            ->setAttributes([
                'id' => 'media-sideload-ingest-directory-__index__',
                'required' => true,
            ]);

        $recursive = new Element\Checkbox('o:media[__index__][ingest_directory_recursively]');
        $recursive
            ->setOptions([
                'label' => 'Ingest directory recursively', // @translate
            ])
            ->setAttributes([
                'id' => 'media-sideload-ingest-directory-recursive-__index__',
                'required' => false,
            ]);

        return $view->formRow($select)
            . $view->formRow($recursive);
    }

    /**
     * Get all directories available to sideload.
     */
    protected function listDirs(): void
    {
        $this->listDirs = [];
        $this->hasMoreDirs = false;

        $dir = new \SplFileInfo($this->directory);
        if (!$dir->isDir()) {
            return;
        }

        $countDirs = 0;

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
        /** @var \SplFileInfo $file */
        foreach ($iterator as $filepath => $file) {
            if ($file->isDir()) {
                if (!$this->hasMoreDirs && $this->verifyFileOrDir($file, true)) {
                    // There are two filepaths for one dirpath: "." and "..".
                    $filepath = $file->getRealPath();
                    // Don't list empty directories.
                    if (!$this->dirHasNoFileAndIsRemovable($filepath)) {
                        // For security, don't display the full path to the user.
                        $relativePath = substr($filepath, $lengthDir);
                        if (!isset($this->listDirs[$relativePath])) {
                            // Use keys for quicker process on big directories.
                            $this->listDirs[$relativePath] = null;
                            if ($this->maxDirectories && ++$countDirs >= $this->maxDirectories) {
                                $this->hasMoreDirs = true;
                                break;
                            }
                        }
                    }
                }
            }
        }

        $this->listDirs = array_keys($this->listDirs);
        natcasesort($this->listDirs);
        $this->listDirs = array_combine($this->listDirs, $this->listDirs);
    }

    /**
     * Verify the passed file or directory.
     *
     * Working off the "real" base directory and "real" filepath: both must
     * exist and have sufficient permissions; the filepath must begin with the
     * base directory path to avoid problems with symlinks; the base directory
     * must be server-writable to delete the file; and the file must be a
     * readable regular file or directory.
     *
     * @param \SplFileInfo $fileinfo
     * @return string|null The real file path or null if the file is invalid.
     */
    protected function verifyFileOrDir(\SplFileInfo $fileinfo, bool $isDir = false): ?string
    {
        if (false === $this->directory) {
            return null;
        }
        $realPath = $fileinfo->getRealPath();
        if (false === $realPath) {
            return null;
        }
        if ($realPath === $this->directory) {
            return null;
        }
        if (0 !== strpos($realPath, $this->directory)) {
            return null;
        }
        if ($this->deleteFile && !$fileinfo->getPathInfo()->isWritable()) {
            return null;
        }
        if (!$fileinfo->isReadable()) {
            return null;
        }
        if ($isDir) {
            if (!$fileinfo->isDir() || !$fileinfo->isExecutable()) {
                return null;
            }
        } elseif (!$fileinfo->isFile()) {
            return null;
        }
        return $realPath;
    }

    protected function checkIngestDir(string $directory, ErrorStore $errorStore): ?string
    {
        if (!strlen($directory)) {
            $errorStore->addError('ingest_directory', 'No ingest directory specified.'); // @translate
            return null;
        }

        // Quick security checks.
        if ($directory === '.' || $directory === '..' || $directory === '/') {
            $errorStore->addError('ingest_directory', 'Illegal ingest directory specified.'); // @translate
            return null;
        }

        $isAbsolutePathInsideDir = $this->directory && strpos($directory, $this->directory) === 0;
        $directory = $isAbsolutePathInsideDir
            ? $directory
            : $this->directory . DIRECTORY_SEPARATOR . $directory;
        $fileinfo = new \SplFileInfo($directory);
        $directory = $this->verifyFileOrDir($fileinfo, true);
        if (is_null($directory)) {
            // Set a clearer message in some cases.
            if ($this->deleteFile && !$fileinfo->getPathInfo()->isWritable()) {
                $errorStore->addError('ingest_directory', new Message(
                    'Ingest directory "%s" is not writeable but the config requires deletion after upload.', // @translate
                    $directory
                ));
            } elseif (!$fileinfo->isDir()) {
                $errorStore->addError('ingest_directory', new Message(
                    'Invalid ingest directory "%s" specified: not a directory', // @translate
                    $directory
                ));
            } else {
                $errorStore->addError('ingest_directory', new Message(
                    'Invalid ingest directory "%s" specified: incorrect path or insufficient permissions', // @translate
                    $directory
                ));
            }
            return null;
        }

        return $directory;
    }

    /**
     * Check if a directory, that is valid, contains files or unwriteable content, recursively.
     *
     * The directory should be already checked.
     */
    private function dirHasNoFileAndIsRemovable(string $dir): bool
    {
        /** @var \SplFileInfo $fileinfo */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir)) as $fileinfo) {
            if (!$fileinfo->isDir()) {
                return false;
            }
            if (!$fileinfo->isExecutable() || !$fileinfo->isReadable() || !$fileinfo->isWritable()) {
                return false;
            }
        }
        return true;
    }

    /**
     * Removes directories recursively and any files inside them.
     */
    private function rrmdir(string $dir): bool
    {
        if (!file_exists($dir)
            || !is_dir($dir)
            || !is_readable($dir)
            || !is_writeable($dir)
        ) {
            return false;
        }

        $scandir = scandir($dir);
        if (!is_array($scandir)) {
            return false;
        }

        $files = array_diff($scandir, ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->rrmdir($path);
            } else {
                @unlink($path);
            }
        }

        return @rmdir($dir);
    }
}
