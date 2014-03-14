<?php

namespace Jackalope;

class RepositoryFactoryDoctrineDBALTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @expectedException \PHPCR\ConfigurationException
     * @expectedExceptionMessage missing
     */
    public function testMissingRequired()
    {
        $factory = new RepositoryFactoryDoctrineDBAL();
        $factory->getRepository(array());
    }

    /**
     * @expectedException \PHPCR\ConfigurationException
     * @expectedExceptionMessage unknown
     */
    public function testExtraParameter()
    {
        $factory = new RepositoryFactoryDoctrineDBAL();
        $conn = $this->getMockBuilder('Doctrine\DBAL\Connection')
            ->disableOriginalConstructor()
            ->getMock()
        ;
        $this->assertNull($factory->getRepository(array(
            'jackalope.doctrine_dbal_connection' => $conn,
            'unknown' => 'garbage',
        )));
    }
}
