#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
TESTS_WORKFLOW="$ROOT/.github/workflows/tests.yml"
A11Y_WORKFLOW="$ROOT/.github/workflows/accessibility.yml"
fail() { echo "FAIL: $1" >&2; exit 1; }

[ -x "$ROOT/scripts/run-dependency-audit-transport.sh" ] || fail "audit transport wrapper is missing or not executable"
[ -x "$ROOT/scripts/tests/composer-audit-transport.test.sh" ] || fail "Composer adversarial contract is missing or not executable"
[ -x "$ROOT/scripts/tests/npm-audit-transport.test.sh" ] || fail "npm adversarial contract is missing or not executable"
grep -q 'bash scripts/run-dependency-audit-transport.sh composer' "$TESTS_WORKFLOW" || fail "Composer audit bypasses wrapper"
grep -q 'bash scripts/tests/composer-audit-transport.test.sh' "$TESTS_WORKFLOW" || fail "Composer adversarial contract is not executed"
grep -q 'bash scripts/tests/composer-npm-audit-workflow.test.sh' "$TESTS_WORKFLOW" || fail "workflow bypass contract is not executed"
grep -q 'bash ../scripts/run-dependency-audit-transport.sh npm' "$A11Y_WORKFLOW" || fail "npm audit bypasses wrapper"
grep -q 'bash ../scripts/tests/npm-audit-transport.test.sh' "$A11Y_WORKFLOW" || fail "npm adversarial contract is not executed"
! grep -Eq 'run:[[:space:]]+(composer|npm) audit' "$TESTS_WORKFLOW" "$A11Y_WORKFLOW" || fail "workflow retains a direct audit command"

echo "PEANUT BOOKER COMPOSER/NPM AUDIT WORKFLOW CONTRACT PASSED"
