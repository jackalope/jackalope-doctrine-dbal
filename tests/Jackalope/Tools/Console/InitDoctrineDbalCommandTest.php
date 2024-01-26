<?php

namespace Jackalope\Tools\Console;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\SchemaConfig;
use Jackalope\Tools\Console\Command\InitDoctrineDbalCommand;
use Jackalope\Tools\Console\Helper\DoctrineDbalHelper;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Helper\HelperSet;
use Symfony\Component\Console\Tester\CommandTester;

class InitDoctrineDbalCommandTest extends TestCase
{
    /**
     * @var HelperSet
     */
    protected $helperSet;

    /**
     * @var Connection
     */
    protected $connection;

    /**
     * @var AbstractPlatform
     */
    protected $platform;

    /**
     * @var AbstractSchemaManager
     */
    protected $schemaManager;

    /**
     * @var SchemaConfig
     */
    protected $schemaConfig;

    /**
     * @var Application
     */
    protected $application;

    public function setUp(): void
    {
        $this->connection = $this->createMock(Connection::class);
        $this->connection
            ->method('getParams')
            ->willReturn([])
        ;
        $this->schemaManager = $this->createMock(AbstractSchemaManager::class);
        $this->schemaConfig = $this->createMock(SchemaConfig::class);

        $this->platform = new MySQLPlatform();

        $this->connection
            ->method('getDatabasePlatform')
            ->willReturn($this->platform);

        $this->schemaManager
            ->method('createSchemaConfig')
            ->willReturn($this->schemaConfig);

        $this->connection
            ->method('createSchemaManager')
            ->willReturn($this->schemaManager);

        $this->helperSet = new HelperSet([
            'phpcr' => new DoctrineDbalHelper($this->connection),
        ]);

        $this->application = new Application();
        $this->application->setHelperSet($this->helperSet);

        $command = new InitDoctrineDbalCommand();
        $this->application->add($command);
    }

    /**
     * Build and execute the command tester.
     *
     * @param string $name   command name
     * @param array  $args   command arguments
     * @param int    $status expected return status
     */
    protected function executeCommand($name, $args, $status = 0): CommandTester
    {
        $command = $this->application->find($name);
        $commandTester = new CommandTester($command);
        $args = array_merge([
            'command' => $command->getName(),
        ], $args);

        $this->assertEquals($status, $commandTester->execute($args));

        return $commandTester;
    }

    public function testCommandMissingForce(): void
    {
        $this->executeCommand('jackalope:init:dbal', [], 2);
    }

    public function testCommandDumpSql(): void
    {
        $this->executeCommand('jackalope:init:dbal', ['--dump-sql' => true], 0);
    }

    public function testCommandDumpSqlWithDrop(): void
    {
        $this->executeCommand('jackalope:init:dbal', ['--dump-sql' => true, '--drop' => true], 0);
    }

    public function testCommandForce(): void
    {
        $this->executeCommand('jackalope:init:dbal', ['--force' => true], 0);
    }

    public function testCommandForceAndDrop(): void
    {
        $this->executeCommand('jackalope:init:dbal', ['--force' => true, '--drop' => true], 0);
    }

    public function testCommandTableExists(): void
    {
        // Unfortunately PDO doesn't follow internals and uses a non integer error code, which cannot be manually created
        $this->connection
            ->method('executeStatement')
            ->will(self::throwException(new MockPDOException('', '42S01')))
        ;

        $this->executeCommand('jackalope:init:dbal', ['--force' => true, '--drop' => true], 1);
    }
}

class MockPDOException extends \PDOException
{
    public function __construct($msg, $code)
    {
        $this->message = $msg;
        $this->code = $code;
    }
}
