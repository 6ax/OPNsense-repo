#!/bin/sh
# Prepare and check the Kazuha Hub RADIUS configuration.
#
#   check.sh run     export the certificates, fix ownership, check the
#                    configuration with radiusd, print the result as JSON
#   check.sh last    print the stored result of the last check as JSON,
#                    with the conflicts looked up again now
#   check.sh start   as run, for the rc script: the exit status is the verdict
#   check.sh conflicts   print what keeps the service from starting, if anything;
#                    the exit status is 1 then
#
# The JSON carries "result" (ok, failed or unknown), "message", "checked" (epoch
# seconds) and "conflicts" (a list). The message is only ever whitelisted lines
# of radiusd's output; secrets and key material are never part of it.
set -u

conf_dir=/usr/local/etc/kazuhahub-radius
run_dir=/var/run/kazuhahub-radius
log_dir=/var/log/kazuhahub-radius
radiusd=/usr/local/sbin/radiusd
export_script=/usr/local/opnsense/scripts/kazuhahub-radius/export_certs.php
php=/usr/local/bin/php
daemon_user=freeradius

result_file="$run_dir/check.result"
message_file="$run_dir/check.message"
time_file="$run_dir/check.time"

mode="${1:-run}"

# Set up what the daemon needs and fix what the template engine leaves behind:
# the generated files belong to root and are readable by the daemon's group only.
prepare() {
    mkdir -p "$run_dir" "$log_dir" "$conf_dir/certs" || return 1
    chown "$daemon_user:$daemon_user" "$run_dir" "$log_dir" || return 1
    chmod 0750 "$run_dir" "$log_dir" || return 1
    chown -R "root:$daemon_user" "$conf_dir" || return 1
    chmod 0750 "$conf_dir" || return 1
    find "$conf_dir" -type d -exec chmod 0750 {} + || return 1
    find "$conf_dir" -type f -exec chmod 0640 {} + || return 1
}

# One line per thing that keeps the service from starting next to the daemon of
# another package. Prints nothing when there is none.
conflicts() {
    if pkg info -e os-freeradius >/dev/null 2>&1; then
        echo 'os-freeradius is installed. It runs the same daemon, so remove it before using this service.'
    fi
    if service -e 2>/dev/null | grep -q '/radiusd$'; then
        echo 'The stock radiusd service is enabled (radiusd_enable). Disable it before using this service.'
    fi
}

# The lines of radiusd's configuration check that say what is wrong.
trim() {
    grep -E '^(Error|ERROR|Warning|WARNING|Unable|Failed|Errors|rlm_|radiusd:|/usr/local/etc/kazuhahub-radius/|Configuration appears)' \
        | grep -v -i -E 'secret|password|####' \
        | cut -c1-240 | tail -n 12
}

escape() {
    # JSON string body: backslash and quote escaped, controls dropped, lines joined by \n.
    tr -c '[:print:]\n' '?' | sed -e 's/\\/\\\\/g' -e 's/"/\\"/g' | awk 'NR > 1 { printf "\\n" } { printf "%s", $0 }'
}

emit() {
    state="$1"; message="$2"; checked="$3"
    printf '{"result":"%s","message":"%s","checked":%s,"conflicts":[' "$state" "$(printf '%s' "$message" | escape)" "$checked"
    first=1
    conflicts | while IFS= read -r line; do
        [ -n "$line" ] || continue
        [ "$first" = 1 ] || printf ','
        first=0
        printf '"%s"' "$(printf '%s' "$line" | escape)"
    done
    printf ']}\n'
}

store() {
    printf '%s\n' "$1" > "$result_file"
    printf '%s\n' "$2" > "$message_file"
    date +%s > "$time_file"
}

run_check() {
    if ! prepare; then
        store failed 'The configuration directory could not be prepared.'
        return 1
    fi
    if ! exported="$("$php" "$export_script" 2>&1)"; then
        store failed "$(printf '%s\n' "$exported" | head -n 3 | cut -c1-240)"
        return 1
    fi
    # Exporting may have written new files as root.
    prepare || return 1
    if output="$("$radiusd" -XC -d "$conf_dir" -n radiusd 2>&1)"; then
        store ok 'Configuration appears to be OK'
        return 0
    fi
    message="$(printf '%s\n' "$output" | trim)"
    [ -n "$message" ] || message='The configuration check failed.'
    store failed "$message"
    return 1
}

case "$mode" in
run|start)
    run_check
    status=$?
    if [ "$mode" = run ]; then
        emit "$(cat "$result_file" 2>/dev/null || echo unknown)" "$(cat "$message_file" 2>/dev/null)" "$(cat "$time_file" 2>/dev/null || echo 0)"
        exit 0
    fi
    if [ "$status" -ne 0 ]; then
        cat "$message_file" >&2
    fi
    exit "$status"
    ;;
conflicts)
    found="$(conflicts)"
    if [ -n "$found" ]; then
        printf '%s\n' "$found"
        exit 1
    fi
    ;;
last)
    if [ -f "$result_file" ]; then
        emit "$(cat "$result_file")" "$(cat "$message_file" 2>/dev/null)" "$(cat "$time_file" 2>/dev/null || echo 0)"
    else
        emit unknown '' 0
    fi
    ;;
*)
    echo "usage: check.sh run|last|start|conflicts" >&2
    exit 2
    ;;
esac
