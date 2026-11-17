#!/usr/bin/env bash
# End-to-end regression suite for the rental system.
#
#   bash tests/e2e.sh
#
# Creates and drops a throwaway MySQL database (default: rentals_test), starts
# the PHP built-in server on 127.0.0.1:8099 and exercises authentication, both
# portals, CSRF enforcement, the simulated payment lifecycle, receipts and
# admin operations.
#
# Overrides: PHP_BIN, MYSQL_BIN, TEST_DB, TEST_PORT, TEST_DB_USER, TEST_DB_PASS.
set -u

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJ="$(cd "$SCRIPT_DIR/.." && pwd)"
if PROJ_W="$(cd "$PROJ" && pwd -W 2>/dev/null)"; then PROJ="$PROJ_W"; fi

PHP_BIN="${PHP_BIN:-}"
if [ -z "$PHP_BIN" ]; then
  if [ -x "C:/xampp/php/php.exe" ]; then PHP_BIN="C:/xampp/php/php.exe"; else PHP_BIN="$(command -v php || true)"; fi
fi
MYSQL_BIN="${MYSQL_BIN:-}"
if [ -z "$MYSQL_BIN" ]; then
  if [ -x "C:/xampp/mysql/bin/mysql.exe" ]; then MYSQL_BIN="C:/xampp/mysql/bin/mysql.exe"; else MYSQL_BIN="$(command -v mysql || true)"; fi
fi
TEST_DB="${TEST_DB:-rentals_test}"
TEST_PORT="${TEST_PORT:-8099}"
DB_USER="${TEST_DB_USER:-root}"
DB_PASS="${TEST_DB_PASS:-}"

[ -n "$PHP_BIN" ] || { echo "php executable not found (set PHP_BIN)."; exit 1; }
[ -n "$MYSQL_BIN" ] || { echo "mysql client not found (set MYSQL_BIN)."; exit 1; }
case "$TEST_DB" in
  *test*) ;;
  *) echo "Refusing to use '$TEST_DB': TEST_DB must contain 'test'."; exit 2;;
esac

MYSQL_CMD=("$MYSQL_BIN" -u "$DB_USER")
if [ -n "$DB_PASS" ]; then MYSQL_CMD+=("-p$DB_PASS"); fi

BASE="http://127.0.0.1:$TEST_PORT"
W="$(mktemp -d)"
SRV=""
DB_READY=""

cleanup() {
  [ -n "$SRV" ] && kill "$SRV" 2>/dev/null
  rm -f "$W"/* 2>/dev/null
  rmdir "$W" 2>/dev/null
  [ -n "$DB_READY" ] && "${MYSQL_CMD[@]}" -e "DROP DATABASE IF EXISTS \`$TEST_DB\`;" 2>/dev/null
}
trap cleanup EXIT

pass=0; fail=0
check() { # label expected actual
  if [ "$2" = "$3" ]; then pass=$((pass+1)); printf 'PASS  %s\n' "$1";
  else fail=$((fail+1)); printf 'FAIL  %s (expected [%s] got [%s])\n' "$1" "$2" "$3"; fi
}
contains() { # label needle file
  if grep -qiF -- "$2" "$3"; then pass=$((pass+1)); printf 'PASS  %s\n' "$1";
  else fail=$((fail+1)); printf 'FAIL  %s (missing [%s])\n' "$1" "$2"; fi
}
lacks() { # label needle file
  if grep -qiF -- "$2" "$3"; then fail=$((fail+1)); printf 'FAIL  %s (unexpected [%s])\n' "$1" "$2";
  else pass=$((pass+1)); printf 'PASS  %s\n' "$1"; fi
}
q() { "${MYSQL_CMD[@]}" -N -e "$1"; }
csrf() { grep -o 'name="csrf_token" value="[^"]*"' "$1" | head -1 | sed 's/.*value="//;s/"//'; }
status() { curl -s -o /dev/null -w '%{http_code}' "$@"; }
login() { # jar identifier password -> "code redirect"
  local jar=$1
  curl -s -c "$jar" "$BASE/index.php" -o "$jar.html"
  local t; t=$(csrf "$jar.html")
  curl -s -b "$jar" -c "$jar" -o /dev/null -w '%{http_code} %{redirect_url}' \
    -X POST "$BASE/index.php" -d "csrf_token=$t" -d "identifier=$2" -d "password=$3"
}

cd "$W" || exit 1

echo "== setup =="
"${MYSQL_CMD[@]}" -e "DROP DATABASE IF EXISTS \`$TEST_DB\`; CREATE DATABASE \`$TEST_DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" || exit 1
DB_READY=1
sed "s/^USE rentals;/USE $TEST_DB;/" "$PROJ/database/schema.sql" | "${MYSQL_CMD[@]}" "$TEST_DB" || { echo "schema import failed"; exit 1; }
DB_NAME="$TEST_DB" "$PHP_BIN" "$PROJ/database/seed.php" > seed.out || { echo "seed failed"; cat seed.out; exit 1; }
DB_NAME="$TEST_DB" PESAPAL_MODE=simulate "$PHP_BIN" -S "127.0.0.1:$TEST_PORT" -t "$PROJ" > server.log 2>&1 &
SRV=$!
sleep 2
check "server responds" 200 "$(status "$BASE/index.php")"
if [ "$fail" -ne 0 ]; then cat server.log; exit 1; fi

echo "== headers and cookies =="
curl -s -D hdr.txt -c g.jar "$BASE/index.php" -o idx.html
contains "X-Content-Type-Options" "X-Content-Type-Options: nosniff" hdr.txt
contains "X-Frame-Options" "X-Frame-Options: SAMEORIGIN" hdr.txt
contains "CSP frame-ancestors" "frame-ancestors 'self'" hdr.txt
contains "Referrer-Policy" "Referrer-Policy: same-origin" hdr.txt
contains "cookie HttpOnly" "HttpOnly" hdr.txt
contains "cookie SameSite=Lax" "SameSite=Lax" hdr.txt
lacks "no Secure flag over HTTP" "Secure" hdr.txt

echo "== authentication =="
R=$(login a.jar admin Admin@123)
check "admin login" "302 $BASE/admin/dashboard.php" "$R"
R=$(login m.jar manager Manager@123)
check "manager login" "302 $BASE/admin/dashboard.php" "$R"
R=$(login t1.jar 0700000001 Tenant@123)
check "tenant login" "302 $BASE/tenant/dashboard.php" "$R"
R=$(login t2.jar 0700000002 Tenant@123)
check "second tenant login" "302 $BASE/tenant/dashboard.php" "$R"
R=$(login bad.jar 0700000001 wrong-password)
check "bad password rejected" 200 "$(echo "$R" | cut -d' ' -f1)"
curl -s -c bad.jar "$BASE/index.php" -o bl.html
T=$(csrf bl.html)
curl -s -b bad.jar -c bad.jar -o bl2.html -X POST "$BASE/index.php" \
  -d "csrf_token=$T" -d "identifier=0700000001" -d "password=wrong-password"
contains "generic login error" "Invalid username, phone/email, or password." bl2.html
check "admin cannot open tenant portal" 302 "$(status -b a.jar "$BASE/tenant/dashboard.php")"
check "tenant cannot open admin portal" 302 "$(status -b t1.jar "$BASE/admin/dashboard.php")"
check "guest cannot open admin portal" 302 "$(status "$BASE/admin/dashboard.php")"
check "guest cannot open tenant portal" 302 "$(status "$BASE/tenant/dashboard.php")"

echo "== portal pages (regression) =="
for p in dashboard properties houses tenants invoices payments reports arrears email_config pesapal_config change_password; do
  check "admin/$p" 200 "$(status -b a.jar "$BASE/admin/$p.php")"
done
for p in dashboard pay invoices history rental_information change_password; do
  check "tenant/$p" 200 "$(status -b t1.jar "$BASE/tenant/$p.php")"
done

echo "== CSRF enforcement =="
check "payment create without token" 403 "$(status -b t1.jar -X POST "$BASE/payment/create.php" -d 'amount=100')"
check "house save without token" 403 "$(status -b a.jar -X POST "$BASE/admin/houses.php" -d 'op=save' -d 'id=CSRF-X' -d 'rent_amount=1')"

echo "== simulated payment lifecycle =="
curl -s -b t1.jar -c t1.jar "$BASE/tenant/pay.php" -o pay.html
T=$(csrf pay.html)
BEFORE=$(q "SELECT COUNT(*) FROM $TEST_DB.payments")
R=$(curl -s -b t1.jar -c t1.jar -o /dev/null -w '%{redirect_url}' -X POST "$BASE/payment/create.php" \
  -d "csrf_token=$T" -d "phone=0700000001" -d "amount=5000")
case "$R" in
  "$BASE/payment/simulate_checkout.php?order_tracking_id=SIM-"*) pass=$((pass+1)); echo "PASS  payment create redirects to mock checkout";;
  *) fail=$((fail+1)); echo "FAIL  payment create redirect [$R]";;
esac
TRK=${R#*order_tracking_id=}
PID=$(q "SELECT id FROM $TEST_DB.payments WHERE order_tracking_id='$TRK'")
check "one payment row added" $((BEFORE+1)) "$(q "SELECT COUNT(*) FROM $TEST_DB.payments")"
check "owner opens mock checkout" 200 "$(curl -s -o mock.html -w '%{http_code}' -b t1.jar "$BASE/payment/simulate_checkout.php?order_tracking_id=$TRK")"
contains "mock page shows amount" "5,000.00" mock.html
T3=$(csrf mock.html)
check "other tenant blocked GET" 404 "$(status -b t2.jar "$BASE/payment/simulate_checkout.php?order_tracking_id=$TRK")"
check "other tenant blocked POST" 404 "$(status -b t2.jar -X POST "$BASE/payment/simulate_checkout.php" -d "order_tracking_id=$TRK" -d 'choice=complete')"
check "guest blocked GET" 302 "$(status "$BASE/payment/simulate_checkout.php?order_tracking_id=$TRK")"
ALLOC_BEFORE=$(q "SELECT COUNT(*) FROM $TEST_DB.payment_allocations WHERE payment_id=$PID")
check "complete payment redirects" 302 "$(status -b t1.jar -X POST "$BASE/payment/simulate_checkout.php" -d "csrf_token=$T3" -d "order_tracking_id=$TRK" -d 'choice=complete' -d 'method=M-PESA')"
CONF=$(q "SELECT confirmation_code FROM $TEST_DB.payments WHERE id=$PID")
check "payment is COMPLETED" COMPLETED "$(q "SELECT status FROM $TEST_DB.payments WHERE id=$PID")"
case "$CONF" in SIM*) pass=$((pass+1)); echo "PASS  confirmation code assigned";; *) fail=$((fail+1)); echo "FAIL  confirmation code [$CONF]";; esac
curl -s -b t1.jar -c t1.jar "$BASE/tenant/payment_status.php?OrderTrackingId=$TRK" -o st.html --max-time 120
contains "status page reports success" "Payment Successful" st.html
check "repeat complete keeps status" 302 "$(status -b t1.jar -X POST "$BASE/payment/simulate_checkout.php" -d "csrf_token=$T3" -d "order_tracking_id=$TRK" -d 'choice=complete')"
check "fail cannot flip completed" 302 "$(status -b t1.jar -X POST "$BASE/payment/simulate_checkout.php" -d "csrf_token=$T3" -d "order_tracking_id=$TRK" -d 'choice=fail')"
check "status still COMPLETED" COMPLETED "$(q "SELECT status FROM $TEST_DB.payments WHERE id=$PID")"
check "confirmation unchanged" "$CONF" "$(q "SELECT confirmation_code FROM $TEST_DB.payments WHERE id=$PID")"
check "no duplicate allocations" "$ALLOC_BEFORE" "$(q "SELECT COUNT(*) FROM $TEST_DB.payment_allocations WHERE payment_id=$PID")"

echo "== receipts =="
check "owner downloads receipt" 200 "$(curl -s -o rec.pdf -w '%{http_code}' -b t1.jar "$BASE/payment/receipt.php?payment_id=$PID" --max-time 120)"
check "receipt is a PDF" "%PDF-" "$(head -c5 rec.pdf)"
check "other tenant denied receipt" 404 "$(status -b t2.jar "$BASE/payment/receipt.php?payment_id=$PID")"
check "guest denied receipt" 302 "$(status "$BASE/payment/receipt.php?payment_id=$PID")"
check "admin can download receipt" 200 "$(status -b a.jar "$BASE/payment/receipt.php?payment_id=$PID" -o rec2.pdf --max-time 120)"

echo "== payment input validation =="
curl -s -b t1.jar -c t1.jar "$BASE/tenant/pay.php" -o pay2.html
T=$(csrf pay2.html)
COUNT=$(q "SELECT COUNT(*) FROM $TEST_DB.payments")
check "zero amount rejected" 302 "$(status -b t1.jar -X POST "$BASE/payment/create.php" -d "csrf_token=$T" -d 'phone=0700000001' -d 'amount=0')"
check "no row for zero amount" "$COUNT" "$(q "SELECT COUNT(*) FROM $TEST_DB.payments")"
INV2=$(q "SELECT id FROM $TEST_DB.invoices WHERE tenant_id=2 LIMIT 1")
check "foreign invoice rejected" 302 "$(status -b t1.jar -X POST "$BASE/payment/create.php" -d "csrf_token=$T" -d 'phone=0700000001' -d 'amount=100' -d "invoice_id=$INV2")"
check "no row for foreign invoice" "$COUNT" "$(q "SELECT COUNT(*) FROM $TEST_DB.payments")"

echo "== admin operations =="
curl -s -b a.jar -c a.jar "$BASE/admin/houses.php?action=add" -o houses.html
T=$(csrf houses.html)
check "add house" 302 "$(status -b a.jar -X POST "$BASE/admin/houses.php" -d "csrf_token=$T" -d 'op=save' -d 'id=TEST-H1' -d 'property_id=' -d 'rent_amount=5000' -d 'existing_id=')"
check "house persisted" 1 "$(q "SELECT COUNT(*) FROM $TEST_DB.houses WHERE id='TEST-H1'")"
check "delete house" 302 "$(status -b a.jar -X POST "$BASE/admin/houses.php" -d "csrf_token=$T" -d 'op=delete' -d 'id=TEST-H1')"
check "house removed" 0 "$(q "SELECT COUNT(*) FROM $TEST_DB.houses WHERE id='TEST-H1'")"

curl -s -b a.jar -c a.jar "$BASE/admin/invoices.php" -o inv.html
T=$(csrf inv.html)
check "create custom invoice" 302 "$(status -b a.jar -X POST "$BASE/admin/invoices.php" -d "csrf_token=$T" -d 'op=create_custom' -d 'tenant_id=2' -d 'description=Water bill' -d 'amount=750' -d 'due_date=2026-12-01')"
CID=$(q "SELECT id FROM $TEST_DB.invoices WHERE type='CUSTOM' AND description='Water bill' ORDER BY id DESC LIMIT 1")
check "custom invoice stored" UNPAID "$(q "SELECT status FROM $TEST_DB.invoices WHERE id=$CID")"
check "cancel custom invoice" 302 "$(status -b a.jar -X POST "$BASE/admin/invoices.php" -d "csrf_token=$T" -d 'op=cancel' -d "id=$CID")"
check "invoice cancelled" CANCELLED "$(q "SELECT status FROM $TEST_DB.invoices WHERE id=$CID")"

GEN1=$(q "SELECT COUNT(*) FROM $TEST_DB.invoices WHERE month=2 AND year=2027")
check "generate February 2027 invoices" 302 "$(status -b a.jar -X POST "$BASE/admin/invoices.php" -d "csrf_token=$T" -d 'op=generate' -d 'month=2' -d 'year=2027')"
GEN2=$(q "SELECT COUNT(*) FROM $TEST_DB.invoices WHERE month=2 AND year=2027")
check "three invoices created" 3 "$((GEN2-GEN1))"
check "second generate is idempotent" 302 "$(status -b a.jar -X POST "$BASE/admin/invoices.php" -d "csrf_token=$T" -d 'op=generate' -d 'month=2' -d 'year=2027')"
check "no duplicate February invoices" "$GEN2" "$(q "SELECT COUNT(*) FROM $TEST_DB.invoices WHERE month=2 AND year=2027")"

INV3=$(q "SELECT id FROM $TEST_DB.invoices WHERE tenant_id=3 AND status='UNPAID' ORDER BY id LIMIT 1")
curl -s -b a.jar -c a.jar "$BASE/admin/payments.php" -o pays.html
T=$(csrf pays.html)
check "record cash payment" 302 "$(status -b a.jar -X POST "$BASE/admin/payments.php" -d "csrf_token=$T" -d 'action=record_cash_payment' -d 'tenant_id=3' -d 'category=RENT' -d "invoice_id=$INV3" -d 'amount=1000')"
check "cash payment stored" 1 "$(q "SELECT COUNT(*) FROM $TEST_DB.payments WHERE tenant_id=3 AND payment_method LIKE 'CASH%' AND status='COMPLETED' AND amount=1000.00")"
check "invoice marked partial" "PARTIAL 1000.00" "$(q "SELECT CONCAT(status, ' ', amount_paid) FROM $TEST_DB.invoices WHERE id=$INV3")"

echo "== password reset flow =="
curl -s -b a.jar -c a.jar "$BASE/admin/tenants.php" -o tenants.html
T=$(csrf tenants.html)
check "admin resets tenant password" 302 "$(status -b a.jar -X POST "$BASE/admin/tenants.php" -d "csrf_token=$T" -d 'op=reset_password' -d 'id=3')"
check "must-change flag set" 1 "$(q "SELECT must_change_password FROM $TEST_DB.tenants WHERE id=3")"
R=$(login t3.jar 0700000003 0700000003)
check "temp login accepted" 302 "$(echo "$R" | cut -d' ' -f1)"
check "forced to change password" "$BASE/tenant/change_password.php" "$(curl -s -b t3.jar -o /dev/null -w '%{redirect_url}' "$BASE/tenant/dashboard.php")"
curl -s -b t3.jar -c t3.jar "$BASE/tenant/change_password.php" -o cp.html
T=$(csrf cp.html)
check "tenant changes password" 302 "$(status -b t3.jar -X POST "$BASE/tenant/change_password.php" -d "csrf_token=$T" -d 'current_password=0700000003' -d 'new_password=NewPass@123' -d 'confirm_password=NewPass@123')"
check "tenant dashboard after change" 200 "$(status -b t3.jar "$BASE/tenant/dashboard.php")"

echo "== inactive tenant =="
q "UPDATE $TEST_DB.tenants SET status='INACTIVE' WHERE id=1;"
check "active session ejected" 302 "$(status -b t1.jar "$BASE/tenant/dashboard.php")"
R=$(login inact.jar 0700000001 Tenant@123)
check "inactive tenant cannot log in" 200 "$(echo "$R" | cut -d' ' -f1)"
check "no session created" 302 "$(status -b inact.jar "$BASE/tenant/dashboard.php")"
q "UPDATE $TEST_DB.tenants SET status='ACTIVE' WHERE id=1;"
R=$(login t1b.jar 0700000001 Tenant@123)
check "reactivated tenant logs in" "302 $BASE/tenant/dashboard.php" "$R"

echo "== cron and seeder =="
check "cron rejects missing token" 403 "$(status "$BASE/cron/generate_monthly.php")"
check "seeder refuses on populated DB" 200 "$(curl -s -o seedweb.txt -w '%{http_code}' "$BASE/database/seed.php")"
contains "seeder guard message" "already contains data" seedweb.txt

echo "== logout =="
check "tenant logout" 302 "$(status -b t1b.jar "$BASE/tenant/logout.php")"
check "tenant session gone" 302 "$(status -b t1b.jar "$BASE/tenant/dashboard.php")"
check "admin logout" 302 "$(status -b a.jar "$BASE/admin/logout.php")"
check "admin session gone" 302 "$(status -b a.jar "$BASE/admin/dashboard.php")"

echo
printf 'RESULT: %d passed, %d failed\n' "$pass" "$fail"
[ "$fail" -eq 0 ]
