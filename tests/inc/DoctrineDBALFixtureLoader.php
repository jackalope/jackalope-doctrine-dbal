<?php

require_once __DIR__.'/../../vendor/phpcr/phpcr-api-tests/inc/FixtureLoaderInterface.php';

/**
 * Import fixtures into the doctrine dbal backend of jackalope
 */
class DoctrineDBALFixtureLoader implements \PHPCR\Test\FixtureLoaderInterface
{
    private $testConn;
    private $fixturePath;

    public function __construct($conn, $fixturePath)
    {
        $this->testConn     = new \PHPUnit_Extensions_Database_DB_DefaultDatabaseConnection($conn, "tests");
        $this->fixturePath  = $fixturePath;
    }

    public function import($file)
    {
        $driver = $this->testConn->getConnection()->getAttribute(PDO::ATTR_DRIVER_NAME);
        $file   = $this->fixturePath . $file . ".xml";

        if (!file_exists($file)) {
            throw new PHPUnit_Framework_SkippedTestSuiteError("No fixtures $file, skipping this test suite"); // TODO: should we not do something that stops the tests from running? this is a very fundamental problem.
        }

        $dataSet    = new PHPUnit_Extensions_Database_DataSet_XmlDataSet($file);
        $tester     = new PHPUnit_Extensions_Database_DefaultTester($this->testConn);
        $tester->setSetUpOperation(PHPUnit_Extensions_Database_Operation_Factory::CLEAN_INSERT($driver == 'pgsql'));
        $tester->setTearDownOperation(PHPUnit_Extensions_Database_Operation_Factory::NONE());
        $tester->setDataSet($dataSet);

        try {
            // mysql from version 5.5.7 does not like to truncate tables with foreign key references: http://bugs.mysql.com/bug.php?id=58788
            $pdo = $this->testConn->getConnection();

            if ($driver == 'mysql') {
                $pdo->exec('SET foreign_key_checks = 0');
            }

            $tester->onSetUp();

            if ($driver == 'mysql') {
                $pdo->exec('SET foreign_key_checks = 1');
            }

            if ($driver == 'pgsql')    {
                // update next serial/autoincrement value to max
                foreach ($pdo->query("SELECT table_name, column_name FROM information_schema.columns WHERE column_default LIKE 'nextval%';")->fetchAll(PDO::FETCH_ASSOC) as $info) {
                    $query = "SELECT setval((SELECT pg_get_serial_sequence('" . $info['table_name'] . "', '" . $info['column_name'] . "') as sequence), (SELECT max(" . $info['column_name'] . ") FROM " . $info['table_name'] . "));";
                    $pdo->query($query);
                }
            }
        } catch(PHPUnit_Extensions_Database_Operation_Exception $e) {
            throw new RuntimeException("Could not load fixture ".$file.": ".$e->getMessage());
        }
    }
}
