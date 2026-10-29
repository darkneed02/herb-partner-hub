# Tests

Functional tests run inside a throwaway WordPress + WooCommerce in Docker (port 8090).
The test files create users and products and overwrite plugin settings, so never run them against a real shop.

```sh
cd tests
docker compose up -d
docker compose exec -T cli wp core install --url=http://localhost:8090 --title=Test --admin_user=admin --admin_password=admin --admin_email=a@example.com --skip-email
docker compose exec -T cli wp core update && docker compose exec -T cli wp core update-db
docker compose exec -T cli wp plugin install woocommerce --version=11.2.1 --activate
docker compose exec -T cli wp option update woocommerce_coming_soon no
docker compose exec -T cli wp wc product create --name="HPH Fixture Product" --slug=hph-fixture-product --regular_price=50 --user=admin
docker compose exec -T cli wp plugin activate herb-partner-hub

# The discount sources dropshippers must never get (same versions as stherb.com, 2026-10-28)
docker compose exec -T cli wp plugin install woo-discount-rules --version=2.6.18 --activate
docker compose exec -T cli wp plugin install tier-pricing-table --version=8.1.2 --activate
docker compose exec -T cli wp plugin install power-coupons --version=1.0.8 --activate
docker cp ~/wp-plugins/stherb_promo_engine/stherb-promo-engine "$(docker compose ps -q wp)":/var/www/html/wp-content/plugins/
docker compose exec -T -u root wp chown -R www-data:www-data /var/www/html/wp-content/plugins/stherb-promo-engine
docker compose exec -T cli wp plugin activate stherb-promo-engine

# T4: checkout like stherb.com — CartFlows modern layout, the site's address book, a
# shipping price per country, cash on delivery, pretty permalinks, orders in the posts table
docker compose exec -T cli wp plugin install cartflows --version=3.3.0 --activate
docker compose exec -T cli wp rewrite structure '/%postname%/'
docker compose exec -T cli wp wc payment_gateway update cod --enabled=true --user=admin
docker compose exec -T cli wp wc hpos sync && docker compose exec -T cli wp wc hpos disable
# stherb-address-book.php: read-only copy of novamira-sandbox/stherb-address-book.php
# (md5 413943faef5d93c6e25f908e770f0b0b on 2026-10-28), loaded as a mu-plugin here
docker cp stherb-address-book.php "$(docker compose ps -q wp)":/var/www/html/wp-content/mu-plugins/
# zones MY 30 / SG 20 / TH 5 / everywhere else 50, the store checkout flow (step slug
# checkout-2, modern-checkout) and the joined fixture product: see t4-fixtures.php
docker compose exec -T cli wp eval-file - < t4-fixtures.php

sh run-all.sh
```

`run-all.sh` runs, in order:

| File | What |
|---|---|
| `test-core.php` | role, tables, settings, `hph_is_dropshipper()`, log |
| `test-applications.php` | form validation, approval, emails, set-password window |
| `http-applications.sh` | the same flow over real HTTP |
| `test-pricing.php setup / customer / dropshipper / admin` | prices, hidden products, and all four discount sources (each viewer in its own process, because Tier Pricing and Woo Discount Rules cache rules per request) |
| `http-pricing.sh` | shop, product pages, direct add-to-cart and cart total as a logged-in dropshipper and customer; admin Products screen save / export / import |
| `test-customers.php` | T4: end customer store, owner isolation, validation, delete on request, order counts, My Account menu |
| `http-customers.sh` | T4 over HTTP: My Customers page, and checkout "Ship this order to" (saved / someone else's / new / myself / none), shipping price per recipient country, profile and address book left alone |
| `test-orders.php` | T5: one sales definition (refunds, cancelled, orders before approval, month edges in the site time zone), ship-to from the order, tracking from DHL notes, CSV formula guard, history filters scoped to the owner, reorder statuses, admin ranking. Sets the time zone to Asia/Bangkok |
| `http-orders.sh` | T5 over HTTP (uses the orders `test-orders.php` made): partner dashboard tiles equal `HPH_Sales`, order history + filters + CSV, Reorder lands on checkout with the same recipient and that country's price (deleted customer / unjoined product / someone else's order), admin Overview + CSV |
| `http-roles.sh` | role separation: 12 kinds of account (guest, customer, applicant, rejected, leftover status, role without approval, suspended, old affiliate, shop manager, admin, dropshipper, admin "View as") each walk shop, product pages, add-to-cart, cart, checkout, My Account, My Customers, Orders and the apply page; only the dropshipper and View as get the dropshipper view. Also orders tagged only for dropshippers, suspend / role removal on the next page load, soft launch, modules off, Store API |
| `storefront-unchanged.sh` | logged-out pages identical with the plugin off and on |

T4 and T5 tests also pass with HPOS on (checked 2026-10-28); stherb.com stores orders in the posts table, so that is the default here.

Every test file ends with `TOTAL: N passed, 0 failed`. `storefront-unchanged.sh` should print `PASS`.
`test-core.php` resets the settings at the end; `run-all.sh` switches the modules back on after it.
`http-applications.sh` submits real requests; the form allows 5 per IP per hour, so wait or clear the `hph_apply_rl_*` transients before running it again.

Lint every file without Docker: `find ../herb-partner-hub -name '*.php' -exec php -l {} \;`

`docker compose down -v` removes everything.
