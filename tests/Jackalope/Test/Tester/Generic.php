<?php

namespace Jackalope\Test\Tester;

use Doctrine\DBAL\Connection;
use ImplementationLoader;
use function implode;
use PHPCR\Test\FixtureLoaderInterface;

/**
 * Generic tester class.
 *
 * @author  cryptocompress <cryptocompress@googlemail.com>
 */
class Generic implements FixtureLoaderInterface
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
     * @var XmlDataSet
     */
    private $dataSet;

    /**
     * Creates a new default database tester using the given connection.
     *
     * @param string $fixturePath
     */
    public function __construct(Connection $connection, $fixturePath)
    {
        $this->connection = $connection;
        $this->fixturePath = $fixturePath;
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
        $fixture = $this->fixturePath.DIRECTORY_SEPARATOR.$fixtureName.'.xml';
        $this->dataSet = new XmlDataSet($fixture);

        if ($workspace) {
            $dataSet = $this->dataSet;

            // TODO: ugly hack, since we only really ever load a 2nd fixture in combination with '10_Writing/copy.xml'
            $fixture = $this->fixturePath.DIRECTORY_SEPARATOR.'10_Writing/copy.xml';
            $this->dataSet = new XmlDataSet($fixture);

            $loader = ImplementationLoader::getInstance();
            $workspaceName = $loader->getOtherWorkspaceName();

            $this->dataSet->addRow('phpcr_workspaces', ['name' => $workspaceName]);

            foreach (['phpcr_nodes', 'phpcr_binarydata'] as $tableName) {
                $table = $dataSet->getRows($tableName);

                foreach ($table as $row) {
                    $row['workspace_name'] = $workspaceName;
                    $this->dataSet->addRow($tableName, $row);
                }
            }
        }

        $this->onSetUp();
    }

    public function onSetUp(): void
    {
        $platform = $this->connection->getDatabasePlatform();
        foreach ($this->dataSet->getTables() as $table) {
            $this->connection->executeStatement($platform->getTruncateTableSQL($table->getName(), true));
        }

        foreach ($this->dataSet->getTables() as $table) {
            foreach ($this->dataSet->getRows($table->getName()) as $row) {
                $sql = 'INSERT INTO '.$platform->quoteIdentifier($table->getName()).
                    ' ('.implode(',', array_keys($row)).') VALUES ('.
                    implode(',', array_fill(0, count($row), '?')).')';

                $this->connection->executeStatement($sql, array_values($row));
            }
        }
    }
}
