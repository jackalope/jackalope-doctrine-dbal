<?php

namespace Jackalope\Test\Fixture;

use PHPCR\Util\PathHelper;
use PHPCR\Util\UUIDHelper;

/**
 * Convert Jackalope Document or System Views into PHPUnit DBUnit Fixture XML files.
 *
 * @author Benjamin Eberlei <kontakt@beberlei.de>
 * @author cryptocompress <cryptocompress@googlemail.com>
 */
class DBUnitFixtureXML extends XMLDocument
{
    const DATEFORMAT = 'Y-m-d\TH:i:s.uP';

    /**
     * @var integer
     */
    protected static $idCounter = 1;

    /**
     * @var array
     */
    protected $tables;

    /**
     * @var array
     */
    protected $ids;

    /**
     * @var array
     */
    protected $references;

    /**
     * @var array
     */
    protected $expectedNodes;

    /**
     * @param string $file    - file path
     * @param int    $options - libxml option constants: http://www.php.net/manual/en/libxml.constants.php
     */
    public function __construct($file, $options = null)
    {
        parent::__construct($file, $options);

        $this->tables           = array();
        $this->ids              = array();
        $this->references       = array();
        $this->expectedNodes    = array();
    }

    public function addDataset()
    {
        $this->appendChild($this->createElement('dataset'));

        // purge binary in case no binary properties are in fixture
        $this->ensureTableExists('phpcr_binarydata', array(
            'node_id',
            'property_name',
            'workspace_name',
            'idx',
            'data',
        ));

        return $this;
    }

    public function addWorkspace($name)
    {
        $this->addRow('phpcr_workspaces', array('name' => $name));

        return $this;
    }

    public function addNamespaces(array $namespaces)
    {
        $namespaces = array_diff($namespaces, $this->namespaces);

        foreach ($namespaces as $prefix => $uri) {
            $this->addRow('phpcr_namespaces', array('prefix' => $prefix, 'uri' => $uri));
        }

        return $this;
    }

    /**
     * Add all nodes from the fixtures xml document.
     *
     * If the root node is not called jcr:root, autogenerate a root node.
     *
     * @param string       $workspaceName
     * @param \DOMNodeList $nodes
     *
     * @return DBUnitFixtureXML
     */
    public function addNodes($workspaceName, \DOMNodeList $nodes)
    {
        $node = $nodes->item(0);
        if ('jcr:root' !== $node->getAttributeNS($this->namespaces['sv'], 'name')) {
            $this->addRootNode('tests');
        }
        foreach ($nodes as $node) {
            $this->addNode($workspaceName, $node);
        }

        return $this;
    }

    public function addRootNode($workspaceName = 'default')
    {
        $uuid = UUIDHelper::generateUUID();
        $this->ids[$uuid] = self::$idCounter++;

        return $this->addRow('phpcr_nodes', array(
            'id'            => $this->ids[$uuid],
            'path'          => '/',
            'parent'        => '',
            'local_name'    => '',
            'namespace'     => '',
            'workspace_name'=> $workspaceName,
            'identifier'    => $uuid,
            'type'          => 'nt:unstructured',
            'props'         => '<?xml version="1.0" encoding="UTF-8"?>'
                            . '<sv:node xmlns:crx="http://www.day.com/crx/1.0"'
                            . 'xmlns:lx="http://flux-cms.org/2.0"'
                            . 'xmlns:test="http://liip.to/jackalope"'
                            . 'xmlns:mix="http://www.jcp.org/jcr/mix/1.0"'
                            . 'xmlns:sling="http://sling.apache.org/jcr/sling/1.0"'
                            . 'xmlns:nt="http://www.jcp.org/jcr/nt/1.0"'
                            . 'xmlns:fn_old="http://www.w3.org/2004/10/xpath-functions"'
                            . 'xmlns:fn="http://www.w3.org/2005/xpath-functions"'
                            . 'xmlns:vlt="http://www.day.com/jcr/vault/1.0"'
                            . 'xmlns:xs="http://www.w3.org/2001/XMLSchema"'
                            . 'xmlns:new_prefix="http://a_new_namespace"'
                            . 'xmlns:jcr="http://www.jcp.org/jcr/1.0"'
                            . 'xmlns:sv="http://www.jcp.org/jcr/sv/1.0"'
                            . 'xmlns:rep="internal" />',
            'depth'         => 0,
            'sort_order'    => 0,
        ));
    }

    public function addNode($workspaceName, \DOMElement $node)
    {
        $properties = $this->getAttributes($node);
        $uuid = isset($properties['jcr:uuid']['value'][0])
            ? (string) $properties['jcr:uuid']['value'][0] : UUIDHelper::generateUUID();
        $this->ids[$uuid] = $id = isset($this->expectedNodes[$uuid])
            ? $this->expectedNodes[$uuid] : self::$idCounter++;

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $phpcrNode = $dom->createElement('sv:node');
        foreach ($this->namespaces as $namespace => $uri) {
            $phpcrNode->setAttribute('xmlns:' . $namespace, $uri);
        }
        $dom->appendChild($phpcrNode);

        foreach ($properties as $propertyName => $propertyData) {
            if ($propertyName == 'jcr:uuid') {
                continue;
            }

            if (!isset($this->jcrTypes[$propertyData['type']])) {
                throw new \InvalidArgumentException('"' . $propertyData['type'] . '" is not a valid JCR type.');
            }

            $phpcrNode->appendChild($this->createPropertyNode($workspaceName, $propertyName, $propertyData, $id, $dom, $phpcrNode));
        }

        list ($parentPath, $childPath) = $this->getPath($node);

        $namespace  = '';
        $name       = $node->getAttributeNS($this->namespaces['sv'], 'name');
        if (count($parts = explode(':', $name, 2)) == 2) {
            list($namespace, $name) = $parts;
        }

        if ($namespace == 'jcr' && $name == 'root') {
            $id         = 1;
            $childPath  = '/';
            $parentPath = '';
            $name       = '';
            $namespace  = '';
        }

        $this->addRow('phpcr_nodes', array(
            'id'            => $id,
            'path'          => $childPath,
            'parent'        => $parentPath,
            'local_name'    => $name,
            'namespace'     => $namespace,
            'workspace_name'=> $workspaceName,
            'identifier'    => $uuid,
            'type'          => $properties['jcr:primaryType']['value'][0],
            'props'         => $dom->saveXML(),
            'depth'         => PathHelper::getPathDepth($childPath),
            'sort_order'    => $id - 2,
        ));

        return $this;
    }

    public function addReferences()
    {
        foreach ($this->references as $type => $references) {
            $table = 'phpcr_nodes_'.$type.'s';

            // make sure we have the references even if there is not a single entry in it to have it truncated
            $this->ensureTableExists($table, array('source_id', 'source_property_name', 'target_id'));

            foreach ($references as $uuid => $reference) {
                if (isset($this->ids[$uuid])) {
                    foreach ($reference as $data) {
                        $this->addRow($table, $data);
                    }
                }
            }
        }

        return $this;
    }

    public function getAttributes(\DOMElement $node)
    {
        $properties = array();

        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMElement && $child->tagName == 'sv:property') {
                list ($name, $propertyNameibute) = $this->getChildAttribute($child);
                $properties[$name] = $propertyNameibute;
            }
        }

        return $properties;
    }

    public function getChildAttribute(\DOMElement $node)
    {
        $name = $node->getAttributeNS($this->namespaces['sv'], 'name');
        $type = strtolower($node->getAttributeNS($this->namespaces['sv'], 'type'));

        $values = array();
        if ($name == 'jcr:created') {
            $values[] = date(self::DATEFORMAT);
        } else {
            foreach ($node->getElementsByTagNameNS($this->namespaces['sv'], 'value') as $nodeValue) {
                $values[] = $nodeValue->nodeValue;
            }
        }

        $isMultiValue = false;
        if ($name == 'jcr:mixinTypes'
            || count($values) > 1
            || ($node->hasAttributeNS($this->namespaces['sv'], 'multiple') && $node->getAttributeNS($this->namespaces['sv'], 'multiple') == 'true')
        ) {
            $isMultiValue = true;
        }

        return array($name, array('type' =>  $type, 'value' => $values, 'multiValued' => $isMultiValue));
    }

    public function createPropertyNode($workspaceName, $propertyName, $propertyData, $id, \DOMDocument $dom)
    {
        $propertyNode = $dom->createElement('sv:property');
        $propertyNode->setAttribute('sv:name', $propertyName);
        $propertyNode->setAttribute('sv:type', $propertyData['type']);
        $propertyNode->setAttribute('sv:multi-valued', $propertyData['multiValued'] ? '1' : '0');

        $binaryDataIdx = 0;
        foreach ($propertyData['value'] as $value) {
            $propertyNode->appendChild($this->createValueNodeByType($workspaceName, $propertyData['type'], $value, $id, $propertyName, $binaryDataIdx++, $dom));
        }

        return $propertyNode;
    }

    public function createValueNodeByType($workspaceName, $type, $value, $id, $propertyName, $binaryDataIdx, \DOMDocument $dom)
    {
        $length = is_scalar($value) ? strlen($value) : null;
        switch ($type) {
            case 'binary':
                $value = $this->addBinaryNode($id, $propertyName, $workspaceName, $binaryDataIdx, $value);
                $length = $value;
                break;

            case 'boolean':
                $value = ('true' === $value) ? '1' : '0';
                $length = 1;
                break;

            case 'date':
                $value = date_format(date_create_from_format(self::DATEFORMAT, $value), self::DATEFORMAT);
                break;

            case 'weakreference':
            case 'reference':
                if (isset($this->ids[$value])) {
                    $targetId = $this->ids[$value];
                } elseif (isset($this->expectedNodes[$value])) {
                    $targetId = $this->expectedNodes[$value];
                } else {
                    $targetId = $this->expectedNodes[$value] = self::$idCounter++;
                }
                $this->references[$type][$value][] = array(
                    'source_id'             => $id,
                    'source_property_name'  => $propertyName,
                    'target_id'             => $targetId,
                );
                break;
        }

        return $this->createValueNode($value, $dom, $length);
    }

    public function createValueNode($value, \DOMDocument $dom, $length)
    {
        $valueNode = $dom->createElement('sv:value');

        if (is_string($value) && strpos($value, ' ') !== false) {
            $valueNode->appendChild($dom->createCDATASection($value));
        } else {
            $valueNode->appendChild($dom->createTextNode($value));
        }

        if (null !== $length) {
            $lengthAttribute = $dom->createAttribute('length');
            $lengthAttribute->value = $length;
            $valueNode->appendChild($lengthAttribute);
        }

        return $valueNode;
    }

    public function getPath(\DOMElement $node)
    {
        $childPath  = '';

        $parent = $node;
        do {
            if ($parent->tagName == 'sv:node') {
                $childPath = '/' . $parent->getAttributeNS($this->namespaces['sv'], 'name') . $childPath;
            }
            $parent = $parent->parentNode;
        } while ($parent instanceof \DOMElement);

        $parentPath = implode('/', array_slice(explode('/', $childPath), 0, -1));
        if (empty($parentPath)) {
            $parentPath = '/';
        }

        return array($parentPath, $childPath);
    }

    /**
     * @param int    $id
     * @param string $propertyName
     * @param string $workspaceName
     * @param int    $idx
     * @param string $data
     *
     * @return int - length of base64 decoded string
     */
    public function addBinaryNode($id, $propertyName, $workspaceName, $idx, $data)
    {
        $data = base64_decode($data);

        $this->addRow('phpcr_binarydata', array(
            'node_id'       => $id,
            'property_name' => $propertyName,
            'workspace_name'  => $workspaceName,
            'idx'           => $idx,
            'data'          => $data,
        ));

        return strlen($data);
    }

    protected function addRow($tableName, array $data)
    {
        $this->ensureTableExists($tableName, array_keys($data));

        $row = $this->createElement('row');
        foreach ($data as $value) {
            if (null === $value) {
                $row->appendChild($this->createElement('null'));
            } else {
                $row->appendChild($this->createElement('value', htmlspecialchars($value)));
            }
        }
        $this->tables[$tableName]->appendChild($row);

        return $this;
    }

    protected function ensureTableExists($tableName, $columns)
    {
        if (!isset($this->tables[$tableName])) {
            $table = $this->createElement('table');
            $table->setAttribute('name', $tableName);
            foreach ($columns as $k) {
                $table->appendChild($this->createElement('column', $k));
            }
            $this->documentElement->appendChild($table);

            $this->tables[$tableName] = $table;
        }

        return $this;
    }

}
