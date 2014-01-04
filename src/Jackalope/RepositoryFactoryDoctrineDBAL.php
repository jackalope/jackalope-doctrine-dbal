<?php

namespace Jackalope;

use PHPCR\RepositoryFactoryInterface;

/**
 * This factory creates repositories with the Doctrine DBAL transport
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
    /**
     * List of required parameters for doctrine dbal.
     *
     * TODO: would be nice if alternatively one could also specify the parameters to let the factory build the connection
     *
     * @var array
     */
    private static $required = array(
        'jackalope.doctrine_dbal_connection' => 'Doctrine\\DBAL\\Connection (required): connection instance',
    );

    /**
     * List of optional parameters for doctrine dbal.
     *
     * @var array
     */
    private static $optional = array(
        'jackalope.factory' => 'string or object: Use a custom factory class for Jackalope objects',
        'jackalope.check_login_on_server' => 'boolean: if set to empty or false, skip initial check whether repository exists. Enabled by default, disable to gain a few milliseconds off each repository instantiation.',
        'jackalope.disable_transactions' => 'boolean: if set and not empty, transactions are disabled, otherwise transactions are enabled. If transactions are enabled but not actively used, every save operation is wrapped into a transaction.',
        'jackalope.disable_stream_wrapper' => 'boolean: if set and not empty, stream wrapper is disabled, otherwise the stream wrapper is enabled and streams are only fetched when reading from for the first time. If your code always uses all binary properties it reads, you can disable this for a small performance gain.',
        'jackalope.data_caches' => 'array: an array of \Doctrine\Common\Cache\Cache instances. keys can be "meta" and "nodes", should be separate namespaces for best performance.',
        'jackalope.logger' => 'Psr\Log\LoggerInterface: Use the LoggingClient to wrap the default transport Client',
        Session::OPTION_AUTO_LASTMODIFIED => 'boolean: Whether to automatically update nodes having mix:lastModified. Defaults to true.',
    );

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
            return null;
        }

        // check if we have all required keys
        $present = array_intersect_key(self::$required, $parameters);
        if (count(array_diff_key(self::$required, $present))) {
            return null;
        }
        $defined = array_intersect_key(array_merge(self::$required, self::$optional), $parameters);
        if (count(array_diff_key($defined, $parameters))) {
            return null;
        }

        if (isset($parameters['jackalope.factory'])) {
            $factory = $parameters['jackalope.factory'] instanceof FactoryInterface
                ? $parameters['jackalope.factory'] : new $parameters['jackalope.factory'];
        } else {
            $factory = new Factory();
        }

        $dbConn = $parameters['jackalope.doctrine_dbal_connection'];

        $transport = isset($parameters['jackalope.data_caches'])
            ? $factory->get('Transport\DoctrineDBAL\CachedClient', array($dbConn, $parameters['jackalope.data_caches']))
            : $factory->get('Transport\DoctrineDBAL\Client', array($dbConn));

        if (isset($parameters['jackalope.check_login_on_server'])) {
            $transport->setCheckLoginOnServer($parameters['jackalope.check_login_on_server']);
        }
        if (isset($parameters['jackalope.uuid_generator'])) {
            $transport->setUuidGenerator($parameters['jackalope.uuid_generator']);
        }
        if (isset($parameters['jackalope.logger'])) {
            $transport = $factory->get('Transport\DoctrineDBAL\LoggingClient', array($transport, $parameters['jackalope.logger']));
        }

        $options['transactions'] = empty($parameters['jackalope.disable_transactions']);
        $options['stream_wrapper'] = empty($parameters['jackalope.disable_stream_wrapper']);
        if (isset($parameters[Session::OPTION_AUTO_LASTMODIFIED])) {
            $options[Session::OPTION_AUTO_LASTMODIFIED] = $parameters[Session::OPTION_AUTO_LASTMODIFIED];
        }

        return new Repository($factory, $transport, $options);
    }

    /**
     * {@inheritDoc}
     *
     * @api
     */
    public function getConfigurationKeys()
    {
        return array_merge(self::$required, self::$optional);
    }
}
