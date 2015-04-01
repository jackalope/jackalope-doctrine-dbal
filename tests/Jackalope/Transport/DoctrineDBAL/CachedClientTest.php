<?php

namespace Jackalope\Transport\DoctrineDBAL;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Jackalope\Repository;
use Jackalope\Session;
use Jackalope\Test\TestCase;
use PHPCR\PropertyType;
use PHPCR\Util\NodeHelper;
use PHPCR\Util\PathHelper;

class CachedClientTest extends TestCase
{
    /**
     * @var Client
     */
    private $transport;

    /**
     * @var Repository
     */
    private $repository;

    /**
     * @var Session
     */
    private $session;

    public function setUp()
    {
        static $initialized = false;
        parent::setUp();

        $conn = $this->getConnection();
        $options = array('disable_fks' => $conn->getDatabasePlatform() instanceof SqlitePlatform);
        $schema = new RepositorySchema($options, $conn);
        $tables = $schema->getTables();

        foreach ($tables as $table) {
            $conn->exec('DELETE FROM ' . $table->getName());
        }

        $this->transport = new \Jackalope\Transport\DoctrineDBAL\CachedClient(new \Jackalope\Factory(), $conn);
        $this->transport->createWorkspace('default');

        $this->repository = new \Jackalope\Repository(null, $this->transport);

        try {
            $this->transport->createWorkspace($GLOBALS['phpcr.workspace']);
        } catch (\PHPCR\RepositoryException $e) {
            if ($e->getMessage() != "Workspace '".$GLOBALS['phpcr.workspace']."' already exists") {
                // if the message is not that the workspace already exists, something went really wrong
                throw $e;
            }
        }
        $this->session = $this->repository->login(new \PHPCR\SimpleCredentials("user", "passwd"), $GLOBALS['phpcr.workspace']);
    }

    public function testArrayObjectIsConvertedToArray()
    {
        $namespaces = $this->transport->getNamespaces();

        $this->assertInternalType("array", $namespaces);
    }
}
