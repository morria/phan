#!/usr/bin/env bash
EXPECTED_PATH=expected/all_output.expected
ACTUAL_PATH=all_output.actual
if [ ! -d expected  ]; then
	echo "Error: must run this script from tests/plugin_test folder" 1>&2
	exit 1
fi
echo "Generating test cases"
for path in $(echo expected/*.php.expected | LC_ALL=C sort); do cat $path; done > $EXPECTED_PATH
if [[ $? != 0 ]]; then
	echo "Failed to concatenate test cases" 1>&2
	exit 1
fi
echo "Running phan in '$PWD' ..."

if [ -f $ACTUAL_PATH ]; then
    rm -f $ACTUAL_PATH || exit 1
fi

# We use the polyfill parser because it behaves consistently in all php versions.
../../phan --force-polyfill-parser --memory-limit 1G | tee $ACTUAL_PATH

sed -i 's,\<closure_[0-9a-f]\{12\}\>,closure_%s,g' $ACTUAL_PATH
sed -i 's,\<closure_[0-9a-f]\{12\}\>,closure_%s,g' $EXPECTED_PATH
# diff returns a non-zero exit code if files differ or are missing
# This outputs the difference between actual and expected output.
echo
echo "Comparing the output:"

diff $EXPECTED_PATH $ACTUAL_PATH
EXIT_CODE=$?
if [ "$EXIT_CODE" == 0 ]; then
	echo "Files $EXPECTED_PATH and output $ACTUAL_PATH are identical"
    rm $ACTUAL_PATH
fi
exit $EXIT_CODE
