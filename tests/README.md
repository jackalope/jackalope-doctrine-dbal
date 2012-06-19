# Tests

There are two kind of tests. The folder ``vendor/phpcr/phpcr-api-tests`` contains the
[phpcr-api-tests](https://github.com/phpcr/phpcr-api-tests/) suite to test
against the specification. This is what you want to look at when using
jackalope as a PHPCR implementation.

Unit tests for the jackalope doctrine-dbal backend implememtation are in
tests/Jackalope/Transport/DoctrineDBAL.

Note that the base jackalope repository contains some unit tests for jackalope in
its tests folder.


## API test suite

The phpunit.xml.dist is configured to run all tests. You can limit the tests
to run by specifying the path to those tests to phpunit.

Note that the phpcr-api tests are skipped for features not implemented in
jackalope. Have a look at the tests/inc/DoctrineDBALImplementationLoader.php
file to see which features are currently skipped.

You should only see success or skipped tests, no failures or errors.


# Setup


**Careful: You should create a separate database for the tests, as the whole
database is dropped each time you run a test.**

You can use your favorite GUI frontend or just do something like this:

    mysqladmin -u root -p  create phpcr_tests
    echo "grant all privileges on phpcr_tests.* to 'jackalope'@'localhost' identified by '1234test'; flush privileges;" | mysql -u root -p


Test fixtures for functional tests are written in the JCR System XML format. Use
the converter script ``tests/generate_fixtures.php`` to prepare the fixtures
for the tests.
The converted fixtures are written into tests/fixtures/doctrine. The
converted fixtures are not tracked in the repository, you should regenerate
them whenever you update the vendors through composer.

To run the tests:

    cd /path/to/jackalope/tests
    cp phpunit.xml.dist phpunit.xml
    # adjust phpunit.xml as necessary
    ./generate_fixtures.php
    phpunit


## Note on JCR

It would be nice if we were able to run the relevant parts of the JSR-283
Technology Compliance Kit (TCK) against php implementations. Note that we would
need to have some glue for things that look different in PHP than in Java, like
the whole topic of Value and ValueFactory.
[https://jira.liip.ch/browse/JACK-24](https://jira.liip.ch/browse/JACK-24)

Once we manage to do that, we could hopefully also use the performance test suite
[https://jira.liip.ch/browse/JACK-23](https://jira.liip.ch/browse/JACK-23)
