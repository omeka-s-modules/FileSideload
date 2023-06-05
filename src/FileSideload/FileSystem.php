<?php declare(strict_types=1);

namespace FileSideload\FileSideload;

class FileSystem
{
    /**
     * @var string
     */
    protected $sideloadDirectory;

    /**
     * @var bool
     */
    protected $deleteFile;

    /**
     * @var int
     */
    protected $maxDirectories;

    /**
     * @var bool
     */
    private $hasMoreDirectories = false;

    public function __construct(
        ?string $sideloadDirectory,
        bool $deleteFile,
        int $maxDirectories
    ) {
        // Only work on the resolved real directory path.
        $this->sideloadDirectory = $sideloadDirectory ? realpath($sideloadDirectory) : '';
        $this->deleteFile = $deleteFile;
        $this->maxDirectories = $maxDirectories;
    }

    /**
     * Get the service FileSystem.
     */
    public function __invoke(): self
    {
        return $this;
    }

    /**
     * Check if a directory, that is valid, contains files or unwriteable content, recursively.
     *
     * The directory should be already checked.
     */
    public function dirHasNoFileAndIsRemovable(string $dir): bool
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
     * Recursively get all directories available in a directory.
     */
    public function listDirs(
        string $directory,
        int $maxDepth = -1,
        ?int $maxDirs = null
    ): array {
        $listDirs = [];
        $this->hasMoreDirectories = false;

        $dir = new \SplFileInfo($directory);
        if (!$dir->isDir()) {
            return [];
        }

        $countDirs = 0;
        $lengthDir = strlen($this->sideloadDirectory) + 1;
        $maxDirs ??= $this->maxDirectories;

        $dir = new \RecursiveDirectoryIterator($directory);
        // Prevent UnexpectedValueException "Permission denied" by excluding
        // directories that are not executable or readable.
        $dir = new \RecursiveCallbackFilterIterator($dir, function ($current, $key, $iterator) {
            if ($iterator->isDir() && (!$iterator->isExecutable() || !$iterator->isReadable())) {
                return false;
            }
            return true;
        });

        // Follow the same rules than SideloadDir::listDirs, even if empty dirs
        // may be allowed here.
        $iterator = new \RecursiveIteratorIterator($dir);
        $iterator->setMaxDepth($maxDepth);

        /** @var \SplFileInfo $file */
        foreach ($iterator as $filepath => $file) {
            if ($file->isDir()) {
                if (!$this->hasMoreDirectories && $this->verifyFileOrDir($file, true, $directory)) {
                    // There are two filepaths for one dirpath: "." and "..".
                    $filepath = $file->getRealPath();
                    // Don't list empty directories.
                    if (!$this->dirHasNoFileAndIsRemovable($filepath)) {
                        // For security, don't display the full path to the user.
                        $relativePath = substr($filepath, $lengthDir);
                        // Use keys for quicker process on big directories.
                        if (!array_key_exists($relativePath, $listDirs)) {
                            $listDirs[$relativePath] = null;
                            if ($maxDirs && ++$countDirs >= $maxDirs) {
                                $this->hasMoreDirectories = true;
                                break;
                            }
                        }
                    }
                }
            }
        }

        $listDirs = array_keys($listDirs);
        natcasesort($listDirs);
        return $listDirs;
    }

    public function hasMoreDirectories(): bool
    {
        return $this->hasMoreDirectories;
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
     * @return string|null The real file path or null if the file is invalid.
     */
    public function verifyFileOrDir(\SplFileInfo $fileinfo, bool $isDir = false, ?string $baseDir = null): ?string
    {
        if (false === ($baseDir ?? $this->sideloadDirectory)) {
            return null;
        }
        $realPath = $fileinfo->getRealPath();
        if (false === $realPath) {
            return null;
        }
        if ($realPath === ($baseDir ?? $this->sideloadDirectory)) {
            return null;
        }
        if (0 !== strpos($realPath, ($baseDir ?? $this->sideloadDirectory))) {
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
}
