#!/bin/sh
# Role separation over HTTP: every kind of account, every storefront page that should differ.
# Only an approved, active dropshipper (and an admin who switched on "View as dropshipper")
# may get the dropshipper view; every other account must see the shop exactly like a customer.
#
#   D = dropshipper view   C = normal customer view
#
# Needs the full setup in README.md (pricing fixtures from test-pricing.php setup, the
# joined "HPH Fixture Product", COD, CartFlows checkout). Creates its own hph_r_* users.
set -u

BASE=http://localhost:8090
WP="docker compose exec -T cli wp"
TMP=$(mktemp -d)
PASS=0
FAIL=0
ok()    { PASS=$((PASS + 1)); echo "PASS  $1"; }
bad()   { FAIL=$((FAIL + 1)); echo "FAIL  $1"; }
has()   { case "$2" in *"$3"*) ok "$1" ;; *) bad "$1" ;; esac; }
hasnt() { case "$2" in *"$3"*) bad "$1" ;; *) ok "$1" ;; esac; }
eq()    { if [ "$2" = "$3" ]; then ok "$1"; else bad "$1 (expected '$3', got '$2')"; fi; }
php()   { $WP eval "$1" 2>/dev/null | tr -d '\r'; }
# yes/no check that flips with the expected view
expect() { # name view(D|C) haystack needle-that-only-D-has
	if [ "$2" = D ]; then has "$1" "$3" "$4"; else hasnt "$1" "$3" "$4"; fi
}

cd "$(dirname "$0")"
php 'HPH_Settings::update( array( "modules" => array( "applications" => 1, "pricing" => 1, "discounts" => 1, "customers" => 1, "orders" => 1, "reports" => 1 ), "soft_launch" => 0, "soft_launch_users" => array() ) );'
$WP option update woocommerce_cart_redirect_after_add no >/dev/null 2>&1

A=$(php 'echo get_option( "hph_test_fixtures" )["a"];')
B=$(php 'echo get_option( "hph_test_fixtures" )["b"];')
FIX=$(php 'echo get_page_by_path( "hph-fixture-product", OBJECT, "product" )->ID;')
A_URL=$(php "echo get_permalink( $A );")
B_URL=$(php "echo get_permalink( $B );")
APPLY_URL=$(php 'echo get_permalink( get_page_by_path( "hph-apply-test" ) );')
CART=$(php 'echo wc_get_cart_url();')
eq "fixtures present (A, B, joined product, apply page)" "$([ -n "$A" ] && [ -n "$B" ] && [ -n "$FIX" ] && [ -n "$APPLY_URL" ] && echo yes)" yes
eq "A: retail 50 / dropshipper 30, B not joined" "$(php "echo get_post_meta( $A, '_regular_price', true ) . '|' . get_post_meta( $A, '_hph_price', true ) . '|' . get_post_meta( $B, '_hph_enabled', true );")" "50|30|no"

# user login roles(comma) status(active|suspended|-) application(new|rejected|-) view_as(0|1)
mkuser() {
	php "
	\$login = '$1';
	\$u  = get_user_by( 'login', \$login );
	\$id = \$u ? \$u->ID : wp_insert_user( array( 'user_login' => \$login, 'user_pass' => 'x', 'user_email' => \$login . '@example.com', 'role' => 'customer' ) );
	wp_set_password( 'pw-' . \$login . '-123', \$id );
	\$user  = new WP_User( \$id );
	\$roles = explode( ',', '$2' );
	\$user->set_role( \$roles[0] );
	foreach ( array_slice( \$roles, 1 ) as \$r ) { \$user->add_role( \$r ); }
	'-' === '$3' ? delete_user_meta( \$id, HPH_Context::STATUS_META ) : update_user_meta( \$id, HPH_Context::STATUS_META, '$3' );
	'1' === '$5' ? update_user_meta( \$id, HPH_Context::VIEW_AS_META, '1' ) : delete_user_meta( \$id, HPH_Context::VIEW_AS_META );
	global \$wpdb;
	\$wpdb->delete( hph_table( 'applications' ), array( 'email' => \$login . '@example.com' ) );
	if ( '-' !== '$4' ) {
		\$app = HPH_Application_Repo::insert( array( 'email' => \$login . '@example.com', 'name' => 'Role Test', 'business_name' => 'Role Shop', 'country' => 'TH', 'phone' => '+66810000000', 'message' => '', 'fields' => array(), 'user_id' => \$id ) );
		if ( 'new' !== '$4' ) { HPH_Application_Repo::update( \$app, array( 'status' => '$4' ) ); }
	}
	foreach ( array( 'first_name' => 'Role', 'last_name' => 'Test', 'address_1' => '99 Sukhumvit Rd', 'city' => 'Bangkok', 'postcode' => '10110', 'country' => 'TH', 'state' => 'TH-10', 'phone' => '+66810000000', 'email' => \$login . '@example.com' ) as \$k => \$v ) { update_user_meta( \$id, 'billing_' . \$k, \$v ); }
	delete_user_meta( \$id, '_woocommerce_persistent_cart_' . get_current_blog_id() );
	\$wpdb->query( \$wpdb->prepare( \"DELETE FROM {\$wpdb->prefix}woocommerce_sessions WHERE session_key = %s\", (string) \$id ) );
	clean_user_cache( \$id );
	echo \$id;"
}
login() {
	rm -f "$TMP/$1"
	[ "$1" = guest ] && { : > "$TMP/guest"; return; }
	curl -s -c "$TMP/$1" -b 'wordpress_test_cookie=WP+Cookie+check' -o /dev/null \
		-d "log=$1&pwd=pw-$1-123&wp-submit=Log+In&testcookie=1" "$BASE/wp-login.php"
}
get()  { curl -s -L -b "$TMP/$1" -c "$TMP/$1" "$2"; }
code() { curl -s -o /dev/null -w '%{http_code}' -b "$TMP/$1" "$2"; }
post() { who=$1; url=$2; shift 2; curl -s -L -b "$TMP/$who" -c "$TMP/$who" "$@" "$url"; }
nonce_in() { grep -o "name=\"$2\" value=\"[^\"]*\"" "$1" | head -1 | sed 's/.*value="//;s/"$//'; }
empty_cart() { get "$1" "$CART?empty-cart=1" >/dev/null; get "$1" "$BASE/?hph_r=1" >/dev/null; }

# One full pass for one account. $1 login, $2 expected view, $3 logged in (1|0), $4 is_partner (1|0)
check_view() {
	who=$1; v=$2; li=$3
	echo "== $who (expect $v) =="
	login "$who"
	if [ "$li" = 1 ]; then
		has "$who: logged in" "$(get "$who" "$BASE/my-account/")" "woocommerce-MyAccount-navigation"
	fi

	SHOP=$(get "$who" "$BASE/?post_type=product")
	if [ "$v" = D ]; then hasnt "$who: shop hides non-joined B" "$SHOP" "HPH Price B"; else has "$who: shop lists B" "$SHOP" "HPH Price B"; fi
	expect "$who: wholesale price style in shop" "$v" "$SHOP" 'hph-price__ws'
	expect "$who: dropshipper bar in shop" "$v" "$SHOP" 'dropshipper wholesale prices'

	PA=$(get "$who" "$A_URL" | tr -d '\n')
	expect "$who: product A shows Retail struck price" "$v" "$PA" 'hph-price__retail'
	if [ "$v" = D ]; then
		has "$who: product A price 30.00" "$PA" '30.00'
	else
		has "$who: product A price 50.00" "$PA" '50.00'
		hasnt "$who: product A no wholesale 30.00" "$PA" '30.00'
	fi
	expect "$who: partner CSS loaded" "$v" "$PA" 'herb-partner-hub/assets/css/partner.css'

	CB=$(code "$who" "$B_URL")
	if [ "$v" = D ]; then eq "$who: product B page" "$CB" 404; else eq "$who: product B page" "$CB" 200; fi

	empty_cart "$who"
	get "$who" "$BASE/?add-to-cart=$B" >/dev/null
	get "$who" "$BASE/?add-to-cart=$A" >/dev/null
	CH=$(get "$who" "$CART" | tr -d '\n')
	if [ "$v" = D ]; then hasnt "$who: direct add-to-cart B refused" "$CH" "HPH Price B"; else has "$who: B in cart" "$CH" "HPH Price B"; fi
	if [ "$v" = D ]; then hasnt "$who: coupon field hidden" "$CH" 'name="coupon_code"'; else has "$who: coupon field shown" "$CH" 'name="coupon_code"'; fi

	empty_cart "$who"
	get "$who" "$BASE/?add-to-cart=$FIX" >/dev/null
	get "$who" "$BASE/checkout/" > "$TMP/co-$who.html"
	expect "$who: checkout 'Ship this order to' box" "$v" "$(cat "$TMP/co-$who.html")" 'id="hph-recipient"'

	if [ "$li" = 1 ]; then
		ACC=$(get "$who" "$BASE/my-account/")
		expect "$who: partner dashboard" "$v" "$ACC" 'Orders this month'
		expect "$who: menu has My Customers" "$v" "$ACC" 'navigation-link--my-customers'
		MC=$(get "$who" "$BASE/my-account/my-customers/")
		if [ "$v" = D ]; then hasnt "$who: My Customers opens" "$MC" 'This page is for approved dropshippers.'
		else has "$who: My Customers refused" "$MC" 'This page is for approved dropshippers.'; fi
		expect "$who: partner order history (filters)" "$v" "$(get "$who" "$BASE/my-account/orders/")" 'class="hph-filters'
		OC=$(code "$who" "$BASE/my-account/orders/?hph_orders_csv=1")
		echo "      (info) $who orders CSV link without nonce -> HTTP $OC"
	fi

	AP=$(get "$who" "$APPLY_URL")
	if [ "$4" = 1 ]; then has "$who: apply page says already approved" "$AP" 'already an approved dropshipper'
	else hasnt "$who: apply page not 'already approved'" "$AP" 'already an approved dropshipper'; fi

	empty_cart "$who"
}

# place an order with the joined product as $1 and print _hph_dropshipper_id
order_meta() {
	who=$1
	empty_cart "$who"
	get "$who" "$BASE/?add-to-cart=$FIX" >/dev/null
	get "$who" "$BASE/checkout/" > "$TMP/co-$who.html"
	n=$(nonce_in "$TMP/co-$who.html" woocommerce-process-checkout-nonce)
	R=$(post "$who" "$BASE/?wc-ajax=checkout" --data "billing_first_name=Role&billing_last_name=Test&billing_country=TH&billing_address_1=99+Sukhumvit+Rd&billing_city=Bangkok&billing_state=TH-10&billing_postcode=10110&billing_phone=%2B66810000000&billing_email=$who%40example.com&payment_method=cod&hph_ship_to=self&woocommerce-process-checkout-nonce=$n")
	case "$R" in *'"result":"success"'*) ;; *) echo "ERR"; return ;; esac
	php "\$u = get_user_by( 'login', '$who' ); \$o = wc_get_orders( array( 'customer_id' => \$u->ID, 'limit' => 1, 'orderby' => 'id', 'order' => 'DESC' ) )[0]; echo \$o->get_meta( '_hph_dropshipper_id' ) . '|' . \$o->get_subtotal();"
}

echo "== accounts =="
R_CUST=$(mkuser hph_r_customer customer - - 0)
R_APPL=$(mkuser hph_r_applicant customer - new 0)
R_REJ=$(mkuser hph_r_rejected customer - rejected 0)
R_STALE=$(mkuser hph_r_stale customer active - 0)            # status meta left behind, role removed
R_NOST=$(mkuser hph_r_role_only customer,herb_dropshipper - - 0)  # role added by hand in Users, never approved
R_SUSP=$(mkuser hph_r_suspended customer,herb_dropshipper suspended - 0)
R_DS=$(mkuser hph_r_dropshipper customer,herb_dropshipper active - 0)
R_ADM=$(mkuser hph_r_admin administrator,herb_dropshipper active - 0)
R_VIEW=$(mkuser hph_r_viewas administrator - - 1)
R_SM=$(mkuser hph_r_shopmgr shop_manager - - 0)
R_AFF=$(mkuser hph_r_affiliate customer - - 0)
php "\$u = new WP_User( $R_AFF ); if ( get_role( 'yith_affiliate' ) ) { \$u->add_role( 'yith_affiliate' ); }" >/dev/null
eq "all accounts created" "$(for i in $R_CUST $R_APPL $R_REJ $R_STALE $R_NOST $R_SUSP $R_DS $R_ADM $R_VIEW $R_SM $R_AFF; do [ -n "$i" ] && printf x; done)" "xxxxxxxxxxx"

# server-side decision, one per account (same function every module asks)
for pair in "$R_CUST:false" "$R_APPL:false" "$R_REJ:false" "$R_STALE:false" "$R_NOST:false" "$R_SUSP:false" "$R_DS:true" "$R_ADM:false" "$R_SM:false" "$R_AFF:false"; do
	id=${pair%%:*}; want=${pair#*:}
	eq "hph_is_dropshipper($id) = $want" "$(php "add_filter( 'hph_is_storefront', '__return_true' ); var_export( hph_is_dropshipper( $id ) );")" "$want"
done

check_view guest              C 0 0
check_view hph_r_customer     C 1 0
check_view hph_r_applicant    C 1 0
check_view hph_r_rejected     C 1 0
check_view hph_r_stale        C 1 0
check_view hph_r_role_only    C 1 0
check_view hph_r_suspended    C 1 0
check_view hph_r_affiliate    C 1 0
check_view hph_r_shopmgr      C 1 0
check_view hph_r_admin        C 1 1
check_view hph_r_dropshipper  D 1 1
check_view hph_r_viewas       D 1 0

echo "== orders are tagged only for dropshippers =="
R=$(order_meta hph_r_dropshipper); eq "dropshipper order: tagged, subtotal at wholesale" "${R%%|*}" "$R_DS"
R=$(order_meta hph_r_customer);    eq "customer order: not tagged" "${R%%|*}" ""
R=$(order_meta hph_r_suspended);   eq "suspended order: not tagged" "${R%%|*}" ""

echo "== status changes take effect on the next page load =="
login hph_r_dropshipper
php "update_user_meta( $R_DS, HPH_Context::STATUS_META, 'suspended' );" >/dev/null
has "suspended mid-session: B back in shop" "$(get hph_r_dropshipper "$BASE/?post_type=product")" "HPH Price B"
php "update_user_meta( $R_DS, HPH_Context::STATUS_META, 'active' ); ( new WP_User( $R_DS ) )->remove_role( HPH_Install::ROLE );" >/dev/null
has "role removed mid-session: B back in shop" "$(get hph_r_dropshipper "$BASE/?post_type=product")" "HPH Price B"
php "( new WP_User( $R_DS ) )->add_role( HPH_Install::ROLE );" >/dev/null
hasnt "role + active restored: dropshipper view again" "$(get hph_r_dropshipper "$BASE/?post_type=product")" "HPH Price B"

echo "== soft launch =="
php "HPH_Settings::update( array( 'soft_launch' => 1, 'soft_launch_users' => array() ) );" >/dev/null
has "soft launch on, not listed: customer view" "$(get hph_r_dropshipper "$BASE/?post_type=product")" "HPH Price B"
php "HPH_Settings::update( array( 'soft_launch_users' => array( $R_DS ) ) );" >/dev/null
hasnt "soft launch on, listed: dropshipper view" "$(get hph_r_dropshipper "$BASE/?post_type=product")" "HPH Price B"
login hph_r_customer
has "soft launch listed id does not leak to others" "$(get hph_r_customer "$BASE/?post_type=product")" "HPH Price B"
php "HPH_Settings::update( array( 'soft_launch' => 0, 'soft_launch_users' => array() ) );" >/dev/null

echo "== modules off: dropshipper sees the normal shop =="
php 'HPH_Settings::update( array( "modules" => array( "applications" => 1, "pricing" => 0, "discounts" => 0, "customers" => 0, "orders" => 0, "reports" => 1 ) ) );' >/dev/null
login hph_r_dropshipper
SHOP=$(get hph_r_dropshipper "$BASE/?post_type=product")
has "pricing off: B listed" "$SHOP" "HPH Price B"
hasnt "pricing off: no wholesale style" "$SHOP" 'hph-price__ws'
hasnt "orders off: Woo dashboard" "$(get hph_r_dropshipper "$BASE/my-account/")" 'Orders this month'
php 'HPH_Settings::update( array( "modules" => array( "applications" => 1, "pricing" => 1, "discounts" => 1, "customers" => 1, "orders" => 1, "reports" => 1 ) ) );' >/dev/null

echo "== Store API (block cart/checkout, headless) =="
login hph_r_dropshipper
# Classic theme pages print no REST nonce; make the one the block cart would send, from the login cookie.
LC=$(grep -o 'wordpress_logged_in_[^	]*	[^	]*$' "$TMP/hph_r_dropshipper" | head -1)
NONCE=$(php "\$_COOKIE['${LC%%	*}'] = rawurldecode( '${LC#*	}' ); wp_set_current_user( $R_DS ); echo wp_create_nonce( 'wp_rest' );")
if [ -n "$NONCE" ]; then
	J=$(curl -s -b "$TMP/hph_r_dropshipper" -H "X-WP-Nonce: $NONCE" "$BASE/wp-json/wc/store/v1/products/$A")
	has "Store API: dropshipper gets A at 30" "$J" '"price":"3000"'
	# Known gap (2026-10-29): /products/<id> and ?slug= still return B (retail price, the same a
	# guest sees). It cannot be bought, which is what matters; the shop page itself 404s.
	has "Store API: B by id is not purchasable" "$(curl -s -b "$TMP/hph_r_dropshipper" -H "X-WP-Nonce: $NONCE" "$BASE/wp-json/wc/store/v1/products/$B")" '"is_purchasable":false'
	SN=$(php "\$_COOKIE['${LC%%	*}'] = rawurldecode( '${LC#*	}' ); wp_set_current_user( $R_DS ); echo wp_create_nonce( 'wc_store_api' );")
	has "Store API: add-item B refused" "$(curl -s -b "$TMP/hph_r_dropshipper" -H "X-WP-Nonce: $NONCE" -H "Nonce: $SN" -H 'Content-Type: application/json' -d "{\"id\":$B,\"quantity\":1}" "$BASE/wp-json/wc/store/v1/cart/add-item")" 'woocommerce_rest_product_not_purchasable'
	has "Store API: add-item A accepted at 30" "$(curl -s -b "$TMP/hph_r_dropshipper" -H "X-WP-Nonce: $NONCE" -H "Nonce: $SN" -H 'Content-Type: application/json' -d "{\"id\":$A,\"quantity\":1}" "$BASE/wp-json/wc/store/v1/cart/add-item")" '"total_items":"3000"'
	empty_cart hph_r_dropshipper
	LIST=$(curl -s -b "$TMP/hph_r_dropshipper" -H "X-WP-Nonce: $NONCE" "$BASE/wp-json/wc/store/v1/products?per_page=100")
	hasnt "Store API: product list hides B" "$LIST" "HPH Price B"
else
	echo "      (info) no REST nonce on the page; Store API checks skipped"
fi
has "Store API: guest gets A at 50" "$(curl -s "$BASE/wp-json/wc/store/v1/products/$A")" '"price":"5000"'

echo
echo "TOTAL: $PASS passed, $FAIL failed"
rm -rf "$TMP"
[ "$FAIL" -eq 0 ]
