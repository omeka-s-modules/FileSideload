# FileSideload

This module provides functionality to "sideload" files (ingesting files that are already on the server). It will allow users to batch-add many files at once to their repository, rather than uploading the files individually. It also will enable users to circumvent server file size restrictions that limit the capacity of web-form upload.

See the [Omeka S user manual](http://omeka.org/s/docs/user-manual/modules/filesideload/) for user documentation.

## Installation

See general end user documentation for [Installing a module](http://omeka.org/s/docs/user-manual/modules/#installing-modules)

## Usage

The copy of a local file can be done in three modes:
- copy: the file is fully copied from the source directory to the Omeka one.
- hard link: the file is hard-linked, and fails if the server does not support it.
- hard link or copy: the file is hard-linked, and if it fails, it is fully copied.

Hard-linking is a safe, instant, and space efficient process: the server adds
simply a new path to the file in the server. Unlike a symbolic link, the new
path is fully registered in the file system and there is no way to distinguish
the first and the second file. The main advantage is that it the copy is done
instantly and without consuming disk space on the server.

Nevertheless, it supposes a specific configuration on the server. In particular,
the file system of the source and the destination should be the same. That is to
say that the files should be generally on the same disk (except when the file
system is virtualized). Furthermore, for validation and security check purposes,
the Omeka temp directory should be on the same file system too.

So to use the hard-linking feature, you have to modify the key `temp_dir` in the
file "config/local.config.php" of your Omeka installation in order to use a
directory on the same file system too. For example, create a directory
`/files/temp` beside `/files/original` and add this:

```php
    'temp_dir' => OMEKA_PATH . '/files/temp',
```

You have to change the file store key `[service_manager][aliases][Omeka\File\Store]`
too:

```php
    'service_manager' => [
        'aliases' => [
            'Omeka\File\Store' => 'FileSideload\File\Store\LocalHardLink',
```

## TODO

- Avoid the intermediate temp directory, but keep the standard Omeka process
  used to validate files.
