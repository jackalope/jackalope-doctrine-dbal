<?php

declare(strict_types=1);

namespace Jackalope\Test\Tester;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
use function libxml_clear_errors;
use function libxml_get_errors;
use function libxml_use_internal_errors;
use RuntimeException;
use SimpleXMLElement;

class XmlDataSet
{
    /**
     * @var array
     */
    protected $tables;

    /**
     * @var SimpleXMLElement
     */
    protected $xmlFileContents;

    /**
     * @var array
     */
    private $rows = [];

    /**
     * Creates a new dataset using the given tables.
     *
     * @param string $xmlFile
     */
    public function __construct($xmlFile)
    {
        if (!\is_file($xmlFile)) {
            throw new InvalidArgumentException("Could not find xml file: $xmlFile");
        }

        $libxmlErrorReporting = libxml_use_internal_errors(true);
        $this->xmlFileContents = simplexml_load_string(file_get_contents($xmlFile), 'SimpleXMLElement', LIBXML_COMPACT | LIBXML_PARSEHUGE);

        if (!$this->xmlFileContents) {
            $message = '';

            foreach (libxml_get_errors() as $error) {
                $message .= \print_r($error, true);
            }

            throw new RuntimeException($message);
        }

        libxml_clear_errors();
        libxml_use_internal_errors($libxmlErrorReporting);

        $tableColumns = [];
        $tableValues = [];

        $this->getTableInfo($tableColumns, $tableValues);
        $this->createTables($tableColumns, $tableValues);
    }

    /**
     * @return Table[]
     */
    public function getTables(): array
    {
        return $this->tables;
    }

    public function getRows(string $tableName): array
    {
        return $this->rows[$tableName] ?? [];
    }

    public function addRow(string $tableName, array $values)
    {
        $this->rows[$tableName][] = $values;
    }

    protected function createTables(array &$tableColumns, array &$tableValues): void
    {
        foreach ($tableValues as $tableName => $values) {
            $this->getOrCreateTable($tableName, $tableColumns[$tableName]);

            foreach ($values as $value) {
                $this->rows[$tableName][] = $value;
            }
        }
    }

    protected function getTableInfo(array &$tableColumns, array &$tableValues): void
    {
        if ('dataset' !== $this->xmlFileContents->getName()) {
            throw new RuntimeException('The root element of an xml data set file must be called <dataset>');
        }

        foreach ($this->xmlFileContents->xpath('/dataset/table') as $tableElement) {
            if (empty($tableElement['name'])) {
                throw new RuntimeException('Table elements must include a name attribute specifying the table name.');
            }

            $tableName = (string) $tableElement['name'];

            if (!isset($tableColumns[$tableName])) {
                $tableColumns[$tableName] = [];
            }

            if (!isset($tableValues[$tableName])) {
                $tableValues[$tableName] = [];
            }

            $tableInstanceColumns = [];

            foreach ($tableElement->xpath('./column') as $columnElement) {
                $columnName = (string) $columnElement;

                if (empty($columnName)) {
                    throw new RuntimeException("Missing <column> elements for table $tableName. Add one or more <column> elements to the <table> element.");
                }

                if (!\in_array($columnName, $tableColumns[$tableName], true)) {
                    $tableColumns[$tableName][] = $columnName;
                }

                $tableInstanceColumns[] = $columnName;
            }

            foreach ($tableElement->xpath('./row') as $rowElement) {
                $rowValues = [];
                $index = 0;
                $numOfTableInstanceColumns = \count($tableInstanceColumns);

                foreach ($rowElement->children() as $columnValue) {
                    if ($index >= $numOfTableInstanceColumns) {
                        throw new RuntimeException("Row contains more values than the number of columns defined for table $tableName.");
                    }

                    switch ($columnValue->getName()) {
                        case 'value':
                            $rowValues[$tableInstanceColumns[$index]] = (string) $columnValue;
                            ++$index;

                            break;
                        case 'null':
                            $rowValues[$tableInstanceColumns[$index]] = null;
                            ++$index;

                            break;
                        default:
                            throw new RuntimeException('Unknown element '.$columnValue->getName().' in a row element.');
                    }
                }

                $tableValues[$tableName][] = $rowValues;
            }
        }
    }

    /**
     * Returns the table with the matching name. If the table does not exist
     * an empty one is created.
     *
     * @param mixed $tableColumns
     *
     * @return Table
     */
    protected function getOrCreateTable(string $tableName, $tableColumns)
    {
        if (empty($this->tables[$tableName])) {
            $table = new Table($tableName, array_map(static function (string $columnName): Column {
                return new Column($columnName, Type::getType(Types::STRING));
            }, $tableColumns), [new Index('primary', [$tableColumns[0]], false, true)]);

            $this->tables[$tableName] = $table;
        }

        return $this->tables[$tableName];
    }
}
