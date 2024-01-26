<?php

namespace Jackalope\Transport\DoctrineDBAL;

use ArrayObject;
use Closure;
use Doctrine\Common\Cache\Cache;
use Doctrine\Common\Cache\ArrayCache;
use Psr\SimpleCache\CacheInterface;
use Doctrine\DBAL\Connection;
use Jackalope\FactoryInterface;
use Jackalope\Node;
use Jackalope\Query\Query;
use PHPCR\ItemNotFoundException;
use PHPCR\RepositoryException;

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
     * @var Cache[]|CacheInterface[]
     */
    private $caches;

    /**
     * @var Closure that accepts a cache key as argument and returns it after sanitizing it.
     */
    private $keySanitizer;

    /**
     * @param Cache[]|CacheInterface[] $caches
     */
    public function __construct(FactoryInterface $factory, Connection $conn, array $caches = [])
    {
        parent::__construct($factory, $conn);

        if (!array_key_exists('meta', $caches)) {
            if (!class_exists(ArrayCache::class)) {
                throw new \RuntimeException('No meta cache has been configured. Please either configure the meta cache explicitly or downgrade doctrine/cache to version 1.');
            }

            $caches['meta'] = new ArrayCache();
        }

        $this->caches = $caches;
        $this->keySanitizer = static function ($cacheKey) {
            return str_replace(' ', '_', $cacheKey);
        };
    }

    /**
     * @param Closure $sanitizer
     */
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
            if (array_key_exists($cache, $this->caches)) {
                $this->clearCache($cache);
            }
        }
    }

    /**
     * Sanitizes the key using $this->keySanitizer
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
     * @param Node $node
     *
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
     *
     */
    public function createWorkspace($name, $srcWorkspace = null)
    {
        parent::createWorkspace($name, $srcWorkspace = null);

        $this->caches['meta']->delete('workspaces');
        $this->set('meta',$this->sanitizeKey("workspace: $name"), 1);
    }

    /**
     * {@inheritDoc}
     *
     */
    public function deleteWorkspace($name)
    {
        parent::deleteWorkspace($name);

        $this->caches['meta']->delete('workspaces');
        $this->caches['meta']->delete($this->sanitizeKey("workspace: $name"));
        $this->clearCaches();
    }

    /**
     * {@inheritDoc}
     */
    protected function workspaceExists($workspaceName)
    {
        $cacheKey = "workspace: $workspaceName";
        $cacheKey = $this->sanitizeKey($cacheKey);

        $result = $this->get('meta', $cacheKey);
        if (!$result && parent::workspaceExists($workspaceName)) {
            $result = 1;
            $this->set('meta', $cacheKey, $result);
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

        if (!$this->inTransaction && $result = $this->get('meta', $cacheKey)) {
            return $result;
        }

        $result = parent::fetchUserNodeTypes();

        if (!$this->inTransaction) {
            $this->set('meta', $cacheKey, $result);
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

        $result = $this->get('meta', $cacheKey);
        if (!$result) {
            $result = parent::getNodeTypes($nodeTypes);
            $this->set('meta', $cacheKey, $result);
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

        $result = $this->get('meta', $cacheKey);
        if ($result) {
            $this->setNamespaces($result);
        } else {
            $result = parent::getNamespaces();

            $this->set('meta', $cacheKey, $result);
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

        $workspaces = $this->get('meta', $cacheKey);
        if (!$workspaces) {
            $workspaces = parent::getAccessibleWorkspaceNames();
            $this->set('meta', $cacheKey, $workspaces);
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
        if (!array_key_exists('nodes', $this->caches)) {
            return parent::getNode($path);
        }

        $this->assertLoggedIn();

        $cacheKey = "nodes: $path, ".$this->workspaceName;
        $cacheKey = $this->sanitizeKey($cacheKey);

        if (false !== ($result = $this->get('nodes', $cacheKey))) {
            if ('ItemNotFoundException' === $result) {
                throw new ItemNotFoundException("Item '$path' not found in workspace '$this->workspaceName'");
            }

            return $result;
        }

        try {
            $node = parent::getNode($path);
        } catch (ItemNotFoundException $e) {
            if (array_key_exists('nodes', $this->caches)) {
                $this->set('nodes', $cacheKey, 'ItemNotFoundException');
            }

            throw $e;
        }

        $this->set('nodes', $cacheKey, $node);

        return $node;
    }

    /**
     * {@inheritDoc}
     */
    public function getNodes($paths)
    {
        if (!array_key_exists('nodes', $this->caches)) {
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
     */
    protected function getSystemIdForNodeUuid($uuid, $workspaceName = null)
    {
        if (!array_key_exists('nodes', $this->caches)) {
            return parent::getSystemIdForNodeUuid($uuid, $workspaceName);
        }

        if (null === $workspaceName) {
            $workspaceName = $this->workspaceName;
        }

        $cacheKey = "id: $uuid, ".$workspaceName;
        $cacheKey = $this->sanitizeKey($cacheKey);

        if (false !== ($result = $this->get('nodes', $cacheKey))) {
            if ('false' === $result) {
                return false;
            }

            return $result;
        }

        $nodeId = parent::getSystemIdForNodeUuid($uuid, $workspaceName);

        $this->set('nodes', $cacheKey, $nodeId ? $nodeId : 'false');

        return $nodeId;
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
        if (!array_key_exists('nodes', $this->caches) || null !== $workspace) {
            return parent::getNodePathForIdentifier($uuid);
        }

        $this->assertLoggedIn();

        $cacheKey = "nodes by uuid: $uuid, $this->workspaceName";
        $cacheKey = $this->sanitizeKey($cacheKey);

        if (false !== ($result = $this->get('nodes', $cacheKey))) {
            if ('ItemNotFoundException' === $result) {
                throw new ItemNotFoundException("no item found with uuid $uuid");
            }

            return $result;
        }

        try {
            $path = parent::getNodePathForIdentifier($uuid);
        } catch (ItemNotFoundException $e) {
            if (array_key_exists('nodes', $this->caches)) {
                $this->set('nodes', $cacheKey, 'ItemNotFoundException');
            }

            throw $e;
        }

        $this->set('nodes', $cacheKey, $path);

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
        $this->set('meta', 'namespaces', $this->namespaces);
    }

    /**
     * {@inheritDoc}
     */
    public function unregisterNamespace($prefix)
    {
        parent::unregisterNamespace($prefix);
        $this->set('meta', 'namespaces', $this->namespaces);
    }

    /**
     * {@inheritDoc}
     */
    public function getReferences($path, $name = null)
    {
        if (!array_key_exists('nodes', $this->caches)) {
            return parent::getReferences($path, $name);
        }

        $cacheKey = "nodes references: $path, $name, " . $this->workspaceName;
        $cacheKey = $this->sanitizeKey($cacheKey);

        if (false !== ($result = $this->get('nodes', $cacheKey))) {
            return $result;
        }

        $references = parent::getReferences($path, $name);

        $this->set('nodes', $cacheKey, $references);

        return $references;
    }

    /**
     * {@inheritDoc}
     */
    public function getWeakReferences($path, $name = null)
    {
        if (!array_key_exists('nodes', $this->caches)) {
            return parent::getWeakReferences($path, $name);
        }

        $cacheKey = "nodes weak references: $path, $name, " . $this->workspaceName;
        $cacheKey = $this->sanitizeKey($cacheKey);

        if ($result = $this->get('nodes', $cacheKey)) {
            return $result;
        }

        $references = parent::getWeakReferences($path, $name);

        $this->set('nodes', $cacheKey, $references);

        return $references;
    }

    /**
     * {@inheritDoc}
     */
    public function query(Query $query)
    {
        if (!array_key_exists('query', $this->caches)) {
            return parent::query($query);
        }

        $this->assertLoggedIn();

        $cacheKey = "query: {$query->getStatement()}, {$query->getLimit()}, {$query->getOffset()}, {$query->getLanguage()}, ".$this->workspaceName;
        $cacheKey = $this->sanitizeKey($cacheKey);

        if (false !== ($result = $this->get('query', $cacheKey))) {
            return $result;
        }

        $result = parent::query($query);

        $this->set('query', $cacheKey, $result);

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

    /// helper methods to support both doctrine cache and PSR cache ///

    private function clearCache(string $name): void
    {
        if ($this->caches[$name] instanceof CacheInterface) {
            $this->caches[$name]->clear();

            return;
        }
        $this->caches[$name]->deleteAll();
    }

    private function set(string $name, string $key, $value): void
    {
        if ($this->caches[$name] instanceof CacheInterface) {
            $this->caches[$name]->set($key, $value);

            return;
        }
        $this->caches[$name]->save($key, $value);
    }

    private function get(string $name, string $key)
    {
        if ($this->caches[$name] instanceof CacheInterface) {
            return $this->caches[$name]->get($key);
        }

        return $this->caches[$name]->fetch($key);
    }
}
