#!/usr/bin/env bash
# Unpack the Omeka S release the module is checked against into
# .integration/omeka-s, verified against its SHA-256 before anything in it runs.
# The digest is the one GitHub records for the release asset
# (`gh api repos/omeka/omeka-s/releases/tags/v4.2.1 -q '.assets[].digest'`).
# The zip carries vendor/, so nothing is installed with Composer.
#
# Used by CI's integration job (integration suite and PHPStan) and by the
# release workflow (PHPStan). Locally, CLAUDE.md's git checkout serves instead.
set -euo pipefail

version=4.2.1
sha256=cc27d1c7aca0209523d19aa285f4a08e29e34950dcc446951a7c1311de348e82
zip="${RUNNER_TEMP:-/tmp}/omeka-s-$version.zip"

curl --fail --location --retry 3 -o "$zip" \
    "https://github.com/omeka/omeka-s/releases/download/v$version/omeka-s-$version.zip"
echo "$sha256  $zip" | sha256sum -c -
mkdir -p .integration
unzip -q "$zip" -d .integration
test -f .integration/omeka-s/vendor/autoload.php
