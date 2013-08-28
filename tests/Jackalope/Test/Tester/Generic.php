<?php

namespace Jackalope\Test\Tester;

use PHPCR\Test\FixtureLoaderInterface;

/**
 * Generic tester class.
 *
 * @author  cryptocompress <cryptocompress@googlemail.com>
 */
class Generic extends \PHPUnit_Extensions_Database_AbstractTester implements FixtureLoaderInterface
{
    /**
     * @var \PHPUnit_Extensions_Database_DB_IDatabaseConnection
     */
    protected $connection;

    /**
     * @var string
     */
    protected $fixturePath;

    /**
     * Creates a new default database tester using the given connection.
     *
     * @param \PHPUnit_Extensions_Database_DB_IDatabaseConnection $connection
     * @param string                                              $fixturePath
     */
    public function __construct(\PHPUnit_Extensions_Database_DB_IDatabaseConnection $connection, $fixturePath)
    {
        parent::__construct();

        $this->connection   = $connection;
        $this->fixturePath  = $fixturePath;
    }

    /**
     * Returns the test database connection.
     *
     * @return \PHPUnit_Extensions_Database_DB_IDatabaseConnection
     */
    public function getConnection()
    {
        return $this->connection;
    }

    public function import($fixtureName, $workspace = null)
    {
        if ($workspace) {
            throw new \Exception('TODO: find a solution to import fixtures for other workspace');
        }
        // @TODO: this should not be BOOL/FALSE => wrong type. should be string or null.
        if ($fixtureName === false) {
            $fixtureName = null;
        }

        $fixture = $this->fixturePath . DIRECTORY_SEPARATOR . $fixtureName . '.xml';
        $this->setDataSet(new \PHPUnit_Extensions_Database_DataSet_XmlDataSet($fixture));
        $this->onSetUp();
    }

}
