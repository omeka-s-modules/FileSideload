<?php declare(strict_types=1);

namespace FileSideload\FileSideload;

class FileSystem
{
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
}
