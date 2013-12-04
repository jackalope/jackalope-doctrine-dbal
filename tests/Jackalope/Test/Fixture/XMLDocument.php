<?php

namespace Jackalope\Test\Fixture;

/**
 * Base for Jackalope Document or System Views and PHPUnit DBUnit Fixture XML classes.
 *
 * @author Benjamin Eberlei <kontakt@beberlei.de>
 * @author cryptocompress <cryptocompress@googlemail.com>
 */
abstract class XMLDocument extends \DOMDocument
{
    /**
     * file path
     *
     * @var string
     */
    protected $file;

    /**
     * @var int
     */
    protected $options;

    /**
     * @var array
     */
    protected $jcrTypes;

    /**
     * @var array
     */
    protected $namespaces;

    /**
     * @param string $file    - file path
     * @param int    $options - libxml option constants: http://www.php.net/manual/en/libxml.constants.php
     */
    public function __construct($file, $options = null)
    {
        parent::__construct('1.0', 'UTF-8');

        $this->preserveWhiteSpace   = false;
        $this->formatOutput         = true;
        $this->strictErrorChecking  = true;
        $this->validateOnParse      = true;
        $this->file                 = $file;
        $this->options              = $options;

        $this->jcrTypes = array(
            'string'        => array(1, 'clob_data'),
            'binary'        => array(2, 'int_data'),
            'long'          => array(3, 'int_data'),
            'double'        => array(4, 'float_data'),
            'date'          => array(5, 'datetime_data'),
            'boolean'       => array(6, 'int_data'),
            'name'          => array(7, 'string_data'),
            'path'          => array(8, 'string_data'),
            'reference'     => array(9, 'string_data'),
            'weakreference' => array(10, 'string_data'),
            'uri'           => array(11, 'string_data'),
            'decimal'       => array(12, 'string_data'),
        );

        $this->namespaces = array(
            'xml'   => 'http://www.w3.org/XML/1998/namespace',
            'mix'   => 'http://www.jcp.org/jcr/mix/1.0',
            'nt'    => 'http://www.jcp.org/jcr/nt/1.0',
            'xs'    => 'http://www.w3.org/2001/XMLSchema',
            'jcr'   => 'http://www.jcp.org/jcr/1.0',
            'sv'    => 'http://www.jcp.org/jcr/sv/1.0',
            'phpcr' => 'http://www.jcp.org/jcr/phpcr/1.0',
            'rep'   => 'internal'
        );
    }

    /**
     * Load xml file.
     *
     * @param string $file    - file path
     * @param int    $options - libxml option constants: http://www.php.net/manual/en/libxml.constants.php
     *
     * @return \Jackalope\Test\XMLDocument
     */
    public function load($file = null, $options = null)
    {
        if (isset($file)) {
            $this->file = $file;
        }

        if (isset($options)) {
            $this->options = $options;
        }

        parent::load($this->file, $this->options);

        return $this;
    }

    /**
     * Dumps the internal XML tree back into a string.
     *
     * @param \DOMNode $node    - node to dump
     * @param int      $options - libxml option constants: http://www.php.net/manual/en/libxml.constants.php
     *
     * @return string
     */
    public function saveXml(\DOMNode $node = null, $options = null)
    {
        return str_replace('escaping_x0020 bla &lt;&gt;\'""', 'escaping_x0020 bla"', parent::saveXML($node));
    }

    /**
     * Dumps the internal XML tree back into a file.
     *
     * @param string $file
     *
     * @return \Jackalope\Test\XMLDocument
     */
    public function save($file = null)
    {
        if (isset($file)) {
            $this->file = $file;
        }

        @mkdir(dirname($this->file), 0777, true);
        file_put_contents($this->file, $this->saveXml());

        return $this;
    }

}
