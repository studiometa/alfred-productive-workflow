#!/usr/bin/env bash

set -o errexit
set -o nounset
set -o pipefail

cd "$(dirname "$0")/.."

main() {
  CMD=${1:-tasks}
  PARAMS=${@/$CMD/}

  mkdir -p logs
  mkdir -p cache

  # Run the update in the background when no parameters are used
  if [[ ! $PARAMS ]]
  then
    ./bin/php src/index.php $CMD --update-cache > logs/$CMD.log 2>&1 &
  fi

  # Display the main output
  ./bin/php src/index.php $CMD $PARAMS
}

main "$@"
