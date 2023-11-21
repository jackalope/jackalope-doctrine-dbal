<?php

namespace Jackalope\Transport\DoctrineDBAL;

use Doctrine\Common\Cache\Cache;
use Doctrine\DBAL\Connection;
use Jackalope\Factory;
use Jackalope\Test\FunctionalTestCase;
use PHPUnit\Framework\MockObject\MockObject;

class CachedClientTest extends FunctionalTestCase
{
    /**
     * @var Cache|MockObject
     */
    private $cacheMock;

    protected function getClient(Connection $conn)
    {
        $this->cacheMock = $this->createMock(Cache::class);

        return new CachedClient(new Factory(), $conn, ['nodes' => $this->cacheMock, 'meta' => $this->cacheMock]);
    }

    public function testArrayObjectIsConvertedToArray()
    {
        $namespaces = $this->transport->getNamespaces();
        self::assertIsArray($namespaces);
    }

    public function testCacheHit()
    {
        $cache = new \stdClass();
        $this->cacheMock->method('fetch')->with('nodes:_/test,_tests')->willReturn($cache);

        $this->assertSame($cache, $this->transport->getNode('/test'));
    }

    /**
     * The default key sanitizer replaces spaces with underscores
     */
    public function testDefaultKeySanitizer()
    {
        $first = true;
        $this->cacheMock
            ->method('fetch')
            ->with(self::callback(function ($arg) use (&$first) {
                self::assertEquals($first ? 'nodetypes:_a:0:{}' : 'node_types', $arg);
                $first = false;

                return true;
            }));

        /** @var CachedClient $cachedClient */
        $cachedClient = $this->transport;
        $cachedClient->getNodeTypes();
    }

    public function testCustomkeySanitizer()
    {
        /** @var CachedClient $cachedClient */
        $cachedClient = $this->transport;
        //set a custom sanitizer that reveres the cachekey
        $cachedClient->setKeySanitizer(function ($cacheKey) {
            return strrev($cacheKey);
        });

        $first = true;
        $this->cacheMock
            ->method('fetch')
            ->with(self::callback(function ($arg) use (&$first) {
                self::assertEquals($first ? '}{:0:a :sepytedon' : 'sepyt_edon', $arg);
                $first = false;

                return true;
            }));

        $cachedClient->getNodeTypes();
    }
}
