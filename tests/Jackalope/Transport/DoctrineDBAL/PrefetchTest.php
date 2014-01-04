<?php

namespace Jackalope\Transport\DoctrineDBAL;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use Jackalope\Functional\Transport\PrefetchTestCase;

class PrefetchTest extends PrefetchTestCase
{
    protected $conn;

    public function setUp()
    {
        parent::setUp();

        $conn = $this->getConnection();
        $options = array('disable_fks' => $conn->getDatabasePlatform() instanceof SqlitePlatform);
        $schema = new RepositorySchema($options, $conn);
        // do not use reset as we want to ignore exceptions on drop
        foreach ($schema->toDropSql($conn->getDatabasePlatform()) as $statement) {
            try {
                $conn->exec($statement);
            } catch (\Exception $e) {
                // ignore
            }
        }

        foreach ($schema->toSql($conn->getDatabasePlatform()) as $statement) {
            $conn->exec($statement);
        }


        $transport = $this->getTransport();

        $repository = new \Jackalope\Repository(null, $transport);
        $session = $repository->login(new \PHPCR\SimpleCredentials("user", "passwd"), $GLOBALS['phpcr.workspace']);
        $a = $session->getNode('/')->addNode('node-a');
        $a->addNode('child-a')->setProperty('prop', 'aa');
        $a->addNode('child-b')->setProperty('prop', 'ab');
        $b = $session->getNode('/')->addNode('node-b');
        $b->addNode('child-a')->setProperty('prop', 'ba');
        $b->addNode('child-b')->setProperty('prop', 'bb');
        $session->save();
    }

    protected function getConnection()
    {
        if ($this->conn === null) {
            // @TODO see https://github.com/jackalope/jackalope-doctrine-dbal/issues/48
            global $dbConn;
            $this->conn = $dbConn;

            if ($this->conn === null) {
                $this->conn = DriverManager::getConnection(array(
                    'driver'    => @$GLOBALS['phpcr.doctrine.dbal.driver'],
                    'path'      => @$GLOBALS['phpcr.doctrine.dbal.path'],
                    'host'      => @$GLOBALS['phpcr.doctrine.dbal.host'],
                    'user'      => @$GLOBALS['phpcr.doctrine.dbal.username'],
                    'password'  => @$GLOBALS['phpcr.doctrine.dbal.password'],
                    'dbname'    => @$GLOBALS['phpcr.doctrine.dbal.dbname']
                ));
            }
        }

        return $this->conn;
    }

    protected function getTransport()
    {
        $transport = new \Jackalope\Transport\DoctrineDBAL\Client(new \Jackalope\Factory(), $this->getConnection());
        try {
            $transport->createWorkspace($GLOBALS['phpcr.workspace']);
        } catch (\PHPCR\RepositoryException $e) {
            if ($e->getMessage() != "Workspace '".$GLOBALS['phpcr.workspace']."' already exists") {
                // if the message is not that the workspace already exists, something went really wrong
                throw $e;
            }
        }

        $transport->login(new \PHPCR\SimpleCredentials("user", "passwd"), $GLOBALS['phpcr.workspace']);

        return $transport;
    }

    public function testGetNodes()
    {
        $this->markTestSkipped('Not implemented, see https://github.com/jackalope/jackalope-doctrine-dbal/issues/157');
    }
}
