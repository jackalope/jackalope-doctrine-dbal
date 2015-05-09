<?php

namespace Jackalope\Tools\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use Doctrine\DBAL\Connection;

use Jackalope\Transport\DoctrineDBAL\RepositorySchema;

/**
 * Init doctrine dbal.
 *
 * @license http://www.apache.org/licenses Apache License Version 2.0, January 2004
 * @license http://opensource.org/licenses/MIT MIT License
 *
 * @author Lukas Kahwe Smith <smith@pooteeweet.org>
 */
class InitDoctrineDbalCommand extends Command
{
    /**
     * @see Command
     */
    protected function configure()
    {
        $this
            ->setName('jackalope:init:dbal')
            ->setDescription('Prepare the database for Jackalope Doctrine-Dbal.')
            ->setDefinition(array(
                new InputOption(
                    'force', null, InputOption::VALUE_NONE,
                    'Set this parameter to execute this action, starting with version 1.2 of Jackalope Doctrine DBAL.'
                ),
                new InputOption(
                    'dump-sql', null, InputOption::VALUE_NONE,
                    'Instead of try to apply generated SQLs to the database, output them.'
                ),
                new InputOption(
                    'drop', null, InputOption::VALUE_NONE,
                    'Drop any existing tables before trying to create the new tables.'
                )
            ))
            ->setHelp(<<<EOT
Prepare the database for Jackalope Doctrine-DBAL transport.
Processes the schema and either creates it directly in the database or generate the SQL output.
EOT
    );
    }

    /**
     * {@inheritDoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $connection = $this->getHelper('jackalope-doctrine-dbal')->getConnection();
        if (!$connection instanceof Connection) {

            $output->write(PHP_EOL.'<error>The provided connection is not an instance of the Doctrine DBAL connection.</error>'.PHP_EOL);
            throw new \InvalidArgumentException('The provided connection is not an instance of the Doctrine DBAL connection.');
        }

        if (true !== $input->getOption('dump-sql')) {
            $output->write('ATTENTION: This operation should not be executed in a production environment.' . PHP_EOL . PHP_EOL);
        }

        $schema = new RepositorySchema;
        try {
            if ($input->getOption('drop')) {
                foreach ($schema->toDropSql($connection->getDatabasePlatform()) as $sql) {
                    if (true === $input->getOption('dump-sql')) {
                        $output->writeln($sql);
                    } else {
                        try {
                            $connection->exec($sql);
                        } catch (\Exception $e) {
                            $output->writeln($e->getMessage());
                        }
                    }
                }
            }

            foreach ($schema->toSql($connection->getDatabasePlatform()) as $sql) {
                if (true === $input->getOption('dump-sql')) {
                    $output->writeln($sql);
                } else {
                    $connection->exec($sql);
                }
            }
        } catch (\PDOException $e) {
            if ("42S01" == $e->getCode()) {
                $output->write(PHP_EOL.'<error>The tables already exist. Nothing was changed.</error>'.PHP_EOL.PHP_EOL); // TODO: add a parameter to drop old scheme first

                return;
            }
            throw $e;
        }

        if (true !== $input->getOption('dump-sql')) {
            $output->writeln("Jackalope Doctrine DBAL tables have been initialized successfully.");
        }
    }
}
