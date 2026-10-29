<?php

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-hph-customer-repo.php';
require_once __DIR__ . '/class-hph-customers-account.php';
require_once __DIR__ . '/class-hph-checkout-recipient.php';

/**
 * Module "customers": a dropshipper's own list of end customers (My Account › My Customers)
 * and the "Ship this order to" box at checkout.
 */
final class HPH_Customers {
	public function __construct() {
		new HPH_Customers_Account();
		new HPH_Checkout_Recipient();
	}
}
