<?php

namespace Jackalope\Transport\DoctrineDBAL;

use Doctrine\DBAL\Connection;
use Jackalope\Test\FunctionalTestCase;

class CachedClientTest extends FunctionalTestCase
{
    /** @var \PHPUnit_Framework_MockObject_MockObject */
    private $cacheMock;

    protected function getClient(Connection $conn)
    {
        $this->cacheMock = $this->getMock('\Doctrine\Common\Cache\MemcachedCache');
        return new CachedClient(new \Jackalope\Factory(), $conn, ['nodes' => $this->cacheMock, 'meta' => $this->cacheMock]);
    }

    public function testArrayObjectIsConvertedToArray()
    {
        $namespaces = $this->transport->getNamespaces();

        $this->assertInternalType("array", $namespaces);
    }

    /**
     * The default key sanitizer replaces spaces with underscores
     */
    public function testDefaultKeySanitizer(){
        $this->cacheMock
            ->expects($this->at(0))
            ->method('fetch')
            ->with(
                $this->equalTo('nodetypes:_a:0:{}')
            );

        /** @var CachedClient $cachedClient */
        $cachedClient = $this->transport;
        $cachedClient->getNodeTypes();
    }

    public function testCustomkeySanitizer(){
        /** @var CachedClient $cachedClient */
        $cachedClient = $this->transport;
        //set a custom sanitizer that reveres the cachekey
        $cachedClient->setKeySanitizer(function($cacheKey){
            return strrev($cacheKey);
        });

        $this->cacheMock
            ->expects($this->at(0))
            ->method('fetch')
            ->with(
                $this->equalTo('}{:0:a :sepytedon')
            );

        /** @var CachedClient $cachedClient */
        $cachedClient = $this->transport;
        $cachedClient->getNodeTypes();
    }
}
