#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PHPCS="${ROOT_DIR}/vendor/bin/phpcs"
STANDARD="${ROOT_DIR}/Oscar/ruleset.xml"

if [[ ! -x "${PHPCS}" ]]; then
    echo "phpcs executable not found at ${PHPCS}. Did you run 'composer install'?" >&2
    exit 1
fi

echo "Running PHPCS on compliant fixtures..."
"${PHPCS}" --standard="${STANDARD}" "${ROOT_DIR}/tests/fixtures/valid"

echo "Ensuring non-compliant fixtures are detected..."
shopt -s nullglob
for file in "${ROOT_DIR}"/tests/fixtures/invalid/*.php; do
    if "${PHPCS}" --standard="${STANDARD}" "${file}"; then
        echo "Expected ${file} to contain violations, but PHPCS reported none." >&2
        exit 1
    fi
done

echo "Ensuring non-compliant Blade fixtures are detected..."
for file in "${ROOT_DIR}"/tests/fixtures/blade/invalid*.blade.php; do
    if [[ ! -f "${file}" ]]; then
        continue
    fi
    if "${PHPCS}" --standard="${STANDARD}" "${file}"; then
        echo "Expected ${file} to contain violations, but PHPCS reported none." >&2
        exit 1
    fi
done

echo "Running PHPCS on compliant Blade fixtures..."
for file in "${ROOT_DIR}"/tests/fixtures/blade/valid*.blade.php; do
    if [[ ! -f "${file}" ]]; then
        continue
    fi
    if ! "${PHPCS}" --standard="${STANDARD}" "${file}"; then
        echo "Expected ${file} to be clean, but PHPCS reported violations." >&2
        exit 1
    fi
done
shopt -u nullglob

echo "All tests passed."
