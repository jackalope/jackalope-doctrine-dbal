<?php

namespace Jackalope\Transport\DoctrineDBAL;

use PHPCR\LoginException;
use PHPCR\NodeType\NodeDefinitionInterface;
use PHPCR\NodeType\NodeTypeDefinitionInterface;
use PHPCR\NodeType\NodeTypeExistsException;
use PHPCR\NodeType\PropertyDefinitionInterface;
use PHPCR\PropertyInterface;
use PHPCR\RepositoryInterface;
use PHPCR\NamespaceRegistryInterface;
use PHPCR\CredentialsInterface;
use PHPCR\PropertyType;
use PHPCR\Query\QOM\QueryObjectModelInterface;
use PHPCR\Query\QOM\SelectorInterface;
use PHPCR\Query\QueryInterface;
use PHPCR\RepositoryException;
use PHPCR\NamespaceException;
use PHPCR\NoSuchWorkspaceException;
use PHPCR\ItemExistsException;
use PHPCR\ItemNotFoundException;
use PHPCR\ReferentialIntegrityException;
use PHPCR\SimpleCredentials;
use PHPCR\ValueFormatException;
use PHPCR\PathNotFoundException;
use PHPCR\Query\InvalidQueryException;
use PHPCR\NodeType\ConstraintViolationException;

use PHPCR\Util\QOM\Sql2ToQomQueryConverter;
use PHPCR\Util\ValueConverter;
use PHPCR\Util\UUIDHelper;
use PHPCR\Util\PathHelper;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\PDOConnection;
use Doctrine\DBAL\DBALException;
use Doctrine\DBAL\Platforms\PostgreSqlPlatform;
use Doctrine\DBAL\Platforms\SqlitePlatform;

use Jackalope\Node;
use Jackalope\Property;
use Jackalope\Query\Query;
use Jackalope\Transport\AddNodeOperation;
use Jackalope\Transport\MoveNodeOperation;
use Jackalope\Transport\BaseTransport;
use Jackalope\Transport\QueryInterface as QueryTransport;
use Jackalope\Transport\WritingInterface;
use Jackalope\Transport\WorkspaceManagementInterface;
use Jackalope\Transport\NodeTypeManagementInterface;
use Jackalope\Transport\TransactionInterface;
use Jackalope\Transport\StandardNodeTypes;
use Jackalope\Transport\DoctrineDBAL\Query\QOMWalker;
use Jackalope\NodeType\NodeTypeManager;
use Jackalope\NodeType\NodeType;
use Jackalope\NodeType\NodeTypeDefinition;
use Jackalope\FactoryInterface;
use Jackalope\NotImplementedException;

/**
 * Class to handle the communication between Jackalope and RDBMS via Doctrine DBAL.
 *
 * @license http://www.apache.org/licenses Apache License Version 2.0, January 2004
 * @license http://opensource.org/licenses/MIT MIT License
 *
 * @author Benjamin Eberlei <kontakt@beberlei.de>
 * @author Lukas Kahwe Smith <smith@pooteeweet.org>
 */
class Client extends BaseTransport implements QueryTransport, WritingInterface, WorkspaceManagementInterface, NodeTypeManagementInterface, TransactionInterface
{
    /**
     * The factory to instantiate objects
     * @var FactoryInterface
     */
    protected $factory;

    /**
     * @var ValueConverter
     */
    protected $valueConverter;

    /**
     * @var Connection
     */
    private $conn;

    /**
     * @var \Closure
     */
    private $uuidGenerator;

    /**
     * @var bool
     */
    private $loggedIn = false;

    /**
     * @var SimpleCredentials
     */
    private $credentials;

    /**
     * @var string
     */
    protected $workspaceName;

    /**
     * @var array
     */
    private $nodeIdentifiers = array();

    /**
     * @var NodeTypeManager
     */
    private $nodeTypeManager;

    /**
     * @var bool
     */
    protected $inTransaction = false;

    /**
     * Check if an initial request on login should be send to check if repository exists
     * This is according to the JCR specifications and set to true by default
     * @see setCheckLoginOnServer
     * @var bool
     */
    private $checkLoginOnServer = true;

    /**
     * @var array
     */
    protected $namespaces = array();

    /**
     * @var string|null
     */
    private $sequenceWorkspaceName;

    /**
     * @var string|null
     */
    private $sequenceNodeName;

    /**
     * @var string|null
     */
    private $sequenceTypeName;

    /**
     * @var array
     */
    private $referencesToUpdate = array();

    /**
     * @var array
     */
    private $referenceTables = array(
        PropertyType::REFERENCE => 'phpcr_nodes_references',
        PropertyType::WEAKREFERENCE => 'phpcr_nodes_weakreferences',
    );

    /**
     * @var array
     */
    private $referencesToDelete = array();

    /**
     * @var array
     */
    private $additionalNodeAddOperations = array();

    /**
     * @param FactoryInterface $factory
     * @param Connection       $conn
     */
    public function __construct(FactoryInterface $factory, Connection $conn)
    {
        $this->factory = $factory;
        $this->valueConverter = $this->factory->get('PHPCR\Util\ValueConverter');
        $this->conn = $conn;
        if ($conn->getDatabasePlatform() instanceof PostgreSqlPlatform) {
            $this->sequenceWorkspaceName = 'phpcr_workspaces_id_seq';
            $this->sequenceNodeName = 'phpcr_nodes_id_seq';
            $this->sequenceTypeName = 'phpcr_type_nodes_node_type_id_seq';
        }

        // @TODO: move to "SqlitePlatform" and rename to "registerExtraFunctions"?
        if ($this->conn->getDatabasePlatform() instanceof SqlitePlatform) {
            $this->registerSqliteFunctions($this->conn->getWrappedConnection());
        }
    }

    /**
     * @TODO: move to "SqlitePlatform" and rename to "registerExtraFunctions"?
     *
     * @param PDOConnection $sqliteConnection
     *
     * @return Client
     */
    private function registerSqliteFunctions(PDOConnection $sqliteConnection)
    {
        $sqliteConnection->sqliteCreateFunction('EXTRACTVALUE', function ($string, $expression) {
            $dom = new \DOMDocument('1.0', 'UTF-8');
            $dom->loadXML($string);
            $xpath = new \DOMXPath($dom);
            $list = $xpath->evaluate($expression);

            if (!is_object($list)) {
                return $list;
            }

            // @TODO: don't know if there are expressions returning more then one row
            if ($list->length > 0) {
                // @TODO: why it can happen that we do not have a type? https://github.com/phpcr/phpcr-api-tests/pull/132
                $type = is_object($list->item(0)->parentNode->attributes->getNamedItem('type'))
                    ? $list->item(0)->parentNode->attributes->getNamedItem('type')->value
                    : null;
                $content = $list->item(0)->textContent;

                switch ($type) {
                    case 'long':
                        return (int) $content;
                        break;
                    case 'double':
                        return (double) $content;
                        break;
                    default:
                        return $content;
                }
            }

            // @TODO: don't know if return value is right
            return null;
        }, 2);

        $sqliteConnection->sqliteCreateFunction('CONCAT', function () {
            return implode('', func_get_args());
        });

        return $this;
    }

    /**
     * @return Connection
     */
    public function getConnection()
    {
        return $this->conn;
    }

    /**
     * Set the UUID generator to use. If not set, the phpcr-utils UUIDHelper
     * will be used.
     *
     * @param callable $generator
     */
    public function setUuidGenerator(\Closure $generator)
    {
        $this->uuidGenerator = $generator;
    }

    /**
     * @return callable a uuid generator function.
     */
    protected function getUuidGenerator()
    {
        if (!$this->uuidGenerator) {
            $this->uuidGenerator = function() {
                return UUIDHelper::generateUUID();
            };
        }

        return $this->uuidGenerator;
    }

    /**
     * @return string a universally unique id.
     */
    protected function generateUuid()
    {
        // php 5.3 compatibility, no direct execution of this function.
        $g = $this->getUuidGenerator();

        return $g();
    }

    /**
     * {@inheritDoc}
     *
     */
    public function createWorkspace($name, $srcWorkspace = null)
    {
        if (null !== $srcWorkspace) {
            throw new NotImplementedException('Creating workspace as clone of existing workspace not supported');
        }

        if ($this->workspaceExists($name)) {
            throw new RepositoryException("Workspace '$name' already exists");
        }

        try {
            $this->conn->insert('phpcr_workspaces', array('name' => $name));
        } catch (\Exception $e) {
            throw new RepositoryException("Couldn't create Workspace '$name': ".$e->getMessage(), 0, $e);
        }

        $this->conn->insert('phpcr_nodes', array(
            'path'          => '/',
            'parent'        => '',
            'workspace_name'=> $name,
            'identifier'    => $this->generateUuid(),
            'type'          => 'nt:unstructured',
            'local_name'    => '',
            'namespace'     => '',
            'props' => '<?xml version="1.0" encoding="UTF-8"?>
<sv:node xmlns:mix="http://www.jcp.org/jcr/mix/1.0" xmlns:nt="http://www.jcp.org/jcr/nt/1.0" xmlns:xs="http://www.w3.org/2001/XMLSchema" xmlns:jcr="http://www.jcp.org/jcr/1.0" xmlns:sv="http://www.jcp.org/jcr/sv/1.0" xmlns:rep="internal" />',
            // TODO compute proper value
            'depth'         => 0,
        ));
    }

    /**
     * {@inheritDoc}
     */
    public function deleteWorkspace($name)
    {
        if (!$this->workspaceExists($name)) {
            throw new RepositoryException("Workspace '$name' cannot be
            deleted as it does not exist");
        }

        try {
            $this->conn->delete('phpcr_workspaces', array('name' => $name));
        } catch (\Exception $e) {
            throw new RepositoryException("Couldn't delete workspace '$name': "
            .$e->getMessage(), 0, $e);
        }

        try {
            $this->conn->delete('phpcr_nodes', array('workspace_name'=>
            $name));
        } catch (\Exception $e) {
            throw new RepositoryException("Couldn't delete nodes in workspace
            '$name': ".$e->getMessage(), 0, $e);
        }

        try {
            $this->conn->delete('phpcr_binarydata', array('workspace_name'=>
            $name));
        } catch (\Exception $e) {
            throw new RepositoryException("Couldn't delete binary data in
            workspace '$name': ".$e->getMessage(), 0, $e);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function login(CredentialsInterface $credentials = null, $workspaceName = null)
    {
        $this->credentials = $credentials;

        $this->workspaceName = $workspaceName ?: 'default';
        if (!$this->checkLoginOnServer) {
            return $this->workspaceName;
        }

        if (!$this->workspaceExists($this->workspaceName)) {
            if ('default' !== $this->workspaceName) {
                throw new NoSuchWorkspaceException("Requested workspace: '{$this->workspaceName}'");
            }

            // create default workspace if it not exists
            $this->createWorkspace($this->workspaceName);
        }

        $this->loggedIn = true;

        return $this->workspaceName;
    }

    /**
     * {@inheritDoc}
     */
    public function logout()
    {
        if ($this->loggedIn) {
            $this->loggedIn = false;
            $this->conn->close();
            $this->conn = null;
        }
    }

    /**
     * Configure whether to check if we are logged in before doing a request.
     *
     * Will improve error reporting at the cost of some round trips.
     */
    public function setCheckLoginOnServer($bool)
    {
        $this->checkLoginOnServer = $bool;
    }

    protected function workspaceExists($workspaceName)
    {
        try {
            $query = 'SELECT 1 FROM phpcr_workspaces WHERE name = ?';
            $result = $this->conn->fetchColumn($query, array($workspaceName));
        } catch (\Exception $e) {
            if ($e instanceof DBALException || $e instanceof \PDOException) {
                if (1045 == $e->getCode()) {
                    throw new LoginException('Access denied with your credentials: '.$e->getMessage());
                }
                if ('42S02' == $e->getCode()) {
                    throw new RepositoryException('You did not properly set up the database for the repository. See README.md for more information. Message from backend: '.$e->getMessage());
                }

                throw new RepositoryException('Unexpected error talking to the backend: '.$e->getMessage(), 0, $e);
            }

            throw $e;
        }

        return $result;
    }

    /**
     * Ensure that we are currently logged in, executing the login in case we
     * did lazy login.
     *
     * @throws RepositoryException if this transport is not logged in.
     */
    protected function assertLoggedIn()
    {
        if (!$this->loggedIn) {
            if (!$this->checkLoginOnServer && $this->workspaceName) {
                $this->checkLoginOnServer = true;
                if ($this->login($this->credentials, $this->workspaceName)) {
                    return;
                }
            }

            throw new RepositoryException('You need to be logged in for this operation');
        }
    }

    /**
     * {@inheritDoc}
     */
    public function getRepositoryDescriptors()
    {
        return array(
            RepositoryInterface::IDENTIFIER_STABILITY => RepositoryInterface::IDENTIFIER_STABILITY_INDEFINITE_DURATION,
            RepositoryInterface::REP_NAME_DESC  => 'jackalope_doctrine_dbal',
            RepositoryInterface::REP_VENDOR_DESC => 'Jackalope Community',
            RepositoryInterface::REP_VENDOR_URL_DESC => 'http://github.com/jackalope',
            RepositoryInterface::REP_VERSION_DESC => '1.1.0-DEV',
            RepositoryInterface::SPEC_NAME_DESC => 'Content Repository for PHP',
            RepositoryInterface::SPEC_VERSION_DESC => '2.1',
            RepositoryInterface::NODE_TYPE_MANAGEMENT_AUTOCREATED_DEFINITIONS_SUPPORTED => true,
            RepositoryInterface::NODE_TYPE_MANAGEMENT_INHERITANCE => RepositoryInterface::NODE_TYPE_MANAGEMENT_INHERITANCE_SINGLE,
            RepositoryInterface::NODE_TYPE_MANAGEMENT_MULTIPLE_BINARY_PROPERTIES_SUPPORTED => true,
            RepositoryInterface::NODE_TYPE_MANAGEMENT_MULTIVALUED_PROPERTIES_SUPPORTED => true,
            RepositoryInterface::NODE_TYPE_MANAGEMENT_ORDERABLE_CHILD_NODES_SUPPORTED => true,
            RepositoryInterface::NODE_TYPE_MANAGEMENT_OVERRIDES_SUPPORTED => false,
            RepositoryInterface::NODE_TYPE_MANAGEMENT_PRIMARY_ITEM_NAME_SUPPORTED => true,
            RepositoryInterface::NODE_TYPE_MANAGEMENT_PROPERTY_TYPES => true,
            RepositoryInterface::NODE_TYPE_MANAGEMENT_RESIDUAL_DEFINITIONS_SUPPORTED => false,
            RepositoryInterface::NODE_TYPE_MANAGEMENT_SAME_NAME_SIBLINGS_SUPPORTED => false,
            RepositoryInterface::NODE_TYPE_MANAGEMENT_UPDATE_IN_USE_SUPPORTED => false,
            RepositoryInterface::NODE_TYPE_MANAGEMENT_VALUE_CONSTRAINTS_SUPPORTED => false,
            RepositoryInterface::OPTION_ACCESS_CONTROL_SUPPORTED => false,
            RepositoryInterface::OPTION_ACTIVITIES_SUPPORTED => false,
            RepositoryInterface::OPTION_BASELINES_SUPPORTED => false,
            RepositoryInterface::OPTION_JOURNALED_OBSERVATION_SUPPORTED => false,
            RepositoryInterface::OPTION_LIFECYCLE_SUPPORTED => false,
            RepositoryInterface::OPTION_LOCKING_SUPPORTED => false,
            RepositoryInterface::OPTION_NODE_AND_PROPERTY_WITH_SAME_NAME_SUPPORTED => true,
            RepositoryInterface::OPTION_NODE_TYPE_MANAGEMENT_SUPPORTED => true,
            RepositoryInterface::OPTION_OBSERVATION_SUPPORTED => false,
            RepositoryInterface::OPTION_RETENTION_SUPPORTED => false,
            RepositoryInterface::OPTION_SHAREABLE_NODES_SUPPORTED => false,
            RepositoryInterface::OPTION_SIMPLE_VERSIONING_SUPPORTED => false,
            RepositoryInterface::OPTION_TRANSACTIONS_SUPPORTED => true,
            RepositoryInterface::OPTION_UNFILED_CONTENT_SUPPORTED => true,
            RepositoryInterface::OPTION_UPDATE_MIXIN_NODETYPES_SUPPORTED => true,
            RepositoryInterface::OPTION_UPDATE_PRIMARY_NODETYPE_SUPPORTED => true,
            RepositoryInterface::OPTION_VERSIONING_SUPPORTED => false,
            RepositoryInterface::OPTION_WORKSPACE_MANAGEMENT_SUPPORTED => true,
            RepositoryInterface::OPTION_XML_EXPORT_SUPPORTED => true,
            RepositoryInterface::OPTION_XML_IMPORT_SUPPORTED => true,
            RepositoryInterface::QUERY_FULL_TEXT_SEARCH_SUPPORTED => true,
            RepositoryInterface::QUERY_CANCEL_SUPPORTED => false,
            RepositoryInterface::QUERY_JOINS => RepositoryInterface::QUERY_JOINS_NONE,
            RepositoryInterface::QUERY_LANGUAGES => array(QueryInterface::JCR_SQL2, QueryInterface::JCR_JQOM),
            RepositoryInterface::QUERY_STORED_QUERIES_SUPPORTED => false,
            RepositoryInterface::WRITE_SUPPORTED => true,
        );
    }

    /**
     * {@inheritDoc}
     */
    public function getNamespaces()
    {
        if (empty($this->namespaces)) {
            $query = 'SELECT * FROM phpcr_namespaces';
            $data = $this->conn->fetchAll($query);

            $this->namespaces = array(
                NamespaceRegistryInterface::PREFIX_EMPTY => NamespaceRegistryInterface::NAMESPACE_EMPTY,
                NamespaceRegistryInterface::PREFIX_JCR => NamespaceRegistryInterface::NAMESPACE_JCR,
                NamespaceRegistryInterface::PREFIX_NT => NamespaceRegistryInterface::NAMESPACE_NT,
                NamespaceRegistryInterface::PREFIX_MIX => NamespaceRegistryInterface::NAMESPACE_MIX,
                NamespaceRegistryInterface::PREFIX_XML => NamespaceRegistryInterface::NAMESPACE_XML,
                NamespaceRegistryInterface::PREFIX_SV => NamespaceRegistryInterface::NAMESPACE_SV,
            );

            foreach ($data as $row) {
                $this->namespaces[$row['prefix']] = $row['uri'];
            }
        }

        return $this->namespaces;
    }

    /**
     * Executes an UPDATE on DBAL while ensuring that we never try to send more than 999 parameters to SQLite
     *
     * @param $query
     * @param array $params
     * @throws DBALException
     */
    private function executeChunkedUpdate($query, array $params)
    {
        $types = array(Connection::PARAM_INT_ARRAY);
        if ($this->conn->getDatabasePlatform() instanceof SqlitePlatform) {
            foreach (array_chunk($params, 999) as $chunk) {
                $this->conn->executeUpdate($query, array($chunk), $types);
            }
        } else {
            $this->conn->executeUpdate($query, array($params), $types);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function copyNode($srcAbsPath, $dstAbsPath, $srcWorkspace = null)
    {
        $this->assertLoggedIn();
        
        if (null !== $srcWorkspace && !$this->workspaceExists($srcWorkspace)) {
            throw new NoSuchWorkspaceException("Source workspace '$srcWorkspace' does not exist.");
        }
        $srcWorkspace = $srcWorkspace ?: $this->workspaceName;

        PathHelper::assertValidAbsolutePath($dstAbsPath, true);

        $srcNodeId = $this->pathExists($srcAbsPath, $srcWorkspace);
        if (!$srcNodeId) {
            throw new PathNotFoundException("Source path '$srcAbsPath' not found");
        }

        if ($this->pathExists($dstAbsPath)) {
            throw new ItemExistsException("Cannot copy to destination path '$dstAbsPath' that already exists.");
        }

        if (!$this->pathExists(PathHelper::getParentPath($dstAbsPath))) {
            throw new PathNotFoundException("Parent of the destination path '" . $dstAbsPath . "' has to exist.");
        }

        // Algorithm:
        // 1. Select all nodes with path $srcAbsPath."%" and iterate them
        // 2. create a new node with path $dstAbsPath + leftovers, with a new uuid. Save old => new uuid
        // 3. copy all properties from old node to new node
        // 4. if a reference is in the properties, either update the uuid based on the map if its inside the copied graph or keep it.
        // 5. "May drop mixin types"

        $query = 'SELECT * FROM phpcr_nodes WHERE path LIKE ? AND workspace_name = ?';
        $stmt = $this->conn->executeQuery($query, array($srcAbsPath . '%', $srcWorkspace));

        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $newPath = str_replace($srcAbsPath, $dstAbsPath, $row['path']);

            $dom = new \DOMDocument('1.0', 'UTF-8');
            $dom->loadXML($row['props']);

            $propsData = array('dom' => $dom);
            // when copying a node, the copy is always a new node. set $isNewNode to true
            $newNodeId = $this->syncNode(null, $newPath, $row['type'], true, array(), $propsData);

            $query = 'INSERT INTO phpcr_binarydata (node_id, property_name, workspace_name, idx, data)'.
                '   SELECT ?, b.property_name, ?, b.idx, b.data FROM phpcr_binarydata b WHERE b.node_id = ?';

            try {
                $this->conn->executeUpdate($query, array($newNodeId, $this->workspaceName, $srcNodeId));
            } catch (DBALException $e) {
                throw new RepositoryException("Unexpected exception while copying node from $srcAbsPath to $dstAbsPath", $e->getCode(), $e);
            }
        }
    }

    /**
     * @param string $path
     *
     * @return array
     */
    private function getJcrName($path)
    {
        $name = implode('', array_slice(explode('/', $path), -1, 1));
        if (($aliasLength = strpos($name, ':')) !== false) {
            $alias = substr($name, 0, $aliasLength);
            $name = substr($name, $aliasLength + 1);
        } else {
            $alias = '';
        }

        $namespaces = $this->getNamespaces();

        if (!isset($namespaces[$alias])) {
            throw new NamespaceException('the namespace ' . $alias . ' was not registered.');
        }

        return array($namespaces[$alias], $name);
    }

    /**
     * Actually write the node into the database
     *
     * @param string  $uuid      node uuid
     * @param string  $path      absolute path of the node
     * @param string  $type      node type
     * @param boolean $isNewNode new nodes to insert (true) or existing node to update (false)
     * @param array   $props
     * @param array   $propsData
     *
     * @return boolean|string
     *
     * @throws ItemExistsException
     * @throws RepositoryException
     */
    private function syncNode($uuid, $path, $type, $isNewNode, $props = array(), $propsData = array())
    {
        // TODO: Not sure if there are always ALL props in $props, should we grab the online data here?
        // TODO: PERFORMANCE Binary data is handled very inefficiently here, UPSERT will really be necessary here as well as lazy handling

        if (!$propsData) {
            $propsData = $this->propsToXML($props);
        }

        if (null === $uuid) {
            $uuid = $this->generateUuid();
        }

        if ($isNewNode) {
            list($namespace, $localName) = $this->getJcrName($path);

            $qb = $this->conn->createQueryBuilder();

            $qb->select(':identifier, :type, :path, :local_name, :namespace, :parent, :workspace_name, :props, :depth, COALESCE(MAX(n.sort_order), 0) + 1')
                ->from('phpcr_nodes', 'n')
                ->where('n.parent = :parent_a');

            $sql = $qb->getSql();

            try {
                $insert = "INSERT INTO phpcr_nodes (identifier, type, path, local_name, namespace, parent, workspace_name, props, depth, sort_order) " . $sql;
                $this->conn->executeUpdate($insert, array(
                    'identifier'    => $uuid,
                    'type'          => $type,
                    'path'          => $path,
                    'local_name'    => $localName,
                    'namespace'     => $namespace,
                    'parent'        => PathHelper::getParentPath($path),
                    'workspace_name'  => $this->workspaceName,
                    'props'         => $propsData['dom']->saveXML(),
                    'depth'         => PathHelper::getPathDepth($path),
                    'parent_a'      => PathHelper::getParentPath($path),
                ));
            } catch(\Exception $e) {
                if ($e instanceof \PDOException || $e instanceof DBALException) {
                   if(strpos($e->getMessage(), "SQLSTATE[23") !== false) {
                       throw new ItemExistsException('Item ' . $path . ' already exists in the database');
                   } else {
                       throw new RepositoryException('Unknown database error while inserting item ' . $path . ': '.$e->getMessage(), 0, $e);
                   }
                } else {
                    throw $e;
                }
            }

            $nodeId = $this->conn->lastInsertId($this->sequenceNodeName);
        } else {
            $nodeId = $this->pathExists($path);
            if (!$nodeId) {
                throw new RepositoryException("nodeId for $path not found");
            }
            $this->conn->update('phpcr_nodes', array('props' => $propsData['dom']->saveXML()), array('id' => $nodeId));
        }

        $this->nodeIdentifiers[$path] = $uuid;

        if (!empty($propsData['binaryData'])) {
            $this->syncBinaryData($nodeId, $propsData['binaryData']);
        }

        if (!empty($propsData['references'])) {
            $this->referencesToUpdate[$nodeId] = array('path' => $path, 'properties' => $propsData['references']);
        }

        return $nodeId;
    }

    private function syncBinaryData($nodeId, $binaryData)
    {
        foreach ($binaryData as $propertyName => $binaryValues) {
            foreach ($binaryValues as $idx => $data) {
                // TODO verify in which cases we can just update
                $params = array(
                    'node_id'       => $nodeId,
                    'property_name' => $propertyName,
                    'workspace_name'  => $this->workspaceName,
                );
                $this->conn->delete('phpcr_binarydata', $params);

                $params['idx'] = $idx;
                $params['data'] = $data;
                $types = array(
                    \PDO::PARAM_INT,
                    \PDO::PARAM_STR,
                    \PDO::PARAM_STR,
                    \PDO::PARAM_INT,
                    \PDO::PARAM_LOB
                );
                $this->conn->insert('phpcr_binarydata', $params, $types);
            }
        }
    }

    private function syncReferences()
    {
        if ($this->referencesToUpdate) {
            // do not update references that are going to be deleted anyways
            $toUpdate = array_diff_assoc($this->referencesToUpdate, $this->referencesToDelete);

            try {
                foreach ($this->referenceTables as $table) {
                    $query = "DELETE FROM $table WHERE source_id IN (?)";
                    $this->executeChunkedUpdate($query, array_keys($toUpdate));
                }
            } catch (DBALException $e) {
                throw new RepositoryException('Unexpected exception while cleaning up after saving', $e->getCode(), $e);
            }

            foreach ($toUpdate as $nodeId => $references) {
                foreach ($references['properties'] as $name => $data) {
                    foreach ($data['values'] as $value) {
                        try {
                            $params = array(
                                'source_id' => $nodeId,
                                'source_property_name' => $name,
                                'target_id' => $this->pathExists(self::getNodePathForIdentifier($value)),
                            );

                            $this->conn->insert($this->referenceTables[$data['type']], $params);
                        } catch (ItemNotFoundException $e) {
                            if (PropertyType::REFERENCE === $data['type']) {
                                throw new ReferentialIntegrityException(
                                    "Trying to store reference to non-existant node with path '$value' in node '{$references['path']}' property '$name'."
                                );
                            }
                        }
                    }
                }
            }
        }

        // TODO on RDBMS that support deferred FKs we could skip this step
        if ($this->referencesToDelete) {
            $params = array_keys($this->referencesToDelete);

            // remove all PropertyType::REFERENCE with a source_id on a deleted node
            try {
                $query = "DELETE FROM phpcr_nodes_references WHERE source_id IN (?)";
                $this->executeChunkedUpdate($query, $params);
            } catch (DBALException $e) {
                throw new RepositoryException('Unexpected exception while cleaning up deleted nodes', $e->getCode(), $e);
            }

            // ensure that there are no PropertyType::REFERENCE pointing to nodes that will be deleted
            // Note: due to the outer join we cannot filter on workspace_name, but this is ok
            // since within a transaction there can never be missing referenced nodes within the current workspace
            // make sure the target node is not in the list of nodes being deleted, to allow deletion in same request
            $query = 'SELECT DISTINCT r.target_id
            FROM phpcr_nodes_references r
                LEFT OUTER JOIN phpcr_nodes n ON r.target_id = n.id
            WHERE r.target_id IN (?)';

            if ($this->conn->getDatabasePlatform() instanceof SqlitePlatform) {
                $missingTargets = array();
                foreach (array_chunk($params, 999) as $chunk) {
                    $stmt = $this->conn->executeQuery($query, array($chunk), array(Connection::PARAM_INT_ARRAY));
                    $missingTargets = array_merge($missingTargets, $stmt->fetchAll(\PDO::FETCH_COLUMN));
                }
            } else {
                $stmt = $this->conn->executeQuery($query, array($params), array(Connection::PARAM_INT_ARRAY));
                $missingTargets = $stmt->fetchAll(\PDO::FETCH_COLUMN);
            }
            if ($missingTargets) {
                $paths = array();
                foreach ($missingTargets as $id) {
                    if (isset($this->referencesToDelete[$id])) {
                        $paths[] = $this->referencesToDelete[$id];
                    }
                }

                throw new ReferentialIntegrityException("Cannot delete '".implode("', '", $paths)."': A reference points to this node or a subnode");
            }

            // clean up all references
            try {
                foreach ($this->referenceTables as $table) {
                    $query = "DELETE FROM $table WHERE target_id IN (?)";
                    $this->executeChunkedUpdate($query, $params);
                }
            } catch (DBALException $e) {
                throw new RepositoryException('Unexpected exception while cleaning up deleted nodes', $e->getCode(), $e);
            }
        }
    }

    public static function xmlToProps($xml, ValueConverter $valueConverter, $filter = null)
    {
        $data = new \stdClass();

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->loadXML($xml);

        foreach ($dom->getElementsByTagNameNS('http://www.jcp.org/jcr/sv/1.0', 'property') as $propertyNode) {
            $name = $propertyNode->getAttribute('sv:name');

            // only return the properties that pass through the filter callback
            if (null !== $filter && is_callable($filter) && false === $filter($name)) {
                continue;
            }

            $values = array();
            $type = PropertyType::valueFromName($propertyNode->getAttribute('sv:type'));
            foreach ($propertyNode->childNodes as $valueNode) {
                switch ($type) {
                    case PropertyType::NAME:
                    case PropertyType::URI:
                    case PropertyType::WEAKREFERENCE:
                    case PropertyType::REFERENCE:
                    case PropertyType::PATH:
                    case PropertyType::DECIMAL:
                    case PropertyType::STRING:
                        $values[] = $valueNode->nodeValue;
                        break;
                    case PropertyType::BOOLEAN:
                        $values[] = (bool) $valueNode->nodeValue;
                        break;
                    case PropertyType::LONG:
                        $values[] = (int) $valueNode->nodeValue;
                        break;
                    case PropertyType::BINARY:
                        $values[] = (int) $valueNode->nodeValue;
                        break;
                    case PropertyType::DATE:
                        $date = $valueNode->nodeValue;
                        if ($date) {
                            $date = new \DateTime($date);
                            $date->setTimezone(new \DateTimeZone(date_default_timezone_get()));
                            // Jackalope expects a string, might make sense to refactor to allow \DateTime instances too
                            $date = $valueConverter->convertType($date, PropertyType::STRING);
                        }
                        $values[] = $date;
                        break;
                    case PropertyType::DOUBLE:
                        $values[] = (double) $valueNode->nodeValue;
                        break;
                    default:
                        throw new \InvalidArgumentException("Type with constant $type not found.");
                }
            }

            switch ($type) {
                case PropertyType::BINARY:
                    $data->{':' . $name} = $propertyNode->getAttribute('sv:multi-valued') ? $values : $values[0];
                    break;
                default:
                    $data->{$name} = $propertyNode->getAttribute('sv:multi-valued') ? $values : $values[0];
                    $data->{':' . $name} = $type;
                    break;
            }
        }

        return $data;
    }

    /**
     * Seperate properties array into an xml and binary data.
     *
     * @param array   $properties
     * @param boolean $inlineBinaries
     *
     * @return array ('dom' => $dom, 'binaryData' => streams, 'references' => array('type' => INT, 'values' => array(UUIDs)))
     */
    private function propsToXML($properties, $inlineBinaries = false)
    {
        $namespaces = array(
            'mix' => "http://www.jcp.org/jcr/mix/1.0",
            'nt' => "http://www.jcp.org/jcr/nt/1.0",
            'xs' => "http://www.w3.org/2001/XMLSchema",
            'jcr' => "http://www.jcp.org/jcr/1.0",
            'sv' => "http://www.jcp.org/jcr/sv/1.0",
            'rep' => "internal",
        );

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $rootNode = $dom->createElement('sv:node');
        foreach ($namespaces as $namespace => $uri) {
            $rootNode->setAttribute('xmlns:' . $namespace, $uri);
        }
        $dom->appendChild($rootNode);

        $binaryData = $references = array();
        foreach ($properties as $property) {
            /* @var $property Property */
            $propertyNode = $dom->createElement('sv:property');
            $propertyNode->setAttribute('sv:name', $property->getName());
            $propertyNode->setAttribute('sv:type', PropertyType::nameFromValue($property->getType()));
            $propertyNode->setAttribute('sv:multi-valued', $property->isMultiple() ? '1' : '0');

            switch ($property->getType()) {
                case PropertyType::WEAKREFERENCE:
                case PropertyType::REFERENCE:
                    $references[$property->getName()] = array(
                        'type' => $property->getType(),
                        'values' => $property->isMultiple() ? array_unique($property->getString()) : array($property->getString()),
                    );
                case PropertyType::NAME:
                case PropertyType::URI:
                case PropertyType::PATH:
                case PropertyType::STRING:
                    $values = $property->getString();
                    break;
                case PropertyType::DECIMAL:
                    $values = $property->getDecimal();
                    break;
                case PropertyType::BOOLEAN:
                    $values = array_map('intval', (array) $property->getBoolean());
                    break;
                case PropertyType::LONG:
                    $values = $property->getLong();
                    break;
                case PropertyType::BINARY:
                    if ($property->isNew() || $property->isModified()) {
                        $values = array();
                        foreach ((array) $property->getValueForStorage() as $stream) {
                            if (null === $stream) {
                                $binary = '';
                            } else {
                                $binary = stream_get_contents($stream);
                                fclose($stream);
                            }
                            $binaryData[$property->getName()][] = $binary;
                            $length = strlen($binary);
                            $values[] = $length;
                        }
                    } else {
                        $values = $property->getLength();
                        if (!$property->isMultiple() && empty($values)) {
                            $values = array(0);
                        }
                    }
                    break;
                case PropertyType::DATE:
                    $values = $property->getDate();
                    if ($values instanceof \DateTime) {
                        $values = array($values);
                    }
                    foreach ((array) $values as $key => $date) {
                        if ($date instanceof \DateTime) {
                            $date->setTimezone(new \DateTimeZone('UTC'));
                        }
                        $values[$key] = $date;
                    }
                    $values = $this->valueConverter->convertType($values, PropertyType::STRING);
                    break;
                case PropertyType::DOUBLE:
                    $values = $property->getDouble();
                    break;
                default:
                    throw new RepositoryException('unknown type '.$property->getType());
            }

            $lengths = (array) $property->getLength();
            foreach ((array) $values as $key => $value) {
                $element = $propertyNode->appendChild($dom->createElement('sv:value'));
                $element->appendChild($dom->createTextNode($value));
                if (isset($lengths[$key])) {
                    $lengthAttribute = $dom->createAttribute('length');
                    $lengthAttribute->value = $lengths[$key];
                    $element->appendChild($lengthAttribute);
                }
            }

            $rootNode->appendChild($propertyNode);
        }

        return array('dom' => $dom, 'binaryData' => $binaryData, 'references' => $references);
    }

    /**
     * {@inheritDoc}
     */
    public function getAccessibleWorkspaceNames()
    {
        $query = "SELECT DISTINCT name FROM phpcr_workspaces";
        $stmt = $this->conn->executeQuery($query);

        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    /**
     * {@inheritDoc}
     */
    public function getNode($path)
    {
        $this->assertLoggedIn();
        PathHelper::assertValidAbsolutePath($path);

        $values[':path'] = $path;
        $values[':pathd'] = rtrim($path,'/') . '/%';
        $values[':workspace'] = $this->workspaceName;

        if ($this->fetchDepth > 0) {
            $values[':fetchDepth'] = $this->fetchDepth;
            $query = '
              SELECT * FROM phpcr_nodes
              WHERE (path LIKE :pathd OR path = :path)
                AND workspace_name = :workspace
                AND depth <= ((SELECT depth FROM phpcr_nodes WHERE path = :path AND workspace_name = :workspace) + :fetchDepth)
              ORDER BY depth, sort_order ASC';
        } else {
            $query = '
              SELECT * FROM phpcr_nodes
              WHERE path = :path
                AND workspace_name = :workspace
              ORDER BY depth, sort_order ASC';
        }

        $stmt = $this->conn->executeQuery($query, $values);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        if (empty($rows)) {
            throw new ItemNotFoundException("Item $path not found in workspace ".$this->workspaceName);
        }

        $node = $this->getNodeData($path, array_shift($rows));
        foreach ($rows as $row) {
            $pathDiff = ltrim(substr($row['path'], strlen($path)),'/');
            $nodeNames = explode('/', $pathDiff);
            $this->nestNode($node, $this->getNodeData($row['path'], $row), $nodeNames);
        }

        return $node;
    }

    private function nestNode($parentNode, $node, array $nodeNames)
    {
        $name = array_shift($nodeNames);

        if (empty($nodeNames)) {
            $parentNode->{$name} = $node;
            return;
        }

        $this->nestNode($parentNode->{$name}, $node, $nodeNames);
    }

    private function getNodeData($path, $row)
    {
        $this->nodeIdentifiers[$path] = $row['identifier'];

        $data = self::xmlToProps($row['props'], $this->valueConverter);
        $data->{'jcr:primaryType'} = $row['type'];

        $query = 'SELECT path FROM phpcr_nodes WHERE parent = ? AND workspace_name = ? ORDER BY sort_order ASC';
        $children = $this->conn->fetchAll($query, array($path, $this->workspaceName));
        foreach ($children as $child) {
            $childName = explode('/', $child['path']);
            $childName = end($childName);
            if (!isset($data->{$childName})) {
                $data->{$childName} = new \stdClass();
            }
        }

        // If the node is referenceable, return jcr:uuid.
        if (isset($data->{"jcr:mixinTypes"})) {
            foreach ((array) $data->{"jcr:mixinTypes"} as $mixin) {
                if ($this->nodeTypeManager->getNodeType($mixin)->isNodeType('mix:referenceable')) {
                    $data->{'jcr:uuid'} = $row['identifier'];
                    break;
                }
            }
        }

        return $data;
    }

    /**
     * {@inheritDoc}
     */
    public function getNodes($paths)
    {
        $this->assertLoggedIn();

        if (empty($paths)) {
            return array();
        }

        foreach ($paths as $path) {
            PathHelper::assertValidAbsolutePath($path);
        }

        $params[':workspace'] = $this->workspaceName;

        if ($this->fetchDepth > 0) {
            $params[':fetchDepth'] = $this->fetchDepth;

            $query = '
              SELECT path AS arraykey, id, path, parent, local_name, namespace, workspace_name, identifier, type, props, depth, sort_order
              FROM phpcr_nodes
              WHERE workspace_name = :workspace
                AND (';

            $i = 0;
            foreach ($paths as $path) {
                $params[':path'.$i] = $path;
                $params[':pathd'.$i] = rtrim($path,'/') . '/%';
                $subquery = 'SELECT depth FROM phpcr_nodes WHERE path = :path'.$i.' AND workspace_name = :workspace';
                $query .= '(path LIKE :pathd'.$i.' OR path = :path'.$i.') AND depth <= ((' . $subquery . ') + :fetchDepth) OR ';
                $i++;
            }
        } else {
            $query = 'SELECT path AS arraykey, id, path, parent, local_name, namespace, workspace_name, identifier, type, props, depth, sort_order
                FROM phpcr_nodes WHERE workspace_name = :workspace AND (';

            $i = 0;
            foreach ($paths as $path) {
                $params[':path'.$i] = $path;
                $query .= 'path = :path'.$i.' OR ';
                $i++;
            }
        }

        $query = rtrim($query, 'OR ');
        $query .= ') ORDER BY sort_order ASC';

        $stmt = $this->conn->executeQuery($query, $params);
        $all = $stmt->fetchAll(\PDO::FETCH_UNIQUE | \PDO::FETCH_GROUP);

        $nodes = array();
        foreach ($all as $path => $row) {
            $nodes[$path] = $this->getNodeData($path, $row);
        }

        return $nodes;
    }

    private function pathExists($path, $workspaceName = null)
    {
        if (null === $workspaceName) {
            $workspaceName = $this->workspaceName;
        }
        
        if ($this->conn->getDriver() instanceof \Doctrine\DBAL\Driver\PDOMySql\Driver) {
            $query = 'SELECT id FROM phpcr_nodes WHERE path COLLATE utf8_bin = ? AND workspace_name = ?';
        } else {
            $query = 'SELECT id FROM phpcr_nodes WHERE path = ? AND workspace_name = ?';
        }

        if ($nodeId = $this->conn->fetchColumn($query, array($path, $workspaceName))) {
            return $nodeId;
        }

        return false;
    }

    /**
     * {@inheritDoc}
     */
    public function getNodeByIdentifier($uuid)
    {
        $this->assertLoggedIn();

        $query = 'SELECT * FROM phpcr_nodes WHERE identifier = ? AND workspace_name = ?';
        $row = $this->conn->fetchAssoc($query, array($uuid, $this->workspaceName));
        if (!$row) {
            throw new ItemNotFoundException("Item $uuid not found in workspace ".$this->workspaceName);
        }

        $path = $row['path'];
        $data = $this->getNodeData($path, $row);
        $data->{':jcr:path'} = $path;

        return $data;
    }

    /**
     * {@inheritDoc}
     */
    public function getNodesByIdentifier($identifiers)
    {
        $this->assertLoggedIn();
        if (empty($identifiers)) {
            return array();
        }

        $query = 'SELECT identifier AS arraykey, id, path, parent, local_name, namespace, workspace_name, identifier, type, props, depth, sort_order
            FROM phpcr_nodes WHERE workspace_name = ? AND identifier IN (?)';
        $params = array($this->workspaceName, $identifiers);
        $stmt = $this->conn->executeQuery($query, $params, array(\PDO::PARAM_STR, Connection::PARAM_STR_ARRAY));
        $all = $stmt->fetchAll(\PDO::FETCH_UNIQUE | \PDO::FETCH_GROUP);

        $nodes = array();
        foreach ($identifiers as $id) {
            if (isset($all[$id])) {
                $path = $all[$id]['path'];
                $nodes[$path] = $this->getNodeData($path, $all[$id]);
            }
        }

        return $nodes;
    }

    /**
     * {@inheritDoc}
     */
    public function getNodePathForIdentifier($uuid, $workspace = null)
    {
        if (null !== $workspace) {
            throw new NotImplementedException('Specifying the workspace is not yet supported.');
        }

        $this->assertLoggedIn();

        $query = "SELECT path FROM phpcr_nodes WHERE identifier = ? AND workspace_name = ?";
        $path = $this->conn->fetchColumn($query, array($uuid, $this->workspaceName));
        if (!$path) {
            throw new ItemNotFoundException("no item found with uuid ".$uuid);
        }

        return $path;
    }

    /**
     * {@inheritDoc}
     */
    public function deleteNodes(array $operations)
    {
        $this->assertLoggedIn();

        foreach ($operations as $op) {
            $this->deleteNode($op->srcPath);
        }

        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function deleteNodeImmediately($path)
    {
        $this->prepareSave();
        $this->deleteNode($path);
        $this->finishSave();

        return true;
    }

    /**
     * TODO instead of calling the deletes separately, we should batch the delete query
     * but careful with the caching!
     *
     * @param string $path node path to delete
     *
     */
    protected function deleteNode($path)
    {
        if ('/' == $path) {
            throw new ConstraintViolationException('You can not delete the root node of a repository');
        }

        $nodeId = $this->pathExists($path);
        if (!$nodeId) {
            throw new ItemNotFoundException("No node found at ".$path);
        }

        $params = array($path, $path."/%", $this->workspaceName);

        // TODO on RDBMS that support deferred FKs we could skip this step
        $query = 'SELECT id, path FROM phpcr_nodes WHERE (path = ? OR path LIKE ?) AND workspace_name = ?';
        $stmt = $this->conn->executeQuery($query, $params);
        $this->referencesToDelete += $stmt->fetchAll(\PDO::FETCH_UNIQUE | \PDO::FETCH_COLUMN);

        try {
            $query = 'DELETE FROM phpcr_nodes WHERE (path = ? OR path LIKE ?) AND workspace_name = ?';
            $this->conn->executeUpdate($query, $params);
            $this->cleanIdentifierCache($path);
        } catch (DBALException $e) {
            throw new RepositoryException('Unexpected exception while deleting node ' . $path, $e->getCode(), $e);
        }
    }

    /**
     * Clean all identifiers under path $root.
     *
     * @param string $root Path to the root node to be cleared
     */
    private function cleanIdentifierCache($root)
    {
        unset($this->nodeIdentifiers[$root]);
        foreach($this->nodeIdentifiers as $path => $uuid) {
            if (strpos($path, "$root/") === 0) {
                unset($this->nodeIdentifiers[$path]);
            }
        }
    }

    /**
     * {@inheritDoc}
     */
    public function deleteProperties(array $operations)
    {
        $this->assertLoggedIn();

        foreach ($operations as $op) {
            $this->deleteProperty($op->srcPath);
        }

        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function deletePropertyImmediately($path)
    {
        $this->prepareSave();
        $this->deleteProperty($path);
        $this->finishSave();

        return true;
    }

    /**
     * {@inheritDoc}
     */
    protected function deleteProperty($path)
    {
        $this->assertLoggedIn();

        $nodePath = PathHelper::getParentPath($path);
        $nodeId = $this->pathExists($nodePath);
        if (!$nodeId) {
            // no we really don't know that path
            throw new ItemNotFoundException("No item found at ".$path);
        }

        $query = 'SELECT props FROM phpcr_nodes WHERE id = ?';
        $xml = $this->conn->fetchColumn($query, array($nodeId));

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->loadXml($xml);

        $found = false;
        $propertyName = PathHelper::getNodeName($path);
        foreach ($dom->getElementsByTagNameNS('http://www.jcp.org/jcr/sv/1.0', 'property') as $propertyNode) {
            if ($propertyName == $propertyNode->getAttribute('sv:name')) {
                $found = true;
                // would be nice to have the property object to ask for type
                // but its in state deleted, would mean lots of refactoring
                if ($propertyNode->hasAttribute('sv:type')) {
                    $type = strtolower($propertyNode->getAttribute('sv:type'));
                    if (in_array($type, array('reference', 'weakreference'))) {
                        $table = $this->referenceTables['reference' === $type ? PropertyType::REFERENCE : PropertyType::WEAKREFERENCE];
                        try {
                            $query = "DELETE FROM $table WHERE source_id = ? AND source_property_name = ?";
                            $this->conn->executeUpdate($query, array($nodeId, $propertyName));
                        } catch (DBALException $e) {
                            throw new RepositoryException('Unexpected exception while cleaning up deleted nodes', $e->getCode(), $e);
                        }
                    }
                }
                $propertyNode->parentNode->removeChild($propertyNode);
                break;
            }
        }
        if (! $found) {
            throw new ItemNotFoundException("Node $nodePath has no property $propertyName");
        }
        $xml = $dom->saveXML();

        $query = 'UPDATE phpcr_nodes SET props = ? WHERE id = ?';
        $params = array($xml, $nodeId);

        try {
            $this->conn->executeUpdate($query, $params);
        } catch (DBALException $e) {
            throw new RepositoryException("Unexpected exception while updating properties of $path", $e->getCode(), $e);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function moveNodes(array $operations)
    {
        /** @var $op MoveNodeOperation */
        foreach ($operations as $op) {
            $this->moveNode($op->srcPath, $op->dstPath);
        }

        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function moveNodeImmediately($srcAbsPath, $dstAbspath)
    {
        $this->prepareSave();
        $this->moveNode($srcAbsPath, $dstAbspath);
        $this->finishSave();

        return true;
    }

    /**
     * Execute moving a single node
     */
    protected function moveNode($srcAbsPath, $dstAbsPath)
    {
        $this->assertLoggedIn();
        PathHelper::assertValidAbsolutePath($dstAbsPath, true);

        $srcNodeId = $this->pathExists($srcAbsPath);
        if (!$srcNodeId) {
            throw new PathNotFoundException("Source path '$srcAbsPath' not found");
        }

        if ($this->pathExists($dstAbsPath)) {
            throw new ItemExistsException("Cannot move '$srcAbsPath' to '$dstAbsPath' because destination node already exists.");
        }

        if (!$this->pathExists(PathHelper::getParentPath($dstAbsPath))) {
            throw new PathNotFoundException("Parent of the destination path '" . $dstAbsPath . "' has to exist.");
        }

        $query = 'SELECT path, id FROM phpcr_nodes WHERE path LIKE ? OR path = ? AND workspace_name = ? '
            . $this->conn->getDatabasePlatform()->getForUpdateSQL();
        $stmt = $this->conn->executeQuery($query, array($srcAbsPath . '/%', $srcAbsPath, $this->workspaceName));

        /*
         * TODO: https://github.com/jackalope/jackalope-doctrine-dbal/pull/26/files#L0R1057
         * the other thing i wonder: can't you do the replacement inside sql instead of loading and then storing
         * the node? this will be extremely slow for a large set of nodes. i think you should use query builder here
         * rather than raw sql, to make it work on a maximum of platforms.
         *
         * can you try to do this please? if we don't figure out how to do it, at least fix the where criteria, and
         * we can ask the doctrine community how to do the substring operation.
         * http://stackoverflow.com/questions/8619421/correct-syntax-for-doctrine2s-query-builder-substring-helper-method
         */

        $query               = "UPDATE phpcr_nodes SET ";
        $updatePathCase      = "path = CASE ";
        $updateParentCase    = "parent = CASE ";
        $updateLocalNameCase = "local_name = CASE ";
        $updateSortOrderCase = "sort_order = CASE ";
        $updateDepthCase     = "depth = CASE ";

        // TODO: Find a better way to do this
        // Calculate CAST type for CASE statement
        switch ($this->conn->getDatabasePlatform()->getName()) {
            case 'pgsql':
                $intType = 'integer';
                break;
            case 'mysql':
                $intType = 'unsigned';
                break;
            default:
                $intType = 'integer';
        }

        $i = 0;
        $values = $ids = array();
        $srcAbsPathPattern = '/^' . preg_quote($srcAbsPath, '/') . '/';
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $values[':id' . $i]     = $row['id'];
            $values[':path' . $i]   = preg_replace($srcAbsPathPattern, $dstAbsPath, $row['path'], 1);
            $values[':parent' . $i] = PathHelper::getParentPath($values[':path' . $i]);
            $values[':depth' . $i]  = PathHelper::getPathDepth($values[':path' . $i]);

            $updatePathCase   .= "WHEN id = :id" . $i . " THEN :path" . $i . " ";
            $updateParentCase .= "WHEN id = :id" . $i . " THEN :parent" . $i . " ";
            $updateDepthCase  .= "WHEN id = :id" . $i . " THEN CAST(:depth" . $i . " AS " . $intType . ") ";

            if ($srcAbsPath === $row['path']) {
                $values[':localname' . $i] = PathHelper::getNodeName($values[':path' . $i]);

                $updateLocalNameCase .= "WHEN id = :id" . $i . " THEN :localname" . $i . " ";
                $updateSortOrderCase .= "WHEN id = :id" . $i . " THEN (SELECT * FROM ( SELECT MAX(x.sort_order) + 1 FROM phpcr_nodes x WHERE x.parent = :parent" . $i . ") y) ";
            }

            $ids[] = $row['id'];

            $i++;
        }

        if (!$i) {
            return;
        }

        $ids = implode($ids, ',');

        $updateLocalNameCase .= "ELSE local_name END, ";
        $updateSortOrderCase .= "ELSE sort_order END ";

        $query .= $updatePathCase . "END, " . $updateParentCase . "END, " . $updateDepthCase . "END, " . $updateLocalNameCase . $updateSortOrderCase;
        $query .= "WHERE id IN (" . $ids . ")";

        try {
            $this->conn->executeUpdate($query, $values);
        } catch (DBALException $e) {
            throw new RepositoryException("Unexpected exception while moving node from $srcAbsPath to $dstAbsPath", $e->getCode(), $e);
        }

        $this->cleanIdentifierCache($srcAbsPath);
    }

    /**
     * {@inheritDoc}
     */
    public function reorderChildren(Node $node)
    {
        $this->assertLoggedIn();

        $values[':absPath'] = $node->getPath();
        $sql = "UPDATE phpcr_nodes SET sort_order = CASE CONCAT(
          namespace,
          (CASE namespace WHEN '' THEN '' ELSE ':' END),
          local_name
        )";

        $i = 0;

        foreach ($node->getNodeNames() as $name) {
            $values[':name' . $i] = $name;
            $values[':order' . $i] = $i; // use our counter to avoid gaps
            $sql .= " WHEN :name" . $i . " THEN :order" . $i;
            $i++;
        }

        $sql .= " ELSE sort_order END WHERE parent = :absPath";

        try {
            $this->conn->executeUpdate($sql, $values);
        } catch (DBALException $e) {
            throw new RepositoryException('Unexpected exception while reordering nodes', $e->getCode(), $e);
        }

        return true;
    }

    private function validateNode(Node $node)
    {
        // This is very slow i believe :-(
        $nodeDef = $node->getPrimaryNodeType();
        $nodeTypes = $node->getMixinNodeTypes();
        array_unshift($nodeTypes, $nodeDef);

        foreach ($nodeTypes as $nodeType) {
            /* @var $nodeType NodeTypeDefinitionInterface */
            $this->validateNodeWithType($node, $nodeType);
        }
    }

    /**
     * TODO: we should move that into the common Jackalope BaseTransport or as new method of NodeType
     * it will be helpful for other implementations.
     *
     * Validate this node with the nodetype and generate not yet existing
     * autogenerated properties as necessary.
     *
     * @param Node     $node
     * @param NodeType $def
     */
    private function validateNodeWithType(Node $node, NodeType $def)
    {
        foreach ($def->getDeclaredChildNodeDefinitions() as $childDef) {
            /* @var $childDef NodeDefinitionInterface */
            if (!$node->hasNode($childDef->getName())) {
                if ('*' === $childDef->getName()) {
                    continue;
                }

                if ($childDef->isMandatory() && !$childDef->isAutoCreated()) {
                    throw new RepositoryException(
                        "Child " . $childDef->getName() . " is mandatory, but is not present while ".
                            "saving " . $def->getName() . " at " . $node->getPath()
                    );
                }

                if ($childDef->isAutoCreated()) {
                    $requiredPrimaryTypeNames = $childDef->getRequiredPrimaryTypeNames();
                    $primaryType = count($requiredPrimaryTypeNames) ? current($requiredPrimaryTypeNames) : null;
                    $newNode = $node->addNode($childDef->getName(), $primaryType);
                    $absPath = $node->getPath() . '/' . $childDef->getName();
                    $operation = new AddNodeOperation($absPath, $newNode);
                    $this->additionalNodeAddOperations[] = $operation;
                }
            }
        }

        foreach ($def->getDeclaredPropertyDefinitions() as $propertyDef) {
            /* @var $propertyDef PropertyDefinitionInterface */
            if ('*' == $propertyDef->getName()) {
                continue;
            }

            if (!$node->hasProperty($propertyDef->getName())) {
                if ($propertyDef->isMandatory() && !$propertyDef->isAutoCreated()) {
                    throw new RepositoryException(
                        "Property " . $propertyDef->getName() . " is mandatory, but is not present while ".
                            "saving " . $def->getName() . " at " . $node->getPath()
                    );
                }
                if ($propertyDef->isAutoCreated()) {
                    switch ($propertyDef->getName()) {
                        case 'jcr:uuid':
                            $value = $this->generateUuid();
                            break;
                        case 'jcr:createdBy':
                        case 'jcr:lastModifiedBy':
                            $value = (string) $this->credentials->getUserID();
                            break;
                        case 'jcr:created':
                        case 'jcr:lastModified':
                            $value = new \DateTime();
                            break;
                        case 'jcr:etag':
                            // TODO: http://www.day.com/specs/jcr/2.0/3_Repository_Model.html#3.7.12.1%20mix:etag
                            $value = 'TODO: generate from binary properties of this node';
                            break;

                        default:
                            $defaultValues = $propertyDef->getDefaultValues();
                            if ($propertyDef->isMultiple()) {
                                $value = $defaultValues;
                            } elseif (isset($defaultValues[0])) {
                                $value = $defaultValues[0];
                            } else {
                                // When implementing versionable or activity, we need to handle more properties explicitly
                                throw new RepositoryException('No default value for autocreated property '.
                                    $propertyDef->getName(). ' at '.$node->getPath());
                            }
                    }

                    $node->setProperty(
                        $propertyDef->getName(),
                        $value,
                        $propertyDef->getRequiredType()
                    );
                }
            } elseif ($propertyDef->isAutoCreated()) {
                $prop = $node->getProperty($propertyDef->getName());
                if (!$prop->isModified() && !$prop->isNew()) {
                    switch($propertyDef->getName()) {
                        case 'jcr:lastModified':
                            if ($this->getAutoLastModified()) {
                                $prop->setValue(new \DateTime());
                            }
                            break;
                        case 'jcr:lastModifiedBy':
                            if ($this->getAutoLastModified()) {
                                $prop->setValue($this->credentials->getUserID());
                            }
                            break;
                        case 'jcr:etag':
                            // TODO: update etag if needed
                            break;
                    }

                }
            }
        }

        foreach ($def->getDeclaredSupertypes() as $superType) {
            $this->validateNodeWithType($node, $superType);
        }

        foreach ($node->getProperties() as $property) {
            $this->assertValidProperty($property);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function storeNodes(array $operations)
    {
        $this->assertLoggedIn();

        /** @var $operation AddNodeOperation */
        foreach ($operations as $operation) {
            if ($operation->node->isDeleted()) {
                $properties = $operation->node->getPropertiesForStoreDeletedNode();
            } else {
                $this->validateNode($operation->node);
                $properties = $operation->node->getProperties();
            }
            $this->storeNode($operation->srcPath, $properties);
        }

        $additionalNodeAddOperations = $this->additionalNodeAddOperations;
        if (!empty($additionalNodeAddOperations)) {
            $this->additionalNodeAddOperations = array();
            $this->storeNodes($additionalNodeAddOperations);
        }
    }

    /**
     * Make sure we have a uuid and a primaryType, then sync data into the database
     *
     * @param string     $path       the path to store the node at
     * @param Property[] $properties the properties of this node
     *
     * @return boolean true on success
     *
     * @throws RepositoryException if not logged in
     */
    protected function storeNode($path, $properties)
    {
        $nodeIdentifier = $this->getIdentifier($path, $properties);
        $type = isset($properties['jcr:primaryType']) ? $properties['jcr:primaryType']->getValue() : "nt:unstructured";

        $this->syncNode($nodeIdentifier, $path, $type, true, $properties);

        return true;
    }

    /**
     * Determine a UUID for the node at this path with these properties
     *
     * @param string     $path
     * @param Property[] $properties
     *
     * @return string a unique id
     */
    protected function getIdentifier($path, $properties)
    {
        if (isset($this->nodeIdentifiers[$path])) {
            return $this->nodeIdentifiers[$path];
        }

        if (isset($properties['jcr:uuid'])) {
            return $properties['jcr:uuid']->getValue();
        }

        // we always generate a uuid, even for non-referenceable nodes that have no automatic uuid
        return $this->generateUuid();
    }


    /**
     * Validation if all the data is correct before writing it into the database.
     *
     * @param PropertyInterface $property
     *
     * @throws ValueFormatException
     */
    private function assertValidProperty($property)
    {
        $type = $property->getType();
        switch ($type) {
            case PropertyType::NAME:
                $values = $property->getValue();
                if (!$property->isMultiple()) {
                    $values = array($values);
                }
                foreach ($values as $value) {
                    $pos = strpos($value, ':');
                    if (false !== $pos) {
                        $prefix = substr($value, 0, $pos);

                        $this->getNamespaces();
                        if (!isset($this->namespaces[$prefix])) {
                            throw new ValueFormatException("Invalid PHPCR NAME at '" . $property->getPath() . "': The namespace prefix " . $prefix . " does not exist.");
                        }
                    }
                }
                break;
            case PropertyType::PATH:
                $values = $property->getValue();
                if (!$property->isMultiple()) {
                    $values = array($values);
                }
                foreach ($values as $value) {
                    if (!preg_match('(((/|..)?[-a-zA-Z0-9:_]+)+)', $value)) {
                        throw new ValueFormatException("Invalid PATH '$value' at '" . $property->getPath() ."': Segments are separated by / and allowed chars are -a-zA-Z0-9:_");
                    }
                }
                break;
            case PropertyType::URI:
                $values = $property->getValue();
                if (!$property->isMultiple()) {
                    $values = array($values);
                }
                foreach ($values as $value) {
                    if (!preg_match(self::VALIDATE_URI_RFC3986, $value)) {
                        throw new ValueFormatException("Invalid URI '$value' at '" . $property->getPath() ."': Has to follow RFC 3986.");
                    }
                }
                break;
            case PropertyType::DECIMAL:
            case PropertyType::STRING:
                $values = (array) $property->getValue();
                foreach ($values as $value) {
                    if (!$this->isStringValid($value)) {
                        throw new ValueFormatException('Invalid character found in property "'.$property->getName().'". Are you passing a valid string?');
                    }
                }
                break;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function getNodeTypes($nodeTypes = array())
    {
        $standardTypes = StandardNodeTypes::getNodeTypeData();

        $userTypes = $this->fetchUserNodeTypes();

        if ($nodeTypes) {
            $nodeTypes = array_flip($nodeTypes);
            return array_values(array_intersect_key($standardTypes, $nodeTypes) + array_intersect_key($userTypes, $nodeTypes));
        }

        return array_values($standardTypes + $userTypes);
    }

    /**
     * Fetch a user-defined node-type definition.
     *
     * @param string $name
     *
     * @return array
     */
    protected function fetchUserNodeTypes()
    {
        $result = array();
        $query = "SELECT * FROM phpcr_type_nodes";
        foreach ($this->conn->fetchAll($query) as $data) {
            $name = $data['name'];
            $result[$name] = array(
                'name' => $name,
                'isAbstract' => (bool) $data['is_abstract'],
                'isMixin' => (bool) ($data['is_mixin']),
                'isQueryable' => (bool) $data['queryable'],
                'hasOrderableChildNodes' => (bool) $data['orderable_child_nodes'],
                'primaryItemName' => $data['primary_item'],
                'declaredSuperTypeNames' => array_filter(explode(' ', $data['supertypes'])),
                'declaredPropertyDefinitions' => array(),
                'declaredNodeDefinitions' => array(),
            );

            $query = 'SELECT * FROM phpcr_type_props WHERE node_type_id = ?';
            $props = $this->conn->fetchAll($query, array($data['node_type_id']));
            foreach ($props as $propertyData) {
                $result[$name]['declaredPropertyDefinitions'][] = array(
                    'declaringNodeType' => $data['name'],
                    'name' => $propertyData['name'],
                    'isAutoCreated' => (bool) $propertyData['auto_created'],
                    'isMandatory' => (bool) $propertyData['mandatory'],
                    'isProtected' => (bool) $propertyData['protected'],
                    'onParentVersion' => $propertyData['on_parent_version'],
                    'requiredType' => $propertyData['required_type'],
                    'multiple' => (bool) $propertyData['multiple'],
                    'isFulltextSearchable' => (bool) $propertyData['fulltext_searchable'],
                    'isQueryOrderable' => (bool) $propertyData['query_orderable'],
                    'queryOperators' => array (
                        0 => 'jcr.operator.equal.to',
                        1 => 'jcr.operator.not.equal.to',
                        2 => 'jcr.operator.greater.than',
                        3 => 'jcr.operator.greater.than.or.equal.to',
                        4 => 'jcr.operator.less.than',
                        5 => 'jcr.operator.less.than.or.equal.to',
                        6 => 'jcr.operator.like',
                    ),
                    'defaultValues' => array($propertyData['default_value']),
                );
            }

            $query = 'SELECT * FROM phpcr_type_childs WHERE node_type_id = ?';
            $childs = $this->conn->fetchAll($query, array($data['node_type_id']));
            foreach ($childs as $childData) {
                $result[$name]['declaredNodeDefinitions'][] = array(
                    'declaringNodeType' => $data['name'],
                    'name' => $childData['name'],
                    'isAutoCreated' => (bool) $childData['auto_created'],
                    'isMandatory' => (bool) $childData['mandatory'],
                    'isProtected' => (bool) $childData['protected'],
                    'onParentVersion' => $childData['on_parent_version'],
                    'allowsSameNameSiblings' => false,
                    'defaultPrimaryTypeName' => $childData['default_type'],
                    'requiredPrimaryTypeNames' => array_filter(explode(" ", $childData['primary_types'])),
                );
            }
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function registerNodeTypes($types, $allowUpdate)
    {
        $builtinTypes = StandardNodeTypes::getNodeTypeData();

        /* @var $type NodeTypeDefinition */
        foreach ($types as $type) {
            if (isset($builtinTypes[$type->getName()])) {
                throw new RepositoryException(sprintf('%s: can\'t reregister built-in node type.', $type->getName()));
            }

            if ($allowUpdate) {
                $query = "SELECT * FROM phpcr_type_nodes WHERE name = ?";
                $result = $this->conn->fetchColumn($query, array($type->getName()));
                if ($result) {
                    $this->conn->delete('phpcr_type_nodes', array('node_type_id' => $result));
                    $this->conn->delete('phpcr_type_props', array('node_type_id' => $result));
                    $this->conn->delete('phpcr_type_childs', array('node_type_id' => $result));
                }
            }

            try {
                $this->conn->insert('phpcr_type_nodes', array(
                    'name' => $type->getName(),
                    'supertypes' => implode(' ', $type->getDeclaredSuperTypeNames()),
                    'is_abstract' => $type->isAbstract() ? 1 : 0,
                    'is_mixin' => $type->isMixin() ? 1 : 0,
                    'queryable' => $type->isQueryable() ? 1 : 0,
                    'orderable_child_nodes' => $type->hasOrderableChildNodes() ? 1 : 0,
                    'primary_item' => $type->getPrimaryItemName(),
                ));
            } catch (DBALException $e) {
                throw new NodeTypeExistsException("Could not register node type with the name '".$type->getName()."'");
            }

            $nodeTypeId = $this->conn->lastInsertId($this->sequenceTypeName);

            if ($propDefs = $type->getDeclaredPropertyDefinitions()) {
                foreach ($propDefs as $propertyDef) {
                    /* @var $propertyDef PropertyDefinitionInterface */
                    $this->conn->insert('phpcr_type_props', array(
                        'node_type_id' => $nodeTypeId,
                        'name' => $propertyDef->getName(),
                        'protected' => $propertyDef->isProtected() ? 1 : 0,
                        'mandatory' => $propertyDef->isMandatory() ? 1 : 0,
                        'auto_created' => $propertyDef->isAutoCreated() ? 1 : 0,
                        'on_parent_version' => $propertyDef->getOnParentVersion(),
                        'multiple' => $propertyDef->isMultiple() ? 1 : 0,
                        'fulltext_searchable' => $propertyDef->isFullTextSearchable() ? 1 : 0,
                        'query_orderable' => $propertyDef->isQueryOrderable() ? 1 : 0,
                        'required_type' => $propertyDef->getRequiredType(),
                        'query_operators' => 0, // transform to bitmask
                        'default_value' => $propertyDef->getDefaultValues() ? current($propertyDef->getDefaultValues()) : null,
                    ));
                }
            }

            if ($childDefs = $type->getDeclaredChildNodeDefinitions()) {
                foreach ($childDefs as $childDef) {
                    /* @var $childDef NodeDefinitionInterface */
                    $this->conn->insert('phpcr_type_childs', array(
                        'node_type_id' => $nodeTypeId,
                        'name' => $childDef->getName(),
                        'protected' => $childDef->isProtected() ? 1 : 0,
                        'mandatory' => $childDef->isMandatory() ? 1 : 0,
                        'auto_created' => $childDef->isAutoCreated() ? 1 : 0,
                        'on_parent_version' => $childDef->getOnParentVersion(),
                        'primary_types' => implode(' ', $childDef->getRequiredPrimaryTypeNames() ?: array()),
                        'default_type' => $childDef->getDefaultPrimaryTypeName(),
                    ));
                }
            }
        }
    }

    /**
     * {@inheritDoc}
     */
    public function setNodeTypeManager($nodeTypeManager)
    {
        $this->nodeTypeManager = $nodeTypeManager;
    }

    /**
     * {@inheritDoc}
     */
    public function cloneFrom($srcWorkspace, $srcAbsPath, $destAbsPath, $removeExisting)
    {
        throw new NotImplementedException('Cloning nodes is not implemented yet');
    }

    /**
     * {@inheritDoc}
     */
    public function updateNode(Node $node, $srcWorkspace)
    {
        throw new NotImplementedException('Updating nodes is not implemented yet');
    }

    /**
     * {@inheritDoc}
     */
    public function getBinaryStream($path)
    {
        $this->assertLoggedIn();

        $nodePath = PathHelper::getParentPath($path);
        $nodeId = $this->pathExists($nodePath);
        $propertyName = PathHelper::getNodeName($path);

        $data = $this->conn->fetchAll(
            'SELECT data, idx FROM phpcr_binarydata WHERE node_id = ? AND property_name = ? AND workspace_name = ?',
            array($nodeId, $propertyName, $this->workspaceName)
        );

        $streams = array();
        foreach ($data as $row) {
            if (is_resource($row['data'])) {
                $stream = $row['data'];
            } else {
                $stream = fopen('php://memory', 'rwb+');
                fwrite($stream, $row['data']);
                rewind($stream);
            }

            $streams[] = $stream;
        }

        // TODO even a multi value field could have only one value stored
        // we need to also fetch if the property is multi valued instead of this count() check
        if (count($data) > 1) {
            return $streams;
        }

        return reset($streams);
    }

    /**
     * {@inheritDoc}
     */
    public function getProperty($path)
    {
        throw new NotImplementedException('Getting properties by path is implemented yet');
    }

    /**
     * {@inheritDoc}
     */
    public function query(Query $query)
    {
        $this->assertLoggedIn();

        if (!$query instanceof QueryObjectModelInterface) {
            $parser = new Sql2ToQomQueryConverter($this->factory->get('Query\QOM\QueryObjectModelFactory'));
            try {
                $qom = $parser->parse($query->getStatement());
                $qom->setLimit($query->getLimit());
                $qom->setOffset($query->getOffset());
            } catch (\Exception $e) {
                throw new InvalidQueryException('Invalid query: '.$query->getStatement(), null, $e);
            }
        } else {
            $qom = $query;
        }

        $qomWalker = new QOMWalker($this->nodeTypeManager, $this->conn, $this->getNamespaces());
        list($selectors, $selectorAliases, $sql) = $qomWalker->walkQOMQuery($qom);

        $primarySource = reset($selectors);
        $primaryType = $primarySource->getSelectorName() ?: $primarySource->getNodeTypeName();
        $data = $this->conn->fetchAll($sql, array($this->workspaceName));

        $results = $properties = $standardColumns = array();
        foreach ($data as $row) {
            $result = array();

            /** @var SelectorInterface $selector */
            foreach ($selectors as $selector) {
                $selectorName   = $selector->getSelectorName() ?: $selector->getNodeTypeName();
                $columnPrefix   = isset($selectorAliases[$selectorName]) ? $selectorAliases[$selectorName] . '_' : $selectorAliases[''] . '_';
                $selectorPrefix = null !== $selector->getSelectorName() ? $selectorName . '.' : '';

                if ($primaryType === $selector->getNodeTypeName()) {
                    $result[] = array('dcr:name' => 'jcr:path', 'dcr:value' => $row[$columnPrefix . 'path'], 'dcr:selectorName' => $selectorName);
                }

                $result[] = array('dcr:name' => $selectorPrefix . 'jcr:path'       ,'dcr:value' => $row[$columnPrefix . 'path'], 'dcr:selectorName' => $selectorName);
                $result[] = array('dcr:name' => $selectorPrefix . 'jcr:score',      'dcr:value' => 0                           , 'dcr:selectorName' => $selectorName);
                if (0 === count($qom->getColumns())) {
                    $result[] = array('dcr:name' => $selectorPrefix . 'jcr:primaryType','dcr:value' => $primaryType                , 'dcr:selectorName' => $selectorName);
                }

                if (isset($row[$columnPrefix . 'props'])) {
                    $properties[$selectorName] = (array) static::xmlToProps(
                        $row[$columnPrefix . 'props'],
                        $this->valueConverter
                    );
                } else {
                    $properties[$selectorName] = array();
                }

                // TODO: add other default columns that Jackrabbit provides to provide a more consistent behavior
                if (isset($properties[$selectorName]['jcr:createdBy'])) {
                    $standardColumns[$selectorName]['jcr:createdBy'] = $properties[$selectorName]['jcr:createdBy'];
                }
                if (isset($properties[$selectorName]['jcr:created'])) {
                    $standardColumns[$selectorName]['jcr:created'] = $properties[$selectorName]['jcr:created'];
                }
            }

            foreach ($qom->getColumns() as $column) {
                $selectorName = $column->getSelectorName();
                $columnName = $column->getPropertyName();
                $columnPrefix = isset($selectorAliases[$selectorName]) ? $selectorAliases[$selectorName] . '_' : $selectorAliases[''] . '_';

                $dcrValue = 'jcr:uuid' === $columnName
                    ? $row[$columnPrefix . 'identifier']
                    : (isset($properties[$selectorName][$columnName]) ? $properties[$selectorName][$columnName] : '')
                ;

                if (isset($standardColumns[$selectorName][$columnName])) {
                    unset($standardColumns[$selectorName][$columnName]);
                }

                $result[] = array(
                    'dcr:name' => ($column->getColumnName() === $columnName && isset($properties[$selectorName][$columnName])
                        ? $selectorName.'.'.$columnName : $column->getColumnName()),
                    'dcr:value' => $dcrValue,
                    'dcr:selectorName' => $selectorName ?: $primaryType,
                );
            }

            foreach ($standardColumns as $selectorName => $columns) {
                foreach ($columns as $columnName => $value) {
                    $result[] = array(
                        'dcr:name' => $primaryType.'.'.$columnName,
                        'dcr:value' => $value,
                        'dcr:selectorName' => $selectorName,
                    );
                }
            }

            $results[] = $result;
        }

        return $results;
    }

    /**
     * {@inheritDoc}
     */
    public function getSupportedQueryLanguages()
    {
        return array(
            QueryInterface::JCR_SQL2,
            QueryInterface::JCR_JQOM,
        );
    }

    /**
     * {@inheritDoc}
     */
    public function registerNamespace($prefix, $uri)
    {
        if (isset($this->namespaces[$prefix]) && $this->namespaces[$prefix] === $uri) {
            return;
        }

        $this->conn->delete('phpcr_namespaces', array('prefix' => $prefix));
        $this->conn->delete('phpcr_namespaces', array('uri' => $uri));

        $this->conn->insert('phpcr_namespaces', array(
            'prefix' => $prefix,
            'uri' => $uri,
        ));

        if (!empty($this->namespaces)) {
            $this->namespaces[$prefix] = $uri;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function unregisterNamespace($prefix)
    {
        $this->conn->delete('phpcr_namespaces', array('prefix' => $prefix));

        if (!empty($this->namespaces)) {
            unset($this->namespaces[$prefix]);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function getReferences($path, $name = null)
    {
        return $this->getNodeReferences($path, $name, false);
    }

    /**
     * {@inheritDoc}
     */
    public function getWeakReferences($path, $name = null)
    {
        return $this->getNodeReferences($path, $name, true);
    }

    /**
     * @param string  $path           the path for which we need the references
     * @param string  $name           the name of the referencing properties or null for all
     * @param boolean $weakReference  whether to get weak or strong references
     *
     * @return array list of paths to nodes that reference $path
     */
    private function getNodeReferences($path, $name = null, $weakReference = false)
    {
        $targetId = $this->pathExists($path);
        $params = array($targetId);

        $table = $weakReference ? $this->referenceTables[PropertyType::WEAKREFERENCE] : $this->referenceTables[PropertyType::REFERENCE];
        $query = "SELECT CONCAT(n.path, '/', r.source_property_name) FROM phpcr_nodes n
               INNER JOIN $table r ON n.id = r.source_id
               WHERE r.target_id = ?";
        if (null !== $name) {
            $query.= " AND source_property_name = ?";
            $params[] = $name;
        }

        $stmt = $this->conn->executeQuery($query, $params);

        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    /**
     * {@inheritDoc}
     */
    public function beginTransaction()
    {
        if ($this->inTransaction) {
            throw new RepositoryException('Begin transaction failed: transaction already open');
        }

        $this->assertLoggedIn();

        try {
            $this->conn->beginTransaction();
            $this->inTransaction = true;
        } catch (\Exception $e) {
            throw new RepositoryException('Begin transaction failed: '.$e->getMessage());
        }
    }

    /**
     * {@inheritDoc}
     */
    public function commitTransaction()
    {
        if (!$this->inTransaction) {
            throw new RepositoryException('Commit transaction failed: no transaction open');
        }

        $this->assertLoggedIn();

        try {
            $this->inTransaction = false;

            $this->conn->commit();
        } catch (\Exception $e) {
            throw new RepositoryException('Commit transaction failed: ' . $e->getMessage());
        }
    }

    /**
     * {@inheritDoc}
     */
    public function rollbackTransaction()
    {
        if (!$this->inTransaction) {
            throw new RepositoryException('Rollback transaction failed: no transaction open');
        }

        $this->assertLoggedIn();

        try {
            $this->inTransaction = false;
            $this->namespaces = array();

            $this->conn->rollback();
        } catch (\Exception $e) {
            throw new RepositoryException('Rollback transaction failed: ' . $e->getMessage());
        }
    }

    /**
     * Sets the default transaction timeout
     *
     * @param int $seconds The value of the timeout in seconds
     */
    public function setTransactionTimeout($seconds)
    {
        $this->assertLoggedIn();

        throw new NotImplementedException("Setting a transaction timeout is not yet implemented");
    }

    /**
     * {@inheritDoc}
     */
    public function prepareSave()
    {
        $this->conn->beginTransaction();
    }

    /**
     * {@inheritDoc}
     */
    public function finishSave()
    {
        $this->syncReferences();
        $this->referencesToUpdate = $this->referencesToDelete = array();
        $this->conn->commit();
    }

    /**
     * {@inheritDoc}
     */
    public function rollbackSave()
    {
        $this->referencesToUpdate = $this->referencesToDelete = array();
        $this->conn->rollback();
    }

    /**
     * Checks for occurrence of invalid UTF characters, that can not occur in valid XML document.
     * If occurrence is found, returns false, otherwise true.
     * Invalid characters were taken from this list: http://en.wikipedia.org/wiki/Valid_characters_in_XML#XML_1.0
     *
     * Uses regexp mentioned here: http://stackoverflow.com/a/961504
     *
     * @param $string string value
     * @return bool true if string is OK, false otherwise.
     */
    protected function isStringValid($string)
    {
        $regex = '/[^\x{9}\x{a}\x{d}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]+/u';

        return (preg_match($regex, $string, $matches) === 0);
    }

    /**
     *
     * @param Node $node the node to update
     */
    public function updateProperties(Node $node)
    {
        $this->assertLoggedIn();
        $this->validateNode($node);

        $this->syncNode($node->getIdentifier(), $node->getPath(), $node->getPrimaryNodeType(), false, $node->getProperties());

        return true;
    }
}
