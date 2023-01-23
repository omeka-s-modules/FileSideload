<?php
namespace FileSideload;

use FileSideload\Form\ConfigForm;
use Laminas\EventManager\Event;
use Laminas\EventManager\SharedEventManagerInterface;
use Laminas\Mvc\Controller\AbstractController;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Representation\UserRepresentation;
use Omeka\Module\AbstractModule;
use Omeka\Stdlib\Message;

class Module extends AbstractModule
{
    protected $directory;

    protected $deleteFile;

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
        $settings->delete('file_sideload_max_directories');
        $settings->delete('file_sideload_directory_depth_user');

        /** @var \Doctrine\DBAL\Connection $connection */
        $connection = $serviceLocator->get('Omeka\Connection');
        $connection->executeStatement('DELETE FROM `user_setting` WHERE `id` LIKE "filesideload#_%" ESCAPE "#";');
    }

    public function attachListeners(SharedEventManagerInterface $sharedEventManager): void
    {
        $sharedEventManager->attach(
            \Omeka\Api\Adapter\ItemAdapter::class,
            'api.hydrate.pre',
            [$this, 'handleItemApiHydratePre']
        );

        // Add the user directory setting to the user form.
        $sharedEventManager->attach(
            \Omeka\Form\UserForm::class,
            'form.add_elements',
            [$this, 'addUserFormElement']
        );
        $sharedEventManager->attach(
            \Omeka\Form\UserForm::class,
            'form.add_input_filters',
            [$this, 'addUserFormElementFilter']
        );

        // Display the user directory in the user show admin pages.
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\User',
            'view.details',
            [$this, 'viewUserDetails']
        );
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\User',
            'view.show.after',
            [$this, 'viewUserShowAfter']
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
            'filesideload_max_directories' => $settings->get('file_sideload_max_directories', 1000),
            'filesideload_directory_depth_user' => $settings->get('file_sideload_directory_depth_user', 2),
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
        $settings->set('file_sideload_max_directories', (int) $formData['filesideload_max_directories']);
        $settings->set('file_sideload_directory_depth_user', (int) $formData['filesideload_directory_depth_user']);
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

            if (!array_key_exists('ingest_directory', $dataMedia)) {
                $errorStore->addError('ingest_directory', 'No ingest directory specified.'); // @translate
                continue;
            }

            $ingestDirectory = (string) $dataMedia['ingest_directory'];

            // Some quick security checks are done here instead of ingester
            // to simplify conversion into multiple media.

            if (!strlen($ingestDirectory)) {
                $errorStore->addError('ingest_directory', 'No ingest directory specified.'); // @translate
                continue;
            }

            if ($ingestDirectory === '.' || $ingestDirectory === '..' || $ingestDirectory === '/') {
                $errorStore->addError('ingest_directory', 'Illegal ingest directory specified.'); // @translate
                continue;
            }

            $isAbsolutePathInsideDir = $this->directory && strpos($ingestDirectory, $this->directory) === 0;
            $directory = $isAbsolutePathInsideDir
                ? $ingestDirectory
                : $this->directory . DIRECTORY_SEPARATOR . $ingestDirectory;
            $fileinfo = new \SplFileInfo($directory);
            $directory = $this->verifyFileOrDir($fileinfo, true);

            if (is_null($directory)) {
                // Set a clearer message in some cases.
                if ($this->deleteFile && !$fileinfo->getPathInfo()->isWritable()) {
                    $errorStore->addError('ingest_directory', new Message(
                        'Ingest directory "%s" is not writeable but the config requires deletion after upload.', // @translate
                        $ingestDirectory
                    ));
                } elseif (!$fileinfo->isDir()) {
                    $errorStore->addError('ingest_directory', new Message(
                        'Invalid ingest directory "%s" specified: not a directory', // @translate
                        $ingestDirectory
                    ));
                } else {
                    $errorStore->addError('ingest_directory', new Message(
                        'Invalid ingest directory "%s" specified: incorrect path or insufficient permissions', // @translate
                        $ingestDirectory
                    ));
                }
                continue;
            }

            $listFiles = $this->listFiles($directory, !empty($dataMedia['ingest_directory_recursively']));
            if (!count($listFiles)) {
                $errorStore->addError('ingest_directory', new Message(
                    'Ingest directory "%s" is empty.',  // @translate
                    $ingestDirectory
                ));
                continue;
            }

            // Convert the media to a list of media for the item hydration.
            // Remove the added media directory from list of media.
            array_pop($newDataMedias);
            foreach ($listFiles as $filepath) {
                $dataMedia['ingest_filename'] = $filepath;
                $newDataMedias[] = $dataMedia;
            }
        }
        $data['o:media'] = $newDataMedias;
        $request->setContent($data);
    }

    public function addUserFormElement(Event $event): void
    {
        /** @var \Omeka\Form\UserForm $form */
        $form = $event->getTarget();
        if ($form->getOption('is_public')) {
            return;
        }

        /**
         * @var \Omeka\Entity\User $user
         */
        $services = $this->getServiceLocator();
        $settings = $services->get('Omeka\Settings');
        $entityManager = $services->get('Omeka\EntityManager');

        // The user is not the current user, but the user in the form.
        // It may be empty for a new user.
        $userId = $services->get('Application')->getMvcEvent()->getRouteMatch()->getParam('id');
        $user = $userId ? $entityManager->find(\Omeka\Entity\User::class, $userId) : null;

        $mainDirectory = $settings->get('file_sideload_directory', '');
        $this->directory = $mainDirectory;
        $this->deleteFile = $settings->get('file_sideload_delete_file') === 'yes';

        // Manage a direct creation (no id).
        if ($user) {
            /** @var \Omeka\Settings\UserSettings $userSettings */
            $userSettings = $services->get('Omeka\Settings\User');
            $userSettings->setTargetId($userId);
            $userDir = $userSettings->get('filesideload_user_dir', '');
        } else {
            $userDir = '';
        }

        // Default 2 levels because it's the most common case: one level for the
        // user or institution, and when the first is the institution, a second
        // for the collections or the users. Maybe 3, but greater depths may
        // cause issues with big directories.
        // This option only applies to the user interface anyway.
        $maxDepth = (int) $settings->get('file_sideload_directory_depth_user', 2);
        $maxDirs = (int) $settings->get('file_sideload_max_directories');
        $directories = $mainDirectory ? $this->listDirs($mainDirectory, $maxDepth, $maxDirs) : [];

        $fieldset = $form->get('user-settings');
        $fieldset
            ->add([
                'name' => 'filesideload_user_dir',
                'type' => \Laminas\Form\Element\Select::class,
                'options' => [
                    'label' => 'User directory', // @translate
                    'empty_option' => '',
                    'value_options' => $directories,
                ],
                'attributes' => [
                    'id' => 'filesideload_user_dir',
                    'value' => $userDir,
                    'required' => false,
                    'disabled' => empty($mainDirectory),
                    'class' => 'chosen-select',
                    'data-placeholder' => count($directories)
                        ? 'Select a sub-directory…' // @translate
                        : 'No sub-directory in main directory', // @translate
                ],
            ]);
    }

    public function addUserFormElementFilter(Event $event): void
    {
        $form = $event->getTarget();
        $inputFilter = $form->getInputFilter();
        $inputFilter->add([
            'name' => 'filesideload_user_dir',
            'required' => false,
            'filters' => [
                ['name' => 'StringTrim'],
            ],
            'validators' => [
                [
                    'name' => 'Callback',
                    'options' => [
                        'messages' => [
                            \Laminas\Validator\Callback::INVALID_VALUE => 'The provided sideload directory is not a directory or does not have sufficient permissions.', // @translate
                        ],
                        'callback' => [$this, 'userDirectoryIsValid'],
                    ],
                ],
            ],
        ]);
    }

    public function userDirectoryIsValid($dir, $context)
    {
        // The user directory is not required.
        if ($dir === '') {
            return true;
        }
        // For security, even if checked via haystack.
        if (mb_strpos($dir, '..') !== false) {
            return false;
        }
        // The user directory is stored as a sub-path of the main directory.
        $mainDirectory = $this->getServiceLocator()->get('Omeka\Settings')->get('file_sideload_directory');
        if (!$mainDirectory || !realpath($mainDirectory)) {
            return false;
        }
        $userDirectory = $mainDirectory . DIRECTORY_SEPARATOR . $dir;
        $dir = new \SplFileInfo($userDirectory);
        $valid = $dir->isDir() && $dir->isExecutable() && $dir->isReadable();
        if (isset($context['delete_file']) && 'yes' === $context['delete_file']) {
            $valid = $valid && $dir->isWritable();
        }
        return $valid;
    }

    public function viewUserDetails(Event $event): void
    {
        $view = $event->getTarget();
        $user = $view->resource;
        $this->viewUserData($view, $user);
    }

    public function viewUserShowAfter(Event $event): void
    {
        $view = $event->getTarget();
        $user = $view->vars()->user;
        $this->viewUserData($view, $user);
    }

    protected function viewUserData(PhpRenderer $view, UserRepresentation $user): void
    {
        $services = $this->getServiceLocator();
        $userSettings = $services->get('Omeka\Settings\User');
        $userSettings->setTargetId($user->id());

        $label = $view->translate('Server directory'); // @translate
        $userDirectory = $userSettings->get('filesideload_user_dir', '');
        $userDir = strlen($userDirectory) ? $userDirectory : $view->translate('[root]'); // @translate

        $html = <<<'HTML'
<div class="property">
    <h4>%1$s</h4>
    <div class="value">
        %2$s
    </div>
</div>

HTML;
        echo sprintf($html, $label, $userDir);
    }

    /**
     * Get all files available to sideload from a directory inside the main dir.
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
            $iterator = new \DirectoryIterator($directory);
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

        // Don't mix directories and files. List root files, then sub-directories.
        $listFiles = array_keys($listFiles);
        natcasesort($listFiles);
        $listRootFiles = array_keys($listRootFiles);
        natcasesort($listRootFiles);
        return array_values(array_unique(array_merge($listRootFiles, $listFiles)));
    }

    /**
     * Get all directories available to sideload.
     */
    protected function listDirs(string $directory, int $maxDepth = -1, int $maxDirectories = 0): array
    {
        $listDirs = [];

        $dir = new \SplFileInfo($directory);
        if (!$dir->isDir()) {
            return [];
        }

        $countDirs = 0;
        $this->directory = $directory;

        $lengthDir = strlen($directory) + 1;
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
                if ($this->verifyFileOrDir($file, true)) {
                    // There are two filepaths for one dirpath: "." and "..".
                    $filepath = $file->getRealPath();
                    // Don't list empty directories.
                    if (!$this->dirHasNoFileAndIsRemovable($filepath)) {
                        // For security, don't display the full path to the user.
                        $relativePath = substr($filepath, $lengthDir);
                        if (!isset($listDirs[$relativePath])) {
                            // Use keys for quicker process on big directories.
                            $listDirs[$relativePath] = null;
                            if ($maxDirectories && ++$countDirs >= $maxDirectories) {
                                break;
                            }
                        }
                    }
                }
            }
        }

        $listDirs = array_keys($listDirs);
        natcasesort($listDirs);
        return array_combine($listDirs, $listDirs);
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
}
