<?php

namespace Jackalope\Test\Tester;

use PHPUnit\DbUnit\Database\Connection;
use PHPUnit\DbUnit\Operation\Factory;
use PHPUnit\DbUnit\Operation_Factory;

/**
 * PostgreSQL specific tester class.
 *
 * @author  cryptocompress <cryptocompress@googlemail.com>
 */
class Pgsql extends Generic
{
    public function __construct(Connection $connection, $fixturePath)
    {
        parent::__construct($connection, $fixturePath);

        $this->setUpOperation = Factory::CLEAN_INSERT(true);
    }

    public function onSetUp(): void
    {
        parent::onSetUp();

        $pdo = $this->getConnection()->getConnection();
        // update next serial/autoincrement value to max
        foreach ($pdo->query("SELECT table_name, column_name FROM information_schema.columns WHERE column_default LIKE 'nextval%';")->fetchAll(\PDO::FETCH_ASSOC) as $info) {
            $query = "SELECT setval((SELECT pg_get_serial_sequence('" . $info['table_name'] . "', '" . $info['column_name'] . "') as sequence), (SELECT max(" . $info['column_name'] . ") FROM " . $info['table_name'] . "));";
            $pdo->query($query);
        }
    }
}
