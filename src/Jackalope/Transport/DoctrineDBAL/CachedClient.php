<?php

namespace Jackalope\Transport\DoctrineDBAL;

use Doctrine\Common\Cache\Cache;
use Doctrine\Common\Cache\ArrayCache;

use Doctrine\DBAL\Connection;

use Jackalope\NotImplementedException;
use Jackalope\FactoryInterface;
use Jackalope\Node;

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
        if (isset($this->caches['nodes'])) {
            $this->caches['nodes']->deleteAll();
        }
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
    public function getNamespaces()
    {
        if (empty($this->namespaces)) {
            $cacheKey = 'namespaces';
            $result = $this->caches['meta']->fetch($cacheKey);
            if ($result) {
                $this->namespaces = $result;
            } else {
                $this->namespaces = parent::getNamespaces();

                $this->caches['meta']->save($cacheKey, $this->namespaces);
            }
        }

        return $this->namespaces;
    }

    /**
     * {@inheritDoc}
     */
    public function copyNode($srcAbsPath, $dstAbsPath, $srcWorkspace = null)
    {
        parent::copyNode($srcAbsPath, $dstAbsPath, $srcWorkspace);

        if (isset($this->caches['nodes'])) {
            $this->caches['nodes']->deleteAll();
        }
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
        $this->assertLoggedIn();

        $cacheKey = "nodes: $path, ".$this->workspaceName;
        if (isset($this->caches['nodes']) && (false !== ($result = $this->caches['nodes']->fetch($cacheKey)))) {
            return $result;
        }

        $node = parent::getNode($path);
        if (isset($this->caches['nodes'])) {
            $this->caches['nodes']->save($cacheKey, $node);
        }

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

        if ($result && isset($this->caches['nodes'])) {
            $this->caches['nodes']->deleteAll();
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function deleteProperties(array $operation)
    {
        $result = parent::deleteProperties($operation);

        if ($result && isset($this->caches['nodes'])) {
            $this->caches['nodes']->deleteAll();
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function deleteNodeImmediately($absPath)
    {
        $result = parent::deleteNodeImmediately($absPath);

        if ($result && isset($this->caches['nodes'])) {
            $this->caches['nodes']->deleteAll();
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function deletePropertyImmediately($absPath)
    {
        $result = parent::deletePropertyImmediately($absPath);

        if ($result && isset($this->caches['nodes'])) {
            $this->caches['nodes']->deleteAll();
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function moveNodes(array $operations)
    {
        $result = parent::moveNodes($operations);

        if ($result && isset($this->caches['nodes'])) {
            $this->caches['nodes']->deleteAll();
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function moveNodeImmediately($srcAbsPath, $dstAbsPath)
    {
        $result = parent::moveNodeImmediately($srcAbsPath, $dstAbsPath);

        if ($result && isset($this->caches['nodes'])) {
            $this->caches['nodes']->deleteAll();
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function reorderNodes($absPath, $reorders)
    {
        parent::reorderNodes($absPath, $reorders);

        if (isset($this->caches['nodes'])) {
            $this->caches['nodes']->deleteAll();
        }
    }

    /**
     * {@inheritDoc}
     */
    public function storeNodes(array $operations)
    {
        $result = parent::storeNodes($operations);

        if ($result && isset($this->caches['nodes'])) {
            $this->caches['nodes']->deleteAll();
        }

        return $result;
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

        $cacheKey = "nodes by uuid: $uuid, ".$this->workspaceName;
        if (isset($this->caches['nodes']) && (false !== ($result = $this->caches['nodes']->fetch($cacheKey)))) {
            return $result;
        }

        $path = parent::getNodePathForIdentifier($uuid);

        if (isset($this->caches['nodes'])) {
            $this->caches['nodes']->save($cacheKey, $path);
        }

        return $path;
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

        if (!empty($this->namespaces)) {
            $this->caches['meta']->save('namespaces', $this->namespaces);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function unregisterNamespace($prefix)
    {
        parent::unregisterNamespace($prefix);

        if (!empty($this->namespaces)) {
            $this->caches['meta']->save('namespaces', $this->namespaces);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function getReferences($path, $name = null)
    {
        $cacheKey = "nodes references: $path, $name, ".$this->workspaceName;
        if (isset($this->caches['nodes']) && (false !== ($result = $this->caches['nodes']->fetch($cacheKey)))) {
            return $result;
        }

        $references = parent::getReferences($path, $name);

        if (isset($this->caches['nodes'])) {
            $this->caches['nodes']->save($cacheKey, $references);
        }

        return $references;
    }

    /**
     * {@inheritDoc}
     */
    public function getWeakReferences($path, $name = null)
    {
        $cacheKey = "nodes weak references: $path, $name, ".$this->workspaceName;
        if (isset($this->caches['nodes']) && $result = $this->caches['nodes']->fetch($cacheKey)) {
            return $result;
        }

        $references = parent::getWeakReferences($path, $name);

        if (isset($this->caches['nodes'])) {
            $this->caches['nodes']->save($cacheKey, $references);
        }

        return $references;
    }

    /**
     * {@inheritDoc}
     */
    public function commitTransaction()
    {
        parent::commitTransaction();

        $this->caches['meta']->deleteAll();
        if (isset($this->caches['nodes'])) {
            $this->caches['nodes']->deleteAll();
        }
    }

    /**
     * {@inheritDoc}
     */
    public function rollbackTransaction()
    {
        parent::rollbackTransaction();

        $this->caches['meta']->deleteAll();
        if (isset($this->caches['nodes'])) {
            $this->caches['nodes']->deleteAll();
        }
    }
}
