<?php

namespace Jackalope\Test\Tester;

use ImplementationLoader;
use PHPCR\Test\FixtureLoaderInterface;
use PHPUnit\DbUnit\AbstractTester;
use PHPUnit\DbUnit\Database\Connection;
use PHPUnit\DbUnit\DataSet\XmlDataSet;

/**
 * Generic tester class.
 *
 * @author  cryptocompress <cryptocompress@googlemail.com>
 */
class Generic extends AbstractTester implements FixtureLoaderInterface
{
    /**
     * @var Connection
     */
    protected $connection;

    /**
     * @var string
     */
    protected $fixturePath;

    /**
     * Creates a new default database tester using the given connection.
     *
     * @param string $fixturePath
     */
    public function __construct(Connection $connection, $fixturePath)
    {
        parent::__construct();

        $this->connection   = $connection;
        $this->fixturePath  = $fixturePath;
    }

    /**
     * Returns the test database connection.
     *
     * @return Connection
     */
    public function getConnection()
    {
        return $this->connection;
    }

    public function import($fixtureName, $workspace = null)
    {
        $fixture = $this->fixturePath . DIRECTORY_SEPARATOR . $fixtureName . '.xml';
        $this->setDataSet(new XmlDataSet($fixture));

        if ($workspace) {
            $dataSet = $this->getDataSet();

            // TODO: ugly hack, since we only really ever load a 2nd fixture in combination with '10_Writing/copy.xml'
            $fixture = $this->fixturePath . DIRECTORY_SEPARATOR . '10_Writing/copy.xml';
            $this->setDataSet(new XmlDataSet($fixture));

            $loader = ImplementationLoader::getInstance();
            $workspaceName = $loader->getOtherWorkspaceName();

            $this->dataSet->getTable('phpcr_workspaces')->addRow(['name' => $workspaceName]);

            foreach (['phpcr_nodes', 'phpcr_binarydata'] as $tableName) {
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
