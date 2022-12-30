<?php

use Jackalope\Tools\Console\Helper\DoctrineDbalHelper;
use Symfony\Component\Console\Helper\DialogHelper;
use Symfony\Component\Console\Helper\QuestionHelper;
use Doctrine\Dbal\Connection;
use Doctrine\DBAL\DriverManager;
use Jackalope\RepositoryFactoryDoctrineDBAL;
use PHPCR\RepositoryInterface;
use PHPCR\SimpleCredentials;
use PHPCR\Util\Console\Helper\PhpcrConsoleDumperHelper;
use PHPCR\Util\Console\Helper\PhpcrHelper;
use Symfony\Component\Console\Helper\HelperSet;

/**
 * Bootstrapping the repository implementation for the stand-alone cli application.
 *
 * Copy this file to cli-config.php and adjust the configuration parts to your need.
 */

/*
 * Configuration
 */
$workspace  = 'default'; // phpcr workspace to use

// jackalope-doctrine-dbal does not verify credentials. $user is recorded as node creator.
// See the `getDoctrineDbalConnection` method for the DBAL database credentials.
$user = 'admin';

function getDoctrineDbalConnection(): Connection
{
    /*
     * Additional Doctrine DBAL configuration.
     *
     * For further details, please see Doctrine configuration page.
     * http://docs.doctrine-project.org/projects/doctrine-dbal/en/latest/reference/configuration.html#connection-details
     */
    $sqluser  = 'root';
    $sqlpass  = '';
    $driver   = 'pdo_sqlite';
    $host     = 'localhost';
    $database = 'jackalope';
    $path     = 'phpcr.sqlite';

    return DriverManager::getConnection([
        'driver'    => $driver,
        'host'      => $host,
        'user'      => $sqluser,
        'password'  => $sqlpass,
        'dbname'    => $database,
        'path'      => $path, // remove this line if not using sqlite
    ]);
}

function bootstrapDoctrineDbal(Connection $connection): RepositoryInterface
{
    return (new RepositoryFactoryDoctrineDBAL())
        ->getRepository([
            'jackalope.doctrine_dbal_connection' => $connection,
        ]);
}

/* Only create a session if this is neither the help nor the initialization command. */
if (!array_key_exists(1, $argv[1])) {
    return;
}
if(!in_array($argv[1], ['jackalope:init:dbal', 'list', 'help'], true)) {
    $repository = bootstrapDoctrineDbal(getDoctrineDbalConnection());
    $credentials = new SimpleCredentials($user, null); // password is not used by jackalope-doctrine-dbal
    $session = $repository->login($credentials, $workspace);

    $helperSet = new HelperSet([
        'phpcr' => new PhpcrHelper($session),
        'phpcr_console_dumper' => new PhpcrConsoleDumperHelper(),
    ]);
    if (class_exists(QuestionHelper::class)) {
        $helperSet->set(new QuestionHelper(), 'question');
    } else {
        // legacy support for old Symfony versions
        $helperSet->set(new DialogHelper(), 'dialog');
    }
} elseif ('jackalope:init:dbal' === $argv[1]) {
    $dbConn = getDoctrineDbalConnection();
    // special case: the init command needs the db connection, but a session is impossible if the db is not yet initialized
    $helperSet = new HelperSet([
        'connection' => new DoctrineDbalHelper($dbConn)
    ]);
}
