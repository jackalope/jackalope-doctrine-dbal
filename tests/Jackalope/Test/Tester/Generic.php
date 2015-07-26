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
        $fixture = $this->fixturePath . DIRECTORY_SEPARATOR . $fixtureName . '.xml';
        $this->setDataSet(new \PHPUnit_Extensions_Database_DataSet_XmlDataSet($fixture));

        if ($workspace) {
            $dataSet = $this->getDataSet();

            // TODO: ugly hack, since we only really ever load a 2nd fixture in combination with '10_Writing/copy.xml'
            $fixture = $this->fixturePath . DIRECTORY_SEPARATOR . '10_Writing/copy.xml';
            $this->setDataSet(new \PHPUnit_Extensions_Database_DataSet_XmlDataSet($fixture));

            $loader = \ImplementationLoader::getInstance();
            $workspaceName = $loader->getOtherWorkspaceName();

            $this->dataSet->getTable('phpcr_workspaces')->addRow(array('name' => $workspaceName));

            foreach (array('phpcr_nodes', 'phpcr_binarydata') as $tableName) {
                $table = $dataSet->getTable($tableName);
                $targetTable = $this->dataSet->getTable($tableName);

                for ($i = 0; $i < $table->getRowCount(); $i++) {
                    $row = $table->getRow($i);
                    $row['workspace_name'] = $workspaceName;
                    $targetTable->addRow($row);
                }
            }
        }

        $this->onSetUp();
    }
}
