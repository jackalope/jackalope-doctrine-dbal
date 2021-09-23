<?php

namespace Jackalope\Transport\DoctrineDBAL\Query;

use Doctrine\DBAL\Platforms\PostgreSQL94Platform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Jackalope\Factory;
use Jackalope\NodeType\NodeTypeManager;
use Jackalope\Query\QOM\Length;
use Jackalope\Query\QOM\PropertyValue;
use Jackalope\Query\QOM\QueryObjectModel;
use Jackalope\Query\QOM\QueryObjectModelFactory;
use Jackalope\Test\TestCase;
use PHPCR\NodeType\NodeTypeManagerInterface;
use PHPCR\Query\InvalidQueryException;
use PHPCR\Query\QOM\QueryObjectModelConstantsInterface;

class QOMWalkerTest extends TestCase
{
    /**
     * @var QueryObjectModelFactory
     */
    private $factory;

    /**
     * @var QOMWalker
     */
    private $walker;

    /**
     * @var NodeTypeManagerInterface
     */
    private $nodeTypeManager;

    /**
     * @var string
     */
    private $defaultColumns = '*';

    public function setUp(): void
    {
        parent::setUp();

        $conn = $this->getConnection();
        $this->nodeTypeManager = $this->createMock(NodeTypeManager::class);

        $this->nodeTypeManager
            ->method('hasNodeType')
            ->willReturn(true)
        ;

        $this->factory = new QueryObjectModelFactory(new Factory());
        $this->walker = new QOMWalker($this->nodeTypeManager, $conn);
        $this->defaultColumns = 'n0.path AS n0_path, n0.identifier AS n0_identifier, n0.props AS n0_props';
    }

    public function testDefaultQuery(): void
    {
        $this->nodeTypeManager
            ->expects($this->once())
            ->method('getSubtypes')
            ->willReturn([])
        ;

        $query = $this->factory->createQuery(
            $this->factory->selector('nt:unstructured', 'nt:unstructured'),
            null,
            [],
            []
        );
        $this->assertInstanceOf(QueryObjectModel::class, $query);

        [, , $sql] = $this->walker->walkQOMQuery($query);

        $this->assertEquals(sprintf("SELECT %s FROM phpcr_nodes n0 WHERE n0.workspace_name = ? AND n0.type IN ('nt:unstructured')", $this->defaultColumns), $sql);
    }

    public function testQueryWithPathComparisonConstraint(): void
    {
        $this->nodeTypeManager
            ->expects($this->once())
            ->method('getSubtypes')
            ->willReturn([])
        ;

        $query = $this->factory->createQuery(
            $this->factory->selector('nt:unstructured', 'nt:unstructured'),
            $this->factory->comparison($this->factory->propertyValue('nt:unstructured', 'jcr:path'), '=', $this->factory->literal('/')),
            [],
            []
        );
        $this->assertInstanceOf(QueryObjectModel::class, $query);

        [, , $sql] = $this->walker->walkQOMQuery($query);

        $this->assertEquals(sprintf("SELECT %s FROM phpcr_nodes n0 WHERE n0.workspace_name = ? AND n0.type IN ('nt:unstructured') AND n0.path = '/'", $this->defaultColumns), $sql);
    }

    public function testQueryWithPropertyComparisonConstraint(): void
    {
        $this->nodeTypeManager
            ->expects(self::once())
            ->method('getSubtypes')
            ->willReturn([])
        ;

        $query = $this->factory->createQuery(
            $this->factory->selector('nt:unstructured', 'nt:unstructured'),
            $this->factory->comparison($this->factory->propertyValue('nt:unstructured', 'jcr:createdBy'), '=', $this->factory->literal('beberlei')),
            [],
            []
        );
        $this->assertInstanceOf(QueryObjectModel::class, $query);

        [, , $sql] = $this->walker->walkQOMQuery($query);

        self::assertStringContainsString(
            '//sv:property[@sv:name="jcr:createdBy"]/sv:value',
            $sql
        );
    }

    public function testQueryWithPropertyComparisonConstraintNumericLiteral(): void
    {
        $this->nodeTypeManager
            ->expects(self::once())
            ->method('getSubtypes')
            ->willReturn([])
        ;

        $query = $this->factory->createQuery(
            $this->factory->selector('a', 'nt:unstructured'),
            $this->factory->comparison($this->factory->propertyValue('a', 'price'), '>', $this->factory->literal(100)),
            [],
            []
        );
        $this->assertInstanceOf(QueryObjectModel::class, $query);

        [, , $sql] = $this->walker->walkQOMQuery($query);

        self::assertStringContainsString('> 100', $sql);
    }

    public function testQueryWithAndConstraint(): void
    {
        $this->nodeTypeManager
            ->expects($this->once())
            ->method('getSubtypes')
            ->willReturn([])
        ;

        $query = $this->factory->createQuery(
            $this->factory->selector('nt:unstructured', 'nt:unstructured'),
            $this->factory->andConstraint(
                $this->factory->comparison($this->factory->propertyValue('nt:unstructured', 'jcr:path'), '=', $this->factory->literal('/')),
                $this->factory->comparison($this->factory->propertyValue('nt:unstructured', 'jcr:path'), '=', $this->factory->literal('/'))
            ),
            [],
            []
        );
        $this->assertInstanceOf(QueryObjectModel::class, $query);

        [, , $sql] = $this->walker->walkQOMQuery($query);

        $this->assertEquals(sprintf("SELECT %s FROM phpcr_nodes n0 WHERE n0.workspace_name = ? AND n0.type IN ('nt:unstructured') AND (n0.path = '/' AND n0.path = '/')", $this->defaultColumns), $sql);
    }

    public function testQueryWithOrConstraint(): void
    {
        $this->nodeTypeManager
            ->expects($this->once())
            ->method('getSubtypes')
            ->willReturn([])
        ;

        $query = $this->factory->createQuery(
            $this->factory->selector('nt:unstructured', 'nt:unstructured'),
            $this->factory->orConstraint(
                $this->factory->comparison($this->factory->propertyValue('nt:unstructured', 'jcr:path'), '=', $this->factory->literal('/')),
                $this->factory->comparison($this->factory->propertyValue('nt:unstructured', 'jcr:path'), '=', $this->factory->literal('/'))
            ),
            [],
            []
        );
        $this->assertInstanceOf(QueryObjectModel::class, $query);

        [, , $sql] = $this->walker->walkQOMQuery($query);

        $this->assertEquals(sprintf("SELECT %s FROM phpcr_nodes n0 WHERE n0.workspace_name = ? AND n0.type IN ('nt:unstructured') AND (n0.path = '/' OR n0.path = '/')", $this->defaultColumns), $sql);
    }

    public function testQueryWithNotConstraint(): void
    {
        $this->nodeTypeManager
            ->expects($this->once())
            ->method('getSubtypes')
            ->willReturn([])
        ;

        $query = $this->factory->createQuery(
            $this->factory->selector('nt:unstructured', 'nt:unstructured'),
            $this->factory->notConstraint(
                $this->factory->comparison($this->factory->propertyValue('nt:unstructured', 'jcr:path'), '=', $this->factory->literal('/'))
            ),
            [],
            []
        );
        $this->assertInstanceOf(QueryObjectModel::class, $query);

        [, , $sql] = $this->walker->walkQOMQuery($query);

        $this->assertEquals(sprintf("SELECT %s FROM phpcr_nodes n0 WHERE n0.workspace_name = ? AND n0.type IN ('nt:unstructured') AND NOT (n0.path = '/')", $this->defaultColumns), $sql);
    }

    public static function dataQueryWithOperator(): array
    {
        return [
            [QueryObjectModelConstantsInterface::JCR_OPERATOR_EQUAL_TO, '='],
            [QueryObjectModelConstantsInterface::JCR_OPERATOR_GREATER_THAN, '>'],
            [QueryObjectModelConstantsInterface::JCR_OPERATOR_GREATER_THAN_OR_EQUAL_TO, '>='],
            [QueryObjectModelConstantsInterface::JCR_OPERATOR_LESS_THAN, '<'],
            [QueryObjectModelConstantsInterface::JCR_OPERATOR_LESS_THAN_OR_EQUAL_TO, '<='],
            [QueryObjectModelConstantsInterface::JCR_OPERATOR_NOT_EQUAL_TO, '!='],
            [QueryObjectModelConstantsInterface::JCR_OPERATOR_LIKE, 'LIKE'],
        ];
    }

    /**
     * @dataProvider dataQueryWithOperator
     */
    public function testQueryWithOperator(string $const, string $op): void
    {
        $this->nodeTypeManager
            ->expects($this->once())
            ->method('getSubtypes')
            ->willReturn([])
        ;

        $query = $this->factory->createQuery(
            $this->factory->selector('nt:unstructured', 'nt:unstructured'),
            $this->factory->comparison($this->factory->propertyValue('nt:unstructured', 'jcr:path'), $const, $this->factory->literal('/')),
            [],
            []
        );
        $this->assertInstanceOf(QueryObjectModel::class, $query);

        [, , $sql] = $this->walker->walkQOMQuery($query);

        $this->assertEquals(sprintf("SELECT %s FROM phpcr_nodes n0 WHERE n0.workspace_name = ? AND n0.type IN ('nt:unstructured') AND n0.path $op '/'", $this->defaultColumns), $sql);
    }

    public function testQueryWithPathOrder(): void
    {
        $this->nodeTypeManager
            ->expects($this->once())
            ->method('getSubtypes')
            ->willReturn([])
        ;

        $query = $this->factory->createQuery(
            $this->factory->selector('nt:unstructured', 'nt:unstructured'),
            null,
            [$this->factory->ascending($this->factory->propertyValue('nt:unstructured', 'jcr:path'))],
            []
        );
        $this->assertInstanceOf(QueryObjectModel::class, $query);

        [, , $sql] = $this->walker->walkQOMQuery($query);

        $this->assertEquals(
            sprintf("SELECT %s FROM phpcr_nodes n0 WHERE n0.workspace_name = ? AND n0.type IN ('nt:unstructured') ORDER BY n0.path ASC", $this->defaultColumns),
            $sql
        );
    }

    public function testQueryWithOrderings(): void
    {
        $platform = $this->getConnection()->getDatabasePlatform();

        $this->nodeTypeManager->expects(self::once())->method('getSubtypes')->willReturn([]);

        $query = $this->factory->createQuery(
            $this->factory->selector('nt:unstructured', 'nt:unstructured'),
            null,
            [$this->factory->ascending($this->factory->propertyValue('nt:unstructured', 'foobar'))],
            []
        );
        $this->assertInstanceOf(QueryObjectModel::class, $query);

        $res = $this->walker->walkQOMQuery($query);

        switch ($platform) {
            case $platform instanceof PostgreSQL94Platform || $platform instanceof PostgreSQLPlatform:
                $ordering =
                    "CAST((xpath('//sv:property[@sv:name=\"foobar\"]/sv:value[1]/text()', CAST(n0.numerical_props AS xml), ARRAY[ARRAY['sv', 'http://www.jcp.org/jcr/sv/1.0']]))[1]::text AS DECIMAL) ASC, ".
                   "(xpath('//sv:property[@sv:name=\"foobar\"]/sv:value[1]/text()', CAST(n0.props AS xml), ARRAY[ARRAY['sv', 'http://www.jcp.org/jcr/sv/1.0']]))[1]::text ASC";
                break;

            default:
                $ordering =
                    "CAST(EXTRACTVALUE(n0.numerical_props, '//sv:property[@sv:name=\"foobar\"]/sv:value[1]') AS DECIMAL) ASC, ".
                    "EXTRACTVALUE(n0.props, '//sv:property[@sv:name=\"foobar\"]/sv:value[1]') ASC";
        }

        self::assertEquals(
            sprintf(
                implode(' ', [
                    'SELECT %s FROM phpcr_nodes n0 WHERE n0.workspace_name = ?',
                    "AND n0.type IN ('nt:unstructured')",
                    'ORDER BY',
                    $ordering,
                ]),
                $this->defaultColumns
            ),
            $res[2]
        );
    }

    public function testDescendantQuery(): void
    {
        $this->nodeTypeManager
            ->expects($this->exactly(2))
            ->method('getSubtypes')
            ->willReturn([])
        ;

        $query = $this->factory->createQuery(
            $this->factory->selector('nt:unstructured', 'nt:unstructured'),
            $this->factory->descendantNode('nt:unstructured', '/')
        );
        $this->assertInstanceOf(QueryObjectModel::class, $query);

        [, , $sql] = $this->walker->walkQOMQuery($query);

        $this->assertEquals(
            sprintf("SELECT %s FROM phpcr_nodes n0 WHERE n0.workspace_name = ? AND n0.type IN ('nt:unstructured') AND n0.path LIKE '/%%'", $this->defaultColumns),
            $sql
        );

        $query = $this->factory->createQuery(
            $this->factory->selector('nt:unstructured', 'nt:unstructured'),
            $this->factory->descendantNode('nt:unstructured', '/some/node')
        );
        $this->assertInstanceOf(QueryObjectModel::class, $query);

        [, , $sql] = $this->walker->walkQOMQuery($query);

        $this->assertEquals(
            sprintf("SELECT %s FROM phpcr_nodes n0 WHERE n0.workspace_name = ? AND n0.type IN ('nt:unstructured') AND n0.path LIKE '/some/node/%%'", $this->defaultColumns),
            $sql
        );
    }

    public function testWalkOperand(): void
    {
        $operand = new Length(new PropertyValue('foo', 'bar'));
        $pattern = '/\/\/sv:property\[@sv:name="bar"\]\/sv:value\[1\]\/@length/';
        $value = $this->walker->walkOperand($operand);

        if (method_exists(self::class, 'assertMatchesRegularExpression')) {
            self::assertMatchesRegularExpression($pattern, $value);
        } else {
            self::assertRegExp($pattern, $value);
        }
    }

    public function testDescendantQueryTrailingSlash(): void
    {
        $this->expectException(InvalidQueryException::class);

        $this->nodeTypeManager
            ->expects($this->once())
            ->method('getSubtypes')
            ->willReturn([])
        ;
        $query = $this->factory->createQuery(
            $this->factory->selector('nt:unstructured', 'nt:unstructured'),
            $this->factory->descendantNode('nt:unstructured', '/some/node/')
        );
        $this->assertInstanceOf(QueryObjectModel::class, $query);

        $this->walker->walkQOMQuery($query);
    }
}
