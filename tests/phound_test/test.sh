#!/usr/bin/env bash
set -u

if ! php -r 'if (version_compare(PHP_VERSION, "7.4") < 0) exit(1);' ; then
    # If we got here, then the PHP version is 7.3 or lower.
    # For some reason, PHP 7.3 does not have access to the SQLite3 class in the CI environment.
    # The PHP 7.3 CI build had errors in the log:
    #
    #   Error: Class 'SQLite3' not found in /home/vsts/work/1/s/src/Phan/Plugin/Internal/PhoundPlugin.php:66
    #
    # Thus, we skip running the test if we're using PHP version 7.3 or lower.
    echo "The installed PHP version is 7.3 or lower, thus we are skipping the phound tests because we had issues using the SQLite3 extension in the CI environment on this version."
    exit 0
fi

if ! type sqlite3 >/dev/null; then
    echo "sqlite3, which is necessary for this test, is not installed!"
    exit 1
fi

EXPECTED_PATH=expected/all_output.expected
if [ ! -d expected  ]; then
    echo "Error: must run this script from tests/override_return_types folder" 1>&2
    exit 1
fi
echo "Generating test cases"
for path in $(echo expected/*.php.expected | LC_ALL=C sort); do cat "$path"; done > $EXPECTED_PATH
EXIT_CODE=$?
if [[ $EXIT_CODE != 0 ]]; then
    echo "Failed to concatenate test cases" 1>&2
    exit 1
fi

echo "Running phan in '$PWD' ..."

rm -rf ~/phound.db

# We use the polyfill parser because it behaves consistently in all php versions.
if ! ../../phan --force-polyfill-parser --memory-limit 1G --analyze-twice ; then
    echo "Phan found some errors - this is unexpected"
    exit 1
fi

# Regarding the ORDER BY clause:
# 1) `substr(callsite, 0, instr(callsite, ":"))`
#   This transforms a callsite like `001_my_test_file.php:10`` to `001_my_test_file.php`
# 2) `cast(substr(callsite, instr(callsite, ":") + 1) as integer)`
#   This transforms a callsite like 001_my_test_file.php:10 to `10`.
#   That is, it transforms it to the callsite line number as an integer. It avoids weirdness where,
#   for example, 'foo.php:10' might otherwise appear ahead of 'foo.php:9' when treated as a string.
#
# Together, these order by clauses ensure the output is ordered by file and line number.
ACTUAL=$(sqlite3 ~/phound.db 'SELECT * FROM callsites ORDER BY substr(callsite, 0, instr(callsite, ":")), cast(substr(callsite, instr(callsite, ":") + 1) as integer), element, type')

# diff returns a non-zero exit code if files differ or are missing
# This outputs the difference between actual and expected output.
echo "$ACTUAL"
echo "Comparing the output:"

if type colordiff >/dev/null; then
    DIFF="colordiff"
else
    DIFF="diff"
fi

$DIFF $EXPECTED_PATH <(echo "$ACTUAL")
EXIT_CODE=$?
if [ "$EXIT_CODE" == 0 ]; then
    echo "The sqlite3 DB content matches what was expected"
else
    echo "The sqlite3 DB content does not match what was expected"
    exit $EXIT_CODE
fi
