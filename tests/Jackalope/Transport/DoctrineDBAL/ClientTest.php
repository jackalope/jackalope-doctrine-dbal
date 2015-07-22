<?php

namespace Jackalope\Transport\DoctrineDBAL;

use Jackalope\Test\FunctionalTestCase;
use PHPCR\PropertyType;
use PHPCR\Util\NodeHelper;
use PHPCR\Util\PathHelper;

class ClientTest extends FunctionalTestCase
{
    public function testQueryNodes()
    {
        $root = $this->session->getNode('/');
        $article = $root->addNode('article');
        $article->setProperty('foo', 'bar');
        $article->setProperty('bar', 'baz');

        $this->session->save();

        $qm = $this->session->getWorkspace()->getQueryManager();
        $query = $qm->createQuery('SELECT * FROM [nt:unstructured]', \PHPCR\Query\QueryInterface::JCR_SQL2);
        $result = $query->execute();

        $this->assertEquals(2, count($result->getNodes()));

        $query = $qm->createQuery('SELECT * FROM [nt:unstructured] WHERE foo = "bar"', \PHPCR\Query\QueryInterface::JCR_SQL2);
        $result = $query->execute();

        $this->assertEquals(1, count($result->getNodes()));
    }

    public function testAddNodeTypes()
    {
        $workspace = $this->session->getWorkspace();
        $ntm = $workspace->getNodeTypeManager();
        $template = $ntm->createNodeTypeTemplate();
        $template->setName('phpcr:article');

        $propertyDefs = $template->getPropertyDefinitionTemplates();
        $propertyTemplate = $ntm->createPropertyDefinitionTemplate();
        $propertyTemplate->setName('headline');
        $propertyTemplate->setRequiredType(\PHPCR\PropertyType::STRING);
        $propertyDefs[] = $propertyTemplate;

        $childDefs = $template->getNodeDefinitionTemplates();
        $nodeTemplate = $ntm->createNodeDefinitionTemplate();
        $nodeTemplate->setName('article_content');
        $nodeTemplate->setDefaultPrimaryTypeName('nt:unstructured');
        $nodeTemplate->setMandatory(true);
        $childDefs[] = $nodeTemplate;

        $ntm->registerNodeTypes(array($template), true);

        $def = $ntm->getNodeType('phpcr:article');
        $this->assertEquals("phpcr:article", $def->getName());
        $this->assertEquals(1, count($def->getDeclaredPropertyDefinitions()));
        $this->assertEquals(1, count($def->getDeclaredChildNodeDefinitions()));
    }

    public function testReorderNodes()
    {
        $root = $this->session->getNode('/');
        $topic = $root->addNode('topic');
        $topic->addNode('page1');
        $topic->addNode('page2');
        $topic->addNode('page3');
        $topic->addNode('page4');
        $topic->addNode('page5');

        $this->session->save();

        $topic->orderBefore('page3', 'page1');
        $topic->orderBefore('page4', null);

        $this->session->save();

        $conn = $this->getConnection();
        $qb = $conn->createQueryBuilder();

        $qb->select('local_name, sort_order')
            ->from('phpcr_nodes', 'n')
            ->where('n.local_name = :name')
            ->andWhere('n.parent = :parent')
            ->orderBy('n.sort_order', 'ASC');

        $query = $qb->getSql();

        $stmnt = $this->conn->executeQuery($query, array('name' => 'page3', 'parent' => '/topic'));
        $row = $stmnt->fetch();
        $this->assertEquals(0, $row['sort_order']);

        $stmnt = $this->conn->executeQuery($query, array('name' => 'page4', 'parent' => '/topic'));

        $row = $stmnt->fetch();
        $this->assertEquals(4, $row['sort_order']);

        $retrieved = $this->session->getNode('/topic');
        foreach ($retrieved as $name => $child) {
            $check[] = $name;
        }

        $this->assertEquals($check[0], 'page3');
        $this->assertEquals($check[4], 'page4');
    }

    /**
     * Test cases for depth set when adding nodes
     */
    public function testDepthOnAdd()
    {
        $root = $this->session->getNode('/');
        $topic = $root->addNode('topic');
        $topic->addNode('page1');

        $this->session->save();

        $conn = $this->getConnection();
        $qb = $conn->createQueryBuilder();

        $qb->select('local_name, depth')
            ->from('phpcr_nodes', 'n')
            ->where('n.path = :path');

        $query = $qb->getSql();

        $stmnt = $this->conn->executeQuery($query, array('path' => '/topic'));
        $row = $stmnt->fetch();

        $this->assertEquals($row['depth'], '1');

        $stmnt = $this->conn->executeQuery($query, array('path' => '/topic/page1'));
        $row = $stmnt->fetch();

        $this->assertEquals($row['depth'], '2');
    }

    /**
     * Test cases for depth when moving nodes
     */
    public function testDepthOnMove()
    {
        $root = $this->session->getNode('/');
        $topic1 = $root->addNode('topic1');
        $topic2 = $root->addNode('topic2');
        $topic3 = $root->addNode('topic3');

        $topic1->addNode('page1');
        $topic2->addNode('page2');
        $topic3->addNode('page3');
        $this->session->save();

        $this->transport->moveNodeImmediately('/topic2/page2', '/topic1/page1/page2');

        $this->transport->moveNodeImmediately('/topic3', '/topic1/page1/page2/topic3');

        $conn = $this->getConnection();
        $qb = $conn->createQueryBuilder();

        $qb->select('local_name, depth')
            ->from('phpcr_nodes', 'n')
            ->where('n.path = :path');

        $query = $qb->getSql();

        $stmnt = $this->conn->executeQuery($query, array('path' => '/topic1/page1/page2'));
        $row = $stmnt->fetch();
        $this->assertEquals($row['depth'], '3');

        $stmnt = $this->conn->executeQuery($query, array('path' => '/topic1/page1/page2/topic3/page3'));
        $row = $stmnt->fetch();
        $this->assertEquals($row['depth'], '5');
    }

    /**
     * @dataProvider provideTestOutOfRangeCharacters
     */
    public function testOutOfRangeCharacterOccurrence($string, $isValid)
    {
        if (false === $isValid) {
            $this->setExpectedException('PHPCR\ValueFormatException', 'Invalid character detected');
        }

        $root = $this->session->getNode('/');
        $article = $root->addNode('article');
        $article->setProperty('test', $string);
        $this->session->save();
    }

    public function provideTestOutOfRangeCharacters()
    {
        return array(
            array('This is valid too!'.$this->translateCharFromCode('\u0009'), true),
            array('This is valid', true),
            array($this->translateCharFromCode('\uD7FF'), true),
            array('This is on the edge, but valid too.'. $this->translateCharFromCode('\uFFFD'), true),
            array($this->translateCharFromCode('\u10000'), true),
            array($this->translateCharFromCode('\u10FFFF'), true),
            array($this->translateCharFromCode('\u0001'), false),
            array($this->translateCharFromCode('\u0002'), false),
            array($this->translateCharFromCode('\u0003'), false),
            array($this->translateCharFromCode('\u0008'), false),
            array($this->translateCharFromCode('\uFFFF'), false),
        );
    }

    private function translateCharFromCode($char)
    {
        return json_decode('"'.$char.'"');
    }

    public function testDeleteMoreThanOneThousandNodes()
    {
        $nodes = array();
        $root = $this->session->getNode('/');
        $parent = $root->addNode('test-more-than-one-thousand');

        for ($i = 0; $i <= 1200; $i++) {
            $nodes[] = $parent->addNode('node-'.$i);
        }

        $this->session->save();

        NodeHelper::purgeWorkspace($this->session);

        $this->session->save();
    }

    public function testPropertyLengthAttribute()
    {
        $rootNode = $this->session->getRootNode();
        $node = $rootNode->addNode('testLengthAttribute');

        $data = array(
            // PropertyName         PropertyValue                   PropertyType            Expected Length
            'simpleString'  => array('simplestring',                PropertyType::STRING,   12),
            'mbString'      => array('stringMultibit漢',             PropertyType::STRING,   17),
            'long'          => array(42,                            PropertyType::LONG,     2),
            'double'        => array(3.1415,                        PropertyType::DOUBLE,   6),
            'decimal'       => array(3.141592,                      PropertyType::DECIMAL,  8),
            'date'          => array(new \DateTime('now'),          PropertyType::DATE,     29),
            'booleanTrue'   => array(true,                          PropertyType::BOOLEAN,  1),
            'booleanFalse'  => array(false,                         PropertyType::BOOLEAN,  0),
            'name'          => array('nt:unstructured',             PropertyType::NAME,     15),
            'uri'           => array('https://google.com',          PropertyType::URI,      18),
            'path'          => array('/root/testLengthAttribute',   PropertyType::PATH,     25),
            // 'multiString'   => array(array('foo', 'bar'),           PropertyType::STRING,   array(3,3)),
            // (weak)reference...
        );

        foreach ($data as $propertyName => $propertyInfo) {
            $node->setProperty($propertyName, $propertyInfo[0], $propertyInfo[1]);
        }

        $this->session->save();

        $statement = $this->getConnection()->executeQuery('SELECT props, numerical_props FROM phpcr_nodes WHERE path = ?', array('/testLengthAttribute'));
        $row = $statement->fetch(\PDO::FETCH_ASSOC);
        $props = $row['props'];
        $decimalProps = $row['numerical_props'];

        foreach ($data as $propertyName => $propertyInfo) {
            $propertyElement = null;

            foreach (array($props, $decimalProps) as $propXml) {
                if (null == $propXml) {
                    continue;
                }

                $doc = new \DOMDocument('1.0', 'utf-8');
                $doc->loadXML($propXml);

                $xpath = new \DOMXPath($doc);
                $propertyElement = $xpath->query(sprintf('sv:property[@sv:name="%s"]', $propertyName));

                if ($propertyElement->length > 0) {
                    break;
                }
            }

            $this->assertEquals(1, $propertyElement->length, 'Property ' . $propertyName . ' exists');

            $values = $xpath->query('sv:value', $propertyElement->item(0));

            /** @var $value \DOMElement */
            foreach ($values as $index => $value) {
                $lengthAttribute = $value->attributes->getNamedItem('length');
                if (null === $lengthAttribute) {
                    $this->fail(sprintf('Value %d for property "%s" is expected to have an attribute "length"', $index, $propertyName));
                }
                $this->assertEquals($propertyInfo[2], $lengthAttribute->nodeValue);
            }
        }
    }

    public function testUuid()
    {
        $class = new \ReflectionClass('Jackalope\Transport\DoctrineDBAL\Client');
        $method = $class->getMethod('generateUuid');
        $method->setAccessible(true);

        $this->assertInternalType('string', $method->invoke($this->transport));

        $this->transport->setUuidGenerator(function () {
            return 'like-a-uuid';
        });

        $this->assertEquals('like-a-uuid', $method->invoke($this->transport));
    }

    public function testMoveAndReplace()
    {
        $root = $this->session->getNode('/');
        $topic1 = $root->addNode('topic1');
        $topic1->addNode('thisisanewnode');
        $topic1->addNode('topic1Child');

        $this->session->save();
        $this->session->move('/topic1', '/topic2');

        $root->addNode('topic1');
        $this->session->save();

        $conn = $this->getConnection();
        $qb = $conn->createQueryBuilder();

        $qb->select('local_name')
            ->from('phpcr_nodes', 'n')
            ->where('n.path = :path');

        $query = $qb->getSql();

        foreach (array(
            '/topic1', '/topic2', '/topic2/thisisanewnode', '/topic2/topic1Child'
        ) as $path) {
            $stmnt = $this->conn->executeQuery($query, array('path' => $path));
            $row = $stmnt->fetch();
            $this->assertTrue(false !== $row, $path . ' does not exist in database');
        }
    }

    public function testCaseInsensativeRename()
    {
        $root = $this->session->getNode('/');
        $topic1 = $root->addNode('topic');

        $this->session->save();
        $this->session->move('/topic', '/Topic');
        $this->session->save();
    }

    public function testStoreTypes()
    {
        $rootNode = $this->session->getRootNode();
        $node = $rootNode->addNode('testStoreTypes');

        $data = array(
            array('string_1', 'string_1', PropertyType::STRING),
            array('string_2', 'string_1', PropertyType::STRING),
            array('long_1', '10', PropertyType::LONG),
            array('long_2', '20', PropertyType::LONG),
            array('decimal_1', '10.0', PropertyType::DECIMAL),
            array('decimal_2', '20.0', PropertyType::DECIMAL),
        );

        foreach ($data as $propertyData) {
            $node->setProperty($propertyData[0], $propertyData[1], $propertyData[2]);
        }

        $this->session->save();
        $this->session->refresh(false);

        foreach ($data as $propertyData) {
            list($propName) = $propertyData;
            $this->assertTrue($node->hasProperty($propName), 'Node has property "' . $propName .'"');
        }
    }

    public function provideOrder()
    {
        return array(
            array(
                array(
                    'one' => array(
                        'value' => 'AAA',
                    ),
                    'two' => array(
                        'value' => 'BBB',
                    ),
                    'three' => array(
                        'value' => 'CCC',
                    ),
                ),
                'value DESC',
                array('three', 'two', 'one'),
            ),

            // longs
            array(
                array(
                    'one' => array(
                        'value' => 30,
                    ),
                    'two' => array(
                        'value' => 20,
                    ),
                    'three' => array(
                        'value' => 10,
                    ),
                ),
                'value',
                array('three', 'two', 'one'),
            ),

            // longs (ensure that values are not cast as strings)
            array(
                array(
                    'one' => array(
                        'value' => 10,
                    ),
                    'two' => array(
                        'value' => 100,
                    ),
                    'three' => array(
                        'value' => 20,
                    ),
                ),
                'value',
                array('one', 'three', 'two'),
            ),

            // decimals
            array(
                array(
                    'one' => array(
                        'value' => 10.01,
                    ),
                    'two' => array(
                        'value' => 0.01,
                    ),
                    'three' => array(
                        'value' => 5.05,
                    ),
                ),
                'value',
                array('two', 'three', 'one'),
            ),

            // mixed
            array(
                array(
                    'one' => array(
                        'title' => 'AAA',
                        'value' => 10.01,
                    ),
                    'two' => array(
                        'title' => 'AAA',
                        'value' => 0.01,
                    ),
                    'three' => array(
                        'title' => 'CCC',
                        'value' => 5.05,
                    ),
                    'four' => array(
                        'title' => 'BBB',
                        'value' => 5.05,
                    ),
                ),
                'title, value ASC',
                array('two', 'one', 'four', 'three'),
            ),
        );
    }

    /**
     * @dataProvider provideOrder
     */
    public function testOrder($nodes, $orderBy, $expectedOrder)
    {
        $rootNode = $this->session->getNode('/');

        foreach ($nodes as $nodeName => $nodeProperties) {
            $node = $rootNode->addNode($nodeName);
            foreach ($nodeProperties as $name => $value) {
                $node->setProperty($name, $value);
            }
        }

        $this->session->save();

        $qm = $this->session->getWorkspace()->getQueryManager();
        $query = $qm->createQuery('SELECT * FROM [nt:unstructured] WHERE value IS NOT NULL ORDER BY ' . $orderBy, \PHPCR\Query\QueryInterface::JCR_SQL2);
        $result = $query->execute();

        $rows = $result->getRows();
        $this->assertGreaterThan(0, count($rows));

        foreach ($rows as $index => $row) {
            $path = $row->getNode()->getPath();
            $name = PathHelper::getNodeName($path);

            $expectedName = $expectedOrder[$index];
            $this->assertEquals($expectedName, $name);
        }
    }

    public function testCopy()
    {
        $rootNode = $this->session->getNode('/');
        $child1 = $rootNode->addNode('child1');
        $child1->setProperty('string', 'Hello');
        $child1->setProperty('number', 1234);

        $this->session->save();

        $this->session->getWorkspace()->copy('/child1', '/child2');

        $stmt = $this->conn->query("SELECT * FROM phpcr_nodes WHERE path = '/child1' OR path = '/child2'");
        $child1 = $stmt->fetch();
        $child2 = $stmt->fetch();

        $this->assertNotNull($child1);
        $this->assertNotNull($child2);

        $this->assertEquals($child1['props'], $child2['props']);
        $this->assertEquals($child1['numerical_props'], $child2['numerical_props']);
    }
}
