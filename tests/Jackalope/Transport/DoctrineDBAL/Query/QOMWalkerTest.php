<?php

namespace Jackalope\Transport\DoctrineDBAL\Query;

use Jackalope\Test\TestCase;
use Jackalope\Query\QOM\Length;
use Jackalope\Query\QOM\PropertyValue;
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

    /**
     * @var QOMWalker
     */
    private $walker;

    private $nodeTypeManager;

    private $defaultColumns = '*';

    public function setUp()
    {
        parent::setUp();

        $conn = $this->getConnection();
        $this->nodeTypeManager = $this->getMock('Jackalope\NodeType\NodeTypeManager', array(), array(), '', false);
        $this->nodeTypeManager
            ->expects($this->any())
            ->method('hasNodeType')
            ->will($this->returnValue(true))
        ;
        $this->factory = new QueryObjectModelFactory(new Factory);
        $this->walker = new QOMWalker($this->nodeTypeManager, $conn);
        $this->defaultColumns = 'n0.id AS n0_id, n0.path AS n0_path, n0.parent AS n0_parent, n0.local_name AS n0_local_name, n0.namespace AS n0_namespace, n0.workspace_name AS n0_workspace_name, n0.identifier AS n0_identifier, n0.type AS n0_type, n0.props AS n0_props, n0.depth AS n0_depth, n0.sort_order AS n0_sort_order';
    }

    public function testDefaultQuery()
    {
        $this->nodeTypeManager->expects($this->once())->method('getSubtypes')->will($this->returnValue( array() ));

        $query = $this->factory->createQuery($this->factory->selector('nt:unstructured', 'nt:unstructured'), null, array(), array());
        list($selectors, $selectorAliases, $sql) = $this->walker->walkQOMQuery($query);

        $this->assertEquals(sprintf("SELECT %s FROM phpcr_nodes n0 WHERE n0.workspace_name = ? AND n0.type IN ('nt:unstructured')", $this->defaultColumns), $sql);
    }

    public function testQueryWithPathComparisonConstraint()
    {
        $this->nodeTypeManager->expects($this->once())->method('getSubtypes')->will($this->returnValue( array() ));

        $query = $this->factory->createQuery(
            $this->factory->selector('nt:unstructured', 'nt:unstructured'),
            $this->factory->comparison($this->factory->propertyValue('nt:unstructured', 'jcr:path'), '=', $this->factory->literal('/')),
            array(),
            array()
        );
        list($selectors, $selectorAliases, $sql) = $this->walker->walkQOMQuery($query);

        $this->assertEquals(sprintf("SELECT %s FROM phpcr_nodes n0 WHERE n0.workspace_name = ? AND n0.type IN ('nt:unstructured') AND n0.path = '/'", $this->defaultColumns), $sql);
    }

    public function testQueryWithPropertyComparisonConstraint()
    {
        $this->nodeTypeManager->expects($this->once())->method('getSubtypes')->will($this->returnValue( array() ));

        $query = $this->factory->createQuery(
            $this->factory->selector('nt:unstructured', 'nt:unstructured'),
            $this->factory->comparison($this->factory->propertyValue('nt:unstructured', 'jcr:createdBy'), '=', $this->factory->literal('beberlei')),
            array(),
            array()
        );
        list($selectors, $selectorAliases, $sql) = $this->walker->walkQOMQuery($query);

        $this->assertContains('//sv:property[@sv:name="jcr:createdBy"]/sv:value',
            $sql
        );
    }

    public function testQueryWithPropertyComparisonConstraintNumericLiteral()
    {
        $this->nodeTypeManager->expects($this->once())->method('getSubtypes')->will($this->returnValue( array() ));

        $query = $this->factory->createQuery(
            $this->factory->selector('a', 'nt:unstructured'),
            $this->factory->comparison($this->factory->propertyValue('a', 'price'), '>', $this->factory->literal(100)),
            array(),
            array()
        );
        list($selectors, $selectorAliases, $sql) = $this->walker->walkQOMQuery($query);

        $this->assertContains('> 100', $sql);
    }

    public function testQueryWithAndConstraint()
    {
        $this->nodeTypeManager->expects($this->once())->method('getSubtypes')->will($this->returnValue( array() ));

        $query = $this->factory->createQuery(
            $this->factory->selector('nt:unstructured', 'nt:unstructured'),
            $this->factory->andConstraint(
                $this->factory->comparison($this->factory->propertyValue('nt:unstructured', 'jcr:path'), '=', $this->factory->literal('/')),
                $this->factory->comparison($this->factory->propertyValue('nt:unstructured', 'jcr:path'), '=', $this->factory->literal('/'))
            ),
            array(),
            array()
        );
        list($selectors, $selectorAliases, $sql) = $this->walker->walkQOMQuery($query);

        $this->assertEquals(sprintf("SELECT %s FROM phpcr_nodes n0 WHERE n0.workspace_name = ? AND n0.type IN ('nt:unstructured') AND (n0.path = '/' AND n0.path = '/')", $this->defaultColumns), $sql);
    }

    public function testQueryWithOrConstraint()
    {
        $this->nodeTypeManager->expects($this->once())->method('getSubtypes')->will($this->returnValue( array() ));

        $query = $this->factory->createQuery(
            $this->factory->selector('nt:unstructured', 'nt:unstructured'),
            $this->factory->orConstraint(
                $this->factory->comparison($this->factory->propertyValue('nt:unstructured', 'jcr:path'), '=', $this->factory->literal('/')),
                $this->factory->comparison($this->factory->propertyValue('nt:unstructured', 'jcr:path'), '=', $this->factory->literal('/'))
            ),
            array(),
            array()
        );
        list($selectors, $selectorAliases, $sql) = $this->walker->walkQOMQuery($query);

        $this->assertEquals(sprintf("SELECT %s FROM phpcr_nodes n0 WHERE n0.workspace_name = ? AND n0.type IN ('nt:unstructured') AND (n0.path = '/' OR n0.path = '/')", $this->defaultColumns), $sql);
    }

    public function testQueryWithNotConstraint()
    {
        $this->nodeTypeManager->expects($this->once())->method('getSubtypes')->will($this->returnValue( array() ));

        $query = $this->factory->createQuery(
            $this->factory->selector('nt:unstructured', 'nt:unstructured'),
            $this->factory->notConstraint(
                $this->factory->comparison($this->factory->propertyValue('nt:unstructured', 'jcr:path'), '=', $this->factory->literal('/'))
            ),
            array(),
            array()
        );
        list($selectors, $selectorAliases, $sql) = $this->walker->walkQOMQuery($query);

        $this->assertEquals(sprintf("SELECT %s FROM phpcr_nodes n0 WHERE n0.workspace_name = ? AND n0.type IN ('nt:unstructured') AND NOT (n0.path = '/')", $this->defaultColumns), $sql);
    }

    public static function dataQueryWithOperator()
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
            $this->factory->selector('nt:unstructured', 'nt:unstructured'),
            $this->factory->comparison($this->factory->propertyValue('nt:unstructured', 'jcr:path'), $const, $this->factory->literal('/')),
            array(),
            array()
        );
        list($selectors, $selectorAliases, $sql) = $this->walker->walkQOMQuery($query);

        $this->assertEquals(sprintf("SELECT %s FROM phpcr_nodes n0 WHERE n0.workspace_name = ? AND n0.type IN ('nt:unstructured') AND n0.path $op '/'", $this->defaultColumns), $sql);
    }

    public function testQueryWithOrderings()
    {
        $this->nodeTypeManager->expects($this->once())->method('getSubtypes')->will($this->returnValue( array() ));

        $query = $this->factory->createQuery(
            $this->factory->selector('nt:unstructured', 'nt:unstructured'),
            null,
            array($this->factory->ascending($this->factory->propertyValue('nt:unstructured', "jcr:path"))),
            array()
        );

        list($selectors, $selectorAliases, $sql) = $this->walker->walkQOMQuery($query);

        $this->assertEquals(
            sprintf("SELECT %s FROM phpcr_nodes n0 WHERE n0.workspace_name = ? AND n0.type IN ('nt:unstructured') ORDER BY n0.path ASC", $this->defaultColumns),
            $sql
        );
    }

    public function testDescendantQuery()
    {
        $this->nodeTypeManager->expects($this->exactly(2))->method('getSubtypes')->will($this->returnValue( array() ));

        $query = $this->factory->createQuery(
            $this->factory->selector('nt:unstructured', 'nt:unstructured'),
            $this->factory->descendantNode('nt:unstructured', '/')
        );

        list($selectors, $selectorAliases, $sql) = $this->walker->walkQOMQuery($query);

        $this->assertEquals(
            sprintf("SELECT %s FROM phpcr_nodes n0 WHERE n0.workspace_name = ? AND n0.type IN ('nt:unstructured') AND n0.path LIKE '/%%'", $this->defaultColumns),
            $sql
        );

        $query = $this->factory->createQuery(
            $this->factory->selector('nt:unstructured', 'nt:unstructured'),
            $this->factory->descendantNode('nt:unstructured', '/some/node')
        );

        list($selectors, $selectorAliases, $sql) = $this->walker->walkQOMQuery($query);

        $this->assertEquals(
            sprintf("SELECT %s FROM phpcr_nodes n0 WHERE n0.workspace_name = ? AND n0.type IN ('nt:unstructured') AND n0.path LIKE '/some/node/%%'", $this->defaultColumns),
            $sql
        );
    }

    public function testWalkOperand()
    {
        $operand = new Length(new PropertyValue('foo', 'bar'));
        $this->assertRegExp('/\/\/sv:property\[@sv:name="bar"\]\/sv:value\[1\]\/@length/', $this->walker->walkOperand($operand));
    }

    /**
     * @expectedException \PHPCR\Query\InvalidQueryException
     */
    public function testDescendantQueryTrailingSlash()
    {
        $this->nodeTypeManager->expects($this->once())->method('getSubtypes')->will($this->returnValue( array() ));
        $query = $this->factory->createQuery(
            $this->factory->selector('nt:unstructured', 'nt:unstructured'),
            $this->factory->descendantNode('nt:unstructured', '/some/node/')
        );

        $this->walker->walkQOMQuery($query);
    }
}
