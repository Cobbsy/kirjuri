#!/bin/sh
# Check a running Kirjuri container: installed, admin can log in and add a case, and protected
# files are refused. Usage: docker/smoke-test.sh [base URL] [admin password]
set -eu
BASE=${1:-http://localhost:8080}
PASSWORD=${2:-admin-password}
JAR=$(mktemp)
trap 'rm -f "$JAR"' EXIT
fail() { echo "FAIL: $*" >&2; exit 1; }

for i in $(seq 1 60); do
    [ "$(curl -s -o /dev/null -w '%{http_code}' "$BASE/login.php")" = "200" ] && break
    [ "$i" = "60" ] && fail "login page did not come up"
    sleep 2
done

[ "$(curl -s -c "$JAR" -b "$JAR" -o /dev/null -w '%{redirect_url}' "$BASE/submit.php?type=login" \
    -d "username=admin&password=$PASSWORD&auth_type=local")" = "$BASE/index.php" ] || fail "admin login"

TOKEN=$(curl -s -c "$JAR" -b "$JAR" "$BASE/add_case.php" | sed -n 's/.*name="token" value="\([^"]*\)".*/\1/p' | head -n 1)
[ -n "$TOKEN" ] || fail "no CSRF token on the add case page"
curl -s -c "$JAR" -b "$JAR" -o /dev/null "$BASE/submit.php?type=examination_request" --data-urlencode "token=$TOKEN" \
    -d 'case_name=Smoke+test&case_file_number=1&case_investigator=I&case_investigator_unit=U&case_investigator_tel=1&case_investigation_lead=L&case_confiscation_date=2026-01-01&case_crime=C&case_suspect=S&case_request_description=D&case_urgency=1&case_urg_justification=&case_requested_action=A&case_contains_mob_dev=0&classification=Public&examiners_notes='
curl -s -c "$JAR" -b "$JAR" "$BASE/index.php" | grep -q "Smoke test" || fail "new case not on the front page"

for path in conf/mysql_credentials.php logs/kirjuri.log lib/cases.php actions/cases.php bin/kirjuri composer.json Dockerfile; do
    code=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/$path")
    [ "$code" = "403" ] || [ "$code" = "404" ] || fail "$path is served ($code)"
done
echo "Smoke test passed."
