<?php
/**
 * Plugin Name:       ArionPay for GiveWP
 * Plugin URI:        https://arionpay.com
 * Description:       Accept crypto donations (Bitcoin, USDT, Litecoin, Ethereum, TRON and more) in GiveWP. The money goes straight to your own wallet.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            ArionPay
 * License:           MIT
 * Text Domain:       arionpay-for-givewp
 */

namespace ArionPay\GiveWP;

defined( 'ABSPATH' ) || exit;

define( 'ARIONPAY_GIVEWP_VERSION', '1.0.0' );
define( 'ARIONPAY_GIVEWP_FILE', __FILE__ );

if ( ! class_exists( '\ArionPay\Client', true ) ) {
	require_once __DIR__ . '/lib/arionpay-php/autoload.php';
}

final class Plugin {

	const SECRETS = array( 'arionpay_api_secret', 'arionpay_webhook_secret' );

	public static function init() {
		add_action(
			'givewp_register_payment_gateway',
			static function ( $registrar ) {
				require_once __DIR__ . '/src/ArionPayGateway.php';
				$registrar->registerGateway( ArionPayGateway::class );
			}
		);
		add_filter( 'give_get_sections_gateways', array( __CLASS__, 'section' ) );
		add_filter( 'give_get_settings_gateways', array( __CLASS__, 'settings' ) );
		add_action( 'give_admin_field_arionpay_secret', array( __CLASS__, 'secret_field' ), 10, 2 );
		foreach ( self::SECRETS as $key ) {
			add_filter( 'give_admin_settings_sanitize_option_' . $key, array( __CLASS__, 'keep_secret' ), 10, 3 );
		}
	}

	public static function option( $name ) {
		return trim( (string) give_get_option( 'arionpay_' . $name, '' ) );
	}

	public static function is_configured() {
		return '' !== self::option( 'api_key' ) && '' !== self::option( 'api_secret' ) && '' !== self::option( 'webhook_secret' );
	}

	/** @return \ArionPay\Client */
	public static function client() {
		$base = self::option( 'base_url' );
		return new \ArionPay\Client(
			self::option( 'api_key' ),
			self::option( 'api_secret' ),
			array(
				'baseUrl' => '' !== $base ? $base : 'https://merchant.arionpay.com',
				'appInfo' => 'wordpress givewp/' . ( defined( 'GIVE_VERSION' ) ? GIVE_VERSION : '?' ) . ' arionpay-givewp/' . ARIONPAY_GIVEWP_VERSION,
			)
		);
	}

	public static function section( $sections ) {
		$sections['arionpay'] = __( 'ArionPay', 'arionpay-for-givewp' );
		return $sections;
	}

	public static function settings( $settings ) {
		if ( 'arionpay' !== give_get_current_setting_section() ) {
			return $settings;
		}
		$webhook = add_query_arg(
			array(
				'give-listener'       => 'give-gateway',
				'give-gateway-id'     => 'arionpay',
				'give-gateway-method' => 'arionpayWebhook',
			),
			home_url( '/' )
		);
		return array(
			array(
				'id'    => 'arionpay_header',
				'type'  => 'title',
				'title' => __( 'ArionPay (crypto)', 'arionpay-for-givewp' ),
				'desc'  => __( 'Donors pay with Bitcoin, USDT, Litecoin, Ethereum, TRON and more. The money goes straight to your own wallet: ArionPay never holds it. In your ArionPay dashboard, open your store and copy the API key (pk_...) and API secret (sk_...); on the Webhooks page, copy the signing secret (whsec_...). GiveWP\'s own Test Mode (Settings > Payment Gateways) makes ArionPay test invoices: no real money moves. Then turn on ArionPay under Payment Gateways > Gateways.', 'arionpay-for-givewp' ),
			),
			array(
				'id'   => 'arionpay_api_key',
				'name' => __( 'API key', 'arionpay-for-givewp' ),
				'type' => 'text',
			),
			array(
				'id'   => 'arionpay_api_secret',
				'name' => __( 'API secret', 'arionpay-for-givewp' ),
				'type' => 'arionpay_secret',
			),
			array(
				'id'   => 'arionpay_webhook_secret',
				'name' => __( 'Webhook signing secret', 'arionpay-for-givewp' ),
				'type' => 'arionpay_secret',
				'desc' => sprintf(
					/* translators: %s: webhook address */
					__( 'Nothing to paste into ArionPay: every invoice tells ArionPay to send its updates to %s', 'arionpay-for-givewp' ),
					'<code>' . esc_html( $webhook ) . '</code>'
				) . ( 0 !== strpos( $webhook, 'https://' ) ? '<br><strong>' . esc_html__( 'Your site address does not start with https://. ArionPay only sends payment updates to https addresses, so donations will not be completed until your site uses https.', 'arionpay-for-givewp' ) . '</strong>' : '' ),
			),
			array(
				'id'      => 'arionpay_checkout_title',
				'name'    => __( 'Name on the form', 'arionpay-for-givewp' ),
				'type'    => 'text',
				'default' => __( 'Crypto (Bitcoin, USDT and more)', 'arionpay-for-givewp' ),
			),
			array(
				'id'   => 'arionpay_debug',
				'name' => __( 'Debug log', 'arionpay-for-givewp' ),
				'type' => 'checkbox',
				'desc' => __( 'Writes what the plugin does to Donations > Tools > Logs. Never writes your keys.', 'arionpay-for-givewp' ),
			),
			array(
				'id'   => 'arionpay_base_url',
				'name' => __( 'ArionPay address', 'arionpay-for-givewp' ),
				'type' => 'text',
				'desc' => __( 'Leave empty unless ArionPay tells you otherwise.', 'arionpay-for-givewp' ),
			),
			array(
				'id'   => 'arionpay_end',
				'type' => 'sectionend',
			),
		);
	}

	/** Secret field: never prints the saved value back into the page. */
	public static function secret_field( $field, $value ) {
		$saved = '' !== (string) $value;
		echo '<tr valign="top"><th scope="row" class="titledesc"><label for="' . esc_attr( $field['id'] ) . '">' . esc_html( $field['name'] ) . '</label></th><td class="give-forminp">';
		echo '<input type="password" autocomplete="new-password" class="give-input-field" name="' . esc_attr( $field['id'] ) . '" id="' . esc_attr( $field['id'] ) . '" value="" placeholder="' . esc_attr( $saved ? __( 'Saved. Leave empty to keep it.', 'arionpay-for-givewp' ) : '' ) . '"/>';
		if ( ! empty( $field['desc'] ) ) {
			echo '<p class="give-field-description">' . wp_kses_post( $field['desc'] ) . '</p>';
		}
		echo '</td></tr>';
	}

	/** An empty secret field keeps the saved secret. */
	public static function keep_secret( $value, $option, $raw ) {
		$value = trim( (string) $value );
		return '' !== $value ? $value : (string) give_get_option( $option['id'], '' );
	}

	public static function log( $message ) {
		if ( 'on' === give_get_option( 'arionpay_debug' ) && function_exists( 'give_record_log' ) ) {
			give_record_log( 'ArionPay', $message, 0, 'gateway_error' );
		}
	}

	/** Atomic per-donation lock: the insert itself is the lock. */
	public static function lock( $id ) {
		global $wpdb;
		$name = 'arionpay_give_lock_' . (int) $id;
		for ( $try = 0; $try < 8; $try++ ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, (string) time() ) ) ) {
				return true;
			}
			$since = (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( $since > 0 && $since < time() - 60 ) {
				self::unlock( $id );
				continue;
			}
			sleep( 1 );
		}
		return false;
	}

	public static function unlock( $id ) {
		global $wpdb;
		$wpdb->delete( $wpdb->options, array( 'option_name' => 'arionpay_give_lock_' . (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}

Plugin::init();
