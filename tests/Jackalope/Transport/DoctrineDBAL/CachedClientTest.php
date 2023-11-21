<?php

namespace Jackalope\Transport\DoctrineDBAL;

use Doctrine\DBAL\Connection;
use Jackalope\Factory;
use Jackalope\Test\FunctionalTestCase;
use Jackalope\Transport\TransportInterface;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;

class CachedClientTest extends FunctionalTestCase
{
    /**
     * @var CacheInterface
     */
    private $cache;

    protected function getClient(Connection $conn): TransportInterface
    {
        $this->cache = new Psr16Cache(new ArrayAdapter());

        return new CachedClient(new Factory(), $conn, ['nodes' => $this->cache, 'meta' => $this->cache]);
    }

    public function testArrayObjectIsConvertedToArray(): void
    {
        $namespaces = $this->transport->getNamespaces();
        self::assertIsArray($namespaces);
    }

    public function testCacheHit()
    {
        $cache = new \stdClass();
        $this->cache->set('nodes:_/test,_tests', $cache);

        $this->assertSame($cache, $this->transport->getNode('/test'));
    }

    /**
     * The default key sanitizer replaces spaces with underscores.
     */
    public function testDefaultKeySanitizer(): void
    {
        /** @var CachedClient $cachedClient */
        $cachedClient = $this->transport;
        $cachedClient->getNodeTypes();

        $this->assertTrue($this->cache->has('node_types'));
        $this->assertTrue($this->cache->has('nodetypes:_a:0:{}'));
    }

    public function testCustomKeySanitizer(): void
    {
        /** @var CachedClient $cachedClient */
        $cachedClient = $this->transport;
        // set a custom sanitizer that reveres the cachekey
        $cachedClient->setKeySanitizer(function ($cacheKey) {
            return strrev($cacheKey);
        });

        $cachedClient->getNodeTypes();

        $this->assertTrue($this->cache->has('sepyt_edon'));
        $this->assertTrue($this->cache->has('}{:0:a :sepytedon'));
    }
}
