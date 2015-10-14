<?php

namespace Jackalope\Transport\DoctrineDBAL;

use Doctrine\Common\Cache\Cache;
use Doctrine\Common\Cache\ArrayCache;
use Doctrine\DBAL\Connection;
use Jackalope\NotImplementedException;
use Jackalope\FactoryInterface;
use Jackalope\Node;
use Jackalope\Query\Query;
use PHPCR\ItemNotFoundException;

/**
 * Class to add caching to the Doctrine DBAL client.
 *
 * @license http://www.apache.org/licenses Apache License Version 2.0, January 2004
 * @license http://opensource.org/licenses/MIT MIT License
 *
 * @author Lukas Kahwe Smith <smith@pooteeweet.org>
 */
class CachedClient extends Client
{
    /**
     * @var Cache[]
     */
    private $caches;

    public function __construct(FactoryInterface $factory, Connection $conn, array $caches = array())
    {
        parent::__construct($factory, $conn);

        $caches['meta'] = isset($caches['meta']) ? $caches['meta'] : new ArrayCache();
        $this->caches = $caches;
    }

    /**
     * @param array|null $caches which caches to invalidate, null means all except meta
     */
    private function clearCaches(array $caches = null)
    {
        $caches = $caches ?: array('nodes', 'query');
        foreach ($caches as $cache) {
            if (isset($this->caches[$cache])) {
                $this->caches[$cache]->deleteAll();
            }
        }
    }

    /**
     * @param Node $node
     */
    private function clearNodeCache(Node $node)
    {
        $cacheKey = "nodes: {$node->getPath()}, ".$this->workspaceName;
        $this->caches['nodes']->delete($cacheKey);

        // actually in the DBAL all nodes have a uuid ..
        if ($node->isNodeType('mix:referenceable')) {
            $uuid = $node->getIdentifier();
            $cacheKey = "nodes by uuid: $uuid, ".$this->workspaceName;
            $this->caches['nodes']->delete($cacheKey);
        }
    }

    /**
     * {@inheritDoc}
     *
     */
    public function createWorkspace($name, $srcWorkspace = null)
    {
        parent::createWorkspace($name, $srcWorkspace = null);

        $this->caches['meta']->delete('workspaces');
        $this->caches['meta']->save("workspace: $name", 1);
    }

    /**
     * {@inheritDoc}
     *
     */
    public function deleteWorkspace($name)
    {
        parent::deleteWorkspace($name);

        $this->caches['meta']->delete('workspaces');
        $this->caches['meta']->delete("workspace: $name");
        $this->clearCaches();
    }

    /**
     * {@inheritDoc}
     */
    protected function workspaceExists($workspaceName)
    {
        $cacheKey = "workspace: $workspaceName";
        $result = $this->caches['meta']->fetch($cacheKey);
        if (!$result && parent::workspaceExists($workspaceName)) {
            $result = 1;
            $this->caches['meta']->save($cacheKey, $result);
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    protected function fetchUserNodeTypes()
    {
        $cacheKey = 'node_types';
        if (!$this->inTransaction && $result = $this->caches['meta']->fetch($cacheKey)) {
            return $result;
        }

        $result = parent::fetchUserNodeTypes();

        if (!$this->inTransaction) {
            $this->caches['meta']->save($cacheKey, $result);
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function getNodeTypes($nodeTypes = array())
    {
        $cacheKey = 'nodetypes: '.serialize($nodeTypes);
        $result = $this->caches['meta']->fetch($cacheKey);
        if (!$result) {
            $result = parent::getNodeTypes($nodeTypes);
            $this->caches['meta']->save($cacheKey, $result);
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function getNamespaces()
    {
        if ($this->namespaces instanceof \ArrayObject) {
            return parent::getNamespaces();
        }

        $cacheKey = 'namespaces';
        $result = $this->caches['meta']->fetch($cacheKey);
        if ($result) {
            $this->setNamespaces($result);
        } else {
            $result = parent::getNamespaces();

            $this->caches['meta']->save($cacheKey, $result);
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function copyNode($srcAbsPath, $dstAbsPath, $srcWorkspace = null)
    {
        parent::copyNode($srcAbsPath, $dstAbsPath, $srcWorkspace);

        $this->clearCaches();
    }

    /**
     * {@inheritDoc}
     */
    public function getAccessibleWorkspaceNames()
    {
        $cacheKey = 'workspaces';
        $workspaces = $this->caches['meta']->fetch($cacheKey);
        if (!$workspaces) {
            $workspaces = parent::getAccessibleWorkspaceNames();
            $this->caches['meta']->save($cacheKey, $workspaces);
        }

        return $workspaces;
    }

    /**
     * {@inheritDoc}
     */
    public function getNode($path)
    {
        if (empty($this->caches['nodes'])) {
            return parent::getNode($path);
        }

        $this->assertLoggedIn();

        $cacheKey = "nodes: $path, ".$this->workspaceName;
        if (false !== ($result = $this->caches['nodes']->fetch($cacheKey))) {
            if ('ItemNotFoundException' === $result) {
                throw new ItemNotFoundException(sprintf('Item "%s" not found in workspace "%s"', $path, $this->workspaceName));
            }

            return $result;
        }

        try {
            $node = parent::getNode($path);
        } catch (ItemNotFoundException $e) {
            if (isset($this->caches['nodes'])) {
                $this->caches['nodes']->save($cacheKey, 'ItemNotFoundException');
            }

            throw $e;
        }

        $this->caches['nodes']->save($cacheKey, $node);

        return $node;
    }

    /**
     * {@inheritDoc}
     */
    public function getNodes($paths)
    {
        if (empty($this->caches['nodes'])) {
            return parent::getNodes($paths);
        }

        $nodes = array();
        foreach ($paths as $key => $path) {
            try {
                $nodes[$key] = $this->getNode($path);
            } catch (\PHPCR\ItemNotFoundException $e) {
                // ignore
            }
        }

        return $nodes;
    }

    /**
     * {@inheritDoc}
     */
    protected function getSystemIdForNodeUuid($uuid, $workspaceName = null)
    {
        if (empty($this->caches['nodes'])) {
            return parent::getSystemIdForNodeUuid($uuid, $workspaceName);
        }

        if (null === $workspaceName) {
            $workspaceName = $this->workspaceName;
        }

        $cacheKey = "id: $uuid, ".$workspaceName;
        if (false !== ($result = $this->caches['nodes']->fetch($cacheKey))) {
            if ('false' === $result) {
                return false;
            }

            return $result;
        }

        $nodeId = parent::getSystemIdForNodeUuid($uuid, $workspaceName);

        $this->caches['nodes']->save($cacheKey, $nodeId ? $nodeId : 'false');

        return $nodeId;
    }

    /**
     * {@inheritDoc}
     */
    public function getNodeByIdentifier($uuid)
    {
        $path = $this->getNodePathForIdentifier($uuid);
        $data = $this->getNode($path);
        $data->{':jcr:path'} = $path;

        return $data;
    }

    /**
     * {@inheritDoc}
     */
    public function getNodesByIdentifier($uuids)
    {
        $data = array();
        foreach ($uuids as $uuid) {
            try {
                $path = $this->getNodePathForIdentifier($uuid);
                $data[$path] = $this->getNode($path);
            } catch (ItemNotFoundException $e) {
                // skip
            }
        }

        return $data;
    }

    /**
     * {@inheritDoc}
     */
    public function deleteNodes(array $operations)
    {
        $result = parent::deleteNodes($operations);

        if ($result) {
            $this->clearCaches();
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function deleteProperties(array $operation)
    {
        $result = parent::deleteProperties($operation);

        if ($result) {
            // we do not have the node here, otherwise we could use clearNodeCache() and then just invalidate all queries
            $this->clearCaches();
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function deleteNodeImmediately($absPath)
    {
        $result = parent::deleteNodeImmediately($absPath);

        if ($result) {
            $this->clearCaches();
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function deletePropertyImmediately($absPath)
    {
        $result = parent::deletePropertyImmediately($absPath);

        if ($result) {
            // we do not have the node here, otherwise we could use clearNodeCache() and then just invalidate all queries
            $this->clearCaches();
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function moveNodes(array $operations)
    {
        $result = parent::moveNodes($operations);

        if ($result) {
            $this->clearCaches();
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function moveNodeImmediately($srcAbsPath, $dstAbsPath)
    {
        $result = parent::moveNodeImmediately($srcAbsPath, $dstAbsPath);

        if ($result) {
            $this->clearCaches();
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function reorderChildren(Node $node)
    {
        $result = parent::reorderChildren($node);

        if ($result) {
            $this->clearNodeCache($node);
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function storeNodes(array $operations)
    {
        parent::storeNodes($operations);

        // we do not have the node here, otherwise we could just use clearNodeCache() on pre-existing parents and then just invalidate all queries
        $this->clearCaches();
    }

    /**
     * {@inheritDoc}
     */
    public function getNodePathForIdentifier($uuid, $workspace = null)
    {
        if (empty($this->caches['nodes']) || null !== $workspace) {
            return parent::getNodePathForIdentifier($uuid);
        }

        $this->assertLoggedIn();

        $cacheKey = "nodes by uuid: $uuid, ".$this->workspaceName;
        if (false !== ($result = $this->caches['nodes']->fetch($cacheKey))) {
            if ('ItemNotFoundException' === $result) {
                throw new ItemNotFoundException("no item found with uuid ".$uuid);
            }

            return $result;
        }

        try {
            $path = parent::getNodePathForIdentifier($uuid);
        } catch (ItemNotFoundException $e) {
            if (isset($this->caches['nodes'])) {
                $this->caches['nodes']->save($cacheKey, 'ItemNotFoundException');
            }

            throw $e;
        }

        $this->caches['nodes']->save($cacheKey, $path);

        return $path;
    }

    /**
     * {@inheritDoc}
     */
    public function registerNodeTypes($types, $allowUpdate)
    {
        parent::registerNodeTypes($types, $allowUpdate);

        if (!$this->inTransaction) {
            $this->caches['meta']->delete('node_types');
        }
    }

    /**
     * {@inheritDoc}
     */
    public function registerNamespace($prefix, $uri)
    {
        parent::registerNamespace($prefix, $uri);
        $this->caches['meta']->save('namespaces', $this->namespaces);
    }

    /**
     * {@inheritDoc}
     */
    public function unregisterNamespace($prefix)
    {
        parent::unregisterNamespace($prefix);
        $this->caches['meta']->save('namespaces', $this->namespaces);
    }

    /**
     * {@inheritDoc}
     */
    public function getReferences($path, $name = null)
    {
        if (empty($this->caches['nodes'])) {
            return parent::getReferences($path, $name);
        }

        $cacheKey = "nodes references: $path, $name, " . $this->workspaceName;
        if (false !== ($result = $this->caches['nodes']->fetch($cacheKey))) {
            return $result;
        }

        $references = parent::getReferences($path, $name);

        $this->caches['nodes']->save($cacheKey, $references);

        return $references;
    }

    /**
     * {@inheritDoc}
     */
    public function getWeakReferences($path, $name = null)
    {
        if (empty($this->caches['nodes'])) {
            return parent::getWeakReferences($path, $name);
        }

        $cacheKey = "nodes weak references: $path, $name, " . $this->workspaceName;
        if ($result = $this->caches['nodes']->fetch($cacheKey)) {
            return $result;
        }

        $references = parent::getWeakReferences($path, $name);

        $this->caches['nodes']->save($cacheKey, $references);

        return $references;
    }

    /**
     * {@inheritDoc}
     */
    public function query(Query $query)
    {
        if (empty($this->caches['query'])) {
            return parent::query($query);
        }

        $this->assertLoggedIn();

        $cacheKey = "query: {$query->getStatement()}, {$query->getLimit()}, {$query->getOffset()}, {$query->getLanguage()}, ".$this->workspaceName;
        if ($result = $this->caches['query']->fetch($cacheKey)) {
            return $result;
        }

        $result = parent::query($query);

        $this->caches['query']->save($cacheKey, $result);

        return $result;
    }

        /**
     * {@inheritDoc}
     */
    public function commitTransaction()
    {
        parent::commitTransaction();

        $this->clearCaches(array_keys($this->caches));
    }

    /**
     * {@inheritDoc}
     */
    public function rollbackTransaction()
    {
        parent::rollbackTransaction();

        $this->clearCaches(array_keys($this->caches));
    }
}
