#!/usr/bin/env bash

SCRIPT_BASEDIR=$(dirname "$0")


set -e
cd "${SCRIPT_BASEDIR}/.."

vendor/bin/phpstan analyse --memory-limit=-1 --level 5 src tests
