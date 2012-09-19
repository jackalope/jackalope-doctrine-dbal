<?php
// @TODO: change BaseCase to use namespaced loader
#namespace Jackalope\Transport\DoctrineDBAL\Test;

use Doctrine\DBAL\Connection;
use Doctrine\Common\Cache\ArrayCache;

/**
 * Implementation loader for jackalope-doctrine-dbal
 */
class ImplementationLoader extends \PHPCR\Test\AbstractLoader
{
    private static $instance = null;

    public static function getInstance()
    {
        if (null === self::$instance) {
            global $dbConn;
            $fixturePath = realpath(__DIR__ . '/../../../../../tests/fixtures/doctrine');
            self::$instance = new ImplementationLoader($dbConn, $fixturePath);
        }

        return self::$instance;
    }

    /**
     * @var Connection
     */
    private $connection;

    /**
     * @var string
     */
    private $fixturePath;

    protected function __construct(Connection $connection, $fixturePath)
    {
        parent::__construct('Jackalope\RepositoryFactoryDoctrineDBAL', $GLOBALS['phpcr.workspace']);

        $this->connection   = $connection;
        $this->fixturePath  = $fixturePath;

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
                    'Query\\Sql1', //TODO: Query language 'sql' not yet implemented
        );

        $this->unsupportedTests = array(
                    'Connecting\\RepositoryTest::testLoginException', //TODO: figure out what would be invalid credentials

                    'Reading\\NodeReadMethodsTest::testGetSharedSetUnreferenced', // TODO: should this be moved to 14_ShareableNodes
                    'Reading\\SessionReadMethodsTest::testImpersonate', //TODO: Check if that's implemented in newer jackrabbit versions.
                    'Reading\\SessionNamespaceRemappingTest::testSetNamespacePrefix', //TODO: implement session scope remapping of namespaces

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

                    // TODO: enable and look at the exception you get as starting point
                    'Writing\\MoveMethodsTest::testSessionDeleteMoved',
                    'Writing\\MoveMethodsTest::testSessionMoveReplace',
                    'Writing\\CombinedManipulationsTest::testAddAndChildAddAndMove',

                    //TODO: https://github.com/jackalope/jackalope-doctrine-dbal/issues/22
                    'Transactions\\TransactionMethodsTest::testTransactionCommit',

                    //TODO: parse cnd https://github.com/phpcr/phpcr-utils/issues/18
                    'NodeTypeManagement\\ManipulationTest::testRegisterNodeTypesCnd',
                    'NodeTypeManagement\\ManipulationTest::testPrimaryItem',
                    'NodeTypeManagement\\ManipulationTest::testRegisterNodeTypesCndNoUpdate',

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

    public function getConnection()
    {
        return $this->connection;
    }

    public function getRepositoryFactoryParameters()
    {
        if (empty($GLOBALS['data_caches'])) {
            $caches = null;
        } else {
            $caches = array();
            foreach (explode(',', $GLOBALS['data_caches']) as $key) {
                $caches[$key] = new ArrayCache();
            }
        }

        return array(
            'jackalope.doctrine_dbal_connection' => $this->connection,
            'jackalope.data_caches' => $caches,
        );
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

    public function getRepository()
    {
        $transport = new \Jackalope\Transport\DoctrineDBAL\Client(new \Jackalope\Factory, $this->connection);
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

    public function getFixtureLoader()
    {
        $testerClass = '\\Jackalope\\Transport\\DoctrineDBAL\\Test\\Tester\\' . ucfirst(strtolower($this->connection->getWrappedConnection()->getAttribute(PDO::ATTR_DRIVER_NAME)));
        if (!class_exists($testerClass)) {
            // load Generic Tester if no database specific Tester class found
            $testerClass = '\\Jackalope\\Transport\\DoctrineDBAL\\Test\\Tester\\Generic';
        }

        return new $testerClass(
            new \PHPUnit_Extensions_Database_DB_DefaultDatabaseConnection($this->connection->getWrappedConnection(), "tests"),
            $this->fixturePath
        );
    }

}
