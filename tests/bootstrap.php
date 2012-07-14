<?php

if (!$loader = @include __DIR__.'/../vendor/autoload.php') {
    die('You must set up the project dependencies, run the following commands:'.PHP_EOL.
        'curl -s http://getcomposer.org/installer | php'.PHP_EOL.
        'php composer.phar install'.PHP_EOL);
}

$loader->add('Jackalope', __DIR__.'/../vendor/jackalope/jackalope/tests');

/** make sure we get ALL infos from php */
error_reporting(E_ALL | E_STRICT);

### Load classes needed for jackalope unit tests ###
require 'Jackalope/Transport/DoctrineDBAL/DoctrineDBALTestCase.php';

### Load the implementation loader class ###
require 'inc/DoctrineDBALImplementationLoader.php';

/**
 * set up the backend connection
 */
$dbConn = \Doctrine\DBAL\DriverManager::getConnection(array(
    'driver'    => @$GLOBALS['phpcr.doctrine.dbal.driver'],
    'path'      => @$GLOBALS['phpcr.doctrine.dbal.path'],
    'host'      => @$GLOBALS['phpcr.doctrine.dbal.host'],
    'user'      => @$GLOBALS['phpcr.doctrine.dbal.username'],
    'password'  => @$GLOBALS['phpcr.doctrine.dbal.password'],
    'dbname'    => @$GLOBALS['phpcr.doctrine.dbal.dbname']
));

// TODO: refactor this into the command (a --reset option) and use the command instead
echo "Updating schema...";
$schema = \Jackalope\Transport\DoctrineDBAL\RepositorySchema::create();
foreach ($schema->toDropSql($dbConn->getDatabasePlatform()) as $sql) {
    try {
        $dbConn->exec($sql);
    } catch(PDOException $e) {
    }
}
foreach ($schema->toSql($dbConn->getDatabasePlatform()) as $sql) {
    try {
        $dbConn->exec($sql);
    } catch(PDOException $e) {
        echo $e->getMessage();
    }
}

echo "done.\n";

/** some constants */

define('SPEC_VERSION_DESC', 'jcr.specification.version');
define('SPEC_NAME_DESC', 'jcr.specification.name');
define('REP_VENDOR_DESC', 'jcr.repository.vendor');
define('REP_VENDOR_URL_DESC', 'jcr.repository.vendor.url');
define('REP_NAME_DESC', 'jcr.repository.name');
define('REP_VERSION_DESC', 'jcr.repository.version');
define('OPTION_TRANSACTIONS_SUPPORTED', 'option.transactions.supported');
define('OPTION_VERSIONING_SUPPORTED', 'option.versioning.supported');
define('OPTION_OBSERVATION_SUPPORTED', 'option.observation.supported');
define('OPTION_LOCKING_SUPPORTED', 'option.locking.supported');
