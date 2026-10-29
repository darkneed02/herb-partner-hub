#!/bin/sh
# Checks that activating the plugin with its default settings changes nothing a
# logged-out visitor sees. Compares page HTML with the plugin off and on.
# Run from tests/ after the setup in README.md.
set -eu

URLS="/ /?post_type=product /?product=hph-fixture-product /?page_id=$(docker compose exec -T cli wp option get woocommerce_cart_page_id | tr -d '\r')"

snapshot() {
	for u in $URLS; do
		# Strip per-request noise before hashing: nonces, Woo's random quantity_<uniqid>
		# ids, the Store API cart token (a JWT minted per guest session) and Unix timestamps.
		curl -s "http://localhost:8090$u" | sed -E 's/nonce[^"]*"[^"]*"[a-f0-9]{10}"/nonce/g; s/"[a-f0-9]{10}"/"x"/g; s/quantity_[a-f0-9]{13}/quantity_x/g; s/eyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+/jwt/g; s/1[0-9]{9}/ts/g' | md5
	done
}

docker compose exec -T cli wp plugin deactivate herb-partner-hub >/dev/null
OFF=$(snapshot)
docker compose exec -T cli wp plugin activate herb-partner-hub >/dev/null
ON=$(snapshot)

if [ "$OFF" = "$ON" ]; then
	echo "PASS  storefront identical with plugin off and on ($(echo "$URLS" | wc -w | tr -d ' ') pages)"
else
	echo "FAIL  storefront differs"
	echo "off: $OFF"
	echo "on:  $ON"
	exit 1
fi
