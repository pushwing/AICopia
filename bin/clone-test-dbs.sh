#!/usr/bin/env bash
#
# 병렬 테스트(ParaTest)용 worker DB를 템플릿에서 복제한다.
# ParaTest worker는 TEST_TOKEN(1..N)마다 전용 DB(<template>_<token>)를 쓴다
# (tests/bootstrap.php 참고). 이 스크립트가 그 DB들을 미리 만든다.
#
# 사용:
#   MYSQL_PWD=<비번> bin/clone-test-dbs.sh <N> <host> <user> <template_db> [port]
#   - 비번 없으면 MYSQL_PWD 생략(빈 값), port 없으면 3306
#   - 예) CI:    MYSQL_PWD= bin/clone-test-dbs.sh 4 127.0.0.1 root aicopia_test 13306
#   - 예) 로컬:  MYSQL_PWD=secret bin/clone-test-dbs.sh 4 127.0.0.1 shop aicopia_test
set -euo pipefail

N="${1:-4}"
HOST="${2:-127.0.0.1}"
USER="${3:-root}"
TPL="${4:-aicopia_test}"
PORT="${5:-3306}"

dump="$(mktemp)"
trap 'rm -f "$dump"' EXIT

# 템플릿 스키마+시드 데이터 덤프(GTID 제외 — 다른 DB 로드 시 충돌 방지)
mysqldump -u"$USER" -h"$HOST" -P"$PORT" --no-tablespaces --set-gtid-purged=OFF "$TPL" > "$dump"

for i in $(seq 1 "$N"); do
  db="${TPL}_${i}"
  mysql -u"$USER" -h"$HOST" -P"$PORT" -e "DROP DATABASE IF EXISTS \`${db}\`; CREATE DATABASE \`${db}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"
  mysql -u"$USER" -h"$HOST" -P"$PORT" "$db" < "$dump"
done

echo "✓ worker DB ${TPL}_1 .. ${TPL}_${N} 준비 완료 (N=${N})"
