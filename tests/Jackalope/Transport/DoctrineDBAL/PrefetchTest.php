<?php

namespace Jackalope\Transport\DoctrineDBAL;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use Jackalope\TestCase;

/**
 * Extend this test case in your jackalope transport and provide the transport
 * instance to be tested.
 *
 * The fixtures must contain the following tree:
 *
 * * node-a
 * * * child-a
 * * * child-b
 * * node-b
 * * * child-a
 * * * child-b
 *
 * each child has a property "prop" with the corresponding a and b value in it:
 * /node-a/child-a get "prop" => "aa".
 */
class PrefetchTest extends TestCase
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

    public function testGetNode()
    {
        $transport = $this->getTransport();
        $transport->setFetchDepth(1);

        $raw = $transport->getNode('/node-a');

        $this->assertNode($raw, 'a');
    }

    public function testGetNodes()
    {
        $transport = $this->getTransport();
        $transport->setFetchDepth(1);

        $list = $transport->getNodes(array('/node-a', '/node-b'));

        $this->assertCount(6, $list);


        $keys = array_keys($list);
        sort($keys);

        $this->assertEquals(
            array('/node-a', '/node-a/child-a', '/node-a/child-b', '/node-b', '/node-b/child-a', '/node-b/child-b'),
            $keys
        );

        $this->assertNode($list['/node-a']);
        $this->assertChildNode($list['/node-a/child-a'], 'a', 'a');
        $this->assertChildNode($list['/node-a/child-b'], 'a', 'b');

        $this->assertNode($list['/node-b']);
        $this->assertChildNode($list['/node-b/child-a'], 'b', 'a');
        $this->assertChildNode($list['/node-b/child-b'], 'b', 'b');
    }

    protected function assertNode($raw)
    {
        $this->assertInstanceOf('\stdClass', $raw);

        $name = "child-a";
        $this->assertTrue(isset($raw->$name), "The raw data is missing child $name");

        $name = 'child-b';
        $this->assertTrue(isset($raw->$name));
    }

    protected function assertChildNode($raw, $parent, $child)
    {
        $this->assertInstanceOf('\stdClass', $raw);

        $this->assertTrue(isset($raw->prop), "The child $child is missing property 'prop'");
        $this->assertEquals($parent . $child, $raw->prop);
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
}
