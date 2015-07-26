<?php

namespace Jackalope\Tools\Console\Command;

use Jackalope\Tools\Console\Helper\DoctrineDbalHelper;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Helper\HelperSet;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Console\Command\Command;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;

class InitDoctrineDbalCommandTest extends \PHPUnit_Framework_TestCase
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
     * @var Application
     */
    protected $application;

    public function setUp()
    {
        $this->connection = $this->getMockBuilder('Doctrine\DBAL\Connection')
            ->disableOriginalConstructor()
            ->getMock();

        $this->platform = $this->getMockBuilder('Doctrine\DBAL\Platforms\MySqlPlatform')
            ->disableOriginalConstructor()
            ->getMock();

        $this->connection
            ->expects($this->any())
            ->method('getDatabasePlatform')
            ->will($this->returnValue($this->platform));

        $this->helperSet = new HelperSet(array(
            'phpcr' => new DoctrineDbalHelper($this->connection),
        ));

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
        $args = array_merge(array(
            'command' => $command->getName(),
        ), $args);
        $this->assertEquals($status, $commandTester->execute($args));

        return $commandTester;
    }

    public function testCommand()
    {
        $this->executeCommand('jackalope:init:dbal', array(), 2);
        $this->executeCommand('jackalope:init:dbal', array('--dump-sql' => true), 0);
        $this->executeCommand('jackalope:init:dbal', array('--dump-sql' => true, '--drop' => true), 0);
        $this->executeCommand('jackalope:init:dbal', array('--force' => true), 0);
        $this->executeCommand('jackalope:init:dbal', array('--force' => true, '--drop' => true), 0);

        // Unfortunately PDO doesn't follow internals and uses a non integer error code, which cannot be manually created
        $this->connection
            ->expects($this->any())
            ->method('exec')
            ->will($this->throwException(new MockPDOException('', '42S01')))
        ;

        $this->executeCommand('jackalope:init:dbal', array('--force' => true, '--drop' => true), 1);
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
