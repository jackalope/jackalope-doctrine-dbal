<?php

namespace Jackalope\Test;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Jackalope\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected ?Connection $conn;

    protected function getConnection(): Connection
    {
        if (isset($this->conn)) {
            return $this->conn;
        }
        // see https://github.com/jackalope/jackalope-doctrine-dbal/issues/48
        global $dbConn;
        if ($this->conn = $dbConn) {
            return $this->conn;
        }

        $params = [
            'driver' => @$GLOBALS['phpcr.doctrine.dbal.driver'],
            'path' => @$GLOBALS['phpcr.doctrine.dbal.path'],
            'host' => @$GLOBALS['phpcr.doctrine.dbal.host'],
            'port' => @$GLOBALS['phpcr.doctrine.dbal.port'],
            'user' => @$GLOBALS['phpcr.doctrine.dbal.username'],
            'password' => @$GLOBALS['phpcr.doctrine.dbal.password'],
            'dbname' => @$GLOBALS['phpcr.doctrine.dbal.dbname'],
        ];
        if (array_key_exists('phpcr.doctrine.dbal.collate', $GLOBALS)) {
            $params['defaultTableOptions'] = [
                'collate' => $GLOBALS['phpcr.doctrine.dbal.collate'],
            ];
        }
        if (array_key_exists('phpcr.doctrine.dbal.charset', $GLOBALS)) {
            $params['charset'] = $GLOBALS['phpcr.doctrine.dbal.charset'];
        }

        $this->conn = DriverManager::getConnection($params);

        return $this->conn;
    }
}
