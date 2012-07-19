<?php

/**
 * create database specific phpunit.xml for travis
 *
 * @author  cryptocompress <cryptocompress@googlemail.com>
 */

$source = __DIR__ . '/../phpunit.xml.dist';
$config = array(
    'mysql'    => array(
        'phpcr.doctrine.dbal.driver'    => 'pdo_mysql',
        'phpcr.doctrine.dbal.host'      => 'localhost',
        'phpcr.doctrine.dbal.username'  => 'travis',
        'phpcr.doctrine.dbal.password'  => '',
        'phpcr.doctrine.dbal.dbname'    => 'phpcr_tests',
    ),
    'pgsql'    => array(
        'phpcr.doctrine.dbal.driver'    => 'pdo_pgsql',
        'phpcr.doctrine.dbal.host'      => 'localhost',
        'phpcr.doctrine.dbal.username'  => 'postgres',
        'phpcr.doctrine.dbal.password'  => '',
        'phpcr.doctrine.dbal.dbname'    => 'phpcr_tests',
    ),
    'sqlite'    => array(
        'phpcr.doctrine.dbal.driver'    => 'pdo_sqlite',
        'phpcr.doctrine.dbal.path'      => 'phpcr_tests.db',
    ),
);

if (!in_array(@$_SERVER['DB'], array_keys($config))) {
    die('Error: Database "' . @$_SERVER['DB'] . '" not supported!' . "\n" . 'Try: export DB=mysql' . "\n");
}

$dom = new \DOMDocument('1.0', 'UTF-8');
$dom->preserveWhiteSpace    = false;
$dom->formatOutput          = true;
$dom->strictErrorChecking   = true;
$dom->validateOnParse       = true;
$dom->load($source);

$xpath  = new \DOMXPath($dom);
$parent = $xpath->query('/phpunit/php')->item(0);
$nodes  = $xpath->query('/phpunit/php/var[starts-with(@name,"phpcr.doctrine.dbal.")]');

foreach ($nodes as $node) {
    $parent->removeChild($node);
}

foreach ($config[$_SERVER['DB']] as $key => $value) {
    $node = $dom->createElement('var');
    $node->setAttribute('name', $key);
    $node->setAttribute('value', $value);
    $parent->appendChild($node);
}

$dom->save(str_replace('phpunit.xml.dist', 'phpunit.xml', $source));