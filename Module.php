<?php
namespace FileSideload;

use FileSideload\Form\ConfigForm;
use Laminas\EventManager\Event;
use Laminas\EventManager\SharedEventManagerInterface;
use Laminas\Mvc\Controller\AbstractController;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Module\AbstractModule;
use Omeka\Stdlib\Message;

class Module extends AbstractModule
{
    public function getConfig()
    {
        return include __DIR__ . '/config/module.config.php';
    }

    public function uninstall(ServiceLocatorInterface $serviceLocator)
    {
        $settings = $serviceLocator->get('Omeka\Settings');
        $settings->delete('file_sideload_directory');
        $settings->delete('file_sideload_delete_file');
        $settings->delete('file_sideload_max_files');
    }

    public function attachListeners(SharedEventManagerInterface $sharedEventManager): void
    {
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\ItemAdapter::class,
            'api.hydrate.pre',
            [$this, 'handleItemApiHydratePre']
        );
    }

    public function getConfigForm(PhpRenderer $renderer)
    {
        $settings = $this->getServiceLocator()->get('Omeka\Settings');
        $form = new ConfigForm;
        $form->init();
        $form->setData([
            'directory' => $settings->get('file_sideload_directory'),
            'delete_file' => $settings->get('file_sideload_delete_file', 'no'),
            'filesideload_max_files' => $settings->get('file_sideload_max_files', 1000),
        ]);
        return $renderer->formCollection($form, false);
    }

    public function handleConfigForm(AbstractController $controller)
    {
        $settings = $this->getServiceLocator()->get('Omeka\Settings');
        $form = new ConfigForm;
        $form->init();
        $form->setData($controller->params()->fromPost());
        if (!$form->isValid()) {
            $controller->messenger()->addErrors($form->getMessages());
            return false;
        }
        $formData = $form->getData();
        $settings->set('file_sideload_directory', $formData['directory']);
        $settings->set('file_sideload_delete_file', $formData['delete_file']);
        $settings->set('file_sideload_max_files', (int) $formData['filesideload_max_files']);
        return true;
    }

    public function handleItemApiHydratePre(Event $event)
    {
        static $isChecked;

        /** @var \Omeka\Api\Request $request */
        $request = $event->getParam('request');
        $data = $request->getContent();
        if (empty($data['o:media'])) {
            return;
        }

        if (is_null($isChecked)) {
            $isChecked = false;
            $settings = $this->getServiceLocator()->get('Omeka\Settings');
            $mainDir = (string) $settings->get('file_sideload_directory', '');
            if (!strlen($mainDir)) {
                return;
            }

            $mainDir = realpath($mainDir);
            if ($mainDir === false) {
                return;
            }

            $dir = new \SplFileInfo($mainDir);
            if (!$dir->isDir() || !$dir->isReadable() || !$dir->isExecutable()) {
                return;
            }

            $this->directory = $mainDir;
            $this->deleteFile = $settings->get('file_sideload_delete_file') === 'yes';

            $isChecked = true;
        }

        if (!$isChecked) {
            return;
        }

        $errorStore = $event->getParam('errorStore');

        $newDataMedias = [];
        foreach ($data['o:media'] as $dataMedia) {
            $newDataMedias[] = $dataMedia;

            if (empty($dataMedia['o:ingester']) || $dataMedia['o:ingester'] !== 'sideload_dir') {
                continue;
            }

            if (!array_key_exists('ingest_folder', $dataMedia)) {
                $errorStore->addError('ingest_folder', 'No ingest folder specified.'); // @translate
                continue;
            }

            $ingestFolder = (string) $dataMedia['ingest_folder'];

            // Some quick security checks are done here instead of ingester
            // to simplify conversion into multiple media.

            if (!strlen($ingestFolder)) {
                $errorStore->addError('ingest_folder', 'No ingest folder specified.'); // @translate
                continue;
            }

            if ($ingestFolder === '.' || $ingestFolder === '..' || $ingestFolder === '/') {
                $errorStore->addError('ingest_folder', 'Illegal ingest folder specified.'); // @translate
                continue;
            }

            $isAbsolutePathInsideDir = $this->directory && strpos($ingestFolder, $this->directory) === 0;
            $folder = $isAbsolutePathInsideDir
                ? $ingestFolder
                : $this->directory . DIRECTORY_SEPARATOR . $ingestFolder;
            $fileinfo = new \SplFileInfo($folder);
            $folder = $this->verifyFileOrDir($fileinfo, true);

            if (is_null($folder)) {
                // Set a clearer message in some cases.
                if ($this->deleteFile && !$fileinfo->getPathInfo()->isWritable()) {
                    $errorStore->addError('ingest_folder', new Message(
                        'Ingest folder "%s" is not writeable but the config requires deletion after upload.', // @translate
                        $ingestFolder
                    ));
                } elseif (!$fileinfo->isDir()) {
                    $errorStore->addError('ingest_folder', new Message(
                        'Invalid ingest folder "%s" specified: not a directory', // @translate
                        $ingestFolder
                    ));
                } else {
                    $errorStore->addError('ingest_folder', new Message(
                        'Invalid ingest folder "%s" specified: incorrect path or insufficient permissions', // @translate
                        $ingestFolder
                    ));
                }
                continue;
            }

            $listFiles = $this->listFiles($folder, !empty($dataMedia['ingest_folder_recursively']));
            if (!count($listFiles)) {
                $errorStore->addError('ingest_folder', new Message(
                    'Ingest folder "%s" is empty.',  // @translate
                    $ingestFolder
                ));
                continue;
            }

            // Convert the media to a list of media for the item hydration.
            // Remove the added media folder from list of media.
            array_pop($newDataMedias);
            foreach ($listFiles as $filepath) {
                $dataMedia['ingest_filename'] = $filepath;
                $newDataMedias[] = $dataMedia;
            }
        }
        $data['o:media'] = $newDataMedias;
        $request->setContent($data);
    }

    /**
     * Get all files available to sideload from a folder inside the main dir.
     *
     * @return array List of filepaths relative to the main directory.
     */
    protected function listFiles(string $directory, bool $recursive = false): array
    {
        $dir = new \SplFileInfo($directory);
        if (!$dir->isDir() || !$dir->isReadable() || !$dir->isExecutable()) {
            return [];
        }

        // Check if the dir is inside main directory: don't import root files.
        $directory = $this->verifyFileOrDir($dir, true);
        if (is_null($directory)) {
            return [];
        }

        $listFiles = [];

        // To simplify sort.
        $listRootFiles = [];

        $lengthDir = strlen($this->directory) + 1;
        if ($recursive) {
            $dir = new \RecursiveDirectoryIterator($directory);
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
                if ($this->verifyFileOrDir($file)) {
                    // For security, don't display the full path to the user.
                    $relativePath = substr($filepath, $lengthDir);
                    // Use keys for quicker process on big directories.
                    $listFiles[$relativePath] = null;
                    if (pathinfo($filepath, PATHINFO_DIRNAME) === $directory) {
                        $listRootFiles[$relativePath] = null;
                    }
                }
            }
        } else {
            $iterator = new \DirectoryIterator($dir);
            /** @var \DirectoryIterator $file */
            foreach ($iterator as $file) {
                $filepath = $this->verifyFileOrDir($file);
                if (!is_null($filepath)) {
                    // For security, don't display the full path to the user.
                    $relativePath = substr($filepath, $lengthDir);
                    // Use keys for quicker process on big directories.
                    $listFiles[$relativePath] = null;
                }
            }
        }

        // Don't mix directories and files. List root files, then sub-folders.
        $listFiles = array_keys($listFiles);
        natcasesort($listFiles);
        $listRootFiles = array_keys($listRootFiles);
        natcasesort($listRootFiles);
        return array_values(array_unique(array_merge($listRootFiles, $listFiles)));
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
     *
     * @todo Factorize with \FileSideload\Media\Ingester\SideloadDir::verifyFileOrDir()
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
}
