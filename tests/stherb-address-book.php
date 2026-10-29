<?php
/**
 * Stherb Address Book — สมุดที่อยู่จัดส่งหลายที่อยู่ สำหรับลูกค้าที่ล็อกอิน
 *
 * ขอบเขตที่ user เคาะ 2026-09-09:
 *   - เฉพาะลูกค้าที่ล็อกอิน (guest ใช้ flow เดิมของ WooCommerce ไม่กระทบ)
 *   - 1 ออร์เดอร์ = 1 ที่อยู่  ❌ ไม่ใช่ split shipment (แยกส่งหลายที่ในออร์เดอร์เดียว)
 *
 * 🔑 หลักสถาปัตยกรรม:
 *   สมุดที่อยู่เป็นแค่ "ตัวช่วยกรอกฟอร์ม" ที่เขียนค่าลงฟิลด์ shipping_* มาตรฐานของ WooCommerce
 *   ไม่สร้างระบบที่อยู่คู่ขนาน และไม่สร้าง meta key ใหม่ในออร์เดอร์
 *   ⇒ DHL Express Commerce / stherb-shipping-manager / การคิดค่าส่ง / ภาษี / อีเมลออร์เดอร์
 *     ทำงานเหมือนเดิมทุกประการ ออร์เดอร์ที่ออกมาหน้าตาไม่ต่างจากเดิมเลย
 *
 * ที่เก็บข้อมูล: usermeta
 *   stherb_address_book          = array ของที่อยู่
 *   stherb_address_book_default  = id ของที่อยู่เริ่มต้น
 *
 * แผนงาน: plans/customer-address-book-2026-09-09.md
 * ปิดไฟล์นี้ได้ทันทีด้วย novamira/disable-file (ไม่มีอะไรค้างในระบบ)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Stherb_Address_Book' ) ) :

class Stherb_Address_Book {

	const META_KEY     = 'stherb_address_book';
	const META_DEFAULT = 'stherb_address_book_default';
	const ENDPOINT     = 'address-book';
	const MAX          = 10;
	const REWRITE_VER  = 'stherb_ab_rewrite_ver';
	const VERSION      = '1.0.0';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'add_endpoint' ) );
		add_filter( 'woocommerce_get_query_vars', array( __CLASS__, 'query_vars' ) );
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'menu_item' ), 10, 1 );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( __CLASS__, 'endpoint_content' ) );

		// จัดการฟอร์ม/แอ็กชันก่อน render (ต้องทำก่อนมี output เพราะมี redirect)
		add_action( 'template_redirect', array( __CLASS__, 'handle_actions' ) );

		// ตัวเลือกที่อยู่ตอน checkout
		// 🔑 เว็บนี้ตั้ง woocommerce_ship_to_destination = 'billing'
		//    ⇒ ของ "ส่งไปตามที่อยู่ billing" เป็นค่าเริ่มต้น (ข้อมูลจริง 577/609 ออร์เดอร์ = 95%)
		//    ⇒ ตัวเลือกหลักจึงต้องอยู่ที่ช่อง BILLING ไม่ใช่ shipping
		//    ถ้าไปใส่ที่ shipping อย่างเดียว = บังคับให้ทุกออร์เดอร์แยก bill/ship ทั้งที่ไม่ต้องแยก
		add_action( 'woocommerce_before_checkout_billing_form', array( __CLASS__, 'selector_billing' ) );

		//    ตัวที่สองอยู่ในส่วน shipping (ซ่อนอยู่จนกว่าจะติ๊ก "Ship to a different address?")
		//    ไว้สำหรับเคสส่วนน้อยที่ต้องการส่งคนละที่กับที่อยู่บิลจริง ๆ
		add_action( 'woocommerce_before_checkout_shipping_form', array( __CLASS__, 'selector_shipping' ) );

		// เก็บที่อยู่ใหม่เข้าสมุดอัตโนมัติหลังสั่งซื้อ
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'maybe_save_after_order' ), 20, 2 );

		// ล้างค่าชื่อว่างที่ค้างใน localStorage ของ CartFlows (ดู clean_cartflows_storage)
		add_action( 'wp_head', array( __CLASS__, 'clean_cartflows_storage' ), 1 );
	}

	/* ---------------------------------------------------------------
	 * Endpoint + เมนู My Account
	 * ------------------------------------------------------------- */

	public static function add_endpoint() {
		add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES );

		if ( get_option( self::REWRITE_VER ) !== self::VERSION ) {
			flush_rewrite_rules( false );
			update_option( self::REWRITE_VER, self::VERSION, false );
		}
	}

	public static function query_vars( $vars ) {
		$vars[ self::ENDPOINT ] = self::ENDPOINT;
		return $vars;
	}

	public static function menu_item( $items ) {
		$new = array();
		foreach ( $items as $key => $label ) {
			$new[ $key ] = $label;
			// วางต่อจาก "Addresses" ของ WooCommerce
			if ( 'edit-address' === $key ) {
				$new[ self::ENDPOINT ] = __( 'Address Book', 'stherb' );
			}
		}
		if ( ! isset( $new[ self::ENDPOINT ] ) ) {
			$new[ self::ENDPOINT ] = __( 'Address Book', 'stherb' );
		}
		return $new;
	}

	/* ---------------------------------------------------------------
	 * อ่าน / เขียนสมุด
	 * ------------------------------------------------------------- */

	public static function get_book( $user_id = 0 ) {
		$user_id = $user_id ? $user_id : get_current_user_id();
		if ( ! $user_id ) {
			return array();
		}
		$book = get_user_meta( $user_id, self::META_KEY, true );
		return is_array( $book ) ? $book : array();
	}

	public static function save_book( $book, $user_id = 0 ) {
		$user_id = $user_id ? $user_id : get_current_user_id();
		if ( ! $user_id ) {
			return false;
		}
		return update_user_meta( $user_id, self::META_KEY, array_values( $book ) );
	}

	public static function get_default_id( $user_id = 0 ) {
		$user_id = $user_id ? $user_id : get_current_user_id();
		return $user_id ? (string) get_user_meta( $user_id, self::META_DEFAULT, true ) : '';
	}

	public static function get_entry( $id, $user_id = 0 ) {
		foreach ( self::get_book( $user_id ) as $entry ) {
			if ( isset( $entry['id'] ) && $entry['id'] === $id ) {
				return $entry;
			}
		}
		return null;
	}

	/** ฟิลด์ที่เก็บ — ตรงกับ shipping_* ของ WooCommerce ทุกตัว */
	public static function fields() {
		return array(
			'first_name',
			'last_name',
			'company',
			'country',
			'address_1',
			'address_2',
			'city',
			'state',
			'postcode',
			'phone',
		);
	}

	/* ---------------------------------------------------------------
	 * จัดการ action ต่าง ๆ (เพิ่ม / แก้ / ลบ / ตั้งค่าเริ่มต้น)
	 * ------------------------------------------------------------- */

	public static function handle_actions() {
		if ( ! is_user_logged_in() || ! function_exists( 'is_wc_endpoint_url' ) ) {
			return;
		}
		if ( ! is_wc_endpoint_url( self::ENDPOINT ) ) {
			return;
		}

		$action = isset( $_REQUEST['ab_action'] ) ? sanitize_key( wp_unslash( $_REQUEST['ab_action'] ) ) : '';
		if ( ! $action ) {
			return;
		}

		$id      = isset( $_REQUEST['ab_id'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['ab_id'] ) ) : '';
		$base    = wc_get_account_endpoint_url( self::ENDPOINT );
		$user_id = get_current_user_id();

		// ---- ลบ ----
		if ( 'delete' === $action ) {
			if ( ! wp_verify_nonce( isset( $_GET['_wpnonce'] ) ? wp_unslash( $_GET['_wpnonce'] ) : '', 'stherb_ab_delete_' . $id ) ) {
				wc_add_notice( __( 'Link expired. Please try again.', 'stherb' ), 'error' );
				wp_safe_redirect( $base );
				exit;
			}
			$book = self::get_book();
			$out  = array();
			foreach ( $book as $entry ) {
				if ( $entry['id'] !== $id ) {
					$out[] = $entry;
				}
			}
			self::save_book( $out );
			if ( self::get_default_id() === $id ) {
				delete_user_meta( $user_id, self::META_DEFAULT );
			}
			wc_add_notice( __( 'Address deleted.', 'stherb' ) );
			wp_safe_redirect( $base );
			exit;
		}

		// ---- ตั้งเป็นค่าเริ่มต้น ----
		if ( 'default' === $action ) {
			if ( ! wp_verify_nonce( isset( $_GET['_wpnonce'] ) ? wp_unslash( $_GET['_wpnonce'] ) : '', 'stherb_ab_default_' . $id ) ) {
				wc_add_notice( __( 'Link expired. Please try again.', 'stherb' ), 'error' );
				wp_safe_redirect( $base );
				exit;
			}
			if ( self::get_entry( $id ) ) {
				update_user_meta( $user_id, self::META_DEFAULT, $id );
				wc_add_notice( __( 'Default shipping address updated.', 'stherb' ) );
			}
			wp_safe_redirect( $base );
			exit;
		}

		// ---- บันทึก (เพิ่ม/แก้) ----
		if ( 'save' === $action && 'POST' === strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : '' ) ) {
			check_admin_referer( 'stherb_ab_save' );
			self::handle_save( $id, $base );
		}
	}

	private static function handle_save( $id, $base ) {
		$user_id = get_current_user_id();
		$book    = self::get_book();

		$is_new = ( '' === $id );
		if ( $is_new && count( $book ) >= self::MAX ) {
			wc_add_notice( sprintf( __( 'You can save up to %d addresses.', 'stherb' ), self::MAX ), 'error' );
			wp_safe_redirect( $base );
			exit;
		}

		$country = isset( $_POST['shipping_country'] ) ? wc_clean( wp_unslash( $_POST['shipping_country'] ) ) : '';
		$wc_flds = WC()->countries->get_address_fields( $country, 'shipping_' );

		$data = array();
		foreach ( self::fields() as $f ) {
			$data[ $f ] = isset( $_POST[ 'shipping_' . $f ] ) ? wc_clean( wp_unslash( $_POST[ 'shipping_' . $f ] ) ) : '';
		}
		$label = isset( $_POST['stherb_ab_label'] ) ? wc_clean( wp_unslash( $_POST['stherb_ab_label'] ) ) : '';

		// validate ตามกฎของ WooCommerce เอง ไม่เขียน validation ใหม่
		$errors = array();
		foreach ( $wc_flds as $key => $field ) {
			$short = str_replace( 'shipping_', '', $key );
			if ( ! empty( $field['required'] ) && '' === trim( (string) ( isset( $data[ $short ] ) ? $data[ $short ] : '' ) ) ) {
				$errors[] = sprintf( __( '%s is a required field.', 'stherb' ), esc_html( $field['label'] ) );
			}
		}
		if ( '' === trim( $data['country'] ) ) {
			$errors[] = __( 'Country is a required field.', 'stherb' );
		}
		if ( $errors ) {
			foreach ( $errors as $e ) {
				wc_add_notice( $e, 'error' );
			}
			wp_safe_redirect( add_query_arg( array( 'ab_action' => $is_new ? 'new' : 'edit', 'ab_id' => $id ), $base ) );
			exit;
		}

		if ( '' === $label ) {
			$label = trim( $data['address_1'] . ' ' . $data['city'] );
			$label = $label ? $label : __( 'Address', 'stherb' );
		}
		$data['label'] = $label;

		if ( $is_new ) {
			$data['id'] = 'ab_' . wp_generate_password( 10, false, false );
			$book[]     = $data;
		} else {
			$found = false;
			foreach ( $book as $i => $entry ) {
				if ( $entry['id'] === $id ) {
					$data['id'] = $id;
					$book[ $i ] = $data;
					$found      = true;
					break;
				}
			}
			if ( ! $found ) {
				// ป้องกันการยัด id ของคนอื่นเข้ามา
				wc_add_notice( __( 'Address not found.', 'stherb' ), 'error' );
				wp_safe_redirect( $base );
				exit;
			}
		}

		self::save_book( $book );

		if ( ! empty( $_POST['stherb_ab_default'] ) || 1 === count( $book ) ) {
			update_user_meta( $user_id, self::META_DEFAULT, $data['id'] );
		}

		wc_add_notice( __( 'Address saved.', 'stherb' ) );
		wp_safe_redirect( $base );
		exit;
	}

	/* ---------------------------------------------------------------
	 * หน้าจอใน My Account
	 * ------------------------------------------------------------- */

	public static function endpoint_content() {
		$action = isset( $_GET['ab_action'] ) ? sanitize_key( wp_unslash( $_GET['ab_action'] ) ) : '';
		$id     = isset( $_GET['ab_id'] ) ? sanitize_text_field( wp_unslash( $_GET['ab_id'] ) ) : '';

		self::print_styles();

		if ( 'new' === $action ) {
			self::render_form( null );
			return;
		}
		if ( 'edit' === $action ) {
			$entry = self::get_entry( $id );
			if ( ! $entry ) {
				echo '<p>' . esc_html__( 'Address not found.', 'stherb' ) . '</p>';
				self::render_list();
				return;
			}
			self::render_form( $entry );
			return;
		}
		self::render_list();
	}

	private static function render_list() {
		$book    = self::get_book();
		$default = self::get_default_id();
		$base    = wc_get_account_endpoint_url( self::ENDPOINT );

		echo '<p>' . esc_html__( 'Save the addresses you ship to most often, then pick one at checkout instead of typing it again.', 'stherb' ) . '</p>';

		if ( empty( $book ) ) {
			echo '<p><em>' . esc_html__( 'You have no saved addresses yet.', 'stherb' ) . '</em></p>';
		} else {
			echo '<div class="stherb-ab-grid">';
			foreach ( $book as $entry ) {
				$is_def = ( $entry['id'] === $default );
				echo '<div class="stherb-ab-card' . ( $is_def ? ' is-default' : '' ) . '">';
				echo '<div class="stherb-ab-card__label">' . esc_html( $entry['label'] );
				if ( $is_def ) {
					echo ' <span class="stherb-ab-badge">' . esc_html__( 'Default', 'stherb' ) . '</span>';
				}
				echo '</div>';
				echo '<address>' . wp_kses_post( self::format_address( $entry ) ) . '</address>';
				echo '<div class="stherb-ab-card__actions">';
				printf(
					'<a href="%s">%s</a>',
					esc_url( add_query_arg( array( 'ab_action' => 'edit', 'ab_id' => $entry['id'] ), $base ) ),
					esc_html__( 'Edit', 'stherb' )
				);
				if ( ! $is_def ) {
					printf(
						' <a href="%s">%s</a>',
						esc_url( wp_nonce_url( add_query_arg( array( 'ab_action' => 'default', 'ab_id' => $entry['id'] ), $base ), 'stherb_ab_default_' . $entry['id'] ) ),
						esc_html__( 'Set as default', 'stherb' )
					);
				}
				printf(
					' <a class="stherb-ab-del" href="%s">%s</a>',
					esc_url( wp_nonce_url( add_query_arg( array( 'ab_action' => 'delete', 'ab_id' => $entry['id'] ), $base ), 'stherb_ab_delete_' . $entry['id'] ) ),
					esc_html__( 'Delete', 'stherb' )
				);
				echo '</div></div>';
			}
			echo '</div>';
		}

		if ( count( $book ) < self::MAX ) {
			printf(
				'<p><a class="button" href="%s">%s</a></p>',
				esc_url( add_query_arg( 'ab_action', 'new', $base ) ),
				esc_html__( 'Add a new address', 'stherb' )
			);
		} else {
			echo '<p><em>' . esc_html( sprintf( __( 'You have reached the limit of %d saved addresses.', 'stherb' ), self::MAX ) ) . '</em></p>';
		}
	}

	private static function render_form( $entry ) {
		$base    = wc_get_account_endpoint_url( self::ENDPOINT );
		$country = $entry && ! empty( $entry['country'] ) ? $entry['country'] : WC()->countries->get_base_country();
		$fields  = WC()->countries->get_address_fields( $country, 'shipping_' );

		echo '<form method="post" action="' . esc_url( add_query_arg( 'ab_action', 'save', $base ) ) . '" class="stherb-ab-form">';
		wp_nonce_field( 'stherb_ab_save' );
		echo '<input type="hidden" name="ab_action" value="save" />';
		echo '<input type="hidden" name="ab_id" value="' . esc_attr( $entry ? $entry['id'] : '' ) . '" />';

		woocommerce_form_field(
			'stherb_ab_label',
			array(
				'type'        => 'text',
				'label'       => __( 'Address name', 'stherb' ),
				'placeholder' => __( 'Home, Office, Mum&#39;s place…', 'stherb' ),
				'class'       => array( 'form-row-wide' ),
			),
			$entry && isset( $entry['label'] ) ? $entry['label'] : ''
		);

		foreach ( $fields as $key => $field ) {
			$short = str_replace( 'shipping_', '', $key );
			$value = $entry && isset( $entry[ $short ] ) ? $entry[ $short ] : '';
			woocommerce_form_field( $key, $field, $value );
		}

		// phone ไม่ได้อยู่ใน get_address_fields เสมอไป — เติมเองให้ครบ
		if ( ! isset( $fields['shipping_phone'] ) ) {
			woocommerce_form_field(
				'shipping_phone',
				array(
					'type'  => 'tel',
					'label' => __( 'Phone', 'stherb' ),
					'class' => array( 'form-row-wide' ),
				),
				$entry && isset( $entry['phone'] ) ? $entry['phone'] : ''
			);
		}

		woocommerce_form_field(
			'stherb_ab_default',
			array(
				'type'  => 'checkbox',
				'label' => __( 'Use this as my default shipping address', 'stherb' ),
				'class' => array( 'form-row-wide' ),
			),
			$entry && self::get_default_id() === $entry['id'] ? 1 : 0
		);

		echo '<p><button type="submit" class="button">' . esc_html__( 'Save address', 'stherb' ) . '</button> ';
		echo '<a href="' . esc_url( $base ) . '">' . esc_html__( 'Cancel', 'stherb' ) . '</a></p>';
		echo '</form>';
	}

	public static function format_address( $entry ) {
		$args = array(
			'first_name' => isset( $entry['first_name'] ) ? $entry['first_name'] : '',
			'last_name'  => isset( $entry['last_name'] ) ? $entry['last_name'] : '',
			'company'    => isset( $entry['company'] ) ? $entry['company'] : '',
			'address_1'  => isset( $entry['address_1'] ) ? $entry['address_1'] : '',
			'address_2'  => isset( $entry['address_2'] ) ? $entry['address_2'] : '',
			'city'       => isset( $entry['city'] ) ? $entry['city'] : '',
			'state'      => isset( $entry['state'] ) ? $entry['state'] : '',
			'postcode'   => isset( $entry['postcode'] ) ? $entry['postcode'] : '',
			'country'    => isset( $entry['country'] ) ? $entry['country'] : '',
		);
		$out = WC()->countries->get_formatted_address( $args );
		if ( ! empty( $entry['phone'] ) ) {
			$out .= '<br/>' . esc_html( $entry['phone'] );
		}
		return $out;
	}

	/* ---------------------------------------------------------------
	 * ตัวเลือกที่อยู่ตอน checkout
	 * ------------------------------------------------------------- */

	public static function selector_billing() {
		self::render_selector( 'billing' );
	}

	public static function selector_shipping() {
		self::render_selector( 'shipping' );
	}

	/**
	 * @param string $which 'billing' หรือ 'shipping'
	 */
	private static function render_selector( $which ) {
		if ( ! is_user_logged_in() ) {
			return;
		}
		$book = self::get_book();
		if ( empty( $book ) ) {
			return;
		}
		$default = self::get_default_id();
		$sel_id  = 'stherb_ab_choose_' . $which;

		if ( 'billing' === $which ) {
			$label = __( 'Use one of your saved addresses', 'stherb' );
			$blank = __( '— Type a new address instead —', 'stherb' );
		} else {
			$label = __( 'Ship to one of your saved addresses', 'stherb' );
			$blank = __( '— Type a different address —', 'stherb' );
		}

		echo '<div class="stherb-ab-choose" data-for="' . esc_attr( $which ) . '">';
		echo '<label for="' . esc_attr( $sel_id ) . '">' . esc_html( $label ) . '</label>';
		// ต้องมี name — CartFlows จำค่าฟอร์มลง localStorage ด้วย this.name ถ้าว่างจะได้ selector [name=]
		// ที่ทำให้ JS ของ CartFlows ล้มทั้งก้อน (ปุ่ม Apply คูปองหน้า checkout ตาย) — แก้ 2026-10-27
		echo '<select id="' . esc_attr( $sel_id ) . '" name="' . esc_attr( $sel_id ) . '" class="stherb-ab-select" data-prefix="' . esc_attr( $which ) . '">';
		echo '<option value="">' . esc_html( $blank ) . '</option>';
		foreach ( $book as $entry ) {
			$opt = $entry['label'];
			if ( $entry['id'] === $default ) {
				/* translators: %s: address name */
				$opt = sprintf( __( '%s (default)', 'stherb' ), $opt );
			}
			printf( '<option value="%s">%s</option>', esc_attr( $entry['id'] ), esc_html( $opt ) );
		}
		echo '</select>';
		printf(
			'<p class="stherb-ab-choose__hint">%s <a href="%s">%s</a></p>',
			esc_html__( 'Manage these in', 'stherb' ),
			esc_url( wc_get_account_endpoint_url( self::ENDPOINT ) ),
			esc_html__( 'your Address Book', 'stherb' )
		);
		echo '</div>';

		self::print_styles();

		// พิมพ์ JS ที่ footer เสมอ — wp_add_inline_script() เงียบ ๆ ไม่ทำงานถ้า handle
		// ยังไม่ถูก register ตอนที่ hook นี้ยิง ซึ่งจะทำให้ตัวเลือกกดแล้วไม่มีอะไรเกิดขึ้น
		if ( empty( self::$js_payload ) ) {
			$rows = array();
			foreach ( $book as $entry ) {
				$row = array( 'id' => $entry['id'], 'label' => $entry['label'] );
				foreach ( self::fields() as $f ) {
					$row[ $f ] = isset( $entry[ $f ] ) ? $entry[ $f ] : '';
				}
				$rows[] = $row;
			}
			self::$js_payload = $rows;
		}
		if ( ! has_action( 'wp_footer', array( __CLASS__, 'print_footer_js' ) ) ) {
			add_action( 'wp_footer', array( __CLASS__, 'print_footer_js' ), 99 );
		}
	}

	/** @var array */
	private static $js_payload = array();

	public static function print_footer_js() {
		if ( empty( self::$js_payload ) ) {
			return;
		}
		echo '<script type="text/javascript" id="stherb-ab-js">' . "\n";
		echo 'window.stherbAddressBook = ' . wp_json_encode( self::$js_payload ) . ';' . "\n";
		echo self::selector_js(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static JS literal
		echo "\n" . '</script>' . "\n";
	}

	private static function selector_js() {
		return <<<'JS'
( function( $ ) {
	if ( ! $ ) { return; }
	$( function() {
		var book = window.stherbAddressBook || [];

		function find( id ) {
			for ( var i = 0; i < book.length; i++ ) {
				if ( book[ i ].id === id ) { return book[ i ]; }
			}
			return null;
		}

		function setField( prefix, name, value ) {
			var $f = $( '#' + prefix + '_' + name );
			if ( $f.length ) { $f.val( value ); }
		}

		function apply( prefix, addr ) {
			if ( ! addr ) { return; }

			// ฟิลด์ธรรมดา
			[ 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'postcode', 'phone' ]
				.forEach( function( k ) { setField( prefix, k, addr[ k ] || '' ); } );

			// country ต้องยิง change ให้ WooCommerce สร้างฟิลด์ state ใหม่ก่อน
			var $country = $( '#' + prefix + '_country' );
			var stateVal = addr.state || '';

			function finish() {
				setField( prefix, 'state', stateVal );
				$( '#' + prefix + '_state' ).trigger( 'change' );
				$( document.body ).trigger( 'update_checkout' );
			}

			if ( $country.length && $country.val() !== addr.country ) {
				$( document.body ).one( 'country_to_state_changed', finish );
				$country.val( addr.country ).trigger( 'change' );
			} else {
				finish();
			}
		}

		// ตัวเลือกมีได้ 2 ตัว: billing (เห็นเสมอ) และ shipping (โผล่เมื่อติ๊กส่งคนละที่)
		$( document ).on( 'change', '.stherb-ab-select', function() {
			var $s = $( this );
			var id = $s.val();
			if ( ! id ) { return; }
			apply( $s.data( 'prefix' ), find( id ) );
		} );

		// ตั้งใจไม่เติมอัตโนมัติตอนเปิดหน้า — จะยิง update_checkout เกินจำเป็นทุกครั้งที่โหลด
		// และไปชนกับ stherb-shipping-manager/includes/autoselect.php ที่เรียก calculate_totals()
		// อยู่ข้างใน hook woocommerce_after_shipping_rate · ให้ทำงานเฉพาะตอนลูกค้าเลือกเอง
	} );
} )( window.jQuery );
JS;
	}

	/* ---------------------------------------------------------------
	 * เก็บที่อยู่ใหม่เข้าสมุดอัตโนมัติหลังสั่งซื้อ
	 * ------------------------------------------------------------- */

	public static function maybe_save_after_order( $order, $data ) {
		if ( ! is_user_logged_in() ) {
			return;
		}
		$user_id = get_current_user_id();
		$book    = self::get_book( $user_id );
		if ( count( $book ) >= self::MAX ) {
			return;
		}

		$addr = array();
		foreach ( self::fields() as $f ) {
			$getter    = 'get_shipping_' . $f;
			$addr[ $f ] = is_callable( array( $order, $getter ) ) ? (string) $order->$getter() : '';
		}
		if ( '' === trim( $addr['address_1'] ) ) {
			return;
		}

		// ซ้ำกับที่มีอยู่แล้วหรือไม่
		foreach ( $book as $entry ) {
			if ( self::same_address( $entry, $addr ) ) {
				return;
			}
		}

		$addr['id']    = 'ab_' . wp_generate_password( 10, false, false );
		$addr['label'] = trim( $addr['address_1'] . ' ' . $addr['city'] );
		if ( '' === $addr['label'] ) {
			$addr['label'] = __( 'Address', 'stherb' );
		}
		$book[] = $addr;
		self::save_book( $book, $user_id );

		if ( '' === self::get_default_id( $user_id ) ) {
			update_user_meta( $user_id, self::META_DEFAULT, $addr['id'] );
		}
	}

	private static function same_address( $a, $b ) {
		// ต้องเทียบชื่อผู้รับด้วย — ลูกค้าที่ส่งของขวัญมักส่งไปตึกเดียวกันแต่คนละคนรับ
		// ถ้าเทียบแค่ที่อยู่ ที่อยู่ที่สองจะถูกกลืนหายไปเงียบ ๆ
		foreach ( array( 'first_name', 'last_name', 'address_1', 'address_2', 'city', 'postcode', 'country' ) as $k ) {
			$av = strtolower( trim( (string) ( isset( $a[ $k ] ) ? $a[ $k ] : '' ) ) );
			$bv = strtolower( trim( (string) ( isset( $b[ $k ] ) ? $b[ $k ] : '' ) ) );
			if ( $av !== $bv ) {
				return false;
			}
		}
		return true;
	}

	/* ---------------------------------------------------------------
	 * ล้างรายการชื่อว่างใน localStorage 'cartflows_checkout_form'
	 * select ของสมุดที่อยู่เคยไม่มี name (ก่อน 2026-10-27) ⇒ CartFlows เก็บเป็น name ""
	 * แล้วโหลดหน้าถัดไปสร้าง selector [name=] ⇒ jQuery error ⇒ wcf_checkout_coupons.init()
	 * ไม่ถูกเรียก ปุ่ม Apply คูปองหน้า checkout ไม่ทำงาน · ต้องรันก่อน jQuery ready ของ CartFlows
	 * ------------------------------------------------------------- */

	public static function clean_cartflows_storage() {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}
		?>
<script id="stherb-ab-cf-storage-fix">
(function(){try{var k='cartflows_checkout_form',v=localStorage.getItem(k);if(!v){return;}var d;try{d=JSON.parse(v);}catch(e){localStorage.removeItem(k);return;}if(!Array.isArray(d)){return;}var c=d.filter(function(x){return x&&x.name;});if(c.length!==d.length){localStorage.setItem(k,JSON.stringify(c));}}catch(e){}})();
</script>
		<?php
	}

	/* ---------------------------------------------------------------
	 * CSS (พอดี ๆ ให้เข้ากับธีม ไม่ทับ design system เดิม)
	 * ------------------------------------------------------------- */

	private static function print_styles() {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;
		?>
<style id="stherb-ab-css">
.stherb-ab-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:16px;margin:0 0 24px}
.stherb-ab-card{border:1px solid #e2e2e2;border-radius:8px;padding:16px;background:#fff}
.stherb-ab-card.is-default{border-color:#8a9a5b;box-shadow:0 0 0 1px #8a9a5b inset}
.stherb-ab-card__label{font-weight:600;margin-bottom:8px}
.stherb-ab-badge{display:inline-block;font-size:11px;font-weight:500;text-transform:uppercase;letter-spacing:.04em;background:#8a9a5b;color:#fff;border-radius:999px;padding:2px 8px;margin-left:4px;vertical-align:middle}
.stherb-ab-card address{font-style:normal;font-size:14px;line-height:1.6;margin:0 0 12px}
.stherb-ab-card__actions{font-size:13px;display:flex;flex-wrap:wrap;gap:12px}
.stherb-ab-card__actions .stherb-ab-del{color:#b3261e}
.stherb-ab-choose{margin:0 0 20px;padding:14px 16px;border:1px solid #e2e2e2;border-radius:8px;background:#fafaf7}
.stherb-ab-choose label{display:block;font-weight:600;margin-bottom:6px}
.stherb-ab-choose select{width:100%;max-width:100%}
.stherb-ab-choose__hint{font-size:12px;margin:8px 0 0;opacity:.75}
</style>
		<?php
	}
}

Stherb_Address_Book::init();

endif;
