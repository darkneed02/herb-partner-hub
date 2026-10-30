=== Herb Partner Hub ===
Requires at least: 6.5
Requires PHP: 8.1
Stable tag: 0.1.0

Dropshipper programme for WooCommerce.

== Description ==

Applications and approval, wholesale prices for approved dropshippers, end customers and "Ship to" at checkout, order history and reports. Dropshippers earn from the margin between the wholesale price and their own selling price; there is no commission.

Every module is off after installing, and soft launch is on with nobody listed, so activating changes nothing for customers. Switch modules on under Dropshippers › Settings.

== Changelog ==

= 0.1.0 =
* Core: dropshipper role and capabilities, database tables, settings, soft launch, "View as dropshipper" for admins, activity log.
* Applications: [herb_partner_apply] form, Dropshippers › Applications list and review screen (approve / ask for more info / not approved), five WooCommerce emails, 7-day set-password link that signs the new dropshipper in.
* Pricing: dropshipper price per product and per variation, products not open to dropshippers hidden and unbuyable, Dropshippers › Products screen with bulk actions and CSV export/import.
* Discounts: Stherb Promo Engine, Woo Discount Rules, Tier Pricing Table, coupons and Power Coupons never apply to dropshippers.
* Customers: My Account › My Customers (each dropshipper sees only their own list, delete on request keeps past orders' addresses), "Ship this order to" at checkout (saved customer / new customer / myself), the server fills the recipient's address and phone. A saved customer's delivery address is shown read-only under billing so the dropshipper can check it before paying (changes are made in My Customers). Billing stays the dropshipper's own (they pay, so it has to match their card) and the receipt goes to them. An order to an end customer keeps a copy of that customer's details on the order (`_hph_bill_to`, read with `HPH_Checkout_Recipient::bill_to()`) for the bill the CRM attaches to the parcel. The dropshipper's own shipping address in their profile is never overwritten.
* Orders: partner dashboard on the My Account front page, order history with ship-to, DHL tracking (read from order notes), filters, CSV export and Reorder that picks the same recipient at checkout.
* Reports: Dropshippers › Overview with monthly dropshipper sales, active dropshippers, waiting applications, ranking and CSV export.
