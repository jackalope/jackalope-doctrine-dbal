#!/bin/bash

DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"

git submodule update --init --recursive

psql -c "DROP DATABASE IF EXISTS phpcr_tests;" -U postgres
psql -c "create database phpcr_tests;" -U postgres

pyrus install phpunit/DBUnit

php $DIR/generate_fixtures.php

