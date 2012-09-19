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
     * @var array Doctrine\Common\Cache\Cache
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
        $workspaceId = parent::createWorkspace($name, $srcWorkspace = null);

        $this->caches['meta']->delete('workspaces');
        $this->caches['meta']->save("workspace: $name", $workspaceId);
    }

    /**
     * {@inheritDoc}
     */
    protected function getWorkspaceId($workspaceName)
    {
        $id = $this->caches['meta']->fetch("workspace: $workspaceName");
        if (!$id) {
            $id = parent::getWorkspaceId($workspaceName);
            $this->caches['meta']->save("workspace: $workspaceName", $id);
        }

        return $id;
    }

    /**
     * {@inheritDoc}
     */
    public function getNamespaces()
    {
        if (empty($this->namespaces)) {
            $result = $this->caches['meta']->fetch('namespaces');
            if ($result) {
                $this->namespaces = $result;
            } else {
                parent::getNamespaces();

                $this->caches['meta']->save('namespaces', $this->namespaces);
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
    protected function syncNode($uuid, $path, $parent, $type, $isNewNode, $props = array(), $propsData = array())
    {
        $nodeId = parent::syncNode($uuid, $path, $parent, $type, $isNewNode, $props, $propsData);

        if (isset($this->caches['nodes'])) {
            $this->caches['nodes']->deleteAll();
        }

        return $nodeId;
    }

    /**
     * {@inheritDoc}
     */
    public function getAccessibleWorkspaceNames()
    {
        $workspaces = $this->caches['meta']->fetch("workspaces");
        if (!$workspaces) {
            $workspaces = parent::getAccessibleWorkspaceNames();
            $this->caches['meta']->save("workspaces", $workspaces);
        }

        return $workspaces;
    }

    /**
     * {@inheritDoc}
     */
    public function getNode($path)
    {
        $this->assertLoggedIn();

        if (isset($this->caches['nodes']) && $result = $this->caches['nodes']->fetch("nodes: $path")) {
            return $result;
        }

        $node = parent::getNode($path);
        if (isset($this->caches['nodes'])) {
            $this->caches['nodes']->save("nodes: $path", $node);
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

        if (isset($this->caches['nodes']) && $result = $this->caches['nodes']->fetch("nodes by uuid: $uuid")) {
            return $result;
        }

        $path = parent::getNodePathForIdentifier($uuid);

        if (isset($this->caches['nodes'])) {
            $this->caches['nodes']->save("nodes by uuid: $uuid", $path);
        }

        return $path;
    }

    /**
     * {@inheritDoc}
     */
    protected function fetchUserNodeTypes()
    {
        if (!$this->inTransaction && $result = $this->caches['meta']->fetch('node_types')) {
            return $result;
        }

        $result = parent::fetchUserNodeTypes();

        if (!$this->inTransaction) {
            $this->caches['meta']->save('node_types', $result);
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
    protected function getNodeReferences($path, $name = null, $weakReference = false)
    {
        if (isset($this->caches['nodes']) && $result = $this->caches['nodes']->fetch("nodes references: $path, $name, $weakReference")) {
            return $result;
        }

        $references = parent::getNodeReferences($path, $name, $weakReference);

        if (isset($this->caches['nodes'])) {
            $this->caches['nodes']->save("nodes references: $path, $name, $weakReference", $references);
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
