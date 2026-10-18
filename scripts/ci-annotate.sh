#!/usr/bin/env bash
# Runs a command and, when it fails on GitHub Actions, repeats the end of its output as error annotations. On a
# public repository anyone can read the annotations of a run (in the run page and through the API) without
# signing in, which the job logs require.
#
#   bash scripts/ci-annotate.sh <command> [arguments...]
#
# The command's output still goes to the log as it is, and its exit status is the script's.
set -uo pipefail

log="$(mktemp)"
"$@" 2>&1 | tee "$log"
status=${PIPESTATUS[0]}

if [ "$status" -ne 0 ] && [ -n "${GITHUB_ACTIONS:-}" ]; then
	# The first lines that name an error, then the last 120 lines: colour codes removed, lines cut to 400
	# characters, 30 lines per annotation (GitHub keeps 10 error annotations per step).
	{
		grep -n -i -E 'error|fail|fatal|exception|warning:|not ok|✘' "$log" | head -n 30
		echo '--- last lines ---'
		tail -n 120 "$log"
	} | sed -e 's/\x1b\[[0-9;]*[A-Za-z]//g' | cut -c1-400 > "$log.tail"
	split -l 30 -d "$log.tail" "$log.part."
	total=$(ls "$log".part.* | wc -l)
	n=0
	for part in "$log".part.*; do
		n=$((n + 1))
		msg="$(cat "$part")"
		msg="${msg//'%'/'%25'}"
		msg="${msg//$'\r'/'%0D'}"
		msg="${msg//$'\n'/'%0A'}"
		echo "::error title=output $n of $total (exit $status)::$msg"
	done
	rm -f "$log".part.* "$log.tail"
fi
rm -f "$log"
exit "$status"
