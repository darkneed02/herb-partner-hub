#!/bin/sh
# T2 end-to-end over HTTP: public form (valid / bot / invalid), admin approval,
# and the set-password link (made 3 days ago, so it only works with the 7-day window).
# Run from tests/ after the setup in README.md, with the applications module on.
set -eu

BASE=http://localhost:8090
WP="docker compose exec -T cli wp"
TMP=$(mktemp -d)
PASS=0
FAIL=0
ok()  { PASS=$((PASS + 1)); echo "PASS  $1"; }
bad() { FAIL=$((FAIL + 1)); echo "FAIL  $1"; }
expect() { if [ "$2" = "$3" ]; then ok "$1"; else bad "$1 (expected '$3', got '$2')"; fi; }
count_apps() { $WP db query "SELECT COUNT(*) FROM wp_hph_applications WHERE email='$1'" --skip-column-names | tr -d '\r'; }

PAGE_ID=$($WP post list --post_type=page --name=hph-apply-test --field=ID | tr -d '\r')
if [ -z "$PAGE_ID" ]; then
	PAGE_ID=$($WP post create --post_type=page --post_status=publish --post_title='Apply test' --post_name=hph-apply-test --post_content='[herb_partner_apply]' --porcelain | tr -d '\r')
fi
# get_permalink, not ?page_id: with pretty permalinks (set up for T4) ?page_id answers 301.
PAGE=$($WP eval "echo get_permalink( $PAGE_ID );" | tr -d '\r')
EMAIL="http-$(date +%s)@example.com"

echo "== public form =="
HTML=$(curl -s "$PAGE")
case "$HTML" in *'id="hph-apply"'*) ok "form renders on page" ;; *) bad "form renders on page" ;; esac
case "$HTML" in *'partner.css'*) ok "form stylesheet enqueued" ;; *) bad "form stylesheet enqueued" ;; esac

post() {
	curl -s -o /dev/null -w '%{redirect_url}' "$BASE/wp-admin/admin-post.php" \
		--data-urlencode "action=hph_apply" --data-urlencode "hph_return=$PAGE" \
		--data-urlencode "name=Http Tester" --data-urlencode "phone=+66 80 000 0000" \
		--data-urlencode "sales_channels[]=Facebook" --data-urlencode "store_name=Http Shop" \
		--data-urlencode "country=TH" --data-urlencode "contact=line:httptester" --data-urlencode "agree=1" "$@"
}

LOC=$(post --data-urlencode "email=$EMAIL" --data-urlencode "hph_js=1")
case "$LOC" in *hph_applied=1*'#hph-apply') ok "valid submit redirects to success" ;; *) bad "valid submit redirects to success ($LOC)" ;; esac
expect "valid submit stored once" "$(count_apps "$EMAIL")" "1"
case "$(curl -s "$LOC")" in *'Application received.'*) ok "success message shown" ;; *) bad "success message shown" ;; esac

BOT="bot-$(date +%s)@example.com"
post --data-urlencode "email=$BOT" --data-urlencode "hph_js=1" --data-urlencode "hph_website=http://spam" >/dev/null
post --data-urlencode "email=$BOT" >/dev/null
expect "honeypot / no-JS submissions not stored" "$(count_apps "$BOT")" "0"

LOC=$(post --data-urlencode "email=not-an-email" --data-urlencode "hph_js=1" --data-urlencode "store_name=")
case "$LOC" in *hph_apply=*) ok "invalid submit redirects back with token" ;; *) bad "invalid submit redirects back ($LOC)" ;; esac
BACK=$(curl -s "$LOC")
case "$BACK" in *'Please enter a valid email address.'*) ok "field error shown" ;; *) bad "field error shown" ;; esac
case "$BACK" in *'value="Http Tester"'*) ok "typed values kept" ;; *) bad "typed values kept" ;; esac

echo "== admin approval =="
J="$TMP/admin"
curl -s -c "$J" -b 'wordpress_test_cookie=WP+Cookie+check' -o /dev/null -d 'log=admin&pwd=admin&wp-submit=Log+In&testcookie=1' "$BASE/wp-login.php"
LIST=$(curl -s -b "$J" "$BASE/wp-admin/admin.php?page=hph-applications")
case "$LIST" in *"$EMAIL"*) ok "application listed in admin" ;; *) bad "application listed in admin" ;; esac
APP_ID=$($WP db query "SELECT id FROM wp_hph_applications WHERE email='$EMAIL'" --skip-column-names | tr -d '\r')
REVIEW=$(curl -s -b "$J" "$BASE/wp-admin/admin.php?page=hph-applications&id=$APP_ID")
NONCE=$(echo "$REVIEW" | grep -o 'name="_wpnonce" value="[^"]*"' | head -1 | cut -d'"' -f4)
case "$REVIEW" in *'class="hph-facts"'*"$EMAIL"*) ok "review shows details in the fact grid" ;; *) bad "review fact grid" ;; esac
# History sits in the sidebar, after the decision boxes, not under the notes.
SIDE=${REVIEW#*class="hph-side"}
case "$SIDE" in *'class="hph-log"'*Submitted*) ok "history is in the sidebar" ;; *) bad "history in sidebar" ;; esac
LOC=$(curl -s -b "$J" -o /dev/null -w '%{redirect_url}' "$BASE/wp-admin/admin-post.php" \
	--data-urlencode "action=hph_application_decide" --data-urlencode "id=$APP_ID" --data-urlencode "_wpnonce=$NONCE" \
	--data-urlencode "decision=approve" --data-urlencode "note=http test")
case "$LOC" in *hph_msg=approved*) ok "approve redirects with success" ;; *) bad "approve redirects ($LOC)" ;; esac
USER_ID=$($WP user get "$EMAIL" --field=ID | tr -d '\r')
case "$($WP user get "$USER_ID" --field=roles | tr -d '\r')" in *herb_dropshipper*) ok "account created with dropshipper role" ;; *) bad "account role" ;; esac
LOC=$(curl -s -o /dev/null -w '%{redirect_url}' "$BASE/wp-admin/admin-post.php" --data-urlencode "action=hph_application_decide" --data-urlencode "id=$APP_ID" --data-urlencode "decision=approve")
expect "decide without login is refused (no redirect into admin action)" "$(echo "$LOC" | grep -c 'hph_msg' || true)" "0"

echo "== quick reject from the list row =="
Q="quick-$(date +%s)@example.com"
post --data-urlencode "email=$Q" --data-urlencode "hph_js=1" >/dev/null || true
QID=$($WP db query "SELECT id FROM wp_hph_applications WHERE email='$Q'" --skip-column-names | tr -d '\r')
LIST=$(curl -s -b "$J" "$BASE/wp-admin/admin.php?page=hph-applications")
QURL=$(echo "$LIST" | grep -o "admin-post.php?action=hph_application_quick&#038;id=$QID&#038;decision=rejected&#038;_wpnonce=[a-f0-9]*" | head -1 | sed 's/&#038;/\&/g')
LOC=$(curl -s -b "$J" -o /dev/null -w '%{redirect_url}' -e "$BASE/wp-admin/admin.php?page=hph-applications" "$BASE/wp-admin/$QURL")
case "$LOC" in *hph_msg=rejected*) ok "row Reject works" ;; *) bad "row Reject works ($LOC)" ;; esac
expect "row Reject stored" "$($WP db query "SELECT status FROM wp_hph_applications WHERE id=$QID" --skip-column-names | tr -d '\r')" "rejected"
expect "row Reject without nonce refused" "$(curl -s -b "$J" -o /dev/null -w '%{http_code}' "$BASE/wp-admin/admin-post.php?action=hph_application_quick&id=$QID&decision=approve")" "403"

echo "== set password (link made 3 days ago) =="
LINK=$($WP eval "echo HPH_Approval::set_password_url( get_userdata( $USER_ID ) );" | tr -d '\r')
$WP eval "global \$wpdb; \$k = get_userdata( $USER_ID )->user_activation_key; \$wpdb->update( \$wpdb->users, array( 'user_activation_key' => ( time() - 3 * DAY_IN_SECONDS ) . substr( \$k, strpos( \$k, ':' ) ) ), array( 'ID' => $USER_ID ) ); clean_user_cache( $USER_ID );"
C="$TMP/user"
FORM_URL=$(curl -s -c "$C" -b "$C" -o /dev/null -w '%{redirect_url}' "$LINK")
FORM=$(curl -s -c "$C" -b "$C" "$FORM_URL")
case "$FORM" in *'name="password_1"'*) ok "3-day-old link still opens the password form" ;; *) bad "3-day-old link opens the form" ;; esac
field() { echo "$FORM" | grep -o "name=\"$1\" value=\"[^\"]*\"" | head -1 | cut -d'"' -f4; }
LOC=$(curl -s -c "$C" -b "$C" -o /dev/null -w '%{redirect_url}' "$FORM_URL" \
	--data-urlencode "password_1=Partner-Pass-123!" --data-urlencode "password_2=Partner-Pass-123!" \
	--data-urlencode "reset_key=$(field reset_key)" --data-urlencode "reset_login=$(field reset_login)" \
	--data-urlencode "reset_token=$(field reset_token)" \
	--data-urlencode "woocommerce-reset-password-nonce=$(field woocommerce-reset-password-nonce)" \
	--data-urlencode "_wp_http_referer=$(field _wp_http_referer)" --data-urlencode "wc_reset_password=true")
case "$LOC" in *password-reset=true*) bad "redirected to WooCommerce login instead of signing in ($LOC)" ;; "") bad "no redirect after setting password" ;; *) ok "after setting password: redirected to $LOC" ;; esac
case "$(curl -s -b "$C" "$LOC")" in *customer-logout*|*'Log out'*) ok "dropshipper is logged in" ;; *) bad "dropshipper is logged in" ;; esac
expect "set-password window cleared" "$($WP user meta get "$USER_ID" _hph_setpw_until 2>/dev/null | tr -d '\r')" ""

echo
echo "TOTAL: $PASS passed, $FAIL failed"
rm -rf "$TMP"
[ "$FAIL" -eq 0 ]
