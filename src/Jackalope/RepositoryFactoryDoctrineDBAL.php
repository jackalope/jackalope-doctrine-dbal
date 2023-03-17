<?php

namespace Jackalope;

use Doctrine\Common\Cache\ArrayCache;
use Doctrine\DBAL\ColumnCase;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\OraclePlatform;
use Doctrine\DBAL\Portability\Connection as PortabilityConnection;
use Doctrine\DBAL\Portability\Middleware as PortabilityMiddleware;
use Jackalope\Transport\DoctrineDBAL\CachedClient;
use Jackalope\Transport\DoctrineDBAL\Client;
use Jackalope\Transport\DoctrineDBAL\LoggingClient;
use PHPCR\ConfigurationException;
use PHPCR\RepositoryFactoryInterface;

/**
 * This factory creates repositories with the Doctrine DBAL transport.
 *
 * Use repository factory based on parameters (the parameters below are examples):
 *
 * <pre>
 *    $parameters = array('jackalope.doctrine_dbal_connection' => $dbConn);
 *    $factory = new \Jackalope\RepositoryFactoryDoctrineDBAL();
 *    $repository = $factory->getRepository($parameters);
 * </pre>
 *
 * @license http://www.apache.org/licenses Apache License Version 2.0, January 2004
 * @license http://opensource.org/licenses/MIT MIT License
 *
 * @api
 */
class RepositoryFactoryDoctrineDBAL implements RepositoryFactoryInterface
{
    public const JACKALOPE_FACTORY = 'jackalope.factory';
    public const JACKALOPE_DOCTRINE_DBAL_CONNECTION = 'jackalope.doctrine_dbal_connection';
    public const JACKALOPE_DATA_CACHES = 'jackalope.data_caches';
    public const JACKALOPE_CHECK_LOGIN_ON_SERVER = 'jackalope.check_login_on_server';
    public const JACKALOPE_UUID_GENERATOR = 'jackalope.uuid_generator';
    public const JACKALOPE_LOGGER = 'jackalope.logger';
    public const JACKALOPE_DISABLE_TRANSACTIONS = 'jackalope.disable_transactions';
    public const JACKALOPE_DISABLE_STREAM_WRAPPER = 'jackalope.disable_stream_wrapper';
    /**
     * List of required parameters for doctrine dbal.
     *
     * TODO: would be nice if alternatively one could also specify the parameters to let the factory build the connection
     *
     * @var array
     */
    private static $required = [
        self::JACKALOPE_DOCTRINE_DBAL_CONNECTION => 'Doctrine\\DBAL\\Connection (required): connection instance',
    ];

    /**
     * List of optional parameters for doctrine dbal.
     *
     * @var array
     */
    private static $optional = [
        self::JACKALOPE_FACTORY => 'string or object: Use a custom factory class for Jackalope objects',
        self::JACKALOPE_CHECK_LOGIN_ON_SERVER => 'boolean: if set to empty or false, skip initial check whether repository exists. Enabled by default, disable to gain a few milliseconds off each repository instantiation.',
        self::JACKALOPE_DISABLE_TRANSACTIONS => 'boolean: if set and not empty, transactions are disabled, otherwise transactions are enabled. If transactions are enabled but not actively used, every save operation is wrapped into a transaction.',
        self::JACKALOPE_DISABLE_STREAM_WRAPPER => 'boolean: if set and not empty, stream wrapper is disabled, otherwise the stream wrapper is enabled and streams are only fetched when reading from for the first time. If your code always uses all binary properties it reads, you can disable this for a small performance gain.',
        self::JACKALOPE_DATA_CACHES => 'array: an array of PSR-16 SimpleCache. Keys can be "meta" and "nodes", should be separate namespaces for best performance.',
        self::JACKALOPE_LOGGER => 'Psr\Log\LoggerInterface: Use the LoggingClient to wrap the default transport Client',
        Session::OPTION_AUTO_LASTMODIFIED => 'boolean: Whether to automatically update nodes having mix:lastModified. Defaults to true.',
    ];

    /**
     * Get a repository connected to the backend with the provided doctrine
     * dbal connection.
     *
     * {@inheritDoc}
     *
     * DoctrineDBAL repositories have no default repository, passing null as
     * parameters will always return null.
     *
     * @api
     */
    public function getRepository(array $parameters = null)
    {
        if (null === $parameters) {
            throw new ConfigurationException('Jackalope-doctrine-dbal needs parameters');
        }

        if (count(array_diff_key(self::$required, $parameters))) {
            throw new ConfigurationException('A required parameter is missing: '.implode(', ', array_keys(array_diff_key(self::$required, $parameters))));
        }

        if (count(array_diff_key($parameters, self::$required, self::$optional))) {
            throw new ConfigurationException('Additional unknown parameters found: '.implode(', ', array_keys(array_diff_key($parameters, self::$required, self::$optional))));
        }

        if (array_key_exists(self::JACKALOPE_FACTORY, $parameters)) {
            $factory = $parameters[self::JACKALOPE_FACTORY] instanceof FactoryInterface
                ? $parameters[self::JACKALOPE_FACTORY]
                : new $parameters[self::JACKALOPE_FACTORY]();
        } else {
            $factory = new Factory();
        }

        $dbConn = $parameters[self::JACKALOPE_DOCTRINE_DBAL_CONNECTION];
        \assert($dbConn instanceof Connection);
        if ($dbConn->getDatabasePlatform() instanceof OraclePlatform) {
            $this->ensureLowerCaseMiddleware($dbConn);
        }

        $canUseCache = array_key_exists(self::JACKALOPE_DATA_CACHES, $parameters)
            && (array_key_exists('meta', $parameters[self::JACKALOPE_DATA_CACHES])
                || class_exists(ArrayCache::class)
            )
        ;
        $transport = $canUseCache
            ? $factory->get(CachedClient::class, [$dbConn, $parameters[self::JACKALOPE_DATA_CACHES]])
            : $factory->get(Client::class, [$dbConn]);

        if (array_key_exists(self::JACKALOPE_CHECK_LOGIN_ON_SERVER, $parameters)) {
            $transport->setCheckLoginOnServer($parameters[self::JACKALOPE_CHECK_LOGIN_ON_SERVER]);
        }

        if (array_key_exists(self::JACKALOPE_UUID_GENERATOR, $parameters)) {
            $transport->setUuidGenerator($parameters[self::JACKALOPE_UUID_GENERATOR]);
        }

        if (array_key_exists(self::JACKALOPE_LOGGER, $parameters)) {
            $transport = $factory->get(LoggingClient::class, [$transport, $parameters[self::JACKALOPE_LOGGER]]);
        }

        $options['transactions'] = empty($parameters[self::JACKALOPE_DISABLE_TRANSACTIONS]);
        $options['stream_wrapper'] = empty($parameters[self::JACKALOPE_DISABLE_STREAM_WRAPPER]);
        if (array_key_exists(Session::OPTION_AUTO_LASTMODIFIED, $parameters)) {
            $options[Session::OPTION_AUTO_LASTMODIFIED] = $parameters[Session::OPTION_AUTO_LASTMODIFIED];
        }

        return new Repository($factory, $transport, $options);
    }

    public function getConfigurationKeys(): array
    {
        return array_merge(self::$required, self::$optional);
    }

    /**
     * Add the lowercase portability middleware if it is not already part of the configuration.
     */
    private function ensureLowerCaseMiddleware(Connection $dbConn): void
    {
        $configuration = $dbConn->getConfiguration();
        if (!$configuration) {
            return;
        }
        foreach ($configuration->getMiddlewares() as $middleware) {
            if ($middleware instanceof PortabilityMiddleware) {
                return;
            }
        }
        $middlewares = $configuration->getMiddlewares();
        $middlewares[] = new PortabilityMiddleware(PortabilityConnection::PORTABILITY_FIX_CASE, ColumnCase::LOWER);
        $configuration->setMiddlewares($middlewares);
    }
}
