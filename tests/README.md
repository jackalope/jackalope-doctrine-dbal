# Tests

To run the tests, you need phpunit 3.6 or newer including the phpunit/DbUnit feature.

The provided phpunit.xml template is built on the assumption that you cloned
the jackalope repository and ran `composer update` in its root folder to get
the development dependencies as well.

There are two kind of tests. The folder ``vendor/phpcr/phpcr-api-tests`` contains the
[phpcr-api-tests](https://github.com/phpcr/phpcr-api-tests/) suite to test
against the specification. This is what you want to look at when using
jackalope as a PHPCR implementation.

Unit tests for the jackalope doctrine-dbal backend implememtation are in
tests/Jackalope/Transport/DoctrineDBAL. There are also some unit tests for
base jackalope in vendor/jackalope/jackalope/tests.


## API test suite

The phpunit.xml.dist is configured to run all tests from jackalope-doctrine-dbal,
jackalope core, phpcr-utils as well as the phpcr-api-tests. You can limit the tests
to run by specifying the path to those tests to phpunit.

Note that the phpcr-api-tests are skipped for features not implemented in
jackalope. Have a look at the tests/inc/DoctrineDBALImplementationLoader.php
file to see which features are currently skipped.

You should only see success or skipped tests, no failures or errors.


# Setup

**Careful: You should create a separate database for the tests, as the whole
database is dropped each time you run a test.**

You can use your favorite GUI frontend or just do something like this:

### MySQL

```sh
$ mysqladmin -u root -p  create phpcr_tests
$ echo "grant all privileges on phpcr_tests.* to 'jackalope'@'localhost' identified by '1234test'; flush privileges;" | mysql -u root -p
```
### PostgreSQL

```sh
$ psql -c "CREATE ROLE jackalope WITH ENCRYPTED PASSWORD '1234test' NOINHERIT LOGIN;" -U postgres
$ psql -c "CREATE DATABASE phpcr_tests WITH OWNER = jackalope;" -U postgres
```

Test fixtures for functional tests are written in the JCR System XML format.
The converted fixtures are not tracked in the repository, and get regenerated
on each testrun.

# Running the tests

Make sure you set your php memory limit high enough so the tests can
successfully be executed (minimum 512M).

To run the tests, execute:

```sh
$ cd /path/to/jackalope-doctrine-dbal
# cp phpunit.xml.dist phpunit.xml
# adjust phpunit.xml as necessary
$ phpunit
```

## Installing phpunit as project dependency

If you haven't installed phpunit globally and you want to keep it as a dependency
of the jackalope doctrine-dbal project, add the following dependencies to your

`composer.json`:
```json
"require-dev": {
    [...]
    "phpunit/phpunit": "dev-master",
    "phpunit/dbunit": "dev-master"
}
```

You can then run the tests as follows:

    ./vendor/bin/phpunit
