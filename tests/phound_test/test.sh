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

echo "Running phan in '$PWD' ..."

rm -rf ~/phound.db

# We use the polyfill parser because it behaves consistently in all php versions.
if ! ../../phan --force-polyfill-parser --memory-limit 1G --analyze-twice ; then
    echo "Phan found some errors - this is unexpected"
    exit 1
fi

ACTUAL=$(sqlite3 ~/phound.db 'select element, type, callsite from callsites order by callsite, element, type')
EXPECTED=$(cat <<-EOF
\A::foo|const|src/001_phound_callsites.php:10
\A::foo|prop|src/001_phound_callsites.php:11
\A::fooz|prop|src/001_phound_callsites.php:12
\A::foo|method|src/001_phound_callsites.php:13
\A::bar|method|src/001_phound_callsites.php:14
\A::getBOrC|method|src/001_phound_callsites.php:34
\B::foo|method|src/001_phound_callsites.php:35
\C::foo|method|src/001_phound_callsites.php:35
\B::bar|prop|src/001_phound_callsites.php:36
\C::bar|prop|src/001_phound_callsites.php:36
\A::getBOrCClassName|method|src/001_phound_callsites.php:38
\B::zoo|method|src/001_phound_callsites.php:39
\C::zoo|method|src/001_phound_callsites.php:39
\B::baz|prop|src/001_phound_callsites.php:40
\C::baz|prop|src/001_phound_callsites.php:40
\B::BOO|const|src/001_phound_callsites.php:41
\C::BOO|const|src/001_phound_callsites.php:41
\TestConstructor::__construct|method|src/001_phound_callsites.php:68
\A::foo|method|src/001_phound_callsites.php:73
\A::bar|method|src/001_phound_callsites.php:74
\A::foo|method|src/001_phound_callsites.php:76
\A::getBOrC|method|src/001_phound_callsites.php:77
\B::foo|method|src/001_phound_callsites.php:77
\C::foo|method|src/001_phound_callsites.php:77
\A::bar|method|src/001_phound_callsites.php:78
\A::baz|method|src/001_phound_callsites.php:79
\A::bar|method|src/001_phound_callsites.php:83
\A::getBOrCClassName|method|src/001_phound_callsites.php:85
\B::foo|method|src/001_phound_callsites.php:86
\C::foo|method|src/001_phound_callsites.php:86
\A::foo|method|src/001_phound_callsites.php:88
\Closure::fromCallable|method|src/001_phound_callsites.php:88
\A::getBOrC|method|src/001_phound_callsites.php:91
\B::foo|method|src/001_phound_callsites.php:91
\C::foo|method|src/001_phound_callsites.php:91
\Closure::fromCallable|method|src/001_phound_callsites.php:91
EOF
)

# diff returns a non-zero exit code if files differ or are missing
# This outputs the difference between actual and expected output.
echo
echo "Comparing the output:"

if type colordiff >/dev/null; then
    DIFF="colordiff"
else
    DIFF="diff"
fi

$DIFF <(echo "$EXPECTED") <(echo "$ACTUAL")
EXIT_CODE=$?
if [ "$EXIT_CODE" == 0 ]; then
    echo "The sqlite3 DB content matches what was expected"
else
    echo "The sqlite3 DB content does not match what was expected"
    exit $EXIT_CODE
fi
