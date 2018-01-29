<?php

namespace Jackalope\Transport\DoctrineDBAL;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Table;
use PDO;
use PHPCR\RepositoryException;

/**
 * Class to handle setup the RDBMS tables for the Doctrine DBAL transport.
 *
 * @license http://www.apache.org/licenses Apache License Version 2.0, January 2004
 * @license http://opensource.org/licenses/MIT MIT License
 */
class RepositorySchema extends Schema
{
    /**
     * @var Connection
     */
    private $connection;

    /**
     * @var integer
     */
    private $maxIndexLength = -1;

    /**
     * @param array $options The options could be use to make the table
     *                               names configurable.
     * @param Connection $connection
     */
    public function __construct(array $options = [], Connection $connection = null)
    {
        $this->connection = $connection;
        $schemaConfig = null === $connection ? null : $connection->getSchemaManager()->createSchemaConfig();

        parent::__construct([], [], $schemaConfig);

        $this->options = $options;

        $this->addNamespacesTable();
        $this->addWorkspacesTable();
        $nodes = $this->addNodesTable();
        $this->addInternalIndexTypesTable();
        $this->addBinaryDataTable();
        $this->addNodesReferencesTable($nodes);
        $this->addNodesWeakreferencesTable($nodes);
        $this->addTypeNodesTable();
        $this->addTypePropsTable();
        $this->addTypeChildsTable();
    }

    /**
     * Merges Jackalope schema with the given schema.
     *
     * @param Schema $schema
     */
    public function addToSchema(Schema $schema)
    {
        foreach ($this->getTables() as $table) {
            $schema->_addTable($table);
        }

        foreach ($this->getSequences() as $sequence) {
            $schema->_addSequence($sequence);
        }
    }

    protected function addNamespacesTable()
    {
        $namespace = $this->createTable('phpcr_namespaces');
        $namespace->addColumn('prefix', 'string', ['length' => $this->getMaxIndexLength()]);
        $namespace->addColumn('uri', 'string');
        $namespace->setPrimaryKey(['prefix']);
    }

    protected function addWorkspacesTable()
    {
        $workspace = $this->createTable('phpcr_workspaces');
        $workspace->addColumn('name', 'string', ['length' => $this->getMaxIndexLength()]);
        $workspace->setPrimaryKey(['name']);
    }

    /**
     * @return Table
     */
    protected function addNodesTable()
    {
        // TODO increase the size of 'path' and 'parent' but this causes issues on MySQL due to key length
        $nodes = $this->createTable('phpcr_nodes');
        $nodes->addColumn('id', 'integer', ['autoincrement' => true]);
        $nodes->addColumn('path', 'string', ['length' => $this->getMaxIndexLength()]);
        $nodes->addColumn('parent', 'string', ['length' => $this->getMaxIndexLength()]);
        $nodes->addColumn('local_name', 'string', ['length' => $this->getMaxIndexLength()]);
        $nodes->addColumn('namespace', 'string', ['length' => $this->getMaxIndexLength()]);
        $nodes->addColumn('workspace_name', 'string', ['length' => $this->getMaxIndexLength()]);
        $nodes->addColumn('identifier', 'string', ['length' => $this->getMaxIndexLength()]);
        $nodes->addColumn('type', 'string', ['length' => $this->getMaxIndexLength()]);
        $nodes->addColumn('props', 'text');
        $nodes->addColumn('numerical_props', 'text', ['notnull' => false]);
        $nodes->addColumn('depth', 'integer');
        $nodes->addColumn('sort_order', 'integer', ['notnull' => false]);
        $nodes->setPrimaryKey(['id']);
        $nodes->addUniqueIndex(['path', 'workspace_name']);
        $nodes->addUniqueIndex(['identifier', 'workspace_name']);
        $nodes->addIndex(['parent']);
        $nodes->addIndex(['type']);
        $nodes->addIndex(['local_name', 'namespace']);

        return $nodes;
    }

    protected function addInternalIndexTypesTable()
    {
        $indexJcrTypes = $this->createTable('phpcr_internal_index_types');
        $indexJcrTypes->addColumn('type', 'string', ['length' => $this->getMaxIndexLength()]);
        $indexJcrTypes->addColumn('node_id', 'integer', ['length' => $this->getMaxIndexLength()]);
        $indexJcrTypes->setPrimaryKey(['type', 'node_id']);
    }

    protected function addBinaryDataTable()
    {
        $binary = $this->createTable('phpcr_binarydata');
        $binary->addColumn('id', 'integer', ['autoincrement' => true]);
        $binary->addColumn('node_id', 'integer');
        $binary->addColumn('property_name', 'string', ['length' => $this->getMaxIndexLength()]);
        $binary->addColumn('workspace_name', 'string', ['length' => $this->getMaxIndexLength()]);
        $binary->addColumn('idx', 'integer', ['default' => 0]);
        $binary->addColumn('data', 'blob');
        $binary->setPrimaryKey(['id']);
        $binary->addUniqueIndex(['node_id', 'property_name', 'workspace_name', 'idx']);
    }

    protected function addNodesReferencesTable(Table $nodes)
    {
        $references = $this->createTable('phpcr_nodes_references');
        $references->addColumn('source_id', 'integer');
        $references->addColumn('source_property_name', 'string', ['length' => $this->getMaxIndexLength(220)]);
        $references->addColumn('target_id', 'integer');
        $references->setPrimaryKey(['source_id', 'source_property_name', 'target_id']);
        $references->addIndex(['target_id']);
        if (!empty($this->options['disable_fk'])) {
            $references->addForeignKeyConstraint($nodes, ['source_id'], ['id'], ['onDelete' => 'CASCADE']);
            // TODO: this should be reenabled on RDBMS with deferred FK support
            //$references->addForeignKeyConstraint($nodes, array('target_id'), array('id'));
        }
    }

    protected function addNodesWeakreferencesTable(Table $nodes)
    {
        $weakreferences = $this->createTable('phpcr_nodes_weakreferences');
        $weakreferences->addColumn('source_id', 'integer');
        $weakreferences->addColumn('source_property_name', 'string', ['length' => $this->getMaxIndexLength(220)]);
        $weakreferences->addColumn('target_id', 'integer');
        $weakreferences->setPrimaryKey(['source_id', 'source_property_name', 'target_id']);
        $weakreferences->addIndex(['target_id']);
        if (!empty($this->options['disable_fk'])) {
            $weakreferences->addForeignKeyConstraint($nodes, ['source_id'], ['id'], ['onDelete' => 'CASCADE']);
            $weakreferences->addForeignKeyConstraint($nodes, ['target_id'], ['id'], ['onDelete' => 'CASCADE']);
        }
    }

    protected function addTypeNodesTable()
    {
        $types = $this->createTable('phpcr_type_nodes');
        $types->addColumn('node_type_id', 'integer', ['autoincrement' => true]);
        $types->addColumn('name', 'string', ['length' => $this->getMaxIndexLength()]);
        $types->addColumn('supertypes', 'string');
        $types->addColumn('is_abstract', 'boolean');
        $types->addColumn('is_mixin', 'boolean');
        $types->addColumn('queryable', 'boolean');
        $types->addColumn('orderable_child_nodes', 'boolean');
        $types->addColumn('primary_item', 'string', ['notnull' => false]);
        $types->setPrimaryKey(['node_type_id']);
        $types->addUniqueIndex(['name']);
    }

    protected function addTypePropsTable()
    {
        $propTypes = $this->createTable('phpcr_type_props');
        $propTypes->addColumn('node_type_id', 'integer');
        $propTypes->addColumn('name', 'string', ['length' => $this->getMaxIndexLength()]);
        $propTypes->addColumn('protected', 'boolean');
        $propTypes->addColumn('auto_created', 'boolean');
        $propTypes->addColumn('mandatory', 'boolean');
        $propTypes->addColumn('on_parent_version', 'integer');
        $propTypes->addcolumn('multiple', 'boolean');
        $propTypes->addColumn('fulltext_searchable', 'boolean');
        $propTypes->addcolumn('query_orderable', 'boolean');
        $propTypes->addColumn('required_type', 'integer');
        $propTypes->addColumn('query_operators', 'integer'); // BITMASK
        $propTypes->addColumn('default_value', 'string', ['notnull' => false]);
        $propTypes->setPrimaryKey(['node_type_id', 'name']);
    }

    protected function addTypeChildsTable()
    {
        $childTypes = $this->createTable('phpcr_type_childs');
        $childTypes->addColumn('node_type_id', 'integer');
        $childTypes->addColumn('name', 'string');
        $childTypes->addColumn('protected', 'boolean');
        $childTypes->addColumn('auto_created', 'boolean');
        $childTypes->addColumn('mandatory', 'boolean');
        $childTypes->addColumn('on_parent_version', 'integer');
        $childTypes->addColumn('primary_types', 'string');
        $childTypes->addColumn('default_type', 'string', ['notnull' => false]);
    }

    public function reset()
    {
        if (null === $this->connection) {
            throw new RepositoryException('Do not use RepositorySchema::reset when not instantiated with a connection');
        }

        $this->connection->getWrappedConnection()->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
        foreach ($this->toDropSql($this->connection->getDatabasePlatform()) as $sql) {
            $this->connection->exec($sql);
        }

        $this->connection->getWrappedConnection()->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach ($this->toSql($this->connection->getDatabasePlatform()) as $sql) {
            $this->connection->exec($sql);
        }
    }

    private function getMaxIndexLength($currentMaxLength = null)
    {
        if (-1 === $this->maxIndexLength) {
            $this->maxIndexLength = null;

            if ($this->isConnectionCharsetUtf8mb4()) {
                $this->maxIndexLength = 191;
            }
        }

        if ($currentMaxLength && (
            null === $this->maxIndexLength
            || $currentMaxLength < $this->maxIndexLength
        )) {
            return $currentMaxLength;
        }
        
        return $this->maxIndexLength;
    }

    private function isConnectionCharsetUtf8mb4()
    {
        if (!$this->connection) {
            return false;
        }

        $databaseParameters = $this->connection->getParams();

        return isset($databaseParameters['charset']) && 'utf8mb4' === strtolower($databaseParameters['charset']);
    }
}
