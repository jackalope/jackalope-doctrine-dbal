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
                    'ShareableNodes', //TODO: Not implemented, no test currently written for it
                    'AccessControlManagement', //TODO: Not implemented, no test currently written for it
                    'LifecycleManagement', //TODO: Not implemented, no test currently written for it
                    'RetentionAndHold', //TODO: Not implemented, no test currently written for it
                    'SameNameSiblings', //TODO: Not implemented, no test currently written for it
                    'PermissionsAndCapabilities', //TODO: Transport does not support permissions
                    'Observation', //TODO: Transport does not support observation
                    'Versioning', //TODO: Transport does not support versioning
                    'Locking', //TODO: Transport does not support locking
        );

        $this->unsupportedCases = array(
                    'Query\\XPath', //TODO: Query language 'xpath' not yet implemented.
                    'Query\\Sql1' //TODO: Query language 'sql' not yet implemented
        );

        $this->unsupportedTests = array(
                    'Connecting\\RepositoryTest::testLoginException', //TODO: figure out what would be invalid credentials

                    'Reading\\NodeReadMethodsTest::testGetSharedSetUnreferenced', // TODO: should this be moved to 14_ShareableNodes
                    'Reading\\SessionReadMethodsTest::testImpersonate', //TODO: Check if that's implemented in newer jackrabbit versions.
                    'Reading\\SessionNamespaceRemappingTest::testSetNamespacePrefix', //TODO: implement session scope remapping of namespaces
                    'Reading\\PropertyReadMethodsTest::testJcrCreated', // TODO: https://github.com/jackalope/jackalope-doctrine-dbal/issues/34

                    //TODO: implement getQuery method in Jackalope QueryManager
                    'Query\\QueryManagerTest::testGetQuery',
                    'Query\\QueryManagerTest::testGetQueryInvalid',
                    'Query\\QueryObjectSql2Test::testGetStoredQueryPath',

                    //TODO: https://github.com/jackalope/jackalope-doctrine-dbal/issues/15
                    'Query\\QuerySql2OperationsTest::testQueryJoin',
                    'Query\\QuerySql2OperationsTest::testQueryJoinReference',

                    // this seems a bug in php with arrayiterator - and jackalope is using
                    // arrayiterator for the search result
                    // https://github.com/phpcr/phpcr-api-tests/issues/22
                    'Query\\NodeViewTest::testSeekable',

                    'Writing\\CopyMethodsTest::testCopyUpdateOnCopy', //TODO: update-on-copy is currently not supported
                    'Writing\\CopyMethodsTest::testWorkspaceCopy', //TODO: https://github.com/jackalope/jackalope-doctrine-dbal/issues/19
                    'Writing\\DeleteMethodsTest::testNodeRemovePropertyConstraintViolation', //TODO: https://github.com/jackalope/jackalope-doctrine-dbal/issues/34

                    // TODO: enable and look at the exception you get as starting point
                    'Writing\\MoveMethodsTest::testSessionDeleteMoved',
                    'Writing\\MoveMethodsTest::testSessionMoveReplace',
                    'Writing\\CombinedManipulationsTest::testAddAndChildAddAndMove',

                    //TODO: https://github.com/jackalope/jackalope-doctrine-dbal/issues/22
                    'Transactions\\TransactionMethodsTest::testTransactionCommit',

                    //TODO: Client::createWorkspace throws a NotImplementedException
                    'WorkspaceManagement\\WorkspaceManagementTest::testCreateWorkspaceWithSource',
                    'WorkspaceManagement\\WorkspaceManagementTest::testCreateWorkspaceWithInvalidSource',
                    'WorkspaceManagement\\WorkspaceManagementTest::testDeleteWorkspace',

                    //TODO: https://github.com/jackalope/jackalope-doctrine-dbal/issues/12
                    'Import\\ImportRepositoryContentTest::testImportXMLUuidReplaceExistingWorkspace',
                    'Import\\ImportRepositoryContentTest::testImportXMLUuidReplaceExistingSession',
                    'Import\\ImportRepositoryContentTest::testImportXMLUuidReplaceRoot',
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
