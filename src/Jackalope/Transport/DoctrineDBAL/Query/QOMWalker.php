<?php

namespace Jackalope\Transport\DoctrineDBAL\Query;

use PHPCR\NamespaceException;
use PHPCR\NodeType\NodeTypeManagerInterface;
use PHPCR\Query\InvalidQueryException;
use PHPCR\Query\QOM;

use Jackalope\NotImplementedException;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MySqlPlatform;
use Doctrine\DBAL\Platforms\PostgreSqlPlatform;
use Doctrine\DBAL\Platforms\SqlitePlatform;

/**
 * Converts QOM to SQL Statements for the Doctrine DBAL database backend.
 *
 * @license http://www.apache.org/licenses/LICENSE-2.0  Apache License Version 2.0, January 2004
 */
class QOMWalker
{
    /**
     * @var NodeTypeManagerInterface
     */
    private $nodeTypeManager;

    /**
     * @var array
     */
    private $alias = array();

    /**
     * @var \Doctrine\DBAL\Connection
     */
    private $conn;

    /**
     * @var AbstractPlatform
     */
    private $platform;

    private $namespaces;

    /**
     * @param \PHPCR\NodeType\NodeTypeManagerInterface $manager
     * @param Connection $conn
     * @param array $namespaces
     */
    public function __construct(NodeTypeManagerInterface $manager, Connection $conn, array $namespaces = array())
    {
        $this->conn = $conn;
        $this->nodeTypeManager = $manager;
        $this->platform = $conn->getDatabasePlatform();
        $this->namespaces = $namespaces;
    }

    /**
     * @param $selectorName
     * @return string
     */
    private function getTableAlias($selectorName)
    {
        if (strpos($selectorName, ".") === false) {
            return "n";
        }

        $selectorAlias = reset(explode(".", $selectorName));
        if (!isset($this->alias[$selectorAlias])) {
            $this->alias[$selectorAlias] = "n" . count($this->alias);
        }

        return $this->alias[$selectorAlias];
    }

    /**
     * @param \PHPCR\Query\QOM\QueryObjectModelInterface $qom
     * @return string
     */
    public function walkQOMQuery(QOM\QueryObjectModelInterface $qom)
    {
        $sql = "SELECT";
        $sql .= " " . $this->walkColumns($qom->getColumns());
        $sql .= " " . $this->walkSource($qom->getSource());
        if ($constraint = $qom->getConstraint()) {
            $sql .= " AND " . $this->walkConstraint($constraint);
        }
        if ($orderings = $qom->getOrderings()) {
            $sql .= " " . $this->walkOrderings($orderings);
        }

        return $sql;
    }

    /**
     * @param $columns
     * @return string
     */
    public function walkColumns($columns)
    {
        $sql = '';
        if ($columns) {
            foreach ($columns as $column) {
                $sql .= $this->walkColumn($column);
            }
        }

        if ('' === trim($sql)) {
            $sql = '*';
        }

        return $sql;
    }

    /*
     * @return string
     */
    public function walkColumn(QOM\ColumnInterface $column)
    {
        return '';
    }

    /**
     * @param \PHPCR\Query\QOM\SourceInterface $source
     * @return string
     * @throws \Jackalope\NotImplementedException
     */
    public function walkSource(QOM\SourceInterface $source)
    {
        if (!($source instanceof QOM\SelectorInterface)) {
            throw new NotImplementedException("Only Selector Sources are supported.");
        }

        $sql = "FROM phpcr_nodes n ".
               "WHERE n.workspace_name = ? AND n.type IN ('" . $source->getNodeTypeName() ."'";

        $subTypes = $this->nodeTypeManager->getSubtypes($source->getNodeTypeName());
        foreach ($subTypes as $subType) {
            /* @var $subType \PHPCR\NodeType\NodeTypeInterface */
            $sql .= ", '" . $subType->getName() . "'";
        }
        $sql .= ')';

        return $sql;
    }

    /**
     * @param \PHPCR\Query\QOM\ConstraintInterface $constraint
     * @return string
     * @throws \PHPCR\Query\InvalidQueryException
     */
    public function walkConstraint(QOM\ConstraintInterface $constraint)
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

        throw new InvalidQueryException("Constraint " . get_class($constraint) . " not yet supported.");
    }

    /**
     * @param \PHPCR\Query\QOM\SameNodeInterface $constraint
     * @return string
     */
    public function walkSameNodeConstraint(QOM\SameNodeInterface $constraint)
    {
        return $this->getTableAlias($constraint->getSelectorName()) . ".path = '" . $constraint->getPath() . "'";
    }

    /**
     * @param \PHPCR\Query\QOM\FullTextSearchInterface $constraint
     * @return string
     */
    public function walkFullTextSearchConstraint(QOM\FullTextSearchInterface $constraint)
    {
        return $this->sqlXpathExtractValue($this->getTableAlias($constraint->getSelectorName()), $constraint->getPropertyName()).' LIKE '. $this->conn->quote('%'.$constraint->getFullTextSearchExpression().'%');
    }

    /**
     * @param QOM\PropertyExistenceInterface $constraint
     */
    public function walkPropertyExistenceConstraint(QOM\PropertyExistenceInterface $constraint)
    {
        return $this->sqlXpathValueExists($this->getTableAlias($constraint->getSelectorName()), $constraint->getPropertyName());
    }

    /**
     * @param QOM\DescendantNodeInterface $constraint
     * @return string
     */
    public function walkDescendantNodeConstraint(QOM\DescendantNodeInterface $constraint)
    {
        $ancestorPath = $constraint->getAncestorPath();
        if ('/' === $ancestorPath) {
            $ancestorPath = '';
        } elseif (substr($ancestorPath, -1) === '/') {
            throw new InvalidQueryException("Trailing slash in $ancestorPath");
        }

        return $this->getTableAlias($constraint->getSelectorName()) . ".path LIKE '" . $ancestorPath . "/%'";
    }

    /**
     * @param \PHPCR\Query\QOM\ChildNodeInterface $constraint
     * @return string
     */
    public function walkChildNodeConstraint(QOM\ChildNodeInterface $constraint)
    {
        return $this->getTableAlias($constraint->getSelectorName()) . ".parent = '" . $constraint->getParentPath() . "'";
    }

    /**
     * @param QOM\AndInterface $constraint
     * @return string
     */
    public function walkAndConstraint(QOM\AndInterface $constraint)
    {
        return "(" . $this->walkConstraint($constraint->getConstraint1()) . " AND " . $this->walkConstraint($constraint->getConstraint2()) . ")";
    }

    /**
     * @param QOM\OrInterface $constraint
     * @return string
     */
    public function walkOrConstraint(QOM\OrInterface $constraint)
    {
        return "(" . $this->walkConstraint($constraint->getConstraint1()) . " OR " . $this->walkConstraint($constraint->getConstraint2()) . ")";
    }

    /**
     * @param QOM\NotInterface $constraint
     * @return string
     */
    public function walkNotConstraint(QOM\NotInterface $constraint)
    {
        return "NOT (" . $this->walkConstraint($constraint->getConstraint()) . ")";
    }

    /**
     * @param QOM\ComparisonInterface $constraint
     */
    public function walkComparisonConstraint(QOM\ComparisonInterface $constraint)
    {
        return $this->walkOperand($constraint->getOperand1()) . " " .
               $this->walkOperator($constraint->getOperator()) . " " .
               $this->walkOperand($constraint->getOperand2());
    }

    /**
     * @param string $operator
     * @return string
     */
    public function walkOperator($operator)
    {
        if ($operator == QOM\QueryObjectModelConstantsInterface::JCR_OPERATOR_EQUAL_TO) {
            return "=";
        }
        if ($operator == QOM\QueryObjectModelConstantsInterface::JCR_OPERATOR_GREATER_THAN) {
            return ">";
        }
        if ($operator == QOM\QueryObjectModelConstantsInterface::JCR_OPERATOR_GREATER_THAN_OR_EQUAL_TO) {
            return ">=";
        }
        if ($operator == QOM\QueryObjectModelConstantsInterface::JCR_OPERATOR_LESS_THAN) {
            return "<";
        }
        if ($operator == QOM\QueryObjectModelConstantsInterface::JCR_OPERATOR_LESS_THAN_OR_EQUAL_TO) {
            return "<=";
        }
        if ($operator == QOM\QueryObjectModelConstantsInterface::JCR_OPERATOR_NOT_EQUAL_TO) {
            return "!=";
        }
        if ($operator == QOM\QueryObjectModelConstantsInterface::JCR_OPERATOR_LIKE) {
            return "LIKE";
        }

        return $operator; // no-op for simplicity, not standard conform (but using the constants is a pain)
    }

    /**
     * @param QOM\OperandInterface $operand
     */
    public function walkOperand(QOM\OperandInterface $operand)
    {
        if ($operand instanceof QOM\NodeNameInterface) {
            $selector = $operand->getSelectorName();
            $alias = $this->getTableAlias($selector);

            return $this->platform->getConcatExpression("$alias.namespace", "(CASE $alias.namespace WHEN '' THEN '' ELSE ':' END)", "$alias.local_name");
        }
        if ($operand instanceof QOM\NodeLocalNameInterface) {
            $selector = $operand->getSelectorName();
            $alias = $this->getTableAlias($selector);

            return "$alias.local_name";
        }
        if ($operand instanceof QOM\LowerCaseInterface) {
            return $this->platform->getLowerExpression($this->walkOperand($operand->getOperand()));
        }
        if ($operand instanceof QOM\UpperCaseInterface) {
            return $this->platform->getUpperExpression($this->walkOperand($operand->getOperand()));
        }
        if ($operand instanceof QOM\LiteralInterface) {
            $namespace = '';
            $literal = trim($operand->getLiteralValue(), '"');
            if (($aliasLength = strpos($literal, ':')) !== false) {
                $alias = substr($literal, 0, $aliasLength);
                if (!isset($this->namespaces[$alias])) {
                    throw new NamespaceException('the namespace ' . $alias . ' was not registered.');
                }
                if (!empty($this->namespaces[$alias])) {
                    $namespace = $this->namespaces[$alias].':';
                }

                $literal = substr($literal, $aliasLength + 1);
            }

            return $this->conn->quote($namespace.$literal);
        }
        if ($operand instanceof QOM\PropertyValueInterface) {
            $alias = $this->getTableAlias($operand->getSelectorName());
            $property = $operand->getPropertyName();
            if ($property == "jcr:path") {
                return $alias . ".path";
            }
            if ($property == "jcr:uuid") {
                return $alias . ".identifier";
            }

            return $this->sqlXpathExtractValue($alias, $property);
        }
        if ($operand instanceof QOM\LengthInterface) {
            $alias = $this->getTableAlias($operand->getPropertyValue()->getSelectorName());
            $property = $operand->getPropertyValue()->getPropertyName();
            if ($property == "jcr:path") {
                return $alias . ".path";
            }
            if ($property == "jcr:uuid") {
                return $alias . ".identifier";
            }

            return $this->sqlXpathExtractValue($alias, $property);
        }

        throw new InvalidQueryException("Dynamic operand " . get_class($operand) . " not yet supported.");
    }

    /**
     * @param array $orderings
     * @return string
     */
    public function walkOrderings(array $orderings)
    {
        $sql = '';
        foreach ($orderings as $ordering) {
            $sql .= empty($sql) ? "ORDER BY " : ", ";
            $sql .= $this->walkOrdering($ordering);
        }
        return $sql;
    }

    /**
     * @param \PHPCR\Query\QOM\OrderingInterface $ordering
     * @return string
     */
    public function walkOrdering(QOM\OrderingInterface $ordering)
    {
        return $this->walkOperand($ordering->getOperand()) . " " .
               (($ordering->getOrder() == QOM\QueryObjectModelConstantsInterface::JCR_ORDER_ASCENDING) ? "ASC" : "DESC");
    }

    /**
     * SQL to execute an XPATH expression checking if the property exist on the node with the given alias.
     *
     * @param string $alias
     * @param string $property
     * @return string
     */
    private function sqlXpathValueExists($alias, $property)
    {
        if ($this->platform instanceof MySqlPlatform) {
            return "EXTRACTVALUE($alias.props, 'count(//sv:property[@sv:name=\"" . $property . "\"]/sv:value[1])') = 1";
        }
        if ($this->platform instanceof PostgreSqlPlatform) {
            return "xpath_exists('//sv:property[@sv:name=\"" . $property . "\"]/sv:value[1]', CAST($alias.props AS xml), ".$this->sqlXpathPostgreSQLNamespaces().") = 't'";
        }
        if ($this->platform instanceof SqlitePlatform) {
            return "EXTRACTVALUE($alias.props, 'count(//sv:property[@sv:name=\"" . $property . "\"]/sv:value[1])') = 1";
        }

        throw new NotImplementedException("Xpath evaluations cannot be executed with '" . $this->platform->getName() . "' yet.");
    }

    /**
     * SQL to execute an XPATH expression extracting the property value on the node with the given alias.
     *
     * @param string $alias
     * @param string $property
     * @return string
     */
    private function sqlXpathExtractValue($alias, $property)
    {
        if ($this->platform instanceof MySqlPlatform) {
            return "EXTRACTVALUE($alias.props, '//sv:property[@sv:name=\"" . $property . "\"]/sv:value[1]')";
        }
        if ($this->platform instanceof PostgreSqlPlatform) {
            return "(xpath('//sv:property[@sv:name=\"" . $property . "\"]/sv:value[1]/text()', CAST($alias.props AS xml), ".$this->sqlXpathPostgreSQLNamespaces()."))[1]::text";
        }
        if ($this->platform instanceof SqlitePlatform) {
            return "EXTRACTVALUE($alias.props, '//sv:property[@sv:name=\"" . $property . "\"]/sv:value[1]')";
        }

        throw new NotImplementedException("Xpath evaluations cannot be executed with '" . $this->platform->getName() . "' yet.");
    }

    /**
     * @return string
     */
    private function sqlXpathPostgreSQLNamespaces()
    {
        return "ARRAY[ARRAY['sv', 'http://www.jcp.org/jcr/sv/1.0']]";
    }
}
