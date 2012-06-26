# Jackalope [![Build Status](https://secure.travis-ci.org/jackalope/jackalope-doctrine-dbal.png?branch=master)](http://travis-ci.org/jackalope/jackalope-doctrine-dbal)

A powerful implementation of the [PHPCR API](http://phpcr.github.com).

Jackalope binding for relational databases with the DoctrineDBAL. Works with any
database supported by doctrine (mysql, postgres, ...) and has no dependency
on java or jackrabbit. For the moment, it is less feature complete.

Discuss on jackalope-dev@googlegroups.com
or visit #jackalope on irc.freenode.net

License: This code is licenced under the apache license.
Please see the file LICENSE in this folder.


# Preconditions

* php >= 5.3
* phpunit >= 3.6 (if you want to run the tests)
* phpunit/DbUnit (if you want to run the Doctrine DBAL Transport tests)
* [composer](http://getcomposer.org/)


# Installation

If you do not yet have composer, install it like this

    curl -s http://getcomposer.org/installer | sudo php -- --install-dir=/usr/local/bin

To install jackalope itselves, run the following in the parent directory of where you want jackalope

    git clone git://github.com/jackalope/jackalope-doctrine-dbal.git
    cd jackalope-doctrine-dbal
    php /usr/local/bin/composer.phar install --dev

Note that the --dev parameter is only needed if you want to be
able to run the test suite. If you already installed jackalope without the test
suite, you need to remove composer.lock before running composer again with the
--dev parameter.


## Create a repository

Set up a new database supported by Doctrine DBAL (i.e. mysql or postgres). You
can use your favorite GUI frontend or just do something like this:

    mysqladmin -u root -p  create jackalope
    echo "grant all privileges on jackalope.* to 'jackalope'@'localhost' identified by '1234test'; flush privileges;" | mysql -u root -p

## phpunit Tests

If you want to run the tests , please see the [README file in the tests folder](https://github.com/jackalope/jackalope-doctrine-dbal/blob/master/tests/README.md)
and check if you told composer to install the suggested dependencies (see Installation)


## Enable the commands

There are a couple of useful commands to interact with the repository.

To use the console, copy cli-config.php.dist to cli-config.php and configure
the connection parameters.
Then you can run the commands from the jackalope directory with ``./bin/jackalope

NOTE: If you are using PHPCR inside of **Symfony**, the DoctrinePHPCRBundle
provides the commands inside the normal Symfony console and you don't need to
prepare anything special.

Jackalope specific commands:

* ``jackalope:init:dbal``: Initialize the configured database for jackalope with the
    Doctrine DBAL transport.

Commands available from the phpcr-utils:

* ``phpcr:workspace:create <name>``: Create a workspace *name* in the repository
* ``phpcr:register-node-types --allow-update [cnd-file]``: Register namespaces
    and node types from a "Compact Node Type Definition" .cnd file
* ``phpcr:dump [--sys_nodes[="..."]] [--props[="..."]] [path]``: Show the node
    names under the specified path. If you set sys_nodes=yes you will also see
    system nodes. If you set props=yes you will additionally see all properties
    of the dumped nodes.
* ``phpcr:purge``: Remove all content from the configured repository in the
     configured workspace
* ``phpcr:sql2``: Run a query in the JCR SQL2 language against the repository
    and dump the resulting rows to the console.



# Bootstrapping

Jackalope relies on autoloading. Namespaces and folders are compliant with
PSR-0. You should use the autoload file generated by composer:
``vendor/autoload.php``

If you want to integrate jackalope into other PSR-0 compliant code and use your
own classloader, find the mapping in ``vendor/composer/autoload_namespaces.php``

Before you can use jackalope with a database, you need to set the database up.
Create a database as described above, then make sure the command line utility
is set up (see above "Enable the commands"). Now you can run:

    bin/jackalope jackalope:init:dbal

Once these steps are done, you can bootstrap the library. A minimalist
sample code to get a PHPCR session with the doctrine-dbal backend:

    $driver   = 'pdo_mysql';
    $host     = 'localhost';
    $user     = 'root';
    $password = '';
    $database = 'jackalope';
    $workspace  = 'default';

    // Bootstrap Doctrine
    $dbConn = \Doctrine\DBAL\DriverManager::getConnection(array(
        'driver'    => $driver,
        'host'      => $host,
        'user'      => $user,
        'password'  => $pass,
        'dbname'    => $database,
    ));

    $repository = \Jackalope\RepositoryFactoryDoctrineDBAL::getRepository(
        array('jackalope.doctrine_dbal_connection' => $dbConn)
    );
    // dummy credentials to comply with the API
    $credentials = new \PHPCR\SimpleCredentials(null, null);
    $session = $repository->login($credentials, $workspace);

To use a workspace different than ``default`` you need to create it first. The
easiest is to run the command ``bin/jackalope phpcr:workspace:create <myworkspace>``
but you can of course also use the PHPCR API to create workspaces from your code.


# Usage

The entry point is to create the repository factory. The factory specifies the
storage backend as well. From this point on, there are no differences in the
usage (except for supported features, that is).

    // see Bootstrapping for how to get the session.

    $rootNode = $session->getNode("/");
    $whitewashing = $rootNode->addNode("www-whitewashing-de");
    $session->save();

    $posts = $whitewashing->addNode("posts");
    $session->save();

    $post = $posts->addNode("welcome-to-blog");
    $post->addMixin("mix:title");
    $post->setProperty("jcr:title", "Welcome to my Blog!");
    $post->setProperty("jcr:description", "This is the first post on my blog! Do you like it?");

    $session->save();


See [PHPCR Tutorial](https://github.com/phpcr/phpcr/blob/master/doc/Tutorial.md)
for a more detailed tutorial on how to use the PHPCR API.


# Implementation notes

See [doc/architecture.md](https://github.com/jackalope/jackalope/blob/master/doc/architecture.md)
for an introduction how Jackalope is built. Have a look at the source files and
generate the phpdoc.


# TODO

The best overview of what needs to be done are the skipped API tests.
Have a look at [DoctrineDBALImplementationLoader](https://github.com/jackalope/jackalope-doctrine-dbal/blob/master/tests/inc/DoctrineDBALImplementationLoader.php)
to see what is currently not working and start hacking :-)


## Some notes

* Refactor storage to implement one one table per database type?
* Optimize database storage more, using real ids and normalizing the uuids and paths?
* Implement parser for Jackrabbit CND syntax for node-type definitions in phpcr-utils.


# Contributors

* Christian Stocker <chregu@liip.ch>
* David Buchmann <david@liip.ch>
* Tobias Ebnöther <ebi@liip.ch>
* Roland Schilter <roland.schilter@liip.ch>
* Uwe Jäger <uwej711@googlemail.com>
* Lukas Kahwe Smith <smith@pooteeweet.org>
* Benjamin Eberlei <kontakt@beberlei.de>
* Daniel Barsotti <daniel.barsotti@liip.ch>
* [and many others](https://github.com/jackalope/jackalope-doctrine-dbal/contributors)
