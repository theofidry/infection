#!/usr/bin/env bash

set -euo pipefail

readonly INFECTION="../../../${1:-bin/infection}"

if [ "${DRIVER:-}" = "phpdbg" ]
then
    phpdbg -qrr "$INFECTION" run Calculator::add --no-progress --no-interaction
else
    php "$INFECTION" run Calculator::add --no-progress --no-interaction
fi

diff -u expected-output.txt var/infection.log
