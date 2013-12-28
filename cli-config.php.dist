<?php

/* bootstrapping the repository implementation */

/* doctrine dbal configuration
 * For further details, please see Doctrine configuration page.
 * http://docs.doctrine-project.org/projects/doctrine-dbal/en/latest/reference/configuration.html#connection-details
 */
$user     = 'root';
$pass     = '';
$driver   = 'pdo_sqlite';
$host     = 'localhost';
$database = 'jackalope';
$path     = 'phpcr.sqlite';

// Bootstrap Doctrine
$dbConn = \Doctrine\DBAL\DriverManager::getConnection(array(
    'driver'    => $driver,
    'host'      => $host,
    'user'      => $user,
    'password'  => $pass,
    'dbname'    => $database,
    'path'      => $path,
));

/*
 * configuration
 */
$workspace  = 'default'; // phpcr workspace to use
$user       = 'admin';
$pass       = 'admin';

$factory = new \Jackalope\RepositoryFactoryDoctrineDBAL();
$repository = $factory->getRepository(array('jackalope.doctrine_dbal_connection' => $dbConn));

$credentials = new \PHPCR\SimpleCredentials($user, $pass);

/* only create a session if this is not about the server control command */
if (isset($argv[1])
    && $argv[1] != 'jackalope:init:dbal'
    && $argv[1] != 'list'
    && $argv[1] != 'help'
) {
    $session = $repository->login($credentials, $workspace);

    $helperSet = new \Symfony\Component\Console\Helper\HelperSet(array(
        'dialog' => new \Symfony\Component\Console\Helper\DialogHelper(),
        'phpcr' => new \PHPCR\Util\Console\Helper\PhpcrHelper($session),
        'phpcr_console_dumper' => new \PHPCR\Util\Console\Helper\PhpcrConsoleDumperHelper(),
    ));
} else if (isset($argv[1]) && $argv[1] == 'jackalope:init:dbal') {
    // special case: the init command needs the db connection, but a session is impossible if the db is not yet initialized
    $helperSet = new \Symfony\Component\Console\Helper\HelperSet(array(
        'connection' => new \Jackalope\Tools\Console\Helper\DoctrineDbalHelper($dbConn)
    ));
}
