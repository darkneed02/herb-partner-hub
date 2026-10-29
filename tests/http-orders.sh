#!/bin/sh
# T5 end-to-end over HTTP: partner dashboard, order history (filters, ship to, tracking,
# CSV), Reorder into checkout with the same recipient, and the admin Overview.
# Run test-orders.php first: it makes the users and orders this script reads.
# Needs: customers, orders and reports modules on, COD, the T4 zones and fixture product.
set -eu

BASE=http://localhost:8090
WP="docker compose exec -T cli wp"
TMP=$(mktemp -d)
PASS=0
FAIL=0
ok()  { PASS=$((PASS + 1)); echo "PASS  $1"; }
bad() { FAIL=$((FAIL + 1)); echo "FAIL  $1"; }
has()   { case "$2" in *"$3"*) ok "$1" ;; *) bad "$1" ;; esac; }
hasnt() { case "$2" in *"$3"*) bad "$1" ;; *) ok "$1" ;; esac; }
eq()    { if [ "$2" = "$3" ]; then ok "$1"; else bad "$1 (expected '$3', got '$2')"; fi; }
php()   { $WP eval "$1" 2>/dev/null | tr -d '\r'; }

login() { # login password
	rm -f "$TMP/$1"
	curl -s -c "$TMP/$1" -b 'wordpress_test_cookie=WP+Cookie+check' -o /dev/null \
		-d "log=$1&pwd=$2&wp-submit=Log+In&testcookie=1" "$BASE/wp-login.php"
}
get()  { curl -s -L -b "$TMP/$1" -c "$TMP/$1" "$2"; }
post() { who=$1; url=$2; shift 2; curl -s -L -b "$TMP/$who" -c "$TMP/$who" "$@" "$url"; }
fx()   { php "echo get_option( 'hph_t5_fixture' )['$1'];"; }
uid()  { php "echo get_user_by( 'login', '$1' )->ID;"; }
cart_hash() { get "$1" "$BASE/?wc-ajax=get_refreshed_fragments" | grep -o '"cart_hash":"[^"]*"' | sed 's/.*:"//;s/"$//'; }
reorder_link() { # html-file order-id → href with nonce, &amp; decoded
	grep -o "href=\"[^\"]*order_again=$2[^\"]*\"" "$1" | head -1 | sed 's/^href="//;s/"$//;s/&#038;/\&/g;s/&amp;/\&/g'
}

php 'HPH_Settings::update( array( "modules" => array( "customers" => 1, "orders" => 1, "reports" => 1 ), "soft_launch" => 0 ) );'
P=$(uid hph_t5_p); Q=$(uid hph_t5_q)
CID=$(fx cid)
for u in hph_t5_p hph_t5_q hph_t5_c; do login $u "pw-$u-123"; done
login admin admin

echo "== dashboard =="
D=$(get hph_t5_p "$BASE/my-account/")
has "dropshipper sees the partner dashboard" "$D" 'hph-dash'
has "greets the shop by name" "$D" 'Hello, Hph_t5_p Shop'
TOTAL=$(php "echo esc_html( wp_strip_all_tags( wc_price( HPH_Sales::month( $P, HPH_Sales::current_month() )['total'] ) ) );")
ORDERS=$(php "echo HPH_Sales::month( $P, HPH_Sales::current_month() )['orders'];")
has "tile: order total = HPH_Sales ($TOTAL)" "$D" "hph-tile__v\">$TOTAL<"
has "tile: orders this month = HPH_Sales ($ORDERS)" "$D" "hph-tile__v\">$ORDERS<"
has "tile: my customers" "$D" 'hph-tile__v">2<'
has "6-month chart" "$D" 'hph-bars__col is-now'
has "recent orders listed (newest first)" "$D" "#$(fx retail)<"
hasnt "no commission anywhere" "$D" 'ommission'
C=$(get hph_t5_c "$BASE/my-account/")
hasnt "normal customer keeps WooCommerce's dashboard" "$C" 'hph-dash'

echo "== order history =="
get hph_t5_p "$BASE/my-account/orders/" > "$TMP/orders.html"
H=$(cat "$TMP/orders.html")
has "history replaces the Orders page" "$H" 'hph-orders'
has "ship to: saved customer" "$H" 'Customer B'
has "ship to: myself" "$H" 'Myself'
has "tracking from the DHL note (latest)" "$H" 'DHL 5550001111'
has "refunded part struck through" "$H" '<s class="hph-muted">'
has "reorder link on a processing order" "$H" "order_again=$(fx proc)"
hasnt "no reorder on a cancelled order" "$H" "order_again=$(fx cancelled)"
hasnt "Q's orders never listed" "$H" "#$(fx q)<"
has "order links to WooCommerce's order page" "$H" "/my-account/view-order/$(fx proc)/"
R=$(get hph_t5_p "$BASE/my-account/orders/?customer=self")
hasnt "filter myself: no customer orders" "$R" "#$(fx proc)<"
R=$(get hph_t5_p "$BASE/my-account/orders/?customer=$CID")
has "filter by customer" "$R" "#$(fx proc)<"
hasnt "filter by customer: others out" "$R" "#$(fx done)<"
R=$(get hph_t5_p "$BASE/my-account/orders/?customer=$(fx qc)")
has "Q's customer id via the filter: nothing" "$R" 'No orders match these filters.'
R=$(get hph_t5_p "$BASE/my-account/orders/?status=cancelled")
has "status filter" "$R" "#$(fx cancelled)<"
hasnt "status filter: others out" "$R" "#$(fx proc)<"
has "view-order page still WooCommerce's" "$(get hph_t5_p "$BASE/my-account/view-order/$(fx partial)/")" '5550001111'
C=$(get hph_t5_c "$BASE/my-account/orders/")
hasnt "normal customer keeps WooCommerce's order list" "$C" 'hph-orders'

PEND=$(php "\$o = wc_create_order( array( 'customer_id' => $P ) ); \$o->add_product( wc_get_product( get_page_by_path( 'hph-fixture-product', OBJECT, 'product' )->ID ), 1 ); \$o->update_meta_data( '_hph_dropshipper_id', $P ); \$o->update_meta_data( '_hph_ship_to', 'self' ); \$o->calculate_totals(); \$o->set_status( 'failed' ); \$o->save(); echo \$o->get_id();")
R=$(get hph_t5_p "$BASE/my-account/orders/")
has "failed order keeps WooCommerce's Pay button" "$(echo "$R" | grep -o "href=\"[^\"]*order-pay/$PEND/[^\"]*\"" | head -1)" 'pay_for_order=true'
has "failed order keeps WooCommerce's Cancel button" "$R" "cancel_order=true&#038;order=wc_order_"
php "wc_get_order( $PEND )->delete( true );"

echo "== CSV =="
EXP=$(grep -o 'href="[^"]*hph_orders_csv=1[^"]*"' "$TMP/orders.html" | head -1 | sed 's/^href="//;s/"$//;s/&#038;/\&/g;s/&amp;/\&/g')
curl -s -D "$TMP/h" -b "$TMP/hph_t5_p" "$EXP" -o "$TMP/o.csv"
has "partner CSV is a CSV" "$(cat "$TMP/h")" 'text/csv'
has "partner CSV lists orders" "$(cat "$TMP/o.csv")" "$(fx proc),"
has "formula in a customer name neutralised" "$(cat "$TMP/o.csv")" "\"'=HYPERLINK"
hasnt "Q's orders not in P's CSV" "$(cat "$TMP/o.csv")" "$(fx q),"
R=$(get hph_t5_p "$BASE/my-account/orders/?hph_orders_csv=1&_wpnonce=bad")
has "bad nonce: no file, a notice" "$R" 'export link expired'

echo "== reorder =="
LINK=$(reorder_link "$TMP/orders.html" "$(fx proc)")
get hph_t5_p "$LINK" > /dev/null
CO=$(get hph_t5_p "$BASE/checkout/")
has "checkout opens with the same customer chosen" "$CO" "value=\"customer-$CID\"  checked='checked'"
has "marked prefilled (no refresh on load)" "$CO" 'data-hph-prefilled'
has "ship-to-different rendered checked" "$(echo "$CO" | grep -o '<input id="ship-to-different-address-checkbox"[^>]*>')" 'checked'
has "shipping country from the session is the customer's" "$(echo "$CO" | tr '\n' ' ' | sed 's/.*name="shipping_country"//; s/<\/select>.*//')" "value=\"SG\"  selected='selected'"
has "first render already prices Singapore" "$CO" 'Flat Singapore'
has "reorder: billing rendered as the customer" "$(echo "$CO" | tr '\n' ' ' | sed 's/.*name="billing_country"//; s/<\/select>.*//')" "value=\"SG\"  selected='selected'"
eq "profile shipping not touched by reorder" "$(php "echo get_user_meta( $P, 'shipping_country', true );")" ''
N=$(echo "$CO" | grep -o 'name="woocommerce-process-checkout-nonce" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"$//')
R=$(post hph_t5_p "$BASE/?wc-ajax=checkout" --data "billing_first_name=Mali&billing_last_name=Srisuk&billing_company=Hph_t5_p+Shop&billing_country=TH&billing_address_1=99+Sukhumvit+Rd&billing_city=Bangkok&billing_state=TH-10&billing_postcode=10110&billing_phone=%2B66810000000&billing_email=hph_t5_p%40example.com&payment_method=cod&woocommerce-process-checkout-nonce=$N&hph_ship_to=customer-$CID")
has "reorder placed" "$R" '"result":"success"'
eq "reorder ships to the same customer at the SG rate" "$(php "\$o = wc_get_orders( array( 'customer_id' => $P, 'limit' => 1, 'orderby' => 'id', 'order' => 'DESC' ) )[0]; echo \$o->get_shipping_country() . '|' . \$o->get_shipping_total() . '|' . \$o->get_meta( '_hph_end_customer_id' );")" "SG|20|$CID"
eq "profile shipping still untouched" "$(php "echo get_user_meta( $P, 'shipping_country', true );")" ''
get hph_t5_p "$BASE/?add-to-cart=$(php 'echo get_page_by_path( "hph-fixture-product", OBJECT, "product" )->ID;')" > /dev/null
CO=$(get hph_t5_p "$BASE/checkout/")
hasnt "after the order the next checkout is normal again" "$CO" 'data-hph-prefilled'
has "next checkout defaults to myself" "$CO" "value=\"self\"  checked='checked'"

echo "== reorder: customer deleted since =="
php "HPH_Customer_Repo::erase( (int) wc_get_order( $(fx partial) )->get_meta( '_hph_end_customer_id' ), $P );"
get hph_t5_p "$BASE/my-account/orders/" > "$TMP/orders2.html"
R=$(get hph_t5_p "$(reorder_link "$TMP/orders2.html" "$(fx partial)")")
CO=$(get hph_t5_p "$BASE/checkout/")
case "$CO" in *"name=\"hph_ship_to\""*"checked='checked'"*"hph-ro__dot"*) bad "no recipient radio checked" ;; *) ok "no recipient radio checked" ;; esac
has "tells the dropshipper to choose again" "$R$CO" 'no longer in My Customers'

echo "== reorder: product no longer joined =="
UNJ=$(php "\$p = get_page_by_path( 'hph-t5-unjoined', OBJECT, 'product' ); if ( ! \$p ) { \$x = new WC_Product_Simple(); \$x->set_name( 'HPH T5 unjoined' ); \$x->set_slug( 'hph-t5-unjoined' ); \$x->set_regular_price( '10' ); \$x->set_status( 'publish' ); echo \$x->save(); } else { echo \$p->ID; }")
OID=$(php "\$o = wc_create_order( array( 'customer_id' => $P ) ); \$o->add_product( wc_get_product( get_page_by_path( 'hph-fixture-product', OBJECT, 'product' )->ID ), 1 ); \$o->add_product( wc_get_product( $UNJ ), 1 ); \$o->update_meta_data( '_hph_dropshipper_id', $P ); \$o->update_meta_data( '_hph_ship_to', 'self' ); \$o->calculate_totals(); \$o->set_status( 'processing' ); \$o->save(); echo \$o->get_id();")
get hph_t5_p "$BASE/my-account/orders/" > "$TMP/orders3.html"
R=$(get hph_t5_p "$(reorder_link "$TMP/orders3.html" "$OID")")
CO=$(get hph_t5_p "$BASE/checkout/")
has "unavailable item reported" "$R$CO" 'could not be added to your cart'
hasnt "unjoined product not in the checkout" "$CO" 'HPH T5 unjoined'

echo "== reorder: someone else's order =="
get hph_t5_q "$BASE/my-account/orders/" > "$TMP/q.html"
QLINK=$(reorder_link "$TMP/q.html" "$(fx q)")
[ -n "$QLINK" ] || { bad "Q has a reorder link of their own"; QLINK="$BASE/cart/?order_again=$(fx q)&_wpnonce=x"; }
# Ids first: docker exec inside the pipe would swallow its input.
QID=$(fx q); PID=$(fx proc)
get hph_t5_q "$(echo "$QLINK" | sed "s/order_again=$QID/order_again=$PID/")" > /dev/null
eq "Q cannot reorder P's order (cart stays empty)" "$(cart_hash hph_t5_q)" ''

echo "== admin overview =="
A=$(get admin "$BASE/wp-admin/admin.php?page=herb-partner-hub")
has "overview title" "$A" 'Dropshipper overview'
has "sales card" "$A" 'Dropshipper sales'
has "ranking lists P" "$A" 'Hph_t5_p Shop'
has "system status kept" "$A" 'System status'
# Ranking pager: rows per page and page links (fixtures give well over 10 dropshippers).
TOTAL=$(docker compose exec -T cli wp eval 'echo count(HPH_Reports::ranking(HPH_Sales::current_month()));' 2>/dev/null | tr -d '\r')
rows() { echo $(( $(echo "$1" | sed -n '/class="widefat striped hph-rank"/,/<\/table>/p' | grep -c '<td class="num">') / 3 )); }
has "pager shows per-page choice" "$A" 'id="hph-per-page"'
has "default 20 per page" "$A" "1–$(( TOTAL < 20 ? TOTAL : 20 )) of $TOTAL"
A10=$(get admin "$BASE/wp-admin/admin.php?page=herb-partner-hub&per_page=10")
eq "per_page=10 shows 10 rows" "$(rows "$A10")" '10'
has "per_page=10 links to page 2 keeping the choice" "$A10" 'per_page=10&#038;paged=2'
A2=$(get admin "$BASE/wp-admin/admin.php?page=herb-partner-hub&per_page=10&paged=2")
has "page 2 shows rows 11-20" "$A2" "11–20 of $TOTAL"
AX=$(get admin "$BASE/wp-admin/admin.php?page=herb-partner-hub&per_page=7&paged=999")
LAST=$(( (TOTAL + 19) / 20 ))
has "bad per_page falls back to 20, page clamps to the last" "$AX" "$LAST of $LAST"
has "month links keep per_page" "$(echo "$A10" | grep -o 'month=[0-9-]*&#038;per_page=10' | head -1)" '&#038;per_page=10'
CSV=$(echo "$A" | grep -o 'href="[^"]*action=hph_overview_csv[^"]*"' | head -1 | sed 's/^href="//;s/"$//;s/&#038;/\&/g;s/&amp;/\&/g')
curl -s -D "$TMP/ah" -b "$TMP/admin" "$CSV" -o "$TMP/a.csv"
has "admin CSV is a CSV" "$(cat "$TMP/ah")" 'text/csv'
has "admin CSV has P's row" "$(cat "$TMP/a.csv")" 'Hph_t5_p Shop'
CODE=$(curl -s -o /dev/null -w '%{http_code}' -b "$TMP/hph_t5_p" "$BASE/wp-admin/admin-post.php?action=hph_overview_csv")
eq "dropshipper cannot download the admin CSV" "$CODE" '403'

rm -rf "$TMP"
echo
echo "TOTAL: $PASS passed, $FAIL failed"
