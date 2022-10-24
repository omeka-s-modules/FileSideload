# FileSideload


This module provides functionality to "sideload" files (ingesting files that are already on the server). It will allow users to batch-add many files at once to their repository, rather than uploading the files individually. It also will enable users to circumvent server file size restrictions that limit the capacity of web-form upload.
Files can be selected individually or as a set at the directory level, for example all images of a scanned manuscript.

See the [Omeka S user manual](http://omeka.org/s/docs/user-manual/modules/filesideload/) for user documentation.

## Installation

See general end user documentation for [Installing a module](http://omeka.org/s/docs/user-manual/modules/#installing-modules)

## Copyright

FileSideload is Copyright © 2016-present Corporation for Digital Scholarship, Vienna, Virginia, USA http://digitalscholar.org

The Corporation for Digital Scholarship distributes the Omeka source code
under the GNU General Public License, version 3 (GPLv3). The full text
of this license is given in the license file.

The Omeka name is a registered trademark of the Corporation for Digital Scholarship.

Third-party copyright in this distribution is noted where applicable.

All rights not expressly granted are reserved.

## Usage

The copy of a local file can be done in three modes:
- copy: the file is fully copied from the source directory to the Omeka one.
- hard link: the file is hard-linked, and fails if the server does not support it.
- hard link or copy: the file is hard-linked, and if it fails, it is fully copied.

Hard-linking is a safe, instant, and space efficient process: the server adds
simply a new path to the file in the server. Unlike a symbolic link, the new
path is fully registered in the file system and there is no way to distinguish
the first and the second file. The main advantage is that the copy is done
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

## Mount NFS

For nfs, the id may need to be mapped between remote server and client. So check:

1. on remote server, the file `/etc/exports` should reference the client uid of
  the web user, for example with `1003`:

  ```
/remote/directory/files 98.76.54.32(rw,sync,all_squash,no_subtree_check,anonuid=1003,anongid=1003)
```

2. enable the id mapping on the remote:

```sh
echo N > /sys/module/nfsd/parameters/nfs4_disable_idmapping
```

3. Update the config and restart the nfs server:

```sh
exportfs -arv
nfsidmap -c
systemctl restart nfs-idmapd
```

4. On the client, update the local mapping in file /etc/idmapd.conf:

```ini
[Mapping]

Nobody-User = omeka
Nobody-Group = omeka
```

5. Update the file `/etc/fstab` according to your config.

```fstab
123.45.67.89:/remote/directory/files    /var/www/omeka/files            nfs4    rw,noexec,nosuid,rsize=524288,wsize=524288,hard,intr,_netdev 0 0
```

For complex mount, you may try to bind directories. For example in `/etc/fstab`:

```fstab
# Remote directory for original, temp and sideload.
123.45.67.89:/remote/directory/files    /var/www/omeka/files            nfs4    rw,noexec,nosuid,rsize=524288,wsize=524288,hard,intr,_netdev 0 0

# Local directories for other directories.
/var/www/omeka/files_local/asset        /var/www/omeka/files/asset      none    defaults,bind,_netdev 0 0
/var/www/omeka/files_local/large        /var/www/omeka/files/large      none    defaults,bind,_netdev 0 0
/var/www/omeka/files_local/medium       /var/www/omeka/files/medium     none    defaults,bind,_netdev 0 0
/var/www/omeka/files_local/square       /var/www/omeka/files/square     none    defaults,bind,_netdev 0 0
/var/www/omeka/files_local/tile         /var/www/omeka/files/tile       none    defaults,bind,_netdev 0 0
```

or, in some cases, the inverse (mount remote directory somewhere, then bind them
in /files:

```fstab
# Remote directory for original, temp and sideload.
123.45.67.89:/remote/directory/files    /var/www/omeka/files_remote     nfs4    rw,noexec,nosuid,rsize=524288,wsize=524288,hard,intr,_netdev 0 0
/var/www/omeka/files_remote/original    /var/www/omeka/files/original   none    defaults,bind,_netdev 0 0
/var/www/omeka/files_remote/sideload    /var/www/omeka/files/sideload   none    defaults,bind,_netdev 0 0
/var/www/omeka/files_remote/temp        /var/www/omeka/files/temp       none    defaults,bind,_netdev 0 0
```

You may have to copy the file `/files/index.html` too.

6. Unmount and remount the nfs.

```sh
mount -a
```

## TODO

- [ ] Avoid the intermediate temp directory, but keep the standard Omeka process used to validate files.
