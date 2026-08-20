#!/bin/bash
# DB-side resource sampler. One CSV row per second:
#   ts,threads_connected,threads_running,questions,slow_queries,max_used_conn,aborted_conns,port80_est
# CPU%/memory are captured separately by typeperf (Process counters).
# Usage: bash tests/sample_resources.sh <out_csv> [max_seconds]
OUT="${1:-tests/out/resources.csv}"
MAX="${2:-600}"
MYSQL='C:/xampp/mysql/bin/mysql.exe'

echo "ts,threads_connected,threads_running,questions,slow_queries,max_used_conn,aborted_conns,port80_est" > "$OUT"

i=0
while [ $i -lt "$MAX" ]; do
    TS=$(date +%s)
    RAW=$("$MYSQL" -uroot -N -e "SHOW GLOBAL STATUS WHERE Variable_name IN ('Threads_connected','Threads_running','Questions','Slow_queries','Max_used_connections','Aborted_connects');" 2>/dev/null)
    TC=$(echo "$RAW" | awk '$1=="Threads_connected"{print $2}')
    TR=$(echo "$RAW" | awk '$1=="Threads_running"{print $2}')
    Q=$(echo "$RAW" | awk '$1=="Questions"{print $2}')
    SL=$(echo "$RAW" | awk '$1=="Slow_queries"{print $2}')
    MU=$(echo "$RAW" | awk '$1=="Max_used_connections"{print $2}')
    AB=$(echo "$RAW" | awk '$1=="Aborted_connects"{print $2}')
    P80=$(netstat -ano 2>/dev/null | grep -c ':80.*ESTABLISHED')
    echo "$TS,$TC,$TR,$Q,$SL,$MU,$AB,$P80" >> "$OUT"
    sleep 1
    i=$((i+1))
done
