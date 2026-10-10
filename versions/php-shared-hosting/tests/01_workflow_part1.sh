#!/bin/bash
set -e
JAR=./tests/tmp/cookies2.txt
OUT=./tests/tmp
BASE=http://127.0.0.1:8900
rm -f "$JAR"

csrf() { grep -o 'name="_csrf" value="[^"]*"' "$1" | head -1 | sed -E 's/.*value="([^"]*)".*/\1/'; }

echo "=== 1. GET /login ==="
curl -s -c "$JAR" -b "$JAR" "$BASE/login" -o "$OUT/t1.html"
TOKEN=$(csrf "$OUT/t1.html")
echo "csrf=$TOKEN"

echo "=== 2. POST /login (correct creds) ==="
curl -s -c "$JAR" -b "$JAR" -X POST "$BASE/login" \
  -d "_csrf=$TOKEN" -d "email=admin@lp-test.local" -d "password=SuperSecret123" \
  -o "$OUT/t2.html" -w "HTTP:%{http_code}\n" -D "$OUT/t2.headers"
grep -i "^location" "$OUT/t2.headers" || true

echo "=== 3. GET /dashboard (should be 200, logged in) ==="
curl -s -c "$JAR" -b "$JAR" "$BASE/dashboard" -o "$OUT/t3.html" -w "HTTP:%{http_code}\n"
grep -o "<h1[^>]*>[^<]*</h1>" "$OUT/t3.html"

echo "=== 4. GET /customers/new, extract csrf, create customer ==="
curl -s -c "$JAR" -b "$JAR" "$BASE/customers/new" -o "$OUT/t4.html"
TOKEN=$(csrf "$OUT/t4.html")
curl -s -c "$JAR" -b "$JAR" -X POST "$BASE/customers" \
  -d "_csrf=$TOKEN" -d "name=Juan Dela Cruz" -d "email=juan@example.com" -d "contact_number=09171234567" \
  -o "$OUT/t4b.html" -w "HTTP:%{http_code}\n" -D "$OUT/t4b.headers"
CUSTOMER_LOC=$(grep -i "^location" "$OUT/t4b.headers" | sed -E 's/.*\/customers\/([a-zA-Z0-9_]+).*/\1/' | tr -d '\r')
echo "customer_id=$CUSTOMER_LOC"

echo "=== 5. GET /inquiries/new, create inquiry ==="
curl -s -c "$JAR" -b "$JAR" "$BASE/inquiries/new" -o "$OUT/t5.html"
TOKEN=$(csrf "$OUT/t5.html")
curl -s -c "$JAR" -b "$JAR" -X POST "$BASE/inquiries" \
  -d "_csrf=$TOKEN" -d "customer_id=$CUSTOMER_LOC" -d "desired_product=Tarpaulin Banner" \
  -d "description=3x5ft full color tarpaulin" -d "rough_qty=2" -d "rough_qty_unit=pcs" \
  -o "$OUT/t5b.html" -w "HTTP:%{http_code}\n" -D "$OUT/t5b.headers"
INQUIRY_LOC=$(grep -i "^location" "$OUT/t5b.headers" | sed -E 's/.*\/inquiries\/([a-zA-Z0-9_]+).*/\1/' | tr -d '\r')
echo "inquiry_id=$INQUIRY_LOC"

echo "=== 6. Convert inquiry to quotation ==="
curl -s -c "$JAR" -b "$JAR" "$BASE/inquiries/$INQUIRY_LOC" -o "$OUT/t6.html"
TOKEN=$(csrf "$OUT/t6.html")
curl -s -c "$JAR" -b "$JAR" -X POST "$BASE/inquiries/$INQUIRY_LOC/convert" \
  -d "_csrf=$TOKEN" -o "$OUT/t6b.html" -w "HTTP:%{http_code}\n" -D "$OUT/t6b.headers"
grep -i "^location" "$OUT/t6b.headers"

echo "=== 7. Create quotation with discount+tax (verify server-computed totals) ==="
curl -s -c "$JAR" -b "$JAR" "$BASE/quotations/new?inquiry_id=$INQUIRY_LOC" -o "$OUT/t7.html"
TOKEN=$(csrf "$OUT/t7.html")
curl -s -c "$JAR" -b "$JAR" -X POST "$BASE/quotations" \
  -d "_csrf=$TOKEN" -d "inquiry_id=$INQUIRY_LOC" -d "customer_id=$CUSTOMER_LOC" \
  -d "description[]=Tarpaulin Banner 3x5ft" -d "service_id[]=" -d "qty[]=2" -d "unit[]=pcs" -d "unit_price[]=500" \
  -d "discount_type=PERCENTAGE" -d "discount_value=10" -d "tax_pct=12" -d "notes=Rush order" \
  -o "$OUT/t7b.html" -w "HTTP:%{http_code}\n" -D "$OUT/t7b.headers"
QUOTE_LOC=$(grep -i "^location" "$OUT/t7b.headers" | sed -E 's/.*\/quotations\/([a-zA-Z0-9_]+).*/\1/' | tr -d '\r')
echo "quotation_id=$QUOTE_LOC"

echo "=== 8. Verify computed totals: subtotal=1000, discount=100 (10%), taxable=900, tax=108 (12%), total=1008 ==="
curl -s -c "$JAR" -b "$JAR" "$BASE/quotations/$QUOTE_LOC" -o "$OUT/t8.html"
grep -oE "₱[0-9,]+\.[0-9]{2}" "$OUT/t8.html"

echo "=== 9. Approve quotation, convert to order ==="
curl -s -c "$JAR" -b "$JAR" "$BASE/quotations/$QUOTE_LOC" -o "$OUT/t9.html"
TOKEN=$(csrf "$OUT/t9.html")
curl -s -c "$JAR" -b "$JAR" -X POST "$BASE/quotations/$QUOTE_LOC/approve" -d "_csrf=$TOKEN" -o /dev/null -w "approve->HTTP:%{http_code}\n"
curl -s -c "$JAR" -b "$JAR" "$BASE/quotations/$QUOTE_LOC" -o "$OUT/t9b.html"
TOKEN=$(csrf "$OUT/t9b.html")
curl -s -c "$JAR" -b "$JAR" -X POST "$BASE/quotations/$QUOTE_LOC/convert" -d "_csrf=$TOKEN" -d "payment_term_type=STANDARD_PARTIAL" -d "required_partial_pct=50" \
  -o "$OUT/t9c.html" -w "HTTP:%{http_code}\n" -D "$OUT/t9c.headers"
ORDER_LOC=$(grep -i "^location" "$OUT/t9c.headers" | sed -E 's/.*\/orders\/([a-zA-Z0-9_]+).*/\1/' | tr -d '\r')
echo "order_id=$ORDER_LOC"

echo "=== 10. View order, verify balance shown + total matches quotation ==="
curl -s -c "$JAR" -b "$JAR" "$BASE/orders/$ORDER_LOC" -o "$OUT/t10.html"
grep -oE "₱[0-9,]+\.[0-9]{2}" "$OUT/t10.html"
grep -i "balance" "$OUT/t10.html" | head -3

echo "ALL STEPS COMPLETE"
