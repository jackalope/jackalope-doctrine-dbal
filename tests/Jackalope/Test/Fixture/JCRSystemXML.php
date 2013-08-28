<?php

namespace Jackalope\Test\Fixture;

/**
 * Jackalope Document or System Views.
 *
 * @author Benjamin Eberlei <kontakt@beberlei.de>
 * @author cryptocompress <cryptocompress@googlemail.com>
 */
class JCRSystemXML extends XMLDocument
{
    /**
     * Returns all found namespaces.
     *
     * @return array
     */
    public function getNamespaces()
    {
        $namespaces = array();

        $xpath = new \DOMXPath($this);
        foreach ($xpath->query('namespace::*') as $node) {
            $namespaces[$this->documentElement->lookupPrefix($node->nodeValue)] = $node->nodeValue;
        }

        return $namespaces;
    }

    /**
     * Returns JSR nodes.
     *
     * @return \DOMNodeList
     */
    public function getNodes()
    {
        return $this->getElementsByTagNameNS($this->namespaces['sv'], 'node');
    }

}
