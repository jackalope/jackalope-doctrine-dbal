<?php

/** Make sure we get ALL infos from php */
error_reporting(E_ALL | E_STRICT);

if (!$loader = @include __DIR__.'/../vendor/autoload.php') {
    die('You must set up the project dependencies, run the following commands:' . PHP_EOL .
        'curl -s http://getcomposer.org/installer | php' . PHP_EOL .
        'php composer.phar install' . PHP_EOL .
        'php composer.phar install --dev' . PHP_EOL);
}

/** Make Jackalope base tests autoloadable */
$loader->add('Jackalope', __DIR__ . '/../vendor/jackalope/jackalope/tests');

/** Load the implementation loader class */
require_once __DIR__ . '/ImplementationLoader.php';
require_once __DIR__ . '/generate_fixtures.php';

/** generate fixtures */
generate_fixtures(
    __DIR__ . '/../vendor/phpcr/phpcr-api-tests/fixtures',
    __DIR__ . '/fixtures/doctrine'
);

/**
 * set up the backend connection
 * For further details, please see Doctrine configuration page.
 * http://docs.doctrine-project.org/projects/doctrine-dbal/en/latest/reference/configuration.html#connection-details
 */
$dbConn = \Doctrine\DBAL\DriverManager::getConnection(array(
    'driver'    => @$GLOBALS['phpcr.doctrine.dbal.driver'],
    'path'      => @$GLOBALS['phpcr.doctrine.dbal.path'],
    'host'      => @$GLOBALS['phpcr.doctrine.dbal.host'],
    'port'      => @$GLOBALS['phpcr.doctrine.dbal.port'],
    'user'      => @$GLOBALS['phpcr.doctrine.dbal.username'],
    'password'  => @$GLOBALS['phpcr.doctrine.dbal.password'],
    'dbname'    => @$GLOBALS['phpcr.doctrine.dbal.dbname']
));

/** Recreate database schema */
if (!getenv('JACKALOPE_NO_TEST_DB_INIT')) {
    $options = array('disable_fks' => $dbConn->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\SqlitePlatform);
    $repositorySchema = new \Jackalope\Transport\DoctrineDBAL\RepositorySchema($options, $dbConn);
    $repositorySchema->reset();
}

/**
 * constants for the repository descriptor test for JCR 1.0/JSR-170 and JSR-283 specs
 */

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
