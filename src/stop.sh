#!/usr/bin/env bash

PROCESS_NUM=`ps axu | grep "server.php" | grep -v "grep" | wc -l`
if [ ${PROCESS_NUM} -ne 0 ]; then
    ps axu | grep "server.php" | grep -v "grep" | awk -F " " '{print $2}' | xargs kill -9
fi