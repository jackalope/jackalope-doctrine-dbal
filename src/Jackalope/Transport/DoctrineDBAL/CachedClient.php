<?php

namespace Jackalope\Transport\DoctrineDBAL;

use ArrayObject;
use Closure;
use Doctrine\DBAL\Connection;
use Jackalope\FactoryInterface;
use Jackalope\Node;
use Jackalope\Query\Query;
use PHPCR\ItemNotFoundException;
use PHPCR\RepositoryException;
use Psr\SimpleCache\CacheInterface;

/**
 * Class to add caching to the Doctrine DBAL client.
 *
 * @license http://www.apache.org/licenses Apache License Version 2.0, January 2004
 * @license http://opensource.org/licenses/MIT MIT License
 * @author Lukas Kahwe Smith <smith@pooteeweet.org>
 */
class CachedClient extends Client
{
    /**
     * @var CacheInterface[]
     */
    private $caches;

    /**
     * @var Closure that accepts a cache key as argument and returns it after sanitizing it
     */
    private $keySanitizer;

    /**
     * @param CacheInterface[] $caches
     */
    public function __construct(FactoryInterface $factory, Connection $conn, array $caches)
    {
        parent::__construct($factory, $conn);

        if (!array_key_exists('meta', $caches)) {
            throw new \InvalidArgumentException('The meta cache is required when setting up the CachedClient');
        }
        foreach ($caches as $type => $cache) {
            if (!$cache instanceof CacheInterface) {
                throw new \InvalidArgumentException(sprintf(
                    'Cache provided for type "%s" is %s which does not implement %s.',
                    $type,
                    is_object($cache) ? get_class($cache) : gettype($cache),
                    CacheInterface::class
                ));
            }
        }
        $this->caches = $caches;
        $this->keySanitizer = static function ($cacheKey) {
            return str_replace(' ', '_', $cacheKey);
        };
    }

    public function setKeySanitizer(Closure $sanitizer)
    {
        $this->keySanitizer = $sanitizer;
    }

    /**
     * @param array|null $caches which caches to invalidate, null means all except meta
     */
    private function clearCaches(array $caches = null)
    {
        $caches = $caches ?: ['nodes', 'query'];
        foreach ($caches as $cache) {
            if (isset($this->caches[$cache])) {
                $this->caches[$cache]->clear();
            }
        }
    }

    /**
     * Sanitizes the key using $this->keySanitizer.
     *
     * @param string $cacheKey
     *
     * @return mixed
     */
    private function sanitizeKey($cacheKey)
    {
        if ($sanitizer = $this->keySanitizer) {
            return $sanitizer($cacheKey);
        }

        return $cacheKey;
    }

    /**
     * @throws RepositoryException
     */
    private function clearNodeCache(Node $node)
    {
        $cacheKey = "nodes: {$node->getPath()}, ".$this->workspaceName;
        $cacheKey = $this->sanitizeKey($cacheKey);

        $this->caches['nodes']->delete($cacheKey);

        // Actually in the DBAL all nodes have a uuid ..
        if ($node->isNodeType('mix:referenceable')) {
            $uuid = $node->getIdentifier();
            $cacheKey = "nodes by uuid: $uuid, ".$this->workspaceName;
            $this->caches['nodes']->delete($cacheKey);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function createWorkspace($name, $srcWorkspace = null)
    {
        parent::createWorkspace($name, $srcWorkspace = null);

        $this->caches['meta']->delete('workspaces');
        $this->caches['meta']->set("workspace: $name", 1);
    }

    /**
     * {@inheritDoc}
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
        $cacheKey = $this->sanitizeKey($cacheKey);

        $result = $this->caches['meta']->get($cacheKey);
        if (!$result && parent::workspaceExists($workspaceName)) {
            $result = 1;
            $this->caches['meta']->set($cacheKey, $result);
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    protected function fetchUserNodeTypes()
    {
        $cacheKey = 'node_types';
        $cacheKey = $this->sanitizeKey($cacheKey);

        if (!$this->inTransaction && $result = $this->caches['meta']->get($cacheKey)) {
            return $result;
        }

        $result = parent::fetchUserNodeTypes();

        if (!$this->inTransaction) {
            $this->caches['meta']->set($cacheKey, $result);
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function getNodeTypes($nodeTypes = [])
    {
        $cacheKey = 'nodetypes: '.serialize($nodeTypes);
        $cacheKey = $this->sanitizeKey($cacheKey);

        $result = $this->caches['meta']->get($cacheKey);
        if (!$result) {
            $result = parent::getNodeTypes($nodeTypes);
            $this->caches['meta']->set($cacheKey, $result);
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function getNamespaces()
    {
        if ($this->namespaces instanceof ArrayObject) {
            return parent::getNamespaces();
        }

        $cacheKey = 'namespaces';
        $cacheKey = $this->sanitizeKey($cacheKey);

        $result = $this->caches['meta']->get($cacheKey);
        if ($result) {
            $this->setNamespaces($result);
        } else {
            $result = parent::getNamespaces();

            $this->caches['meta']->set($cacheKey, $result);
        }

        return (array) $result;
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
        $cacheKey = $this->sanitizeKey($cacheKey);

        $workspaces = $this->caches['meta']->get($cacheKey);
        if (!$workspaces) {
            $workspaces = parent::getAccessibleWorkspaceNames();
            $this->caches['meta']->set($cacheKey, $workspaces);
        }

        return $workspaces;
    }

    /**
     * {@inheritDoc}
     *
     * @throws RepositoryException
     */
    public function getNode($path)
    {
        if (empty($this->caches['nodes'])) {
            return parent::getNode($path);
        }

        $this->assertLoggedIn();

        $cacheKey = "nodes: $path, ".$this->workspaceName;
        $cacheKey = $this->sanitizeKey($cacheKey);

        if (false !== ($result = $this->caches['nodes']->get($cacheKey))) {
            if ('ItemNotFoundException' === $result) {
                throw new ItemNotFoundException("Item '$path' not found in workspace '$this->workspaceName'");
            }

            return $result;
        }

        try {
            $node = parent::getNode($path);
        } catch (ItemNotFoundException $e) {
            if (isset($this->caches['nodes'])) {
                $this->caches['nodes']->set($cacheKey, 'ItemNotFoundException');
            }

            throw $e;
        }

        $this->caches['nodes']->set($cacheKey, $node);

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

        $nodes = [];
        foreach ($paths as $key => $path) {
            try {
                $nodes[$key] = $this->getNode($path);
            } catch (ItemNotFoundException $e) {
                // ignore
            }
        }

        return $nodes;
    }

    /**
     * {@inheritDoc}
     *
     * @throws RepositoryException
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
        $data = [];
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
     *
     * @throws RepositoryException
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
     *
     * @throws RepositoryException
     */
    public function getNodePathForIdentifier($uuid, $workspace = null)
    {
        if (empty($this->caches['nodes']) || null !== $workspace) {
            return parent::getNodePathForIdentifier($uuid);
        }

        $this->assertLoggedIn();

        $cacheKey = "nodes by uuid: $uuid, $this->workspaceName";
        $cacheKey = $this->sanitizeKey($cacheKey);

        if (false !== ($result = $this->caches['nodes']->get($cacheKey))) {
            if ('ItemNotFoundException' === $result) {
                throw new ItemNotFoundException("no item found with uuid $uuid");
            }

            return $result;
        }

        try {
            $path = parent::getNodePathForIdentifier($uuid);
        } catch (ItemNotFoundException $e) {
            if (isset($this->caches['nodes'])) {
                $this->caches['nodes']->set($cacheKey, 'ItemNotFoundException');
            }

            throw $e;
        }

        $this->caches['nodes']->set($cacheKey, $path);

        return $path;
    }

    /**
     * {@inheritDoc}
     */
    public function registerNodeTypes($types, $allowUpdate)
    {
        $ret = parent::registerNodeTypes($types, $allowUpdate);

        if (!$this->inTransaction) {
            $this->caches['meta']->delete('node_types');
        }

        return $ret;
    }

    /**
     * {@inheritDoc}
     */
    public function registerNamespace($prefix, $uri)
    {
        parent::registerNamespace($prefix, $uri);
        $this->caches['meta']->set('namespaces', $this->namespaces);
    }

    /**
     * {@inheritDoc}
     */
    public function unregisterNamespace($prefix)
    {
        parent::unregisterNamespace($prefix);
        $this->caches['meta']->set('namespaces', $this->namespaces);
    }

    /**
     * {@inheritDoc}
     */
    public function getReferences($path, $name = null)
    {
        if (empty($this->caches['nodes'])) {
            return parent::getReferences($path, $name);
        }

        $cacheKey = "nodes references: $path, $name, ".$this->workspaceName;
        $cacheKey = $this->sanitizeKey($cacheKey);

        if (false !== ($result = $this->caches['nodes']->get($cacheKey))) {
            return $result;
        }

        $references = parent::getReferences($path, $name);

        $this->caches['nodes']->set($cacheKey, $references);

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

        $cacheKey = "nodes weak references: $path, $name, ".$this->workspaceName;
        $cacheKey = $this->sanitizeKey($cacheKey);

        if ($result = $this->caches['nodes']->get($cacheKey)) {
            return $result;
        }

        $references = parent::getWeakReferences($path, $name);

        $this->caches['nodes']->set($cacheKey, $references);

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
        $cacheKey = $this->sanitizeKey($cacheKey);

        if (false !== ($result = $this->caches['query']->get($cacheKey))) {
            return $result;
        }

        $result = parent::query($query);

        $this->caches['query']->set($cacheKey, $result);

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
