<?php

namespace Jackalope\Transport\DoctrineDBAL\Query;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\PostgreSQL94Platform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use Jackalope\NodeType\NodeTypeManager;
use Jackalope\NotImplementedException;
use Jackalope\Query\QOM\PropertyValue;
use Jackalope\Query\QOM\QueryObjectModel;
use Jackalope\Transport\DoctrineDBAL\Util\Xpath;
use PHPCR\NamespaceException;
use PHPCR\NodeType\NodeTypeManagerInterface;
use PHPCR\Query\InvalidQueryException;
use PHPCR\Query\QOM;

/**
 * Converts QOM to SQL Statements for the Doctrine DBAL database backend.
 *
 * @license http://www.apache.org/licenses Apache License Version 2.0, January 2004
 * @license http://opensource.org/licenses/MIT MIT License
 */
class QOMWalker
{
    private NodeTypeManagerInterface $nodeTypeManager;

    /**
     * @var array<string, string>
     */
    private array $alias = [];

    private ?QOM\SourceInterface $source = null;

    private Connection $conn;

    private ?AbstractPlatform $platform;

    /**
     * @var string[]
     */
    private array $namespaces;

    public function __construct(NodeTypeManagerInterface $manager, Connection $conn, array $namespaces = [])
    {
        $this->conn = $conn;
        $this->nodeTypeManager = $manager;
        $this->platform = $conn->getDatabasePlatform();
        $this->namespaces = $namespaces;
    }

    /**
     * Generate a table alias.
     */
    private function getTableAlias(string $selectorName): string
    {
        $selectorAlias = $this->getSelectorAlias($selectorName);

        if (!array_key_exists($selectorAlias, $this->alias)) {
            $this->alias[$selectorAlias] = 'n'.count($this->alias);
        }

        return $this->alias[$selectorAlias];
    }

    private function getSelectorAlias(?string $selectorName): string
    {
        if (null === $selectorName) {
            if (count($this->alias)) { // We have aliases, use the first
                $selectorAlias = array_search('n0', $this->alias, true);
            } else { // Currently no aliases, use an empty string as index
                $selectorAlias = '';
            }
        } elseif (!str_contains($selectorName, '.')) {
            $selectorAlias = $selectorName;
        } else {
            $parts = explode('.', $selectorName);
            $selectorAlias = reset($parts);
        }

        if (str_starts_with($selectorAlias, '[')) {
            $selectorAlias = substr($selectorAlias, 1, -1);
        }

        if ($this->source instanceof QOM\SelectorInterface
            && $this->source->getNodeTypeName() === $selectorAlias
        ) {
            $selectorAlias = '';
        }

        return $selectorAlias;
    }

    public function walkQOMQuery(QueryObjectModel $qom): array
    {
        $source = $qom->getSource();
        $selectors = $this->validateSource($source);

        $sourceSql = ' '.$this->walkSource($source);
        $constraintSql = '';
        if ($constraint = $qom->getConstraint()) {
            $constraintSql = ' AND '.$this->walkConstraint($constraint);
        }

        $orderingSql = '';
        if ($orderings = $qom->getOrderings()) {
            $orderingSql = ' '.$this->walkOrderings($orderings);
        }

        $sql = 'SELECT '.$this->getColumns($qom);
        $sql .= $sourceSql;
        $sql .= $constraintSql;
        $sql .= $orderingSql;

        $limit = $qom->getLimit();
        $offset = $qom->getOffset();

        if (null !== $offset && null === $limit
            && ($this->platform instanceof AbstractMySQLPlatform || $this->platform instanceof SqlitePlatform)
        ) {
            $limit = PHP_INT_MAX;
        }

        $sql = $this->platform->modifyLimitQuery($sql, $limit, $offset ?: 0);

        return [$selectors, $this->alias, $sql];
    }

    public function getColumns(QueryObjectModel $qom): string
    {
        // TODO we should actually build Xpath statements for each column we actually need in the result and not fetch all 'props'
        $sqlColumns = ['path', 'identifier', 'props'];

        if (count($this->alias)) {
            $aliasSql = [];
            foreach ($this->alias as $alias) {
                foreach ($sqlColumns as $sqlColumn) {
                    $aliasSql[] = sprintf('%s.%s AS %s_%s', $alias, $sqlColumn, $alias, $sqlColumn);
                }
            }

            return implode(', ', $aliasSql);
        }

        return '*';
    }

    /**
     * Validates the nodeTypes in given source.
     *
     * @return QOM\SelectorInterface[]
     *
     * @throws InvalidQueryException
     */
    protected function validateSource(QOM\SourceInterface $source): array
    {
        if ($source instanceof QOM\SelectorInterface) {
            $this->validateSelectorSource($source);

            return [$source];
        }
        if ($source instanceof QOM\JoinInterface) {
            return $this->validateJoinSource($source);
        }

        return [];
    }

    /**
     * @throws InvalidQueryException
     */
    protected function validateSelectorSource(QOM\SelectorInterface $source): void
    {
        $nodeType = $source->getNodeTypeName();

        if (!$this->nodeTypeManager->hasNodeType($nodeType)) {
            $msg = 'Selected node type does not exist: '.$nodeType;
            if ($alias = $source->getSelectorName()) {
                $msg .= ' AS '.$alias;
            }

            throw new InvalidQueryException($msg);
        }
    }

    /**
     * @return QOM\SelectorInterface[]
     *
     * @throws InvalidQueryException
     */
    protected function validateJoinSource(QOM\JoinInterface $source): array
    {
        $left = $source->getLeft();
        $right = $source->getRight();

        if ($left) {
            $selectors = $this->validateSource($left);
        } else {
            $selectors = [];
        }

        if ($right) {
            // Ensure that the primary selector is first
            if (QOM\QueryObjectModelConstantsInterface::JCR_JOIN_TYPE_RIGHT_OUTER === $source->getJoinType()) {
                $selectors = array_merge($this->validateSource($right), $selectors);
            } else {
                $selectors = array_merge($selectors, $this->validateSource($right));
            }
        }

        return $selectors;
    }

    /**
     * @throws NotImplementedException
     */
    public function walkSource(QOM\SourceInterface $source): string
    {
        if ($source instanceof QOM\SelectorInterface) {
            return $this->walkSelectorSource($source);
        }

        if ($source instanceof QOM\JoinInterface) {
            return $this->walkJoinSource($source);
        }

        throw new NotImplementedException(sprintf("The source class '%s' is not supported", get_class($source)));
    }

    public function walkSelectorSource(QOM\SelectorInterface $source): string
    {
        $this->source = $source;
        $alias = $this->getTableAlias($source->getSelectorName());
        $nodeTypeClause = $this->sqlNodeTypeClause($alias, $source);
        $sql = "FROM phpcr_nodes $alias WHERE $alias.workspace_name = ? AND $nodeTypeClause";

        return $sql;
    }

    /**
     * @return string the alias on the right side of a join
     *
     * @throws \BadMethodCallException if the provided JoinCondition has no valid way of getting the right selector
     */
    private function getRightJoinSelector(QOM\JoinConditionInterface $right): string
    {
        if ($right instanceof QOM\ChildNodeJoinConditionInterface) {
            return $right->getParentSelectorName();
        }
        if ($right instanceof QOM\DescendantNodeJoinConditionInterface) {
            return $right->getAncestorSelectorName();
        }
        if ($right instanceof QOM\SameNodeJoinConditionInterface || $right instanceof QOM\EquiJoinConditionInterface) {
            return $right->getSelector2Name();
        }

        throw new \BadMethodCallException('Supplied join type should implement getSelector2Name() or be an instance of ChildNodeJoinConditionInterface or DescendantNodeJoinConditionInterface');
    }

    /**
     * @return string the alias on the left side of a join
     *
     * @throws \BadMethodCallException if the provided JoinCondition has no valid way of getting the left selector
     */
    private function getLeftJoinSelector(QOM\JoinConditionInterface $left): string
    {
        if ($left instanceof QOM\ChildNodeJoinConditionInterface) {
            return $left->getChildSelectorName();
        }
        if ($left instanceof QOM\DescendantNodeJoinConditionInterface) {
            return $left->getAncestorSelectorName();
        }
        if ($left instanceof QOM\SameNodeJoinConditionInterface || $left instanceof QOM\EquiJoinConditionInterface) {
            return $left->getSelector1Name();
        }

        throw new \BadMethodCallException('Supplied join type should implement getSelector2Name() or be an instance of ChildNodeJoinConditionInterface or DescendantNodeJoinConditionInterface');
    }

    /**
     * find the most left join in a tree.
     */
    private function getLeftMostJoin(QOM\JoinInterface $source): QOM\JoinInterface
    {
        if ($source->getLeft() instanceof QOM\JoinInterface) {
            return $this->getLeftMostJoin($source->getLeft());
        }

        return $source;
    }

    /**
     * @param bool $root whether the method call is recursed for nested joins. If true, it will add a WHERE clause
     *                   that checks the workspace_name and type
     *
     * @throws NotImplementedException if the right side of the join consists of another join
     */
    public function walkJoinSource(QOM\JoinInterface $source, bool $root = true): string
    {
        $this->source = $left = $source->getLeft(); // The $left variable is used for storing the leftmost selector

        if (!$source->getRight() instanceof QOM\SelectorInterface) {
            throw new NotImplementedException('The right side of the join should not consist of another join');
        }

        if ($left instanceof QOM\SelectorInterface) {
            $leftAlias = $this->getTableAlias($left->getSelectorName());
            $this->getTableAlias($left->getSelectorName());
            $sql = "FROM phpcr_nodes $leftAlias ";
        } else {
            \assert($left instanceof QOM\JoinInterface);
            $sql = $this->walkJoinSource($left, false).' '; // One step left, until we're at the selector
            $leftMostJoin = $this->getLeftMostJoin($source);
            $leftAlias = $this->getTableAlias(
                $this->getLeftJoinSelector($leftMostJoin->getJoinCondition())
            );
            $left = $leftMostJoin->getLeft();
        }
        $rightAlias = $this->getTableAlias($source->getRight()->getSelectorName());
        $right = $source->getRight();
        \assert($right instanceof QOM\SelectorInterface);
        $nodeTypeClause = $this->sqlNodeTypeClause($rightAlias, $right);

        switch ($source->getJoinType()) {
            case QOM\QueryObjectModelConstantsInterface::JCR_JOIN_TYPE_INNER:
                $sql .= sprintf('INNER JOIN phpcr_nodes %s ', $rightAlias);
                break;
            case QOM\QueryObjectModelConstantsInterface::JCR_JOIN_TYPE_LEFT_OUTER:
                $sql .= sprintf('LEFT JOIN phpcr_nodes %s ', $rightAlias);
                break;
            case QOM\QueryObjectModelConstantsInterface::JCR_JOIN_TYPE_RIGHT_OUTER:
                $sql .= sprintf('RIGHT JOIN phpcr_nodes %s ', $rightAlias);
                break;
        }

        $sql .= sprintf('ON ( %s.workspace_name = %s.workspace_name AND %s ', $leftAlias, $rightAlias, $nodeTypeClause);
        $sql .= 'AND '.$this->walkJoinCondition($left, $right, $source->getJoinCondition()).' ';
        $sql .= ') '; // close on-clause

        if ($root) {
            // The method call is not recursed when $root is true, so we can add a WHERE clause
            // TODO: revise this part for alternatives
            \assert($left instanceof QOM\SelectorInterface);
            $sql .= sprintf("WHERE %s.workspace_name = ? AND %s.type IN ('%s'", $leftAlias, $leftAlias, $left->getNodeTypeName());
            if (!$this->nodeTypeManager instanceof NodeTypeManager) {
                throw new NotImplementedException('Dont know how to get subtypes from NodeTypeManager of class '.get_class($this->nodeTypeManager));
            }
            $subTypes = $this->nodeTypeManager->getSubtypes($left->getNodeTypeName());
            foreach ($subTypes as $subType) {
                $sql .= sprintf(", '%s'", $subType->getName());
            }
            $sql .= ')';
        }

        return $sql;
    }

    /**
     * @param QOM\SelectorInterface|QOM\JoinInterface $left
     *
     * @throws NotImplementedException if a SameNodeJoinCondition is used
     */
    public function walkJoinCondition($left, QOM\SelectorInterface $right, QOM\JoinConditionInterface $condition): string
    {
        switch ($condition) {
            case $condition instanceof QOM\ChildNodeJoinConditionInterface:
                return $this->walkChildNodeJoinCondition($condition);

            case $condition instanceof QOM\DescendantNodeJoinConditionInterface:
                return $this->walkDescendantNodeJoinCondition($condition);

            case $condition instanceof QOM\EquiJoinConditionInterface:
                if ($left instanceof QOM\SelectorInterface) {
                    $selectorName = $left->getSelectorName();
                } else {
                    $selectorName = $this->getLeftJoinSelector($this->getLeftMostJoin($left)->getJoinCondition());
                }

                return $this->walkEquiJoinCondition($selectorName, $right->getSelectorName(), $condition);

            default:
                throw new NotImplementedException(get_class($condition));
        }
    }

    public function walkChildNodeJoinCondition(QOM\ChildNodeJoinConditionInterface $condition): string
    {
        $rightAlias = $this->getTableAlias($condition->getChildSelectorName());
        $leftAlias = $this->getTableAlias($condition->getParentSelectorName());
        $concatExpression = $this->platform->getConcatExpression("$leftAlias.path", "'/%'");

        return sprintf('(%s.path LIKE %s AND %s.depth = %s.depth + 1) ', $rightAlias, $concatExpression, $rightAlias, $leftAlias);
    }

    public function walkDescendantNodeJoinCondition(QOM\DescendantNodeJoinConditionInterface $condition): string
    {
        $rightAlias = $this->getTableAlias($condition->getDescendantSelectorName());
        $leftAlias = $this->getTableAlias($condition->getAncestorSelectorName());
        $concatExpression = $this->platform->getConcatExpression("$leftAlias.path", "'/%'");

        return sprintf('%s.path LIKE %s ', $rightAlias, $concatExpression);
    }

    public function walkEquiJoinCondition($leftSelectorName, $rightSelectorName, QOM\EquiJoinConditionInterface $condition): string
    {
        return $this->walkOperand(new PropertyValue($leftSelectorName, $condition->getProperty1Name())).' '.
               $this->walkOperator(QOM\QueryObjectModelConstantsInterface::JCR_OPERATOR_EQUAL_TO).' '.
               $this->walkOperand(new PropertyValue($rightSelectorName, $condition->getProperty2Name()));
    }

    /**
     * @throws InvalidQueryException
     */
    public function walkConstraint(QOM\ConstraintInterface $constraint): string
    {
        if ($constraint instanceof QOM\AndInterface) {
            return $this->walkAndConstraint($constraint);
        }
        if ($constraint instanceof QOM\OrInterface) {
            return $this->walkOrConstraint($constraint);
        }
        if ($constraint instanceof QOM\NotInterface) {
            return $this->walkNotConstraint($constraint);
        }
        if ($constraint instanceof QOM\ComparisonInterface) {
            return $this->walkComparisonConstraint($constraint);
        }
        if ($constraint instanceof QOM\DescendantNodeInterface) {
            return $this->walkDescendantNodeConstraint($constraint);
        }
        if ($constraint instanceof QOM\ChildNodeInterface) {
            return $this->walkChildNodeConstraint($constraint);
        }
        if ($constraint instanceof QOM\PropertyExistenceInterface) {
            return $this->walkPropertyExistenceConstraint($constraint);
        }
        if ($constraint instanceof QOM\SameNodeInterface) {
            return $this->walkSameNodeConstraint($constraint);
        }
        if ($constraint instanceof QOM\FullTextSearchInterface) {
            return $this->walkFullTextSearchConstraint($constraint);
        }

        throw new InvalidQueryException(sprintf('Constraint %s not yet supported.', get_class($constraint)));
    }

    public function walkSameNodeConstraint(QOM\SameNodeInterface $constraint): string
    {
        return sprintf(
            "%s.path = '%s'",
            $this->getTableAlias($constraint->getSelectorName()),
            $constraint->getPath()
        );
    }

    public function walkFullTextSearchConstraint(QOM\FullTextSearchInterface $constraint): string
    {
        $expression = $constraint->getFullTextSearchExpression();
        if (!$expression instanceof QOM\LiteralInterface) {
            throw new NotImplementedException('Expected full text search expression to be of type Literal, but got '.get_class($expression));
        }

        if (null === $constraint->getPropertyName()) {
            return sprintf('%s LIKE %s',
                $this->sqlXpathExtractValueWithNullProperty($this->getTableAlias($constraint->getSelectorName())),
                $this->conn->quote('%' . $expression->getLiteralValue() . '%')
            );
        }

        return sprintf('%s LIKE %s',
            $this->sqlXpathExtractValue(
                $this->getTableAlias($constraint->getSelectorName()),
                $constraint->getPropertyName()
            ),
            $this->conn->quote('%' . $expression->getLiteralValue() . '%')
        );
    }

    public function walkPropertyExistenceConstraint(QOM\PropertyExistenceInterface $constraint): string
    {
        return $this->sqlXpathValueExists($this->getTableAlias($constraint->getSelectorName()), $constraint->getPropertyName());
    }

    public function walkDescendantNodeConstraint(QOM\DescendantNodeInterface $constraint): string
    {
        $ancestorPath = $constraint->getAncestorPath();
        if ('/' === $ancestorPath) {
            $ancestorPath = '';
        } elseif (str_ends_with($ancestorPath, '/')) {
            throw new InvalidQueryException("Trailing slash in $ancestorPath");
        }

        return $this->getTableAlias($constraint->getSelectorName()).".path LIKE '".$ancestorPath."/%'";
    }

    public function walkChildNodeConstraint(QOM\ChildNodeInterface $constraint): string
    {
        return sprintf(
            "%s.parent = '%s'",
            $this->getTableAlias($constraint->getSelectorName()),
            addcslashes($constraint->getParentPath(), "'")
        );
    }

    public function walkAndConstraint(QOM\AndInterface $constraint): string
    {
        return sprintf(
            '(%s AND %s)',
            $this->walkConstraint($constraint->getConstraint1()),
            $this->walkConstraint($constraint->getConstraint2())
        );
    }

    public function walkOrConstraint(QOM\OrInterface $constraint): string
    {
        return sprintf(
            '(%s OR %s)',
            $this->walkConstraint($constraint->getConstraint1()),
            $this->walkConstraint($constraint->getConstraint2())
        );
    }

    public function walkNotConstraint(QOM\NotInterface $constraint): string
    {
        return sprintf(
            'NOT (%s)',
            $this->walkConstraint($constraint->getConstraint())
        );
    }

    /**
     * This method figures out the best way to do a comparison
     * When we need to compare a property with a literal value,
     * we need to be aware of the multivalued properties, we then require
     * a different xpath statement then with other comparisons.
     */
    public function walkComparisonConstraint(QOM\ComparisonInterface $constraint): string
    {
        $operator = $this->walkOperator($constraint->getOperator());

        $operator1 = $constraint->getOperand1();
        $operator2 = $constraint->getOperand2();

        // Check if we have a property and a literal value (in random order)
        if (
            ($operator1 instanceof QOM\PropertyValueInterface
                && $operator2 instanceof QOM\LiteralInterface)
            || ($operator1 instanceof QOM\LiteralInterface
                && $operator2 instanceof QOM\PropertyValueInterface)
            || ($operator1 instanceof QOM\NodeNameInterface
                && $operator2 instanceof QOM\LiteralInterface)
            || ($operator1 instanceof QOM\LiteralInterface
                && $operator2 instanceof QOM\NodeNameInterface)
        ) {
            // Check whether the left is the literal, at this point the other always is the literal/nodename operand
            if ($operator1 instanceof QOM\LiteralInterface) {
                $operand = $operator2;
                $literalOperand = $operator1;
            } else {
                $literalOperand = $operator2;
                $operand = $operator1;
            }

            if (is_string($literalOperand->getLiteralValue()) && '=' !== $operator && '!=' !== $operator) {
                return
                    $this->walkOperand($operator1).' '.
                    $operator.' '.
                    $this->walkOperand($operator2);
            }

            if ($operand instanceof QOM\NodeNameInterface) {
                $selectorName = $operand->getSelectorName();
                $alias = $this->getTableAlias($selectorName);

                $literal = $literalOperand->getLiteralValue();
                if (str_contains($literal, ':')) {
                    $parts = explode(':', $literal);
                    if (!array_key_exists($parts[0], $this->namespaces)) {
                        throw new NamespaceException('The namespace '.$parts[0].' was not registered.');
                    }

                    $parts[0] = $this->namespaces[$parts[0]];
                    $literal = implode(':', $parts);
                }

                return sprintf(
                    '%s %s %s',
                    $this->platform->getConcatExpression(
                        sprintf('%s.namespace', $alias),
                        sprintf("(CASE %s.namespace WHEN '' THEN '' ELSE ':' END)", $alias),
                        sprintf('%s.local_name', $alias)
                    ),
                    $operator,
                    $this->conn->quote($literal)
                );
            }

            if ('jcr:path' !== $operand->getPropertyName() && 'jcr:uuid' !== $operand->getPropertyName()) {
                if (is_int($literalOperand->getLiteralValue()) || is_float($literalOperand->getLiteralValue())) {
                    return $this->walkNumComparisonConstraint($operand, $literalOperand, $operator);
                }
                if (is_bool($literalOperand->getLiteralValue())) {
                    return $this->walkBoolComparisonConstraint($operand, $literalOperand, $operator);
                }

                return $this->walkTextComparisonConstraint($operand, $literalOperand, $operator);
            }
        }

        return sprintf(
            '%s %s %s',
            $this->walkOperand($operator1),
            $operator,
            $this->walkOperand($operator2)
        );
    }

    public function walkTextComparisonConstraint(QOM\PropertyValueInterface $propertyOperand, QOM\LiteralInterface $literalOperand, string $operator): string
    {
        $alias = $this->getTableAlias($propertyOperand->getSelectorName().'.'.$propertyOperand->getPropertyName());
        $property = $propertyOperand->getPropertyName();

        return $this->sqlXpathComparePropertyValue($alias, $property, $this->getLiteralValue($literalOperand), $operator);
    }

    public function walkBoolComparisonConstraint(QOM\PropertyValueInterface $propertyOperand, QOM\LiteralInterface $literalOperand, string $operator): string
    {
        $value = true === $literalOperand->getLiteralValue() ? '1' : '0';

        return $this->walkOperand($propertyOperand).' '.$operator.' '.$this->conn->quote($value);
    }

    public function walkNumComparisonConstraint(QOM\PropertyValueInterface $propertyOperand, QOM\LiteralInterface $literalOperand, string $operator): string
    {
        $alias = $this->getTableAlias($propertyOperand->getSelectorName().'.'.$propertyOperand->getPropertyName());
        $property = $propertyOperand->getPropertyName();

        if ($this->platform instanceof AbstractMySQLPlatform && '=' === $operator) {
            return sprintf(
                "0 != FIND_IN_SET('%s', REPLACE(EXTRACTVALUE(%s.props, '//sv:property[@sv:name=%s]/sv:value'), ' ', ','))",
                $literalOperand->getLiteralValue(),
                $alias,
                Xpath::escape($property)
            );
        }

        if ('=' === $operator) {
            return $this->sqlXpathComparePropertyValue($alias, $property, $literalOperand->getLiteralValue(), $operator);
        }

        return sprintf(
            '%s %s %s',
            $this->sqlXpathExtractNumValue($alias, $property),
            $operator,
            $literalOperand->getLiteralValue()
        );
    }

    public function walkOperator(string $operator): string
    {
        if (QOM\QueryObjectModelConstantsInterface::JCR_OPERATOR_EQUAL_TO === $operator) {
            return '=';
        }
        if (QOM\QueryObjectModelConstantsInterface::JCR_OPERATOR_GREATER_THAN === $operator) {
            return '>';
        }
        if (QOM\QueryObjectModelConstantsInterface::JCR_OPERATOR_GREATER_THAN_OR_EQUAL_TO === $operator) {
            return '>=';
        }
        if (QOM\QueryObjectModelConstantsInterface::JCR_OPERATOR_LESS_THAN === $operator) {
            return '<';
        }
        if (QOM\QueryObjectModelConstantsInterface::JCR_OPERATOR_LESS_THAN_OR_EQUAL_TO === $operator) {
            return '<=';
        }
        if (QOM\QueryObjectModelConstantsInterface::JCR_OPERATOR_NOT_EQUAL_TO === $operator) {
            return '!=';
        }
        if (QOM\QueryObjectModelConstantsInterface::JCR_OPERATOR_LIKE === $operator) {
            return 'LIKE';
        }

        return $operator; // no-op for simplicity, not standard conform (but using the constants is a pain)
    }

    /**
     * @throws InvalidQueryException
     */
    public function walkOperand(QOM\OperandInterface $operand): string
    {
        if ($operand instanceof QOM\NodeNameInterface) {
            $selectorName = $operand->getSelectorName();
            $alias = $this->getTableAlias($selectorName);

            return $this->platform->getConcatExpression(
                sprintf('%s.namespace', $alias),
                sprintf("(CASE %s.namespace WHEN '' THEN '' ELSE ':' END)", $alias),
                sprintf('%s.local_name', $alias)
            );
        }

        if ($operand instanceof QOM\NodeLocalNameInterface) {
            $selectorName = $operand->getSelectorName();
            $alias = $this->getTableAlias($selectorName);

            return sprintf('%s.local_name', $alias);
        }

        if ($operand instanceof QOM\LowerCaseInterface) {
            return $this->platform->getLowerExpression($this->walkOperand($operand->getOperand()));
        }

        if ($operand instanceof QOM\UpperCaseInterface) {
            return $this->platform->getUpperExpression($this->walkOperand($operand->getOperand()));
        }

        if ($operand instanceof QOM\LiteralInterface) {
            return $this->conn->quote($this->getLiteralValue($operand));
        }

        if ($operand instanceof QOM\PropertyValueInterface) {
            $alias = $this->getTableAlias($operand->getSelectorName().'.'.$operand->getPropertyName());
            $property = $operand->getPropertyName();
            if ('jcr:path' === $property) {
                return sprintf('%s.path', $alias);
            }
            if ('jcr:uuid' === $property) {
                return sprintf('%s.identifier', $alias);
            }

            return $this->sqlXpathExtractValue($alias, $property);
        }

        if ($operand instanceof QOM\LengthInterface) {
            $alias = $this->getTableAlias($operand->getPropertyValue()->getSelectorName());
            $property = $operand->getPropertyValue()->getPropertyName();

            return $this->sqlXpathExtractValueAttribute($alias, $property, 'length');
        }

        throw new InvalidQueryException(sprintf('Dynamic operand %s not yet supported.', get_class($operand)));
    }

    /**
     * @param QOM\OrderingInterface[] $orderings
     */
    public function walkOrderings(array $orderings): string
    {
        $sql = '';
        foreach ($orderings as $ordering) {
            $sql .= empty($sql) ? 'ORDER BY ' : ', ';
            $sql .= $this->walkOrdering($ordering);
        }

        return $sql;
    }

    public function walkOrdering(QOM\OrderingInterface $ordering): string
    {
        $direction = $ordering->getOrder();
        if (QOM\QueryObjectModelConstantsInterface::JCR_ORDER_ASCENDING === $direction) {
            $direction = 'ASC';
        } elseif (QOM\QueryObjectModelConstantsInterface::JCR_ORDER_DESCENDING === $direction) {
            $direction = 'DESC';
        }

        $sql = $this->walkOperand($ordering->getOperand());

        if ($ordering->getOperand() instanceof QOM\PropertyValueInterface) {
            $operand = $ordering->getOperand();
            $property = $ordering->getOperand()->getPropertyName();
            if ('jcr:path' !== $property && 'jcr:uuid' !== $property) {
                $alias = $this->getTableAlias($operand->getSelectorName().'.'.$property);

                $numericalSelector = $this->sqlXpathExtractValue($alias, $property, 'numerical_props');

                $sql = sprintf(
                    'CAST(%s AS DECIMAL) %s, %s',
                    $numericalSelector,
                    $direction,
                    $sql
                );
            }
        }

        $sql .= ' '.$direction;

        return $sql;
    }

    /**
     * @throws NamespaceException
     */
    private function getLiteralValue(QOM\LiteralInterface $operand): string
    {
        $value = $operand->getLiteralValue();

        /*
         * Normalize Dates to UTC
         */
        if ($value instanceof \DateTime) {
            $valueUTC = clone $value;
            $valueUTC->setTimezone(new \DateTimeZone('UTC'));

            return $valueUTC->format('c');
        }

        return $value;
    }

    /**
     * SQL to execute an XPATH expression checking if the property exist on the node with the given alias.
     */
    private function sqlXpathValueExists(string $alias, string $property): string
    {
        if ($this->platform instanceof AbstractMySQLPlatform) {
            return sprintf("EXTRACTVALUE(%s.props, 'count(//sv:property[@sv:name=%s]/sv:value[1])') = 1", $alias, Xpath::escape($property));
        }

        if ($this->platform instanceof PostgreSQL94Platform || $this->platform instanceof PostgreSQLPlatform) {
            return sprintf("xpath_exists('//sv:property[@sv:name=%s]/sv:value[1]', CAST(%s.props AS xml), ".$this->sqlXpathPostgreSQLNamespaces().") = 't'", Xpath::escape($property), $alias);
        }

        if ($this->platform instanceof SqlitePlatform) {
            return sprintf("EXTRACTVALUE(%s.props, 'count(//sv:property[@sv:name=%s]/sv:value[1])') = 1", $alias, Xpath::escape($property));
        }

        throw new NotImplementedException(sprintf("Xpath evaluations cannot be executed with '%s' yet.", $this->platform->getName()));
    }

    /**
     * SQL to execute an XPATH expression extracting the property value on the node with the given alias.
     */
    private function sqlXpathExtractValue(string $alias, string $property, string $column = 'props'): string
    {
        if ($this->platform instanceof AbstractMySQLPlatform) {
            return sprintf("EXTRACTVALUE(%s.%s, '//sv:property[@sv:name=%s]/sv:value[1]')", $alias, $column, Xpath::escape($property));
        }

        if ($this->platform instanceof PostgreSQL94Platform || $this->platform instanceof PostgreSQLPlatform) {
            return sprintf("(xpath('//sv:property[@sv:name=%s]/sv:value[1]/text()', CAST(%s.%s AS xml), %s))[1]::text", Xpath::escape($property), $alias, $column, $this->sqlXpathPostgreSQLNamespaces());
        }

        if ($this->platform instanceof SqlitePlatform) {
            return sprintf("EXTRACTVALUE(%s.%s, '//sv:property[@sv:name=%s]/sv:value[1]')", $alias, $column, Xpath::escape($property));
        }

        throw new NotImplementedException(sprintf("Xpath evaluations cannot be executed with '%s' yet.", $this->platform->getName()));
    }

    private function sqlXpathExtractValueWithNullProperty(string $alias): string
    {
        if ($this->platform instanceof AbstractMySQLPlatform) {
            return sprintf("EXTRACTVALUE(%s.props, '//sv:value')", $alias);
        }

        if ($this->platform instanceof PostgreSQL94Platform || $this->platform instanceof PostgreSQLPlatform) {
            return sprintf(
                "(xpath('/sv:value/text()', CAST(%s.props AS xml), %s))[1]::text",
                $alias,
                $this->sqlXpathPostgreSQLNamespaces()
            );
        }

        if ($this->platform instanceof SqlitePlatform) {
            return sprintf("EXTRACTVALUE(%s.props, '//sv:value')", $alias);
        }

        throw new NotImplementedException(
            sprintf("Xpath evaluations cannot be executed with '%s' yet.", $this->platform->getName())
        );
    }

    private function sqlXpathExtractNumValue(string $alias, string $property): string
    {
        if ($this->platform instanceof PostgreSQL94Platform || $this->platform instanceof PostgreSQLPlatform) {
            return sprintf("(xpath('//sv:property[@sv:name=%s]/sv:value[1]/text()', CAST(%s.props AS xml), %s))[1]::text::int", Xpath::escape($property), $alias, $this->sqlXpathPostgreSQLNamespaces());
        }

        return sprintf('CAST(%s AS DECIMAL)', $this->sqlXpathExtractValue($alias, $property));
    }

    private function sqlXpathExtractValueAttribute(string $alias, string $property, string $attribute, int $valueIndex = 1): string
    {
        if ($this->platform instanceof AbstractMySQLPlatform) {
            return sprintf("EXTRACTVALUE(%s.props, '//sv:property[@sv:name=%s]/sv:value[%d]/@%s')", $alias, Xpath::escape($property), $valueIndex, $attribute);
        }

        if ($this->platform instanceof PostgreSQL94Platform || $this->platform instanceof PostgreSQLPlatform) {
            return sprintf("CAST((xpath('//sv:property[@sv:name=%s]/sv:value[%d]/@%s', CAST(%s.props AS xml), %s))[1]::text AS bigint)", Xpath::escape($property), $valueIndex, $attribute, $alias, $this->sqlXpathPostgreSQLNamespaces());
        }

        if ($this->platform instanceof SqlitePlatform) {
            return sprintf("EXTRACTVALUE(%s.props, '//sv:property[@sv:name=%s]/sv:value[%d]/@%s')", $alias, Xpath::escape($property), $valueIndex, $attribute);
        }

        throw new NotImplementedException(sprintf("Xpath evaluations cannot be executed with '%s' yet.", $this->platform->getName()));
    }

    /**
     * @throws NotImplementedException if the storage backend is neither mysql
     *                                 nor postgres nor sqlite
     */
    private function sqlXpathComparePropertyValue(string $alias, string $property, string $value, string $operator): string
    {
        $expression = null;

        if ($this->platform instanceof AbstractMySQLPlatform) {
            $expression = sprintf("EXTRACTVALUE(%s.props, 'count(//sv:property[@sv:name=%s]/sv:value[text()%%s%%s]) > 0')", $alias, Xpath::escape($property));
            // mysql does not escape the backslashes for us, while postgres and sqlite do
            $value = Xpath::escapeBackslashes($value);
        } elseif ($this->platform instanceof PostgreSQL94Platform || $this->platform instanceof PostgreSQLPlatform) {
            $expression = sprintf("xpath_exists('//sv:property[@sv:name=%s]/sv:value[text()%%s%%s]', CAST(%s.props AS xml), %s) = 't'", Xpath::escape($property), $alias, $this->sqlXpathPostgreSQLNamespaces());
        } elseif ($this->platform instanceof SqlitePlatform) {
            $expression = sprintf("EXTRACTVALUE(%s.props, 'count(//sv:property[@sv:name=%s]/sv:value[text()%%s%%s]) > 0')", $alias, Xpath::escape($property));
        } else {
            throw new NotImplementedException(sprintf("Xpath evaluations cannot be executed with '%s' yet.", $this->platform->getName()));
        }

        return sprintf($expression, $this->walkOperator($operator), Xpath::escape($value));
    }

    private function sqlXpathPostgreSQLNamespaces(): string
    {
        return "ARRAY[ARRAY['sv', 'http://www.jcp.org/jcr/sv/1.0']]";
    }

    private function sqlNodeTypeClause(string $alias, QOM\SelectorInterface $source): string
    {
        $sql = sprintf("%s.type IN ('%s'", $alias, $source->getNodeTypeName());

        if (!$this->nodeTypeManager instanceof NodeTypeManager) {
            throw new NotImplementedException('Dont know how to get subtypes from NodeTypeManager of class '.get_class($this->nodeTypeManager));
        }
        $subTypes = $this->nodeTypeManager->getSubtypes($source->getNodeTypeName());
        foreach ($subTypes as $subType) {
            $sql .= sprintf(", '%s'", $subType->getName());
        }
        $sql .= ')';

        return $sql;
    }
}
