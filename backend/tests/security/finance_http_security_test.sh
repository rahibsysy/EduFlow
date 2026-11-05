#!/usr/bin/env bash
set -euo pipefail

# Requires a freshly bootstrapped miranda_finance_data_test_* database populated
# with the isolated HTTP fixtures. It never accepts a non-dedicated DB name.
: "${MIRANDA_FINANCE_TEST:?}"
: "${DB_NAME:?}"
[[ "$MIRANDA_FINANCE_TEST" == 1 && "$DB_NAME" =~ ^miranda_finance_data_test_ ]] || { echo 'Refusing unsafe database.' >&2; exit 2; }

root="$(cd "$(dirname "$0")/../.." && pwd)"
port="${FINANCE_HTTP_TEST_PORT:-18088}"
base="http://127.0.0.1:${port}"
export JWT_SECRET="${JWT_SECRET:-$(php -r 'echo base64_encode(random_bytes(48));')}"
log="${TMPDIR:-/tmp}/miranda-finance-http-${port}.log"
php -d variables_order=EGPCS -S "127.0.0.1:${port}" -t "$root/public" >"$log" 2>&1 &
server_pid=$!
trap 'kill "$server_pid" 2>/dev/null || true; wait "$server_pid" 2>/dev/null || true' EXIT

for _ in {1..30}; do curl -fsS "$base/api/setup/status" >/dev/null 2>&1 && break; sleep 0.1; done
curl -fsS "$base/api/setup/status" >/dev/null
token() { curl -fsS -H 'Content-Type: application/json' -d "{\"email\":\"$1\",\"password\":\"isolated-http-password\"}" "$base/api/login" | php -r '$d=json_decode(stream_get_contents(STDIN),true); if (!isset($d["token"])) exit(1); echo $d["token"];'; }
status() { curl -sS -o "${TMPDIR:-/tmp}/miranda-http-body" -w '%{http_code}' "$@"; }
expect() { [[ "$1" == "$2" ]] || { echo "Expected HTTP $2, got $1" >&2; exit 1; }; }

expect "$(status "$base/api/payments")" 401
none="$(token http-none@test.local)"; view="$(token http-view@test.local)"; manage="$(token http-manage@test.local)"; admin="$(token http-admin@test.local)"; other="$(token http-other@test.local)"; super="$(token http-super@test.local)"
expect "$(status -H "Authorization: Bearer $none" "$base/api/payments")" 403
expect "$(status -H "Authorization: Bearer $view" "$base/api/payments")" 200
expect "$(status -X POST -H "Authorization: Bearer $view" -H 'Content-Type: application/json' -d '{}' "$base/api/payments")" 403
expect "$(status -X POST -H "Authorization: Bearer $manage" -H 'Content-Type: application/json' -d '{}' "$base/api/payments")" 422
expect "$(status -H "Authorization: Bearer $admin" "$base/api/payments")" 200
expect "$(status -H "Authorization: Bearer $super" "$base/api/payments")" 200
expect "$(status -H "Authorization: Bearer $other" "$base/api/students/1")" 404
expect "$(status -H "Authorization: Bearer $none" "$base/api/students/1")" 200
php -r '$d=json_decode(file_get_contents(getenv("TMPDIR") . "/miranda-http-body"),true); foreach (["finance","financial_items","financial_payments"] as $k) if (array_key_exists($k,$d) && $d[$k] !== null && $d[$k] !== []) exit(1); foreach (["monthly_amount","discount_percent"] as $k) if (isset($d["student"][$k])) exit(1);'
expect "$(status -H "Authorization: Bearer $none" "$base/api/families/1")" 200
php -r '$d=json_decode(file_get_contents(getenv("TMPDIR") . "/miranda-http-body"),true); if (isset($d["financial_projection"])) exit(1); foreach (($d["members"] ?? []) as $m) foreach (["monthly_amount","amount_paid","remaining_amount"] as $k) if (isset($m[$k])) exit(1);'
expect "$(status -H "Authorization: Bearer $view" "$base/api/families/1")" 200
expect "$(status -H "Authorization: Bearer $other" "$base/api/families/1")" 404
expect "$(status -H "Authorization: Bearer $none" "$base/api/receipts/1")" 403
expect "$(status -H "Authorization: Bearer $none" "$base/api/receipts/1/pdf")" 403
expect "$(status -X POST -H "Authorization: Bearer $none" -H 'Content-Type: application/json' -d '{"reason":"test"}' "$base/api/payments/1/cancel")" 403
expect "$(status -H "Authorization: Bearer $none" "$base/api/payment-documents/unpaid")" 403
expect "$(status -H "Authorization: Bearer $none" "$base/api/financial/unpaid")" 403
expect "$(status -H "Authorization: Bearer $view" "$base/api/financial/unpaid")" 200
echo 'finance HTTP security: OK'
