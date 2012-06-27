#!/usr/bin/env php
<?php
/**
 * Script to create test database.
 *
 * @author	CryptoCompress <cryptocompress+jackalope-doctrine-dbal@googlemail.com>
 *
 * @usage	/usr/bin/php ./create_database.php "phpunit.xml.pgsql" --force-drop-database
 */

$configFile			= __DIR__ . DIRECTORY_SEPARATOR . 'phpunit.xml.' . @$_SERVER['DB'];
$forceDropDatabase	= false;

// parse script parameter
unset($argv[0]);
foreach ($argv as $param) {
	switch ($param) {
		case '--force-drop-database':
			$forceDropDatabase = true;
			break;
		case (realpath($param) !== false):
			$configFile = $param;
			break;
		case (realpath(__DIR__ . DIRECTORY_SEPARATOR . $param) !== false):
			$configFile = __DIR__ . DIRECTORY_SEPARATOR . $param;
			break;
		default: break;
	}
}

$configFile = realpath($configFile);
if ($configFile === false) {
	die("Config file not found!\n\t=> " . $param);
}

// build xml/xpath objects
$domDoc = new DOMDocument('1.0', 'UTF-8');
if (!$domDoc->loadXML(file_get_contents($configFile))) {
	throw new \Exception('Cannot parse config!');
}
$xpath = new \DOMXPath($domDoc);

// get config values
$cfg = array();
foreach ($xpath->query('/phpunit/php/var[starts-with(@name,"phpcr.doctrine.dbal.")]') as $node) {
	$cfg[str_replace('phpcr.doctrine.dbal.', '', $node->getAttribute('name'))] = $node->getAttribute('value');
}

// create database
try {
	$dsn = str_replace('pdo_', '', $cfg['driver']) . ':host=' . $cfg['host'];

	$pdo = new PDO($dsn, $cfg['username'], $cfg['password']);
	$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

	if ($forceDropDatabase) {
		$pdo->exec('DROP DATABASE IF EXISTS ' . $cfg['dbname']);
	}

	$pdo->exec('CREATE DATABASE ' . $cfg['dbname']);
} catch (\Exception $e) {
	echo $e->getMessage() . "\nCheck your config file:\n" . $configFile . "\n";
	exit(127);
}
