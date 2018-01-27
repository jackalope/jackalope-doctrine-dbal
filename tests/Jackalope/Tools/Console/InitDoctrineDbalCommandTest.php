<?php

namespace Jackalope\Tools\Console\Command;

use Doctrine\DBAL\Platforms\MySqlPlatform;
use Jackalope\Tools\Console\Helper\DoctrineDbalHelper;
use PDOException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Helper\HelperSet;
use Symfony\Component\Console\Tester\CommandTester;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Schema\AbstractSchemaManager;

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
     * @var Application
     */
    protected $application;

    public function setUp()
    {
        $this->connection = $this->getMockBuilder(Connection::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->schemaManager = $this->getMockBuilder(AbstractSchemaManager::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->platform = $this->getMockBuilder(MySqlPlatform::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->connection
            ->expects($this->any())
            ->method('getDatabasePlatform')
            ->will($this->returnValue($this->platform));

        $this->schemaManager
            ->expects($this->any())
            ->method('createSchemaConfig')
            ->will($this->returnValue(null));

        $this->connection
            ->expects($this->any())
            ->method('getSchemaManager')
            ->will($this->returnValue($this->schemaManager));

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
     *
     * @return CommandTester
     */
    protected function executeCommand($name, $args, $status = 0)
    {
        $command = $this->application->find($name);
        $commandTester = new CommandTester($command);
        $args = array_merge([
            'command' => $command->getName(),
        ], $args);
        $this->assertEquals($status, $commandTester->execute($args));

        return $commandTester;
    }

    public function testCommand()
    {
        $this->executeCommand('jackalope:init:dbal', [], 2);
        $this->executeCommand('jackalope:init:dbal', ['--dump-sql' => true], 0);
        $this->executeCommand('jackalope:init:dbal', ['--dump-sql' => true, '--drop' => true], 0);
        $this->executeCommand('jackalope:init:dbal', ['--force' => true], 0);
        $this->executeCommand('jackalope:init:dbal', ['--force' => true, '--drop' => true], 0);

        // Unfortunately PDO doesn't follow internals and uses a non integer error code, which cannot be manually created
        $this->connection
            ->expects($this->any())
            ->method('exec')
            ->will($this->throwException(new MockPDOException('', '42S01')))
        ;

        $this->executeCommand('jackalope:init:dbal', ['--force' => true, '--drop' => true], 1);
    }
}

class MockPDOException extends PDOException
{
    public function __construct($msg, $code)
    {
        $this->message = $msg;
        $this->code = $code;
    }
}
