<?php

namespace Jackalope\Test;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use Jackalope\Repository;
use Jackalope\Session;
use Jackalope\Transport\DoctrineDBAL\Client;
use Jackalope\Transport\DoctrineDBAL\RepositorySchema;

/**
 * Base class for testing jackalope clients.
 */
class FunctionalTestCase extends TestCase
{
    /**
     * @var Client
     */
    protected $transport;

    /**
     * @var Repository
     */
    protected $repository;

    /**
     * @var Session
     */
    protected $session;

    public function setUp()
    {
        parent::setUp();

        $conn = $this->getConnection();
        $this->loadFixtures($conn);
        $this->transport = $this->getClient($conn);

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

    protected function loadFixtures(Connection $conn)
    {
        $options = array('disable_fks' => $conn->getDatabasePlatform() instanceof SqlitePlatform);
        $schema = new RepositorySchema($options, $conn);
        $tables = $schema->getTables();

        foreach ($tables as $table) {
            $conn->exec('DELETE FROM ' . $table->getName());
        }
    }

    protected function getClient(Connection $conn)
    {
        return new Client(new \Jackalope\Factory(), $conn);
    }
}
