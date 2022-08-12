<?php

namespace Jackalope\Test\Fixture;

use DOMDocument;

/**
 * Base for Jackalope Document or System Views and PHPUnit DBUnit Fixture XML classes.
 *
 * @author Benjamin Eberlei <kontakt@beberlei.de>
 * @author cryptocompress <cryptocompress@googlemail.com>
 */
abstract class XMLDocument
{
    /**
     * @var DOMDocument
     */
    protected $dom;

    /**
     * file path.
     *
     * @var string
     */
    protected $file;

    /**
     * @var array
     */
    protected $jcrTypes;

    /**
     * @var array
     */
    protected $namespaces;

    /**
     * @param string $file Path to XML file
     */
    public function __construct(string $file)
    {
        $this->dom = new DOMDocument('1.0', 'UTF-8');
        $this->dom->preserveWhiteSpace = false;
        $this->dom->formatOutput = true;
        $this->dom->strictErrorChecking = true;
        $this->dom->validateOnParse = true;

        $this->file = $file;

        $this->jcrTypes = [
            'string' => [1, 'clob_data'],
            'binary' => [2, 'int_data'],
            'long' => [3, 'int_data'],
            'double' => [4, 'float_data'],
            'date' => [5, 'datetime_data'],
            'boolean' => [6, 'int_data'],
            'name' => [7, 'string_data'],
            'path' => [8, 'string_data'],
            'reference' => [9, 'string_data'],
            'weakreference' => [10, 'string_data'],
            'uri' => [11, 'string_data'],
            'decimal' => [12, 'string_data'],
        ];

        $this->namespaces = [
            'xml' => 'http://www.w3.org/XML/1998/namespace',
            'mix' => 'http://www.jcp.org/jcr/mix/1.0',
            'nt' => 'http://www.jcp.org/jcr/nt/1.0',
            'xs' => 'http://www.w3.org/2001/XMLSchema',
            'jcr' => 'http://www.jcp.org/jcr/1.0',
            'sv' => 'http://www.jcp.org/jcr/sv/1.0',
            'phpcr' => 'http://www.jcp.org/jcr/phpcr/1.0',
            'rep' => 'internal',
        ];
    }

    public function loadDocument(): XMLDocument
    {
        $this->dom->load($this->file);

        return $this;
    }

    /**
     * Dumps the internal XML tree back into a file.
     *
     * @return XMLDocument
     */
    public function save()
    {
        @mkdir(dirname($this->file), 0777, true);
        file_put_contents($this->file, $this->toXmlString());

        return $this;
    }

    private function toXmlString()
    {
        return str_replace('escaping_x0020 bla &lt;&gt;\'""', 'escaping_x0020 bla"', $this->dom->saveXML());
    }
}
