<?php
/**
 * Herb Partner Hub email (HTML). Override in yourtheme/woocommerce/emails/hph-email.php.
 *
 * @var string   $email_heading
 * @var array    $blocks
 * @var string   $additional_content
 * @var WC_Email $email
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email );

foreach ( $blocks as $hph_block ) {
	switch ( $hph_block[0] ) {
		case 'p':
			echo '<p>' . esc_html( $hph_block[1] ) . '</p>';
			break;
		case 'button':
			echo '<p style="margin:24px 0;"><a href="' . esc_url( $hph_block[2] ) . '" style="display:inline-block;padding:12px 24px;border-radius:999px;background:#1C6FAB;color:#FFFFFF;text-decoration:none;font-weight:700;">' . esc_html( $hph_block[1] ) . '</a></p>';
			break;
		case 'quote':
			echo '<blockquote style="margin:16px 0;padding:12px 16px;border-left:4px solid #E7DECF;background:#F7F1E6;">' . wp_kses_post( nl2br( esc_html( $hph_block[1] ) ) ) . '</blockquote>';
			break;
		case 'note':
			echo '<p style="font-size:13px;color:#7A7163;">' . esc_html( $hph_block[1] ) . '</p>';
			break;
		case 'details':
			if ( ! empty( $hph_block[2] ) ) {
				echo '<h3 style="margin:24px 0 8px;">' . esc_html( $hph_block[2] ) . '</h3>';
			}
			echo '<table cellspacing="0" cellpadding="6" border="1" style="width:100%;border-collapse:collapse;border-color:#E7DECF;margin:16px 0;">';
			foreach ( $hph_block[1] as $hph_label => $hph_value ) {
				if ( '' === (string) $hph_value ) {
					continue;
				}
				echo '<tr><th scope="row" style="text-align:left;vertical-align:top;width:35%;">' . esc_html( $hph_label ) . '</th><td style="text-align:left;">' . wp_kses_post( nl2br( esc_html( (string) $hph_value ) ) ) . '</td></tr>';
			}
			echo '</table>';
			break;
	}
}

if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email );
