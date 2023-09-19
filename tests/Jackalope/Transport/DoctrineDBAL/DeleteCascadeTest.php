<?php

namespace Jackalope\Transport\DoctrineDBAL;

use Doctrine\DBAL\Platforms\SqlitePlatform;
use Jackalope\Test\FunctionalTestCase;
use PHPCR\PropertyType;

class DeleteCascadeTest extends FunctionalTestCase
{
    public function setUp(): void
    {
        parent::setUp();

        if ($this->conn->getDatabasePlatform() instanceof SqlitePlatform) {
            $this->markTestSkipped('Foreign keys are not supported with sqlite');
        }

        $a = $this->session->getNode('/')->addNode('node-a');
        $a->setProperty('data', 'foo', PropertyType::BINARY);
        $this->session->save();
    }

    public function testRemoveProperty(): void
    {
        $binaryRows = $this->conn->executeQuery('SELECT * FROM phpcr_binarydata');
        $this->assertSame(1, $binaryRows->rowCount());

        $this->session->removeItem('/node-a/data');
        $this->session->save();
        $binaryRows = $this->conn->executeQuery('SELECT * FROM phpcr_binarydata');
        $this->assertSame(0, $binaryRows->rowCount());
    }

    public function testRemovePropertyFromNode(): void
    {
        $a = $this->session->getNode('/node-a');
        $binaryRows = $this->conn->executeQuery('SELECT * FROM phpcr_binarydata');
        $this->assertSame(1, $binaryRows->rowCount());

        $a->getProperty('data')->remove();
        $this->session->save();
        $binaryRows = $this->conn->executeQuery('SELECT * FROM phpcr_binarydata');
        $this->assertSame(0, $binaryRows->rowCount());
    }

    public function testRemoveNode(): void
    {
        $a = $this->session->getNode('/node-a');
        $binaryRows = $this->conn->executeQuery('SELECT * FROM phpcr_binarydata');
        $this->assertSame(1, $binaryRows->rowCount());

        $a->remove();
        $this->session->save();
        $binaryRows = $this->conn->executeQuery('SELECT * FROM phpcr_binarydata');
        $this->assertSame(0, $binaryRows->rowCount());
    }
}
