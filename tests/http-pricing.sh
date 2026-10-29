#!/bin/sh
# T3 end-to-end over HTTP, as a logged-in dropshipper and a logged-in customer:
# shop listing, product pages, direct add-to-cart, cart total; then the admin
# Products screen (save, CSV export, CSV import).
# Run after: wp eval-file test-pricing.php setup
set -eu

BASE=http://localhost:8090
WP="docker compose exec -T cli wp"
TMP=$(mktemp -d)
PASS=0
FAIL=0
ok()  { PASS=$((PASS + 1)); echo "PASS  $1"; }
bad() { FAIL=$((FAIL + 1)); echo "FAIL  $1"; }
has()    { case "$2" in *"$3"*) ok "$1" ;; *) bad "$1" ;; esac; }
hasnt()  { case "$2" in *"$3"*) bad "$1" ;; *) ok "$1" ;; esac; }
fx() { $WP eval "echo get_option( 'hph_test_fixtures' )$1;" | tr -d '\r'; }

A=$(fx "['a']"); B=$(fx "['b']"); V=$(fx "['v']")
CART=$($WP option get woocommerce_cart_page_id | tr -d '\r')
# Classic cart so the total is in the HTML (the block cart renders in the browser).
$WP post update "$CART" --post_content='[woocommerce_cart]' >/dev/null
$WP option update woocommerce_cart_redirect_after_add no >/dev/null
$WP eval 'HPH_Settings::update( array( "soft_launch" => 0 ) );' >/dev/null

login() {
	$WP user update "hph_t3_$1" --user_pass="pw-$1-123" >/dev/null
	curl -s -c "$TMP/$1" -b 'wordpress_test_cookie=WP+Cookie+check' -o /dev/null \
		-d "log=hph_t3_$1&pwd=pw-$1-123&wp-submit=Log+In&testcookie=1" "$BASE/wp-login.php"
}
get() { curl -s -L -b "$TMP/$1" -c "$TMP/$1" "$2"; }
code() { curl -s -o /dev/null -w '%{http_code}' -b "$TMP/$1" "$2"; }
cart_total() { get "$1" "$BASE/?page_id=$CART" | tr -d '\n' | grep -o 'cart-subtotal.*' | grep -o '[0-9][0-9,]*\.[0-9][0-9]' | head -1; }

A_URL=$($WP eval "echo get_permalink( $A );" | tr -d '\r'); B_URL=$($WP eval "echo get_permalink( $B );" | tr -d '\r')

for who in dropshipper customer; do
	echo "== $who =="
	login "$who"
	SHOP=$(get "$who" "$BASE/?post_type=product")
	if [ "$who" = dropshipper ]; then
		has   "shop lists open product A" "$SHOP" "HPH Price A"
		has   "shop lists variable product V" "$SHOP" "HPH Price V"
		hasnt "shop hides B (not open)" "$SHOP" "HPH Price B"
		hasnt "shop hides C (no price)" "$SHOP" "HPH Price C"
		has   "shop shows wholesale price style" "$SHOP" "hph-price__ws"
		has   "shop shows dropshipper bar" "$SHOP" "dropshipper wholesale prices"
		[ "$(code "$who" "$B_URL")" = 404 ] && ok "B product page is 404" || bad "B product page is 404 (got $(code "$who" "$B_URL"))"
		has   "A page shows Retail price" "$(get "$who" "$A_URL")" "hph-price__retail"
	else
		has   "shop lists B" "$SHOP" "HPH Price B"
		hasnt "no wholesale price style" "$SHOP" "hph-price__ws"
		hasnt "no dropshipper bar" "$SHOP" "dropshipper wholesale prices"
		[ "$(code "$who" "$B_URL")" = 200 ] && ok "B product page is 200" || bad "B product page is 200"
	fi
	get "$who" "$BASE/?page_id=$CART&empty-cart=1" >/dev/null
	$WP eval "\$u = get_user_by( 'login', 'hph_t3_$who' ); delete_user_meta( \$u->ID, '_woocommerce_persistent_cart_' . get_current_blog_id() ); global \$wpdb; \$wpdb->query( \$wpdb->prepare( \"DELETE FROM {\$wpdb->prefix}woocommerce_sessions WHERE session_key = %s\", (string) \$u->ID ) );" >/dev/null
	get "$who" "$BASE/?add-to-cart=$A&quantity=3" >/dev/null
	get "$who" "$BASE/?add-to-cart=$B" >/dev/null
	CART_HTML=$(get "$who" "$BASE/?page_id=$CART")
	TOTAL=$(cart_total "$who")
	if [ "$who" = dropshipper ]; then
		hasnt "direct ?add-to-cart=B did not add B" "$CART_HTML" "HPH Price B"
		[ "$TOTAL" = "90.00" ] && ok "cart subtotal 3 x 30 = 90.00" || bad "cart subtotal 3 x 30 = 90.00 (got $TOTAL)"
		hasnt "no coupon field" "$CART_HTML" 'name="coupon_code"'
	else
		has "B added for customer" "$CART_HTML" "HPH Price B"
		echo "      (info) customer cart total $TOTAL (retail 3 x 50 + 40 = 190 before discounts)"
	fi
done

echo "== admin Products screen =="
J="$TMP/admin"
curl -s -c "$J" -b 'wordpress_test_cookie=WP+Cookie+check' -o /dev/null -d 'log=admin&pwd=admin&wp-submit=Log+In&testcookie=1' "$BASE/wp-login.php"
PAGE=$(get admin "$BASE/wp-admin/admin.php?page=hph-products")
has "Products screen lists A" "$PAGE" "HPH Price A"
has "Products screen shows variation row" "$PAGE" "set per variation"
has "Products screen shows status tags" "$PAGE" "Joined"
has "Joined is an on/off switch" "$PAGE" 'class="hph-switch"'
has "switch posts the same enabled[] field" "$PAGE" 'role="switch" name="enabled['
# Each form's nonce sits right after its hidden action field.
form_nonce() { echo "$PAGE" | tr '\n' ' ' | grep -o "value=\"$1\">[^>]*name=\"_wpnonce\" value=\"[^\"]*\"" | grep -o '_wpnonce" value="[^"]*"' | cut -d'"' -f3; }
NONCE=$(form_nonce hph_products_save)
LOC=$(curl -s -b "$J" -o /dev/null -w '%{redirect_url}' "$BASE/wp-admin/admin-post.php" \
	--data-urlencode action=hph_products_save --data-urlencode "_wpnonce=$NONCE" --data-urlencode do=save \
	--data-urlencode "rows[]=$B" --data-urlencode "price[$B]=22.5" --data-urlencode "enabled[$B]=1")
has "save redirects back" "$LOC" "page=hph-products"
[ "$($WP post meta get "$B" _hph_price | tr -d '\r')" = "22.5" ] && ok "inline save set B price 22.5" || bad "inline save set B price"
[ "$($WP post meta get "$B" _hph_enabled | tr -d '\r')" = "yes" ] && ok "inline save opened B" || bad "inline save opened B"

EXPORT_URL=$(echo "$PAGE" | grep -o 'admin-post.php?action=hph_products_export[^"]*' | head -1 | sed 's/&#038;/\&/g; s/&amp;/\&/g')
EXPORT=$(curl -s -b "$J" "$BASE/wp-admin/$EXPORT_URL")
has "export has header" "$EXPORT" "id,parent_id,type,sku,name,retail_price,dropshipper_price,open"
has "export has B at 22.5 open" "$EXPORT" "22.5,yes"

printf 'id,dropshipper_price,open\n%s,,no\n999999,5,yes\n' "$B" > "$TMP/import.csv"
INONCE=$(form_nonce hph_products_import)
curl -s -b "$J" -o /dev/null "$BASE/wp-admin/admin-post.php" -F action=hph_products_import -F "_wpnonce=$INONCE" -F "csv=@$TMP/import.csv;type=text/csv"
[ -z "$($WP post meta get "$B" _hph_price 2>/dev/null | tr -d '\r')" ] && ok "import cleared B price" || bad "import cleared B price"
[ "$($WP post meta get "$B" _hph_enabled | tr -d '\r')" = "no" ] && ok "import closed B" || bad "import closed B"
has "import reports unknown id" "$(get admin "$BASE/wp-admin/admin.php?page=hph-products")" "Line 3: product not found."

has "product edit screen has the fields" "$(get admin "$BASE/wp-admin/post.php?post=$A&action=edit")" 'name="_hph_price"'

echo
echo "TOTAL: $PASS passed, $FAIL failed"
rm -rf "$TMP"
[ "$FAIL" -eq 0 ]
