<?php

namespace Jackalope\Transport\DoctrineDBAL;

use Doctrine\DBAL\Connection;
use Jackalope\FactoryInterface;
use Jackalope\Query\Query;
use Jackalope\Transport\AbstractReadWriteLoggingWrapper;
use Jackalope\Transport\Logging\LoggerInterface;
use Jackalope\Transport\NodeTypeManagementInterface;
use Jackalope\Transport\QueryInterface as QueryTransport;
use Jackalope\Transport\TransactionInterface;
use Jackalope\Transport\WorkspaceManagementInterface;

/**
 * Logging enabled wrapper for the Jackalope Doctrine DBAL client.
 *
 * @license http://www.apache.org/licenses Apache License Version 2.0, January 2004
 * @license http://opensource.org/licenses/MIT MIT License
 * @author Lukas Kahwe Smith <smith@pooteeweet.org>
 */

// PermissionInterface, VersioningInterface, LockingInterface, ObservationInterface

/**
 * @property Client $transport
 */
class LoggingClient extends AbstractReadWriteLoggingWrapper implements QueryTransport, NodeTypeManagementInterface, WorkspaceManagementInterface, TransactionInterface
{
    /**
     * @param Client          $transport A jackalope doctrine dbal client instance
     * @param LoggerInterface $logger    A logger instance
     */
    public function __construct(FactoryInterface $factory, Client $transport, LoggerInterface $logger)
    {
        parent::__construct($factory, $transport, $logger);
    }

    public function getConnection(): Connection
    {
        return $this->transport->getConnection();
    }

    /**
     * Configure whether to check if we are logged in before doing a request.
     *
     * Will improve error reporting at the cost of some round trips.
     */
    public function setCheckLoginOnServer($bool): void
    {
        $this->transport->setCheckLoginOnServer($bool);
    }

    public function query(Query $query): array
    {
        $this->logger->startCall(__FUNCTION__, func_get_args(), ['fetchDepth' => $this->transport->getFetchDepth()]);
        $result = $this->transport->query($query);
        $this->logger->stopCall();

        return $result;
    }

    public function getSupportedQueryLanguages(): array
    {
        return $this->transport->getSupportedQueryLanguages();
    }

    public function registerNamespace($prefix, $uri): void
    {
        $this->transport->registerNamespace($prefix, $uri);
    }

    public function unregisterNamespace($prefix): void
    {
        $this->transport->unregisterNamespace($prefix);
    }

    public function registerNodeTypes($types, $allowUpdate): void
    {
        $this->transport->registerNodeTypes($types, $allowUpdate);
    }

    public function createWorkspace(string $name, string $srcWorkspace = null): void
    {
        $this->transport->createWorkspace($name, $srcWorkspace);
    }

    public function deleteWorkspace($name): void
    {
        $this->transport->deleteWorkspace($name);
    }

    public function beginTransaction(): ?string
    {
        return $this->transport->beginTransaction();
    }

    public function commitTransaction(): void
    {
        $this->transport->commitTransaction();
    }

    public function rollbackTransaction(): void
    {
        $this->transport->rollbackTransaction();
    }

    public function setTransactionTimeout(int $seconds): void
    {
        $this->transport->setTransactionTimeout($seconds);
    }
}
