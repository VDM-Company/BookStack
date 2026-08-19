#!/usr/bin/env bash
#
# Runs every check for the AI Chatbot module.
#
#   ./tests/run.sh
#
# No API key or database is needed: the streaming suite talks to a local fake
# Anthropic endpoint, and the rest stub whatever they need. Nothing here calls
# the real API, so running it costs nothing.

set -uo pipefail

cd "$(dirname "$0")"

PORT="${AIC_TEST_PORT:-8791}"
FAILED=0

# A dummy key is enough to make the module register its routes and views.
export ANTHROPIC_API_KEY="sk-ant-test-dummy"
export AI_CHATBOT_API_BASE="http://127.0.0.1:${PORT}"

command -v php >/dev/null || { echo "php not found on PATH"; exit 2; }

listening() {
    php -r 'exit(@fsockopen("127.0.0.1", (int)$argv[1], $e, $s, 0.2) ? 0 : 1);' "${PORT}" 2>/dev/null
}

# Refuse to run against something else already on the port. Binding failures
# are silent, and the tests would otherwise pass or fail against a stranger.
if listening; then
    echo "Port ${PORT} is already in use. Free it, or set AIC_TEST_PORT."
    exit 2
fi

php -S "127.0.0.1:${PORT}" fake-anthropic.php >/dev/null 2>&1 &
SERVER_PID=$!
trap 'kill "${SERVER_PID}" 2>/dev/null' EXIT

for _ in $(seq 1 50); do
    listening && break
    sleep 0.1
done

if ! listening; then
    echo "The fake Anthropic server did not start on port ${PORT}."
    exit 2
fi

run() {
    "$@"
    local status=$?

    if [ "${status}" -ne 0 ]; then
        FAILED=1
        echo -e "    \033[31m$* exited with status ${status}\033[0m"
    fi
}

run php wiring.php
run php units.php
run php streaming.php

if command -v node >/dev/null; then
    run node markdown.mjs
else
    echo
    echo "  node not found on PATH; skipping the Markdown renderer suite."
fi

echo
if [ "${FAILED}" -eq 0 ]; then
    echo -e "\033[32mEvery suite passed.\033[0m"
else
    echo -e "\033[31mOne or more suites failed.\033[0m"
fi

exit "${FAILED}"
