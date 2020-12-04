<?php declare(strict_types=1);

// doctrine/dbal version 2 used the lowercase form. On case sensitive filesystems, this leads to autoloading not working
// for PHP, class names are case insensitive.
class_exists('Doctrine\DBAL\Platforms\MySQLPlatform') || class_exists('Doctrine\DBAL\Platforms\MySqlPlatform');
