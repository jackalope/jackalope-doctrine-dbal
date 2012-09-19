<?php

namespace Jackalope\Transport\DoctrineDBAL;

use Doctrine\Common\Cache\Cache;
use Doctrine\Common\Cache\ArrayCache;

use Doctrine\DBAL\Connection;

use Jackalope\FactoryInterface;
use Jackalope\Node;

/**
 * Class to add caching to the Doctrine DBAL client.
 *
 * @license http://www.apache.org/licenses/LICENSE-2.0  Apache License Version 2.0, January 2004
 *
 * @author Lukas Kahwe Smith <smith@pooteeweet.org>
 */
class CachedClient extends Client
{
    /**
     * @var Doctrine\Common\Cache\Cache[]
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
    public function deleteNode($path)
    {
        $result = parent::deleteNode($path);

        if ($result && isset($this->caches['nodes'])) {
            $this->caches['nodes']->deleteAll();
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function deleteProperty($path)
    {
        $result = parent::deleteProperty($path);

        if ($result && isset($this->caches['nodes'])) {
            $this->caches['nodes']->deleteAll();
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function moveNode($srcAbsPath, $dstAbsPath)
    {
        $result = parent::moveNode($srcAbsPath, $dstAbsPath);

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
    public function storeNode(Node $node, $saveChildren = true)
    {
        $result = parent::storeNode($node, $saveChildren);

        if ($result && isset($this->caches['nodes'])) {
            $this->caches['nodes']->deleteAll();
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function getNodePathForIdentifier($uuid)
    {
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
