<?php

namespace Jackalope\Transport\DoctrineDBAL;

use Doctrine\DBAL\Connection;
use Jackalope\Test\FunctionalTestCase;

class CachedClientTest extends FunctionalTestCase
{
    protected function getClient(Connection $conn)
    {
        return new CachedClient(new \Jackalope\Factory(), $conn);
    }

    public function testArrayObjectIsConvertedToArray()
    {
        $namespaces = $this->transport->getNamespaces();

        $this->assertInternalType("array", $namespaces);
    }
}
