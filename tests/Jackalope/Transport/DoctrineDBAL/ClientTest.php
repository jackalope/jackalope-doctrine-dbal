<?php

namespace Jackalope\Transport\DoctrineDBAL;

use DateTime;
use DOMDocument;
use DOMXPath;
use Jackalope\NodeType\NodeTypeTemplate;
use Jackalope\Test\FunctionalTestCase;
use PDO;
use PHPCR\PropertyType;
use PHPCR\Query\QOM\QueryObjectModelConstantsInterface;
use PHPCR\Query\QueryInterface;
use PHPCR\Util\NodeHelper;
use PHPCR\Util\PathHelper;
use PHPCR\Util\QOM\QueryBuilder;
use PHPCR\ValueFormatException;
use ReflectionClass;

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
        $query = $qm->createQuery('SELECT * FROM [nt:unstructured]', QueryInterface::JCR_SQL2);
        $result = $query->execute();

        $this->assertCount(2, $result->getNodes());

        $query = $qm->createQuery('SELECT * FROM [nt:unstructured] WHERE foo = "bar"', QueryInterface::JCR_SQL2);
        $result = $query->execute();

        $this->assertCount(1, $result->getNodes());
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
        $propertyTemplate->setRequiredType(PropertyType::STRING);
        $propertyDefs[] = $propertyTemplate;

        $childDefs = $template->getNodeDefinitionTemplates();
        $nodeTemplate = $ntm->createNodeDefinitionTemplate();
        $nodeTemplate->setName('article_content');
        $nodeTemplate->setDefaultPrimaryTypeName('nt:unstructured');
        $nodeTemplate->setMandatory(true);
        $childDefs[] = $nodeTemplate;

        $ntm->registerNodeTypes([$template], true);

        $def = $ntm->getNodeType('phpcr:article');
        $this->assertEquals("phpcr:article", $def->getName());
        $this->assertCount(1, $def->getDeclaredPropertyDefinitions());
        $this->assertCount(1, $def->getDeclaredChildNodeDefinitions());
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

        $stmnt = $this->conn->executeQuery($query, ['name' => 'page3', 'parent' => '/topic']);
        $row = $stmnt->fetch();
        $this->assertEquals(0, $row['sort_order']);

        $stmnt = $this->conn->executeQuery($query, ['name' => 'page4', 'parent' => '/topic']);

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

        $stmnt = $this->conn->executeQuery($query, ['path' => '/topic']);
        $row = $stmnt->fetch();

        $this->assertEquals($row['depth'], '1');

        $stmnt = $this->conn->executeQuery($query, ['path' => '/topic/page1']);
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

        $stmnt = $this->conn->executeQuery($query, ['path' => '/topic1/page1/page2']);
        $row = $stmnt->fetch();
        $this->assertEquals($row['depth'], '3');

        $stmnt = $this->conn->executeQuery($query, ['path' => '/topic1/page1/page2/topic3/page3']);
        $row = $stmnt->fetch();
        $this->assertEquals($row['depth'], '5');
    }

    /**
     * @dataProvider provideTestOutOfRangeCharacters
     */
    public function testOutOfRangeCharacterOccurrence($string, $isValid)
    {
        if (false === $isValid) {
            $this->expectException(ValueFormatException::class);
            $this->expectExceptionMessage('Invalid character detected');
        }

        $root = $this->session->getNode('/');
        $article = $root->addNode('article');
        $article->setProperty('test', $string);
        $this->session->save();
        $this->addToAssertionCount(1);
    }

    public function provideTestOutOfRangeCharacters()
    {
        return [
            ['This is valid too!'.$this->translateCharFromCode('\u0009'), true],
            ['This is valid', true],
            [$this->translateCharFromCode('\uD7FF'), true],
            ['This is on the edge, but valid too.'. $this->translateCharFromCode('\uFFFD'), true],
            [$this->translateCharFromCode('\u10000'), true],
            [$this->translateCharFromCode('\u10FFFF'), true],
            [$this->translateCharFromCode('\u0001'), false],
            [$this->translateCharFromCode('\u0002'), false],
            [$this->translateCharFromCode('\u0003'), false],
            [$this->translateCharFromCode('\u0008'), false],
            [$this->translateCharFromCode('\uFFFF'), false],
        ];
    }

    private function translateCharFromCode($char)
    {
        return json_decode('"'.$char.'"');
    }

    public function testDeleteMoreThanOneThousandNodes()
    {
        $root = $this->session->getNode('/');
        $parent = $root->addNode('test-more-than-one-thousand');

        for ($i = 0; $i <= 1200; $i++) {
            $parent->addNode('node-'.$i);
        }

        $this->session->save();

        NodeHelper::purgeWorkspace($this->session);

        $this->session->save();

        $this->addToAssertionCount(1);
    }

    public function testPropertyLengthAttribute()
    {
        $rootNode = $this->session->getRootNode();
        $node = $rootNode->addNode('testLengthAttribute');

        $data = [
            // PropertyName         PropertyValue                   PropertyType            Expected Length
            'simpleString'  => ['simplestring',                PropertyType::STRING,   12],
            'mbString'      => ['stringMultibit漢',             PropertyType::STRING,   17],
            'long'          => [42,                            PropertyType::LONG,     2],
            'double'        => [3.1415,                        PropertyType::DOUBLE,   6],
            'decimal'       => [3.141592,                      PropertyType::DECIMAL,  8],
            'date'          => [new DateTime('now'),          PropertyType::DATE,     29],
            'booleanTrue'   => [true,                          PropertyType::BOOLEAN,  1],
            'booleanFalse'  => [false,                         PropertyType::BOOLEAN,  0],
            'name'          => ['nt:unstructured',             PropertyType::NAME,     15],
            'uri'           => ['https://google.com',          PropertyType::URI,      18],
            'path'          => ['/root/testLengthAttribute',   PropertyType::PATH,     25],
            // 'multiString'   => array(array('foo', 'bar'),           PropertyType::STRING,   array(3,3)),
            // (weak)reference...
        ];

        foreach ($data as $propertyName => $propertyInfo) {
            $node->setProperty($propertyName, $propertyInfo[0], $propertyInfo[1]);
        }

        $this->session->save();

        $statement = $this->getConnection()->executeQuery('SELECT props, numerical_props FROM phpcr_nodes WHERE path = ?', ['/testLengthAttribute']);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        $props = $row['props'];
        $decimalProps = $row['numerical_props'];

        foreach ($data as $propertyName => $propertyInfo) {
            $propertyElement = null;

            foreach ([$props, $decimalProps] as $propXml) {
                if (null === $propXml) {
                    continue;
                }

                $doc = new DOMDocument('1.0', 'utf-8');
                $doc->loadXML($propXml);

                $xpath = new DOMXPath($doc);
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
        $class = new ReflectionClass(Client::class);
        $method = $class->getMethod('generateUuid');
        $method->setAccessible(true);

        self::assertIsString($method->invoke($this->transport));

        $this->transport->setUuidGenerator(function () {
            return 'like-a-uuid';
        });

        self::assertEquals('like-a-uuid', $method->invoke($this->transport));
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

        foreach (['/topic1', '/topic2', '/topic2/thisisanewnode', '/topic2/topic1Child'] as $path) {
            $stmnt = $this->conn->executeQuery($query, ['path' => $path]);
            $row = $stmnt->fetch();
            $this->assertNotFalse($row, $path . ' does not exist in database');
        }
    }

    public function testMoveNamespacedNodes()
    {
        $root = $this->session->getNode('/');
        $topic1 = $root->addNode('jcr:topic1');
        $topic1->addNode('jcr:thisisanewnode');
        $topic1->addNode('jcr:topic1Child');

        $this->session->save();
        $this->session->move('/jcr:topic1', '/jcr:topic2');

        $this->session->save();

        $conn = $this->getConnection();
        $qb = $conn->createQueryBuilder();

        $qb->select('local_name')
            ->from('phpcr_nodes', 'n')
            ->where('n.path = :path')->andWhere('n.local_name = :local_name');

        $query = $qb->getSql();

        $expectedData = [
            '/jcr:topic2' => 'topic2',
            '/jcr:topic2/jcr:thisisanewnode' => 'thisisanewnode',
            '/jcr:topic2/jcr:topic1Child' => 'topic1Child'
        ];
        foreach ($expectedData as $path => $localName) {
            $stmnt = $this->conn->executeQuery($query, ['path' => $path, 'local_name' => $localName]);
            $row = $stmnt->fetch();
            $this->assertNotFalse($row, $path . ' with local_name' . $localName . ' does not exist in database');
        }
    }

    public function testCaseInsensativeRename()
    {
        $root = $this->session->getNode('/');
        $topic1 = $root->addNode('topic');

        $this->session->save();
        $this->session->move('/topic', '/Topic');
        $this->session->save();

        $this->addToAssertionCount(1);
    }

    public function testStoreTypes()
    {
        $rootNode = $this->session->getRootNode();
        $node = $rootNode->addNode('testStoreTypes');

        $data = [
            ['string_1', 'string_1', PropertyType::STRING],
            ['string_2', 'string_1', PropertyType::STRING],
            ['long_1', '10', PropertyType::LONG],
            ['long_2', '20', PropertyType::LONG],
            ['decimal_1', '10.0', PropertyType::DECIMAL],
            ['decimal_2', '20.0', PropertyType::DECIMAL],
        ];

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
        return [
            [
                [
                    'one' => [
                        'value' => 'AAA',
                    ],
                    'two' => [
                        'value' => 'BBB',
                    ],
                    'three' => [
                        'value' => 'CCC',
                    ],
                ],
                'value',
                'value DESC',
                ['three', 'two', 'one'],
            ],

            // longs
            [
                [
                    'one' => [
                        'value' => 30,
                    ],
                    'two' => [
                        'value' => 20,
                    ],
                    'three' => [
                        'value' => 10,
                    ],
                ],
                'value',
                'value',
                ['three', 'two', 'one'],
            ],

            // longs (ensure that values are not cast as strings)
            [
                [
                    'one' => [
                        'value' => 10,
                    ],
                    'two' => [
                        'value' => 100,
                    ],
                    'three' => [
                        'value' => 20,
                    ],
                ],
                'value',
                'value',
                ['one', 'three', 'two'],
            ],

            // decimals
            [
                [
                    'one' => [
                        'value' => 10.01,
                    ],
                    'two' => [
                        'value' => 0.01,
                    ],
                    'three' => [
                        'value' => 5.05,
                    ],
                ],
                'value',
                'value',
                ['two', 'three', 'one'],
            ],

            // mixed
            [
                [
                    'one' => [
                        'title' => 'AAA',
                        'value' => 10.01,
                    ],
                    'two' => [
                        'title' => 'AAA',
                        'value' => 0.01,
                    ],
                    'three' => [
                        'title' => 'CCC',
                        'value' => 5.05,
                    ],
                    'four' => [
                        'title' => 'BBB',
                        'value' => 5.05,
                    ],
                ],
                'value',
                'title, value ASC',
                ['two', 'one', 'four', 'three'],
            ],

            // property with double quotes
            [
                [
                    'one' => [
                        'val"ue' => 'AAA',
                    ],
                    'two' => [
                        'val"ue' => 'BBB',
                    ],
                    'three' => [
                        'val"ue' => 'CCC',
                    ],
                ],
                'val"ue',
                'val"ue DESC',
                ['three', 'two', 'one'],
            ],

            // property with single quotes
            [
                [
                    'one' => [
                        'val\'ue' => 'AAA',
                    ],
                    'two' => [
                        'val\'ue' => 'BBB',
                    ],
                    'three' => [
                        'val\'ue' => 'CCC',
                    ],
                ],
                'val\'ue',
                'val\'ue DESC',
                ['three', 'two', 'one'],
            ],

            // property with semicolon quotes
            [
                [
                    'one' => [
                        'val;ue' => 'AAA',
                    ],
                    'two' => [
                        'val;ue' => 'BBB',
                    ],
                    'three' => [
                        'val;ue' => 'CCC',
                    ],
                ],
                'val;ue',
                'val;ue DESC',
                ['three', 'two', 'one'],
            ],
        ];
    }

    /**
     * @dataProvider provideOrder
     */
    public function testOrder($nodes, $propertyName, $orderBy, $expectedOrder)
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
        $qf = $qm->getQOMFactory();
        $qb = new QueryBuilder($qf);
        $qb->from(
            $qb->qomf()->selector('a', 'nt:unstructured')
        );
        $qb->where($qf->comparison(
            $qf->propertyValue('a', $propertyName),
            QueryObjectModelConstantsInterface::JCR_OPERATOR_NOT_EQUAL_TO,
            $qf->literal('NULL')
        ));

        $orderBys = explode(',', $orderBy);
        foreach ($orderBys as $orderByItem) {
            $orderByParts = explode(' ', trim($orderByItem));
            $propertyName = $orderByParts[0];
            $order = isset($orderByParts[1]) ? $orderByParts[1] : 'ASC';

            $qb->addOrderBy(
                $qb->qomf()->propertyValue('a', $propertyName),
                $order
            );
        }

        $query = $qb->getQuery();
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

    public function testCopySiblingWithSamePrefix()
    {
        $rootNode = $this->session->getNode('/');
        $child1 = $rootNode->addNode('child1');
        $child1->setProperty('string', 'Hello');
        $child1->setProperty('number', 1234);
        $child2 = $rootNode->addNode('child1-2');
        $child2->setProperty('string', 'Hello');
        $child2->setProperty('number', 1234);

        $this->session->save();

        $this->session->getWorkspace()->copy('/child1', '/child2');

        $stmt = $this->conn->query("SELECT * FROM phpcr_nodes WHERE path LIKE '/child%'");
        $children = $stmt->fetchAll();

        $this->assertCount(3, $children);

        $paths = array_map(
            function ($child) {
                return $child['path'];
            },
            $children
        );

        $this->assertContains('/child1', $paths);
        $this->assertContains('/child2', $paths);
        $this->assertContains('/child1-2', $paths);
    }

    /**
     * The date value should not change when saving.
     */
    public function testDate()
    {
        $rootNode = $this->session->getNode('/');
        $child1 = $rootNode->addNode('child1');
        $date = new DateTime();
        $before = $date->format('c');
        $child1->setProperty('date', $date);
        $this->session->save();
        $after = $date->format('c');

        $this->assertEquals($before, $after);
    }

    public function testNestedJoinForDifferentDocumentTypes()
    {
        $ntm = $this->session->getWorkspace()->getNodeTypeManager();
        $template = $ntm->createNodeTypeTemplate();
        $template->setName('test');
        $template->setDeclaredSuperTypeNames(['nt:unstructured']);
        $ntm->registerNodeType($template, true);

        $root = $this->session->getNode('/');
        $documentNode = $root->addNode('document', 'test');
        $category = $root->addNode('category');
        $category->addMixin('mix:referenceable');
        $this->session->save();
        $category = $this->session->getNode('/category');
        $documentChild = $documentNode->addNode('document_child', 'nt:unstructured');
        $documentChild->setProperty('title', 'someChild');
        $documentChild->setProperty('locale', 'en');
        $category->setProperty('title', 'someCategory');
        $documentNode->setProperty('category', $category->getProperty('jcr:uuid'), 'WeakReference');
        $this->session->save();



        $qm = $this->session->getWorkspace()->getQueryManager();
        $qom = $qm->getQOMFactory();
        $documentSelector = $qom->selector('d', 'test');
        $categorySelector = $qom->selector('c', 'nt:unstructured');
        $documentChildSelector = $qom->selector('dt', 'nt:base');
        $join = $qom->join($documentSelector, $categorySelector, $qom::JCR_JOIN_TYPE_INNER, $qom->equiJoinCondition(
            'd',
            'category',
            'c',
            'jcr:uuid'
        ));
        $childTitleProp = $qom->propertyValue('dt', 'title');
        $childTitleVal = $qom->literal($documentChild->getProperty('title')->getValue());
        $titleConstraint = $qom->comparison($childTitleProp, $qom::JCR_OPERATOR_EQUAL_TO, $childTitleVal);

        $from = $qom->join($join, $documentChildSelector, $qom::JCR_JOIN_TYPE_INNER, $qom->childNodeJoinCondition(
            'dt',
            'd'
        ));
        $localeConstraint = $qom->comparison(
            $qom->propertyValue('dt', 'locale'),
            $qom::JCR_OPERATOR_EQUAL_TO,
            $qom->literal($documentChild->getProperty('locale')->getValue())
        );
        $where = $qom->andConstraint($titleConstraint, $localeConstraint);

        $queryObjectModel = $qom->createQuery($from, $where);
        $result = $queryObjectModel->execute();

        $this->assertCount(1, $result);
    }

    public function testMultiJoiningReferencedDocuments()
    {
        $ntm = $this->session->getWorkspace()->getNodeTypeManager();
        $template = $ntm->createNodeTypeTemplate();
        $template->setName('test');
        $template->setDeclaredSuperTypeNames(['nt:unstructured']);
        $ntm->registerNodeType($template, true);

        $root = $this->session->getNode('/');
        $documentNode = $root->addNode('document', 'test');

        $category = $root->addNode('category');
        $category->addMixin('mix:referenceable');

        $group = $root->addNode('group');
        $group->addMixin('mix:referenceable');

        $this->session->save();
        $category = $this->session->getNode('/category');
        $category->setProperty('title', 'someCategory');
        $group = $this->session->getNode('/group');
        $group->setProperty('title', 'someGroup');

        $documentNode->setProperty('category', $category->getProperty('jcr:uuid'), 'WeakReference');
        $documentNode->setProperty('group', $group->getProperty('jcr:uuid'), 'WeakReference');
        $this->session->save();

        $qm = $this->session->getWorkspace()->getQueryManager();
        $qom = $qm->getQOMFactory();
        $documentSelector = $qom->selector('d', 'test');
        $categorySelector = $qom->selector('c', 'nt:unstructured');
        $groupSelector = $qom->selector('g', 'nt:unstructured');
        $join = $qom->join($documentSelector, $categorySelector, $qom::JCR_JOIN_TYPE_INNER, $qom->equiJoinCondition(
            'd',
            'category',
            'c',
            'jcr:uuid'
        ));


        $from = $qom->join($join, $groupSelector, $qom::JCR_JOIN_TYPE_INNER, $qom->equiJoinCondition(
            'd',
            'group',
            'g',
            'jcr:uuid'
        ));

        $queryObjectModel = $qom->createQuery($from);
        $result = $queryObjectModel->execute();

        $this->assertCount(1, $result);
    }
}
