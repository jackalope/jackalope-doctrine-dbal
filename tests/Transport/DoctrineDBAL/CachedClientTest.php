<?php

namespace Jackalope\Tests\Transport\DoctrineDBAL;

use Doctrine\DBAL\Connection;
use Jackalope\Factory;
use Jackalope\Tests\FunctionalTestCase;
use Jackalope\Transport\DoctrineDBAL\CachedClient;
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

    public function testCacheHit(): void
    {
        $cache = new \stdClass();
        $cache->foo = 'bar';
        $this->cache->set('nodes_3A+_2Ftest_2C+tests', $cache);
        $this->assertEquals($cache, $this->transport->getNode('/test'));
    }

    /**
     * The default key sanitizer keeps the cache key compatible with PSR16.
     */
    public function testDefaultKeySanitizer(): void
    {
        $client = $this->getClient($this->getConnection());
        $reflection = new \ReflectionClass($client);
        $keySanitizerProperty = $reflection->getProperty('keySanitizer');
        // remove when we drop PHP 8.0 support
        if (PHP_VERSION_ID < 80100) {
            $keySanitizerProperty->setAccessible(true);
        }
        $defaultKeySanitizer = $keySanitizerProperty->getValue($client);

        $result = $defaultKeySanitizer(' :{}().@/"\\'); // not allowed PSR16 keys

        $this->assertEquals('+_3A_7B_7D_28_29|_40_2F_22_5C', $result);
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
