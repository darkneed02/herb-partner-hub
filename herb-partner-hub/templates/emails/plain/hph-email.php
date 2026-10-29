<?php
/**
 * Herb Partner Hub email (plain text). Override in yourtheme/woocommerce/emails/plain/hph-email.php.
 *
 * @var string   $email_heading
 * @var array    $blocks
 * @var string   $additional_content
 * @var WC_Email $email
 */

defined( 'ABSPATH' ) || exit;

echo "=================================================\n";
echo esc_html( wp_strip_all_tags( $email_heading ) ) . "\n";
echo "=================================================\n\n";

foreach ( $blocks as $hph_block ) {
	switch ( $hph_block[0] ) {
		case 'p':
			echo esc_html( $hph_block[1] ) . "\n\n";
			break;
		case 'button':
			echo esc_html( $hph_block[1] ) . ': ' . esc_url_raw( $hph_block[2] ) . "\n\n";
			break;
		case 'quote':
			echo '> ' . esc_html( str_replace( "\n", "\n> ", $hph_block[1] ) ) . "\n\n";
			break;
		case 'note':
			echo esc_html( $hph_block[1] ) . "\n\n";
			break;
		case 'details':
			if ( ! empty( $hph_block[2] ) ) {
				echo esc_html( strtoupper( $hph_block[2] ) ) . "\n";
			}
			foreach ( $hph_block[1] as $hph_label => $hph_value ) {
				if ( '' !== (string) $hph_value ) {
					echo esc_html( $hph_label ) . ': ' . esc_html( (string) $hph_value ) . "\n";
				}
			}
			echo "\n";
			break;
	}
}

if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n\n";
}

echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );
