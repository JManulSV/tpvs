#!/bin/sh
# Development queue worker: `php artisan queue:listen` with graceful shutdown.
#
# queue:listen installs no signal handler, and as PID 1 the kernel ignores an
# unhandled SIGTERM, so `docker compose stop` would always wait the full
# stop_grace_period. This wrapper handles the stop signal instead: it freezes
# the listener so no new job starts, waits for the running `queue:work --once`
# child to finish its current job, and exits. The child is not signalled:
# with --once Laravel installs no signal handler, so a signal would kill the job.
# Docker still sends SIGKILL after stop_grace_period (340 s).
#
# Usage: queue-listen.sh <queue:listen arguments...>
set -u

php artisan queue:listen "$@" &
listener=$!

# PIDs of the listener's direct children (the queue:work --once process).
children_of() {
    cat /proc/"$1"/task/*/children 2>/dev/null
}

# True while the process exists and is not a zombie.
is_running() {
    [ -r /proc/"$1"/stat ] || return 1
    state=$(sed 's/^.*) //' /proc/"$1"/stat | cut -d ' ' -f 1)
    [ "$state" != "Z" ]
}

shutdown() {
    echo "queue-listen: stop signal received, waiting for the job in progress"
    kill -STOP "$listener" 2>/dev/null
    for pid in $(children_of "$listener"); do
        while is_running "$pid"; do
            sleep 1
        done
    done
    kill -KILL "$listener" 2>/dev/null
    echo "queue-listen: stopped"
    exit 0
}

trap shutdown TERM INT QUIT

wait "$listener"
exit $?
