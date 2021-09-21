<?php

namespace Jackalope\Test\Tester;

/**
 * PostgreSQL specific tester class.
 *
 * @author  cryptocompress <cryptocompress@googlemail.com>
 */
class Pgsql extends Generic
{
    public function onSetUp(): void
    {
        parent::onSetUp();

        $result = $this->connection->executeQuery("SELECT table_name, column_name FROM information_schema.columns WHERE column_default LIKE 'nextval%';");

        // update next serial/autoincrement value to max
        foreach ($result->fetchAllAssociative() as $info) {
            $query = "SELECT setval((SELECT pg_get_serial_sequence('".$info['table_name']."', '".$info['column_name']."') as sequence), (SELECT max(".$info['column_name'].') FROM '.$info['table_name'].'));';
            $this->connection->executeStatement($query);
        }
    }
}
