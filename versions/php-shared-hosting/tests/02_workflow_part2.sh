#!/bin/bash
set -e
JAR=./tests/tmp/cookies2.txt
OUT=./tests/tmp
BASE=http://127.0.0.1:8900
ORDER_LOC="$1"

csrf() { grep -o 'name="_csrf" value="[^"]*"' "$1" | head -1 | sed -E 's/.*value="([^"]*)".*/\1/'; }

echo "=== 11. Create a job order from the order BEFORE any payment ==="
curl -s -c "$JAR" -b "$JAR" "$BASE/orders/$ORDER_LOC" -o "$OUT/t11.html"
TOKEN=$(csrf "$OUT/t11.html")
curl -s -c "$JAR" -b "$JAR" -X POST "$BASE/orders/$ORDER_LOC/job-orders" -d "_csrf=$TOKEN" \
  -o "$OUT/t11b.html" -w "HTTP:%{http_code}\n" -D "$OUT/t11b.headers"
JO_LOC=$(grep -i "^location" "$OUT/t11b.headers" | sed -E 's/.*\/job-orders\/([a-zA-Z0-9_]+).*/\1/' | tr -d '\r')
echo "job_order_id=$JO_LOC"

echo "=== 12. Try to advance stage BEFORE partial payment -> must be rejected (Rule #1) ==="
curl -s -c "$JAR" -b "$JAR" "$BASE/job-orders/$JO_LOC" -o "$OUT/t12.html"
TOKEN=$(csrf "$OUT/t12.html")
curl -s -c "$JAR" -b "$JAR" -X POST "$BASE/job-orders/$JO_LOC/advance" -d "_csrf=$TOKEN" \
  -o "$OUT/t12b.html" -w "HTTP:%{http_code}\n" -D "$OUT/t12b.headers"
# Should redirect back with flash error, status should remain ON_HOLD
curl -s -c "$JAR" -b "$JAR" "$BASE/job-orders/$JO_LOC" -o "$OUT/t12c.html"
grep -i "alert-error\|ON_HOLD\|badge-slate" "$OUT/t12c.html" | head -3

echo "=== 13. Record a PARTIAL payment (504.00 of 1008.00 = exactly 50%) ==="
curl -s -c "$JAR" -b "$JAR" "$BASE/orders/$ORDER_LOC" -o "$OUT/t13.html"
TOKEN=$(csrf "$OUT/t13.html")
curl -s -c "$JAR" -b "$JAR" -X POST "$BASE/orders/$ORDER_LOC/payments" \
  -d "_csrf=$TOKEN" -d "amount=504.00" -d "method=CASH" -d "reference_number=TEST-REF-1" \
  -o "$OUT/t13b.html" -w "HTTP:%{http_code}\n" -D "$OUT/t13b.headers"
grep -i "^location" "$OUT/t13b.headers"

echo "=== 14. Confirm the payment ==="
curl -s -c "$JAR" -b "$JAR" "$BASE/orders/$ORDER_LOC" -o "$OUT/t14.html"
TOKEN=$(csrf "$OUT/t14.html")
PAY_ID=$(grep -oE 'payments/[a-zA-Z0-9_]+/confirm' "$OUT/t14.html" | head -1 | sed -E 's#payments/([a-zA-Z0-9_]+)/confirm#\1#')
echo "payment_id=$PAY_ID"
curl -s -c "$JAR" -b "$JAR" -X POST "$BASE/payments/$PAY_ID/confirm" -d "_csrf=$TOKEN" -o /dev/null -w "confirm->HTTP:%{http_code}\n"

echo "=== 15. Verify balance updated to 504.00 ==="
curl -s -c "$JAR" -b "$JAR" "$BASE/orders/$ORDER_LOC" -o "$OUT/t15.html"
grep -i "balance\|fully paid" "$OUT/t15.html" | head -3
grep -oE "₱[0-9,]+\.[0-9]{2}" "$OUT/t15.html" | head -5

echo "=== 16. Advance stage NOW (partial met exactly 50%) -> should succeed ==="
curl -s -c "$JAR" -b "$JAR" "$BASE/job-orders/$JO_LOC" -o "$OUT/t16.html"
TOKEN=$(csrf "$OUT/t16.html")
curl -s -c "$JAR" -b "$JAR" -X POST "$BASE/job-orders/$JO_LOC/advance" -d "_csrf=$TOKEN" \
  -o "$OUT/t16b.html" -w "HTTP:%{http_code}\n"
curl -s -c "$JAR" -b "$JAR" "$BASE/job-orders/$JO_LOC" -o "$OUT/t16c.html"
grep -i "badge-blue\|IN_PROGRESS\|Design" "$OUT/t16c.html" | head -5

echo "=== 17. Record QC result ==="
TOKEN=$(csrf "$OUT/t16c.html")
curl -s -c "$JAR" -b "$JAR" -X POST "$BASE/job-orders/$JO_LOC/qc" \
  -d "_csrf=$TOKEN" -d "item_label=Color accuracy check" -d "outcome=PASS" -d "notes=Looks good" \
  -o "$OUT/t17.html" -w "HTTP:%{http_code}\n"
curl -s -c "$JAR" -b "$JAR" "$BASE/job-orders/$JO_LOC" -o "$OUT/t17b.html"
grep -i "Color accuracy\|badge-green" "$OUT/t17b.html" | head -3

echo "ALL STEPS COMPLETE"
