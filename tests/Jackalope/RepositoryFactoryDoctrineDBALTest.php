<?php

namespace Jackalope;

use Doctrine\DBAL\Connection;
use PHPCR\ConfigurationException;
use PHPUnit\Framework\TestCase;

class RepositoryFactoryDoctrineDBALTest extends TestCase
{
    /**
     * @expectedExceptionMessage missing
     */
    public function testMissingRequired()
    {
        $this->expectException(ConfigurationException::class);

        $factory = new RepositoryFactoryDoctrineDBAL();
        $factory->getRepository([]);
    }

    /**
     * @expectedExceptionMessage unknown
     */
    public function testExtraParameter()
    {
        $this->expectException(ConfigurationException::class);

        $factory = new RepositoryFactoryDoctrineDBAL();
        $conn = $this->getMockBuilder(Connection::class)
            ->disableOriginalConstructor()
            ->getMock()
        ;
        $this->assertNull($factory->getRepository([
            'jackalope.doctrine_dbal_connection' => $conn,
            'unknown' => 'garbage',
        ]));
    }
}
