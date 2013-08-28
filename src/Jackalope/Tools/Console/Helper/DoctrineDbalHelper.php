<?php

namespace Jackalope\Tools\Console\Helper;

use Symfony\Component\Console\Helper\Helper;
use Doctrine\DBAL\Connection;

/**
 * Helper class to make the session instance available to console command.
 *
 * @license http://www.apache.org/licenses Apache License Version 2.0, January 2004
 * @license http://opensource.org/licenses/MIT MIT License
 */
class DoctrineDbalHelper extends Helper
{
    /**
     * @var Connection
     */
    protected $connection;

    /**
     * Constructor
     *
     * @param Connection $connection the doctrine dbal connection
     */
    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    public function getConnection()
    {
        return $this->connection;
    }

    public function getName()
    {
        return 'jackalope-doctrine-dbal';
    }
}
