<?php
namespace FileSideload\File\Store;

use Omeka\File\Exception;

class LocalHardLink extends \Omeka\File\Store\Local
{
    /**
     * Override the default local store in order to hard-link files if possible,
     * else copy them as usual.
     *
     * {@inheritDoc}
     * @see \Omeka\File\Store\Local::put()
     */
    public function put($source, $storagePath)
    {
        $localPath = $this->getLocalPath($storagePath);
        $this->assurePathDirectories($localPath);
        // Unlike copy, the function link() does not override temp file created
        // by the temp file factory, so the file should be unlinked first.
        @unlink($localPath);
        $status = @link($source, $localPath);
        if (!$status) {
            $status = copy($source, $localPath);
            if (!$status) {
                throw new Exception\RuntimeException(
                    sprintf('Failed to copy "%s" to "%s".', $source, $localPath)
                );
            }
        }
    }
}
