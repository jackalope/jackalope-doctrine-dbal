#!/bin/bash

TARGET=$1

if [ -e $TARGET ]; then
    rm $TARGET;
    touch $TARGET;
fi

while read QUERY; do
    echo $QUERY
    echo $QUERY >> $TARGET;
    for i in 1 2 3; do
        echo "."
        php ~/www/phpcr/phpcr-shell/bin/phpcrsh -psulucmf --command="$QUERY" | tail -n 1 >> $TARGET
    done
done < queries.txt

cat $TARGET
