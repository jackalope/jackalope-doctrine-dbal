<?php

use Jackalope\Test\Tester\Generic;
use Doctrine\DBAL\Connection;
use Doctrine\Common\Cache\ArrayCache;
use Jackalope\Factory;
use Jackalope\Repository;
use Jackalope\RepositoryFactoryDoctrineDBAL;
use Jackalope\Session;
use Jackalope\Transport\DoctrineDBAL\Client;
use Jackalope\Transport\Logging\Psr3Logger;
use PHPCR\RepositoryException;
use PHPCR\SimpleCredentials;
use PHPCR\Test\AbstractLoader;
use Psr\Log\NullLogger;

/**
 * Implementation loader for jackalope-doctrine-dbal
 */
class ImplementationLoader extends AbstractLoader
{
    private static $instance = null;

    public static function getInstance()
    {
        if (null === self::$instance) {
            global $dbConn;
            $fixturePath = realpath(__DIR__ . '/fixtures/doctrine');
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
        parent::__construct(RepositoryFactoryDoctrineDBAL::class, $GLOBALS['phpcr.workspace']);

        $this->connection   = $connection;
        $this->fixturePath  = $fixturePath;

        $this->unsupportedChapters = [
            'ShareableNodes', //TODO: Not implemented, no test currently written for it
            'AccessControlManagement', //TODO: Not implemented, no test currently written for it
            'LifecycleManagement', //TODO: Not implemented, no test currently written for it
            'RetentionAndHold', //TODO: Not implemented, no test currently written for it
            'SameNameSiblings', //TODO: Not implemented, no test currently written for it
            'PermissionsAndCapabilities', //TODO: Transport does not support permissions
            'Observation', //TODO: Transport does not support observation
            'Versioning', //TODO: Transport does not support versioning
            'Locking', //TODO: Transport does not support locking
        ];

        $this->unsupportedCases = [
            'Query\\XPath', // Query language 'xpath' not implemented.
            'Query\\Sql1', // Query language 'sql' is legacy and only makes sense with jackrabbit
            'Writing\\CloneMethodsTest', // TODO: Support for workspace->clone, node->update, node->getCorrespondingNodePath
        ];

        $this->unsupportedTests = [
            'Connecting\\RepositoryTest::testLoginException', //TODO: figure out what would be invalid credentials

            'Reading\\NodeReadMethodsTest::testGetSharedSetUnreferenced', // TODO: should this be moved to 14_ShareableNodes
            'Reading\\SessionReadMethodsTest::testImpersonate', //TODO: Check if that's implemented in newer jackrabbit versions.
            'Reading\\SessionNamespaceRemappingTest::testSetNamespacePrefix', //TODO: implement session scope remapping of namespaces

            //TODO: implement getQuery method in Jackalope QueryManager
            'Query\\QueryManagerTest::testGetQuery',
            'Query\\QueryManagerTest::testGetQueryInvalid',
            'Query\\QueryObjectSql2Test::testGetStoredQueryPath',
            // TODO: implement CAST, see also https://github.com/jackalope/jackalope-doctrine-dbal/issues/267
            'Query\QuerySql2OperationsTest::testQueryFieldDate',
            // TODO fix handling of order by with missing properties
            'Query\QuerySql2OperationsTest::testQueryOrderWithMissingProperty',

            // this seems a bug in php with arrayiterator - and jackalope is using
            // arrayiterator for the search result
            // TODO https://github.com/phpcr/phpcr-api-tests/issues/22
            'Query\\NodeViewTest::testSeekable',

            'Writing\\CopyMethodsTest::testCopyUpdateOnCopy', //TODO: update-on-copy is currently not supported

            //TODO: https://github.com/jackalope/jackalope-doctrine-dbal/issues/22
            'Transactions\\TransactionMethodsTest::testTransactionCommit',

            // TODO: implement creating workspace with source
            'WorkspaceManagement\\WorkspaceManagementTest::testCreateWorkspaceWithSource',
            'WorkspaceManagement\\WorkspaceManagementTest::testCreateWorkspaceWithInvalidSource'
        ];

        if ($connection->getDatabasePlatform() instanceof Doctrine\DBAL\Platforms\SqlitePlatform) {
            $this->unsupportedTests[] = 'Query\\QuerySql2OperationsTest::testQueryRightJoin';
        }
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
            $caches = [];
            foreach (explode(',', $GLOBALS['data_caches']) as $key) {
                $caches[$key] = new ArrayCache();
            }
        }

        return [
            'jackalope.doctrine_dbal_connection' => $this->connection,
            'jackalope.data_caches' => $caches,
            Session::OPTION_AUTO_LASTMODIFIED => false,
            'jackalope.logger' => new Psr3Logger(new NullLogger()),
        ];
    }

    public function getSessionWithLastModified()
    {
        /** @var $session Session */
        $session = $this->getSession();
        $session->setSessionOption(Session::OPTION_AUTO_LASTMODIFIED, true);

        return $session;
    }

    public function getCredentials()
    {
        return new SimpleCredentials($GLOBALS['phpcr.user'], $GLOBALS['phpcr.pass']);
    }

    public function getInvalidCredentials()
    {
        return new SimpleCredentials('nonexistinguser', '');
    }

    public function getRestrictedCredentials()
    {
        return new SimpleCredentials('anonymous', 'abc');
    }

    /**
     * Doctrine dbal supports anonymous login
     *
     * @return bool true
     */
    public function prepareAnonymousLogin()
    {
        return true;
    }

    public function getUserId()
    {
        return $GLOBALS['phpcr.user'];
    }

    public function getRepository()
    {
        $transport = new Client(new Factory, $this->connection);
        foreach ([$GLOBALS['phpcr.workspace'], $this->otherWorkspacename] as $workspace) {
            try {
                $transport->createWorkspace($workspace);
            } catch (RepositoryException $e) {
                if ($e->getMessage() !== "Workspace '$workspace' already exists") {
                    // if the message is not that the workspace already exists, something went really wrong
                    throw $e;
                }
            }
        }

        return new Repository(null, $transport, $this->getRepositoryFactoryParameters());
    }

    public function getFixtureLoader()
    {
        $testerClass = '\\Jackalope\\Test\\Tester\\' . ucfirst(strtolower($this->connection->getWrappedConnection()->getAttribute(PDO::ATTR_DRIVER_NAME)));
        if (!class_exists($testerClass)) {
            // load Generic Tester if no database specific Tester class found
            $testerClass = Generic::class;
        }

        return new $testerClass(
            new PHPUnit_Extensions_Database_DB_DefaultDatabaseConnection($this->connection->getWrappedConnection(), 'tests'),
            $this->fixturePath
        );
    }
}
