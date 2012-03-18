<?php

require_once __DIR__.'/../../vendor/phpcr/phpcr-api-tests/inc/AbstractLoader.php';

/**
 * Implementation loader for jackalope-doctrine-dbal
 */
class ImplementationLoader extends \PHPCR\Test\AbstractLoader
{
    private static $instance = null;

    protected function __construct()
    {
        parent::__construct('Jackalope\RepositoryFactoryDoctrineDBAL', $GLOBALS['phpcr.workspace']);

        $this->unsupportedChapters = array(
                    'PermissionsAndCapabilities',
                    'Import',
                    'Observation',
                    'ShareableNodes',
                    'Versioning',
                    'AccessControlManagement',
                    'Locking',
                    'LifecycleManagement',
                    'RetentionAndHold',
                    'SameNameSiblings',
                    'OrderableChildNodes',
        );

        $this->unsupportedCases = array(
                    'Writing\\MoveMethodsTest',
        );

        $this->unsupportedTests = array(
                    'Connecting\\RepositoryTest::testLoginException', //TODO: figure out what would be invalid credentials
                    'Connecting\\RepositoryTest::testNoLogin',

                    'Reading\\NodeReadMethodsTest::testGetSharedSetUnreferenced', // TODO: should this be moved to 14_ShareableNodes
                    'Reading\\SessionReadMethodsTest::testImpersonate', //TODO: Check if that's implemented in newer jackrabbit versions.
                    'Reading\\SessionNamespaceRemappingTest::testSetNamespacePrefix',
                    'Reading\\PropertyReadMethodsTest::testJcrCreated', // TODO: fails because NodeTypeDefinitions do not work inside DoctrineDBAL transport yet.

                    'Query\\QueryManagerTest::testGetQuery',
                    'Query\\QueryManagerTest::testGetQueryInvalid',
                    'Query\\QueryObjectSql2Test::testGetStoredQueryPath',
                    'Query\\QueryObjectSql2Test::testExecuteOffset',
                    'Query\\QuerySql2OperationsTest::testQueryJoin',
                    'Query\\QuerySql2OperationsTest::testQueryJoinReference',
                    // this seems a bug in php with arrayiterator - and jackalope is using
                    // arrayiterator for the search result
                    // https://github.com/phpcr/phpcr-api-tests/issues/22
                    'Query\\NodeViewTest::testSeekable',

                    'Writing\\NamespaceRegistryTest::testRegisterUnregisterNamespace',
                    'Writing\\AddMethodsTest::testAddNodeIllegalType',
                    'Writing\\AddMethodsTest::testAddNodeInParallel',
                    'Writing\\AddMethodsTest::testAddPropertyWrongType',
                    'Writing\\CopyMethodsTest::testCopyUpdateOnCopy',
                    'Writing\\CopyMethodsTest::testWorkspaceCopy',
                    'Writing\\MoveMethodsTest::testSessionDeleteMoved', // TODO: enable and look at the exception you get as starting point
                    'Writing\\MoveMethodsTest::testNodeOrderBeforeEnd',
                    'Writing\\MoveMethodsTest::testNodeOrderBeforeDown',
                    'Writing\\MoveMethodsTest::testNodeOrderBeforeUp',
                    'Writing\\DeleteMethodsTest::testRemoveNodeConstraintViolation',
                    'Writing\\DeleteMethodsTest::testNodeRemovePropertyConstraintViolation',
                    'Writing\\CombinedManipulationsTest::testRemoveAndMove',
                    'Writing\\CombinedManipulationsTest::testAddAndChildAddAndMove',
                    'Writing\\CombinedManipulationsTest::testMoveSessionRefreshKeepChanges',

                    'Transactions\\TransactionMethodsTest::testInTransaction',
                    'Transactions\\TransactionMethodsTest::testTransactionCommit',
                    'Transactions\\TransactionMethodsTest::testTransactionRollback',
        );

    }

    public static function getInstance()
    {
        if (null === self::$instance) {
            self::$instance = new ImplementationLoader();
        }
        return self::$instance;
    }

    public function getRepositoryFactoryParameters()
    {
        global $dbConn; // initialized in bootstrap_doctrine_dbal.php
        return array('jackalope.doctrine_dbal_connection' => $dbConn);
    }

    public function getCredentials()
    {
        return new \PHPCR\SimpleCredentials($GLOBALS['phpcr.user'], $GLOBALS['phpcr.pass']);
    }

    public function getInvalidCredentials()
    {
        return new \PHPCR\SimpleCredentials('nonexistinguser', '');
    }

    public function getRestrictedCredentials()
    {
        return new \PHPCR\SimpleCredentials('anonymous', 'abc');
    }

    public function getUserId()
    {
        return $GLOBALS['phpcr.user'];
    }

    function getRepository()
    {
        global $dbConn;

        $transport = new \Jackalope\Transport\DoctrineDBAL\Client(new \Jackalope\Factory, $dbConn);
        try {
            $transport->createWorkspace($GLOBALS['phpcr.workspace']);
        } catch (\PHPCR\RepositoryException $e) {
            if ($e->getMessage() != "Workspace '".$GLOBALS['phpcr.workspace']."' already exists") {
                // if the message is not that the workspace already exists, something went really wrong
                throw $e;
            }
        }
        return new \Jackalope\Repository(null, $transport);
    }

    function getFixtureLoader()
    {
        global $dbConn;
        require_once "DoctrineDBALFixtureLoader.php";
        return new DoctrineDBALFixtureLoader($dbConn->getWrappedConnection(), __DIR__ . "/../fixtures/doctrine/");
    }
}
