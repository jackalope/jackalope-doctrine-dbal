# Tests

There are two kind of tests. The folder ``tests/phpcr-api`` contains the
[phpcr-api-tests](https://github.com/phpcr/phpcr-api-tests/) suite to test
against the specification. This is what you want to look at when using
jackalope as a PHPCR implementation.

The folder ``tests/Jackalope`` contains unit tests for the jackalope
implementation. You should only need those if you want to debug jackalope
itself or implement new features.

For both, you need to have the test workspace created in the storage (see
below).


There is one bootstrap and one phpunit file per backend implementation.
The additional classes required to bootstrap the tests are found in inc/
Utility code is placed in bin/ and lib/.


The phpunit_*.xml.dist are configured to run all tests. You can limit the tests
to run by specifying the path to those tests to phpunit.

Note that the phpcr-api tests are skipped for features not implemented in
jackalope. Have a look at the tests/inc/*ImplementationLoader.php files to see
which features are skipped for what backend.


# Setup


Jackalope bundles the extensive phpcr-api-tests suite to test compliance with
the PHPCR standard. Additionally jackalope contains a set of unit tests.
After setting tests up (see below), you can simply run them with phpunit

You should only see success or skipped tests, no failures or errors.

## Test setup for the Doctrine DBAL transport

There is a phpunit_doctrinedbal.xml.dist file in the tests/ folder. Copy that
to phpunit.xml and adjust settings as you need them.

Set up a new mysql database to run the tests against - be aware that the whole
database is dropped each time you run the tests. You can use your favorite GUI
frontend or just do something like this:

    mysqladmin -u root -p  create jackalope_doctrine
    echo "grant all privileges on jackalope_doctrine.* to 'jackalope'@'localhost' identified by '1234test'; flush privileges;" | mysql -u root -p

Test fixtures for functional tests are written in JCR System XML format. Use
the converter script ``tests/generate_doctrine_dbal_fixture.php`` to prepare
the fixtures for doctrine tests.
The converted fixtures are written into **tests/fixtures/doctrine**. The
converted fixtures are not tracked in the repository, you should regenerate
them whenever the fixtures in tests/phpcr-api/fixtures change.

    cd /path/to/jackalope/tests
    cp phpunit_doctrine_dbal.xml.dist phpunit.xml
    ./generate_doctrine_dbal_fixture.php
    phpunit

## Note on JCR

It would be nice if we were able to run the relevant parts of the JSR-283
Technology Compliance Kit (TCK) against php implementations. Note that we would
need to have some glue for things that look different in PHP than in Java, like
the whole topic of Value and ValueFactory.
[https://jira.liip.ch/browse/JACK-24](https://jira.liip.ch/browse/JACK-24)

Once we manage to do that, we could hopefully also use the performance test suite
[https://jira.liip.ch/browse/JACK-23](https://jira.liip.ch/browse/JACK-23)
