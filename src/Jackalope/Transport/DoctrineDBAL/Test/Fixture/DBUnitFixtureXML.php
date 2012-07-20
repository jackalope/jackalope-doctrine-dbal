<?php
namespace Jackalope\Transport\DoctrineDBAL\Test\Fixture;

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
    protected $foreignKeys;

    /**
     * @var array
     */
    protected $expectedNodes;

    /**
     * @param   string  $file       - file path
     * @param   int     $options    - libxml option constants: http://www.php.net/manual/en/libxml.constants.php
     */
    public function __construct($file, $options = null)
    {
        parent::__construct($file, $options);

        $this->tables           = array();
        $this->ids              = array();
        $this->foreignKeys      = array();
        $this->expectedNodes    = array();
    }

    public function addDataset()
    {
        $this->appendChild($this->createElement('dataset'));

        return $this;
    }

    public function addWorkspace($id, $name)
    {
        $this->addRow('phpcr_workspaces', array('id' => $id, 'name' => $name));

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

    public function addNodes(\DOMNodeList $nodes)
    {
        foreach ($nodes as $node) {
            $this->addNode($node);
        }

        return $this;
    }

    public function addRootNode($id, $uuid, $path = '/', $workspaceId = 1)
    {
        $this->ids[$uuid] = $id;

        return $this->addRow('phpcr_nodes', array(
            'id'            => $id,
            'path'          => $path,
            'parent'        => '',
            'local_name'    => '',
            'namespace'     => '',
            'workspace_id'  => $workspaceId,
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
            'sort_order'    => 0,
        ));
    }

    public function addNode(\DOMElement $node)
    {
        $properties             = $this->getAttributes($node);
        $uuid                   = isset($properties['jcr:uuid']['value'][0]) ? (string)$properties['jcr:uuid']['value'][0] : \PHPCR\Util\UUIDHelper::generateUUID();
        $this->ids[$uuid] = $id = isset($this->expectedNodes[$uuid])         ? $this->expectedNodes[$uuid]                 : count($this->ids) + 1;

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $rootNode = $dom->createElement('sv:node');
        foreach ($this->namespaces as $namespace => $uri) {
            $rootNode->setAttribute('xmlns:' . $namespace, $uri);
        }
        $dom->appendChild($rootNode);

        foreach ($properties as $propertyName => $propertyData) {
            if ($propertyName == 'jcr:uuid') {
                continue;
            }

            if (!isset($this->jcrTypes[$propertyData['type']])) {
                throw new \InvalidArgumentException('"' . $propertyData['type'] . '" is not a valid JCR type.');
            }

            $rootNode->appendChild($this->createPropertyNode($propertyName, $propertyData, $id, $dom, $rootNode));
        }

        list ($parentPath, $childPath) = $this->getPath($node);

        $namespace  = '';
        $name       = $node->getAttributeNS($this->namespaces['sv'], 'name');
        if (count($parts = explode(':', $name, 2)) == 2) {
            list($namespace, $name) = $parts;
        }

        $this->addRow('phpcr_nodes', array(
            'id'            => $id,
            'path'          => $childPath,
            'parent'        => $parentPath,
            'local_name'    => $name,
            'namespace'     => $namespace,
            'workspace_id'  => 1,
            'identifier'    => $uuid,
            'type'          => $properties['jcr:primaryType']['value'][0],
            'props'         => $dom->saveXML(),
            'sort_order'    => $id - 2,
        ));

        return $this;
    }

    public function addForeignKeys()
    {
        // make sure we have table phpcr_nodes_foreignkeys even if there is not a single entry in it to have it truncated
        $this->ensureTableExists('phpcr_nodes_foreignkeys', array('source_id', 'source_property_name', 'target_id', 'type'));

        // delay this to the end to not add entries for weak refs to not existing nodes
        foreach($this->foreignKeys as $uuid => $foreignKey) {
            if (isset($this->ids[$uuid])) {
                foreach($foreignKey as $data) {
                    $this->addRow('phpcr_nodes_foreignkeys', $data);
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

    public function createPropertyNode($propertyName, $propertyData, $id, \DOMDocument $dom)
    {
        $propertyNode = $dom->createElement('sv:property');
        $propertyNode->setAttribute('sv:name', $propertyName);
        $propertyNode->setAttribute('sv:type', $propertyData['type']);
        $propertyNode->setAttribute('sv:multi-valued', $propertyData['multiValued'] ? '1' : '0');

        $binaryDataIdx = 0;
        foreach ($propertyData['value'] as $value) {
            $propertyNode->appendChild($this->createValueNodeByType($propertyData['type'], $value, $id, $propertyName, $binaryDataIdx++, $dom));
        }

        return $propertyNode;
    }

    public function createValueNodeByType($type, $value, $id, $propertyName, $binaryDataIdx, \DOMDocument $dom)
    {
        switch ($type) {
            case 'binary':
                $value = $this->addBinaryNode($id, $propertyName, 1, $binaryDataIdx, $value);
                break;

            case 'boolean':
                $value = ('true' === $value) ? '1' : '0';
                break;

            case 'date':
                $value = date_format(date_create_from_format(self::DATEFORMAT, $value), self::DATEFORMAT);
                break;

            case 'weakreference':
            case 'reference':
                if (isset($this->ids[$value])) {
                    $targetId = $this->ids[$value];
                } else if (isset($this->expectedNodes[$value])) {
                    $targetId = $this->expectedNodes[$value];
                } else {
                    $targetId = $this->expectedNodes[$value] = count($this->ids) + 1;
                }
                $this->foreignKeys[$value][] = array(
                    'source_id'             => $id,
                    'source_property_name'  => $propertyName,
                    'target_id'             => $targetId,
                    'type'                  => $this->jcrTypes[$type][0],
                );
                break;
        }

        return $this->createValueNode($value, $dom);
    }

    public function createValueNode($value, \DOMDocument $dom)
    {
        $valueNode = $dom->createElement('sv:value');

        if (is_string($value) && strpos($value, ' ') !== false) {
            $valueNode->appendChild($dom->createCDATASection($value));
        } else {
            $valueNode->appendChild($dom->createTextNode($value));
        }

        return $valueNode;
    }

    public function getPath(\DOMElement $node)
    {
        $childPath  = '';
        $parentPath = '';

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
     * @param   int     $id
     * @param   string  $propertyName
     * @param   int     $workspaceId
     * @param   int     $idx
     * @param   string  $data
     *
     * @return  int     - length of base64 decoded string
     */
    public function addBinaryNode($id, $propertyName, $workspaceId, $idx, $data)
    {
        $data = base64_decode($data);

        $this->addRow('phpcr_binarydata', array(
            'node_id'       => $id,
            'property_name' => $propertyName,
            'workspace_id'  => $workspaceId,
            'idx'           => $idx,
            'data'          => $data,
        ));

        return strlen($data);
    }

    protected function addRow($tableName, array $data)
    {
        $this->ensureTableExists($tableName, array_keys($data));

        $row = $this->createElement('row');
        foreach ($data as $k => $v) {
            if ($v === null) {
                $row->appendChild($this->createElement('null'));
            } else {
                $row->appendChild($this->createElement('value', $v));
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
