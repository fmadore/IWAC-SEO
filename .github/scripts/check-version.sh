#!/usr/bin/env bash
# The version lives in three files that nothing else keeps in step:
# config/module.ini, CITATION.cff and the newest released CHANGELOG.md
# section. An "## Unreleased" section above it is allowed.
#
# Usage: check-version.sh [expected]
#   With no argument (ordinary CI), the three must agree with module.ini.
#   With one (the release workflow, given the tag), they must all equal it.
# Prints the version on success.
set -euo pipefail

ini=$(sed -n 's/^version *= *"\(.*\)"/\1/p' config/module.ini)
cff=$(sed -n 's/^version: *"\(.*\)"/\1/p' CITATION.cff)
log=$(sed -n 's/^## \([0-9][^ ]*\).*/\1/p' CHANGELOG.md | head -n 1)
expected=${1:-$ini}

fail=
[ "$ini" = "$expected" ] || fail="config/module.ini says '$ini'"
[ "$cff" = "$expected" ] || fail="${fail:+$fail; }CITATION.cff says '$cff'"
[ "$log" = "$expected" ] || fail="${fail:+$fail; }the newest CHANGELOG.md release is '$log'"
if [ -n "$fail" ]; then
    echo "::error::Version mismatch — expected '$expected' but $fail." >&2
    exit 1
fi
echo "$expected"
