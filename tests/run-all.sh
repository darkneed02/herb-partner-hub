#!/bin/sh
# Runs every test file in order. Needs the setup in README.md (including the discount plugins).
set -u
W="docker compose exec -T cli wp"
cd "$(dirname "$0")"
docker compose exec -T cli sh -c 'rm -f wp-content/debug.log'
echo "-- core";        $W eval-file - < test-core.php 2>&1 | grep -E 'FAIL|TOTAL'
$W eval 'HPH_Settings::update( array( "modules" => array( "applications" => 1, "pricing" => 1, "discounts" => 1, "customers" => 1, "orders" => 1, "reports" => 1 ), "soft_launch" => 0 ) );' >/dev/null 2>&1
echo "-- applications"; $W eval-file - < test-applications.php 2>&1 | grep -E 'FAIL|TOTAL'
$W transient delete --all >/dev/null 2>&1
echo "-- http applications"; sh http-applications.sh 2>&1 | grep -E '^FAIL|^TOTAL'
for m in setup customer dropshipper admin; do echo "-- pricing $m"; $W eval-file - $m < test-pricing.php 2>&1 | grep -E 'FAIL|TOTAL'; done
echo "-- http pricing"; sh http-pricing.sh 2>&1 | grep -E '^FAIL|^TOTAL'
echo "-- customers";   $W eval-file - < test-customers.php 2>&1 | grep -E 'FAIL|TOTAL'
echo "-- http customers"; sh http-customers.sh 2>&1 | grep -E '^FAIL|^TOTAL'
echo "-- orders";      $W eval-file - < test-orders.php 2>&1 | grep -E 'FAIL|TOTAL'
echo "-- http orders"; sh http-orders.sh 2>&1 | grep -E '^FAIL|^TOTAL'
echo "-- http roles";  sh http-roles.sh 2>&1 | grep -E '^FAIL|^TOTAL'
echo "-- storefront";  sh storefront-unchanged.sh 2>&1 | grep -E 'PASS|FAIL'
echo "-- debug.log (our plugin / fatals / warnings)"; docker compose exec -T cli sh -c 'grep -E "herb-partner-hub|Fatal|PHP Warning" wp-content/debug.log 2>/dev/null | head -10'; echo "[end]"
