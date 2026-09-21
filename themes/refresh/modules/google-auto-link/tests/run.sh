#!/usr/bin/env bash
#
# Runs the Google Auto-Link module's test suite.
#
#   ./tests/run.sh
#
# Needs the same mysql_testing database that BookStack's own suite uses; these
# are HTTP-level tests against the real social login flow, with Socialite mocked
# so nothing leaves the machine.

set -euo pipefail

cd "$(dirname "$0")"
ROOT=$(cd ../../../../.. && pwd)

exec "${ROOT}/vendor/bin/phpunit" -c phpunit.xml "$@"
