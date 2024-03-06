#!/usr/bin/env bash

set -o errexit
set -o nounset
set -o pipefail

cd "$(dirname "$0")/.."

main() {
  ARCHIVE_NAME="alfred-productive-workflow.alfredworkflow"
  rm -f "$ARCHIVE_NAME"
  zip "$ARCHIVE_NAME" bin src vendor info.plist CHANGELOG.md README.md icon.png composer.json
}

main
