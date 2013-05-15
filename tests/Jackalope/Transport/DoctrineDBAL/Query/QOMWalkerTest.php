<?php

namespace Jackalope\Transport\DoctrineDBAL\Query;

use Jackalope\Test\TestCase;
use Jackalope\Query\QOM\QueryObjectModelFactory;
use Jackalope\Factory;

use PHPCR\Query\QOM\QueryObjectModelConstantsInterface;

class QOMWalkerTest extends TestCase
{
    /**
     *
     * @var QueryObjectModelFactory
     */
    private $factory;

    private $walker;

    private $nodeTypeManager;

    private $defaultColumns = '*';

    public function setUp()
    {
        parent::setUp();

        $conn = $this->getConnection();
        $this->nodeTypeManager = $this->getMock('Jackalope\NodeType\NodeTypeManager', array(), array(), '', false);
        $this->factory = new QueryObjectModelFactory(new Factory);
        $this->walker = new QOMWalker($this->nodeTypeManager, $conn);
        $this->defaultColumns = 'n0.id AS id, n0.path AS path, n0.parent AS parent, n0.local_name AS local_name, n0.namespace AS namespace, n0.workspace_name AS workspace_name, n0.identifier AS identifier, n0.type AS type, n0.props AS props, n0.depth AS depth, n0.sort_order AS sort_order';
    }

    public function testDefaultQuery()
    {
        $this->nodeTypeManager->expects($this->once())->method('getSubtypes')->will($this->returnValue( array() ));

        $query = $this->factory->createQuery($this->factory->selector('nt:unstructured'), null, array(), array());
        $sql = $this->walker->walkQOMQuery($query);

        $this->assertEquals(sprintf("SELECT %s FROM phpcr_nodes n0 WHERE n0.workspace_name = ? AND n0.type IN ('nt:unstructured')", $this->defaultColumns), $sql);
    }

    public function testQueryWithPathComparisonConstraint()
    {
        $this->nodeTypeManager->expects($this->once())->method('getSubtypes')->will($this->returnValue( array() ));

        $query = $this->factory->createQuery(
            $this->factory->selector('nt:unstructured'),
            $this->factory->comparison($this->factory->propertyValue('jcr:path'), '=', $this->factory->literal('/')),
            array(),
            array()
        );
        $sql = $this->walker->walkQOMQuery($query);

        $this->assertEquals(sprintf("SELECT %s FROM phpcr_nodes n0 WHERE n0.workspace_name = ? AND n0.type IN ('nt:unstructured') AND n0.path = '/'", $this->defaultColumns), $sql);
    }

    public function testQueryWithPropertyComparisonConstraint()
    {
        $this->nodeTypeManager->expects($this->once())->method('getSubtypes')->will($this->returnValue( array() ));

        $query = $this->factory->createQuery(
            $this->factory->selector('nt:unstructured'),
            $this->factory->comparison($this->factory->propertyValue('jcr:createdBy'), '=', $this->factory->literal('beberlei')),
            array(),
            array()
        );
        $sql = $this->walker->walkQOMQuery($query);

        $this->assertContains('//sv:property[@sv:name="jcr:createdBy"]/sv:value[1]',
            $sql
        );
    }

    public function testQueryWithAndConstraint()
    {
        $this->nodeTypeManager->expects($this->once())->method('getSubtypes')->will($this->returnValue( array() ));

        $query = $this->factory->createQuery(
            $this->factory->selector('nt:unstructured'),
            $this->factory->andConstraint(
                $this->factory->comparison($this->factory->propertyValue('jcr:path'), '=', $this->factory->literal('/')),
                $this->factory->comparison($this->factory->propertyValue('jcr:path'), '=', $this->factory->literal('/'))
            ),
            array(),
            array()
        );
        $sql = $this->walker->walkQOMQuery($query);

        $this->assertEquals(sprintf("SELECT %s FROM phpcr_nodes n0 WHERE n0.workspace_name = ? AND n0.type IN ('nt:unstructured') AND (n0.path = '/' AND n0.path = '/')", $this->defaultColumns), $sql);
    }

    public function testQueryWithOrConstraint()
    {
        $this->nodeTypeManager->expects($this->once())->method('getSubtypes')->will($this->returnValue( array() ));

        $query = $this->factory->createQuery(
            $this->factory->selector('nt:unstructured'),
            $this->factory->orConstraint(
                $this->factory->comparison($this->factory->propertyValue('jcr:path'), '=', $this->factory->literal('/')),
                $this->factory->comparison($this->factory->propertyValue('jcr:path'), '=', $this->factory->literal('/'))
            ),
            array(),
            array()
        );
        $sql = $this->walker->walkQOMQuery($query);

        $this->assertEquals(sprintf("SELECT %s FROM phpcr_nodes n0 WHERE n0.workspace_name = ? AND n0.type IN ('nt:unstructured') AND (n0.path = '/' OR n0.path = '/')", $this->defaultColumns), $sql);
    }

    public function testQueryWithNotConstraint()
    {
        $this->nodeTypeManager->expects($this->once())->method('getSubtypes')->will($this->returnValue( array() ));

        $query = $this->factory->createQuery(
            $this->factory->selector('nt:unstructured'),
            $this->factory->notConstraint(
                $this->factory->comparison($this->factory->propertyValue('jcr:path'), '=', $this->factory->literal('/'))
            ),
            array(),
            array()
        );
        $sql = $this->walker->walkQOMQuery($query);

        $this->assertEquals(sprintf("SELECT %s FROM phpcr_nodes n0 WHERE n0.workspace_name = ? AND n0.type IN ('nt:unstructured') AND NOT (n0.path = '/')", $this->defaultColumns), $sql);
    }

    static public function dataQueryWithOperator()
    {
        return array(
            array(QueryObjectModelConstantsInterface::JCR_OPERATOR_EQUAL_TO, "="),
            array(QueryObjectModelConstantsInterface::JCR_OPERATOR_GREATER_THAN, ">"),
            array(QueryObjectModelConstantsInterface::JCR_OPERATOR_GREATER_THAN_OR_EQUAL_TO, ">="),
            array(QueryObjectModelConstantsInterface::JCR_OPERATOR_LESS_THAN, "<"),
            array(QueryObjectModelConstantsInterface::JCR_OPERATOR_LESS_THAN_OR_EQUAL_TO, "<="),
            array(QueryObjectModelConstantsInterface::JCR_OPERATOR_NOT_EQUAL_TO, "!="),
            array(QueryObjectModelConstantsInterface::JCR_OPERATOR_LIKE,"LIKE")
        );
    }

    /**
     * @dataProvider dataQueryWithOperator
     * @param type $const
     * @param type $op
     */
    public function testQueryWithOperator($const, $op)
    {
        $this->nodeTypeManager->expects($this->once())->method('getSubtypes')->will($this->returnValue( array() ));

        $query = $this->factory->createQuery(
            $this->factory->selector('nt:unstructured'),
            $this->factory->comparison($this->factory->propertyValue('jcr:path'), $const, $this->factory->literal('/')),
            array(),
            array()
        );
        $sql = $this->walker->walkQOMQuery($query);

        $this->assertEquals(sprintf("SELECT %s FROM phpcr_nodes n0 WHERE n0.workspace_name = ? AND n0.type IN ('nt:unstructured') AND n0.path $op '/'", $this->defaultColumns), $sql);
    }

    public function testQueryWithOrderings()
    {
        $this->nodeTypeManager->expects($this->once())->method('getSubtypes')->will($this->returnValue( array() ));

        $query = $this->factory->createQuery(
            $this->factory->selector('nt:unstructured'),
            null,
            array($this->factory->ascending($this->factory->propertyValue("jcr:path"))),
            array()
        );

        $sql = $this->walker->walkQOMQuery($query);

        $this->assertEquals(
            sprintf("SELECT %s FROM phpcr_nodes n0 WHERE n0.workspace_name = ? AND n0.type IN ('nt:unstructured') ORDER BY n0.path ASC", $this->defaultColumns),
            $sql
        );
    }

    public function testDescendantQuery()
    {
        $this->nodeTypeManager->expects($this->exactly(2))->method('getSubtypes')->will($this->returnValue( array() ));

        $query = $this->factory->createQuery(
            $this->factory->selector('nt:unstructured'),
            $this->factory->descendantNode('/')
        );

        $sql = $this->walker->walkQOMQuery($query);

        $this->assertEquals(
            sprintf("SELECT %s FROM phpcr_nodes n0 WHERE n0.workspace_name = ? AND n0.type IN ('nt:unstructured') AND n0.path LIKE '/%%'", $this->defaultColumns),
            $sql
        );

        $query = $this->factory->createQuery(
            $this->factory->selector('nt:unstructured'),
            $this->factory->descendantNode('/some/node')
        );

        $sql = $this->walker->walkQOMQuery($query);

        $this->assertEquals(
            sprintf("SELECT %s FROM phpcr_nodes n0 WHERE n0.workspace_name = ? AND n0.type IN ('nt:unstructured') AND n0.path LIKE '/some/node/%%'", $this->defaultColumns),
            $sql
        );
    }

    /**
     * @expectedException \PHPCR\Query\InvalidQueryException
     */
    public function testDescendantQuery_trailingSlash()
    {
        $this->nodeTypeManager->expects($this->once())->method('getSubtypes')->will($this->returnValue( array() ));
        $query = $this->factory->createQuery(
            $this->factory->selector('nt:unstructured'),
            $this->factory->descendantNode('/some/node/')
        );

        $sql = $this->walker->walkQOMQuery($query);
    }
}
