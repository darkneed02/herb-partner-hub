#!/bin/sh
# T4 end-to-end over HTTP: My Account › My Customers (add, validate, owner isolation) and
# checkout "Ship this order to" (saved customer, someone else's customer, new customer,
# ship to myself, nothing chosen), as two dropshippers and a normal customer.
# Needs: customers module on, COD enabled, flat-rate zones MY 30 / SG 20 / TH 5 / rest 50,
# product "HPH Fixture Product" joined with a dropshipper price (see README.md).
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

PRODUCT=$(php 'echo get_page_by_path( "hph-fixture-product", OBJECT, "product" )->ID;')
php 'HPH_Settings::update( array( "modules" => array( "customers" => 1 ), "soft_launch" => 0 ) );'
$WP option update woocommerce_cart_redirect_after_add no >/dev/null 2>&1

user() { # login dropshipper(0|1)
	php "\$u = get_user_by( 'login', '$1' ); \$id = \$u ? \$u->ID : wp_insert_user( array( 'user_login' => '$1', 'user_pass' => 'pw', 'user_email' => '$1@example.com', 'role' => 'customer' ) );
	wp_set_password( 'pw-$1-123', \$id );
	if ( $2 ) { ( new WP_User( \$id ) )->add_role( HPH_Install::ROLE ); update_user_meta( \$id, HPH_Context::STATUS_META, 'active' ); }
	global \$wpdb; \$wpdb->delete( hph_table( 'end_customers' ), array( 'owner_user_id' => \$id ) );
	foreach ( array( 'first_name' => 'Mali', 'last_name' => 'Srisuk', 'company' => 'Mali Beauty Shop', 'address_1' => '99 Sukhumvit Rd', 'city' => 'Bangkok', 'postcode' => '10110', 'country' => 'TH', 'state' => 'TH-10', 'phone' => '+66 81 000 0000', 'email' => '$1@example.com' ) as \$k => \$v ) { update_user_meta( \$id, 'billing_' . \$k, \$v ); delete_user_meta( \$id, 'shipping_' . \$k ); }
	delete_user_meta( \$id, 'stherb_address_book' ); delete_user_meta( \$id, 'stherb_address_book_default' );
	echo \$id;"
}
login() {
	rm -f "$TMP/$1"
	curl -s -c "$TMP/$1" -b 'wordpress_test_cookie=WP+Cookie+check' -o /dev/null \
		-d "log=$1&pwd=pw-$1-123&wp-submit=Log+In&testcookie=1" "$BASE/wp-login.php"
}
get()  { curl -s -L -b "$TMP/$1" -c "$TMP/$1" "$2"; }
post() { who=$1; url=$2; shift 2; curl -s -L -b "$TMP/$who" -c "$TMP/$who" "$@" "$url"; }
nonce_in() { grep -o "name=\"$2\" value=\"[^\"]*\"" "$1" | head -1 | sed 's/.*value="//;s/"$//'; }

A=$(user hph_t4_http_a 1); B=$(user hph_t4_http_b 1); C=$(user hph_t4_http_c 0)
for u in hph_t4_http_a hph_t4_http_b hph_t4_http_c; do login $u; done
ACC="$BASE/my-account/my-customers/"

echo "== My Customers page =="
PAGE=$(get hph_t4_http_a "$ACC")
has "page renders for a dropshipper" "$PAGE" 'My Customers'
has "empty state" "$PAGE" 'No customers yet.'
has "menu has My Customers" "$PAGE" 'woocommerce-MyAccount-navigation-link--my-customers'
hasnt "customer menu has no My Customers" "$(get hph_t4_http_c "$BASE/my-account/")" 'navigation-link--my-customers'
has "customer gets a polite refusal" "$(get hph_t4_http_c "$ACC")" 'This page is for approved dropshippers.'

get hph_t4_http_a "$ACC?add=1" > "$TMP/add.html"
N=$(nonce_in "$TMP/add.html" _hph_nonce)
FORM="hph_action=hph_customer_save&hph_customer_id=0&_hph_nonce=$N&hph_customer[first_name]=Customer&hph_customer[last_name]=A&hph_customer[address_1]=12+Jalan+Ampang&hph_customer[city]=Kuala+Lumpur&hph_customer[postcode]=50450&hph_customer[state]=KUL&hph_customer[country]=MY&hph_customer[note]=after+5pm"
R=$(post hph_t4_http_a "$ACC?add=1" --data "$FORM&hph_customer[phone]=")
has "missing phone shows the error" "$R" 'Phone is required'
has "typed values kept after an error" "$R" 'value="12 Jalan Ampang"'
R=$(post hph_t4_http_a "$ACC?add=1" --data "$FORM&hph_customer[phone]=%2B60+12+345+6789")
has "saved customer listed" "$R" 'Kuala Lumpur, Malaysia'
has "success notice" "$R" 'was added to My Customers'
CA=$(php "global \$wpdb; echo \$wpdb->get_var( \$wpdb->prepare( 'SELECT id FROM ' . hph_table( 'end_customers' ) . ' WHERE owner_user_id = %d', $A ) );")
eq "row stored for A" "$([ -n "$CA" ] && echo yes)" yes

echo "== owner isolation =="
R=$(get hph_t4_http_b "$ACC?edit=$CA")
has "B opening A's customer: not found" "$R" 'Customer not found.'
hasnt "B never sees A's address" "$R" 'Jalan Ampang'
get hph_t4_http_b "$ACC?add=1" > "$TMP/addb.html"
NB=$(nonce_in "$TMP/addb.html" _hph_nonce)
R=$(post hph_t4_http_b "$ACC" --data "hph_action=hph_customer_save&hph_customer_id=$CA&_hph_nonce=$NB&hph_customer[first_name]=Hacked&hph_customer[last_name]=X&hph_customer[address_1]=x&hph_customer[city]=x&hph_customer[country]=SG&hph_customer[postcode]=111111&hph_customer[phone]=123456")
R=$(post hph_t4_http_b "$ACC" --data "hph_action=hph_customer_delete&hph_customer_id=$CA&_hph_nonce=$NB")
eq "A's customer untouched by B's edit + delete" "$(php "echo HPH_Customer_Repo::get( $CA, $A )['first_name'] ?? 'gone';")" 'Customer'

echo "== checkout =="
checkout_page() { get "$1" "$BASE/?add-to-cart=$PRODUCT" >/dev/null; get "$1" "$BASE/checkout/" > "$TMP/co-$1.html"; }
place() { # who extra-form-data
	n=$(nonce_in "$TMP/co-$1.html" woocommerce-process-checkout-nonce)
	post "$1" "$BASE/?wc-ajax=checkout" --data "billing_first_name=Mali&billing_last_name=Srisuk&billing_company=Mali+Beauty+Shop&billing_country=TH&billing_address_1=99+Sukhumvit+Rd&billing_city=Bangkok&billing_state=TH-10&billing_postcode=10110&billing_phone=%2B66810000000&billing_email=$1%40example.com&payment_method=cod&woocommerce-process-checkout-nonce=$n&$2"
}
last_order() { php "\$o = wc_get_orders( array( 'customer_id' => $1, 'limit' => 1, 'orderby' => 'id', 'order' => 'DESC' ) )[0]; echo $2;"; }

# A dropshipper who already has a personal address book (an existing customer who applied).
php "update_user_meta( $A, 'stherb_address_book', array( array( 'id' => 'ab_test1', 'label' => '99 Sukhumvit Rd Bangkok', 'first_name' => 'Mali', 'last_name' => 'Srisuk', 'company' => '', 'address_1' => '99 Sukhumvit Rd', 'address_2' => '', 'city' => 'Bangkok', 'state' => 'TH-10', 'postcode' => '10110', 'country' => 'TH', 'phone' => '+66810000000' ) ) );"
checkout_page hph_t4_http_a
CO=$(cat "$TMP/co-hph_t4_http_a.html")
has "address book billing picker kept" "$CO" 'data-for="billing"'
hasnt "address book shipping picker removed for dropshippers" "$CO" 'data-for="shipping"'
has "box on the checkout page" "$CO" 'id="hph-recipient"'
has "saved customer offered" "$CO" "value=\"customer-$CA\""
has "receipt goes to the dropshipper" "$CO" 'receipt goes to hph_t4_http_a@example.com'
has "billing note for customs (shown when a customer is chosen)" "$CO" 'data-hph-billing-note'
has "own billing passed to the script for Ship to myself" "$CO" '"self":{"first_name":"Mali"'
has "first render bills the dropshipper (self preselected)" "$(echo "$CO" | grep -o 'id="billing_city"[^>]*')" 'value="Bangkok"'
has "ship to myself preselected" "$CO" 'value="self"  checked='
has "new customer fields get their own heading" "$CO" 'class="hph-ship-heading"'
has "recipient phone required on the form" "$CO" 'validate-required validate-phone" id="shipping_phone_field"'
has "address-i18n leaves the recipient phone alone" "$CO" '\"phone\":\"#billing_phone_field\"'
UR=$(nonce_in "$TMP/co-hph_t4_http_a.html" update_order_review_nonce || true)
[ -z "$UR" ] && UR=$(grep -o '"update_order_review_nonce":"[^"]*"' "$TMP/co-hph_t4_http_a.html" | head -1 | sed 's/.*:"//;s/"$//')
R=$(post hph_t4_http_a "$BASE/?wc-ajax=update_order_review" --data "security=$UR&country=TH&s_country=MY&s_state=KUL&s_postcode=50450&s_city=Kuala+Lumpur&s_address=12+Jalan+Ampang&has_full_address=true&ship_to_different_address=1&post_data=hph_ship_to%3Dcustomer-$CA")
has "shipping price follows the recipient's country (MY 30)" "$R" 'Flat Malaysia'

# Saved customer, with the browser sending nothing useful for shipping (JS failed): server fills it.
# The browser also posts the dropshipper's own billing (JS failed, or edited): the server replaces it.
R=$(place hph_t4_http_a "hph_ship_to=customer-$CA&shipping_country=TH&shipping_city=Wrong")
has "order to saved customer placed" "$R" '"result":"success"'
eq "order ships to the saved customer" "$(last_order $A '$o->get_shipping_city() . "|" . $o->get_shipping_country() . "|" . $o->get_shipping_phone()')" 'Kuala Lumpur|MY|+60 12 345 6789'
eq "billing is the saved customer (for customs)" "$(last_order $A '$o->get_billing_first_name() . " " . $o->get_billing_last_name() . "|" . $o->get_billing_address_1() . "|" . $o->get_billing_city() . "|" . $o->get_billing_country() . "|" . $o->get_billing_phone()')" 'Customer A|12 Jalan Ampang|Kuala Lumpur|MY|+60 12 345 6789'
eq "billing company not the dropshipper's shop" "$(last_order $A '$o->get_billing_company()')" ''
eq "billing email stays the dropshipper's (receipt)" "$(last_order $A '$o->get_billing_email()')" 'hph_t4_http_a@example.com'
eq "profile billing not overwritten" "$(php "echo get_user_meta( $A, 'billing_company', true ) . '|' . get_user_meta( $A, 'billing_city', true ) . '|' . get_user_meta( $A, 'billing_country', true ) . '|' . get_user_meta( $A, 'billing_phone', true );")" 'Mali Beauty Shop|Bangkok|TH|+66 81 000 0000'
CO2=$(get hph_t4_http_a "$BASE/?add-to-cart=$PRODUCT" >/dev/null; get hph_t4_http_a "$BASE/checkout/")
has "next checkout bills the dropshipper again" "$(echo "$CO2" | grep -o 'id="billing_city"[^>]*')" 'value="Bangkok"'
has "next checkout billing country is own again" "$(echo "$CO2" | tr '\n' ' ' | sed 's/.*name="billing_country"//; s/<\/select>.*//')" "value=\"TH\"  selected='selected'"
get hph_t4_http_a "$BASE/cart/?empty-cart=1" >/dev/null
eq "shipping charged for Malaysia" "$(last_order $A '$o->get_shipping_total()')" '30'
eq "order meta snapshot" "$(last_order $A '$o->get_meta( "_hph_ship_to" ) . "|" . $o->get_meta( "_hph_end_customer_id" ) . "|" . $o->get_meta( "_hph_dropshipper_id" )')" "customer|$CA|$A"
eq "profile shipping address not overwritten" "$(php "echo get_user_meta( $A, 'shipping_city', true );")" ''
eq "end customer not added to the personal address book" "$(php "echo false === strpos( (string) wp_json_encode( get_user_meta( $A, 'stherb_address_book', true ) ), 'Jalan' ) ? 'clean' : 'leaked';")" 'clean'

checkout_page hph_t4_http_b
R=$(place hph_t4_http_b "hph_ship_to=customer-$CA")
has "B cannot ship to A's customer" "$R" 'not in your list'
R=$(place hph_t4_http_b "")
has "nothing chosen: asked to choose" "$R" 'Choose who this order ships to.'

NEWC="hph_ship_to=new&hph_save_customer=1&ship_to_different_address=1&shipping_first_name=Customer&shipping_last_name=C&shipping_country=SG&shipping_address_1=1+Orchard+Rd&shipping_city=Singapore&shipping_postcode=238801"
R=$(place hph_t4_http_b "$NEWC")
has "new customer without phone refused" "$R" 'recipient phone'
R=$(place hph_t4_http_b "$NEWC&shipping_phone=%2B6581234567")
has "new customer order placed" "$R" '"result":"success"'
eq "new customer order ships to Singapore at SG rate" "$(last_order $B '$o->get_shipping_country() . "|" . $o->get_shipping_total() . "|" . $o->get_shipping_phone()')" 'SG|20|+6581234567'
eq "new customer order billed to the new customer" "$(last_order $B '$o->get_billing_first_name() . " " . $o->get_billing_last_name() . "|" . $o->get_billing_city() . "|" . $o->get_billing_country() . "|" . $o->get_billing_phone()')" 'Customer C|Singapore|SG|+6581234567'
eq "new customer order: receipt to the dropshipper" "$(last_order $B '$o->get_billing_email()')" 'hph_t4_http_b@example.com'
eq "B's profile billing unchanged" "$(php "echo get_user_meta( $B, 'billing_city', true ) . '|' . get_user_meta( $B, 'billing_country', true );")" 'Bangkok|TH'
checkout_page hph_t4_http_b
R=$(place hph_t4_http_b "$NEWC&shipping_phone=%2B6581234567")
eq "new customer saved once (retry does not duplicate)" "$(php "echo HPH_Customer_Repo::for_owner( $B )['total'];")" '1'
eq "order linked to the saved row" "$(last_order $B '(int) $o->get_meta( "_hph_end_customer_id" ) > 0 ? "yes" : "no"')" 'yes'

checkout_page hph_t4_http_a
R=$(place hph_t4_http_a "hph_ship_to=self&ship_to_different_address=1&shipping_country=MY&shipping_city=Stale")
has "ship to myself placed" "$R" '"result":"success"'
eq "ships to own billing address" "$(last_order $A '$o->get_shipping_city() . "|" . $o->get_shipping_total() . "|" . $o->get_meta( "_hph_ship_to" ) . "|" . $o->get_meta( "_hph_end_customer_id" )')" 'Bangkok|5|self|'
eq "ship to myself: billed to the dropshipper" "$(last_order $A '$o->get_billing_first_name() . "|" . $o->get_billing_city() . "|" . $o->get_billing_country()')" 'Mali|Bangkok|TH'

checkout_page hph_t4_http_c
hasnt "normal customer sees no box" "$(cat "$TMP/co-hph_t4_http_c.html")" 'hph-recipient'
R=$(place hph_t4_http_c "")
has "normal customer checkout unchanged" "$R" '"result":"success"'

has "My Customers shows the order count" "$(get hph_t4_http_a "$ACC")" '<td data-title="Orders" class="hph-r hph-num">1</td>'

rm -rf "$TMP"
echo
echo "TOTAL: $PASS passed, $FAIL failed"
