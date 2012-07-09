#!/bin/bash

: ${DB?"Error: Database name not set!
Try: export DB=mysql"}

DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"

git submodule update --init --recursive

pyrus install phpunit/DBUnit

php $DIR/generate_fixtures.php
