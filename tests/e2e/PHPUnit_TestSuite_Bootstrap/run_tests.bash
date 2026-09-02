#!/usr/bin/env bash

cd "$(dirname "$0")"

readonly INFECTION=../../../${1}

set -eo pipefail

if [ "$DRIVER" = "phpdbg" ]
then
    phpdbg -qrr $INFECTION
else
    php $INFECTION
fi

diff -u --ignore-all-space expected-output.txt var/infection.log
