<?php

namespace Jackalope\Transport\DoctrineDBAL;

use Jackalope\Test\FunctionalTestCase;

class PrefetchTest extends FunctionalTestCase
{
    public function setUp(): void
    {
        parent::setUp();

        $a = $this->session->getNode('/')->addNode('node-a');
        $a->addNode('child-a')->setProperty('prop', 'aa');
        $a->addNode('child-b')->setProperty('prop', 'ab');
        $b = $this->session->getNode('/')->addNode('node-b');
        $b->addNode('child-a')->setProperty('prop', 'ba');
        $b->addNode('child-b')->setProperty('prop', 'bb');
        $this->session->save();
    }

    public function testGetNode(): void
    {
        $this->transport->setFetchDepth(1);

        $raw = $this->transport->getNode('/node-a');

        $this->assertNode($raw);
    }

    public function testGetNodes(): void
    {
        $this->transport->setFetchDepth(1);

        $list = $this->transport->getNodes(['/node-a', '/node-b']);

        $this->assertCount(6, $list);

        $keys = array_keys($list);
        sort($keys);

        $this->assertEquals(
            ['/node-a', '/node-a/child-a', '/node-a/child-b', '/node-b', '/node-b/child-a', '/node-b/child-b'],
            $keys
        );

        $this->assertNode($list['/node-a']);
        $this->assertChildNode($list['/node-a/child-a'], 'a', 'a');
        $this->assertChildNode($list['/node-a/child-b'], 'a', 'b');

        $this->assertNode($list['/node-b']);
        $this->assertChildNode($list['/node-b/child-a'], 'b', 'a');
        $this->assertChildNode($list['/node-b/child-b'], 'b', 'b');
    }

    protected function assertNode($raw): void
    {
        $this->assertInstanceOf('\stdClass', $raw);

        $name = 'child-a';
        $this->assertTrue(property_exists($raw, $name), "The raw data is missing child $name");

        $name = 'child-b';
        $this->assertTrue(property_exists($raw, $name));
    }

    protected function assertChildNode($raw, $parent, $child): void
    {
        $this->assertInstanceOf(\stdClass::class, $raw);

        $this->assertTrue(property_exists($raw, 'prop'), "The child $child is missing property 'prop'");
        $this->assertEquals($parent.$child, $raw->prop);
    }
}
