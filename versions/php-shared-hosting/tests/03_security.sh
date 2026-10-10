#!/bin/bash
# Credentials for the admin account you created with the installer on
# your OWN test install — never real production credentials. Override
# via environment variables: TEST_ADMIN_EMAIL=... TEST_ADMIN_PASSWORD=...
TEST_ADMIN_EMAIL="${TEST_ADMIN_EMAIL:-admin@example.test}"
TEST_ADMIN_PASSWORD="${TEST_ADMIN_PASSWORD:-change-this-test-password}"
JAR=./tests/tmp/cookies2.txt
BASE=http://127.0.0.1:8900

echo "=== A. Unauthenticated access to /dashboard -> should redirect to /login ==="
curl -s -o /dev/null -w "HTTP:%{http_code}  " "$BASE/dashboard"
curl -s -I "$BASE/dashboard" | grep -i "^location"

echo "=== B. POST without CSRF token -> should be rejected (419) ==="
curl -s -c "$JAR" -b "$JAR" -X POST "$BASE/customers" -d "name=Attacker Inc" -o ./tests/tmp/sec_b.html -w "HTTP:%{http_code}\n"
head -c 200 ./tests/tmp/sec_b.html; echo

echo "=== C. SQL injection attempt in login email ==="
curl -s -c "$JAR" -b "$JAR" "$BASE/login" -o ./tests/tmp/sec_c0.html
TOKEN=$(grep -o 'name="_csrf" value="[^"]*"' ./tests/tmp/sec_c0.html | head -1 | sed -E 's/.*value="([^"]*)".*/\1/')
curl -s -c "$JAR" -b "$JAR" -X POST "$BASE/login" \
  --data-urlencode "_csrf=$TOKEN" --data-urlencode "email=$TEST_ADMIN_EMAIL' OR '1'='1" --data-urlencode "password=x" \
  -o ./tests/tmp/sec_c.html -w "HTTP:%{http_code}\n"
grep -i "invalid email or password\|dashboard" ./tests/tmp/sec_c.html | head -2
echo "(table still intact check)"
mysql -u root lp_install_test -e "SELECT COUNT(*) AS user_count FROM users;"

echo "=== D. Wrong password rejected + throttling after repeated failures ==="
for i in 1 2 3 4 5 6; do
  curl -s -c "$JAR" -b "$JAR" "$BASE/login" -o ./tests/tmp/sec_d0.html
  TOKEN=$(grep -o 'name="_csrf" value="[^"]*"' ./tests/tmp/sec_d0.html | head -1 | sed -E 's/.*value="([^"]*)".*/\1/')
  curl -s -c "$JAR" -b "$JAR" -X POST "$BASE/login" -d "_csrf=$TOKEN" -d "email=$TEST_ADMIN_EMAIL" -d "password=WrongPass$i" -o ./tests/tmp/sec_d.html
  grep -o "Invalid email or password\.\|Too many failed attempts[^<]*" ./tests/tmp/sec_d.html | head -1
done

echo "=== E. Direct access to a non-existent protected file download ==="
curl -s -o /dev/null -w "HTTP:%{http_code}\n" "$BASE/files/nonexistent"

echo "=== F. Path traversal attempt on a route param ==="
curl -s -o /dev/null -w "HTTP:%{http_code}\n" "$BASE/customers/../../../etc/passwd"
