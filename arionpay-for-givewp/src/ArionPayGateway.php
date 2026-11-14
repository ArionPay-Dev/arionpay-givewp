<?php
/**
 * ArionPay gateway for GiveWP 3.x and 4.x (visual form builder and legacy forms).
 *
 * The donor confirms the donation and goes to the ArionPay payment page. Only a signed ArionPay webhook
 * (invoice.paid) completes the donation. Whether an invoice is paid is decided by ArionPay alone.
 *
 * @license MIT
 */

namespace ArionPay\GiveWP;

use Give\Donations\Models\Donation;
use Give\Donations\Models\DonationNote;
use Give\Donations\ValueObjects\DonationStatus;
use Give\Framework\Exceptions\Primitives\Exception;
use Give\Framework\PaymentGateways\Commands\PaymentRefunded;
use Give\Framework\PaymentGateways\Commands\RedirectOffsite;
use Give\Framework\PaymentGateways\PaymentGateway;

defined( 'ABSPATH' ) || exit;

class ArionPayGateway extends PaymentGateway {

	const CURRENCIES = array( 'USD', 'EUR', 'GBP', 'CAD', 'AUD', 'CHF', 'JPY', 'TRY', 'AED', 'INR', 'BRL', 'RUB', 'CNY', 'SGD', 'HKD', 'KRW', 'MXN', 'PLN', 'SEK', 'NOK', 'ZAR' );
	const META_INVOICES = '_arionpay_invoices';
	const META_EVENTS   = '_arionpay_events';

	/** Webhook: ?give-listener=give-gateway&give-gateway-id=arionpay&give-gateway-method=arionpayWebhook */
	public $routeMethods = array( 'arionpayWebhook' );

	public static function id(): string {
		return 'arionpay';
	}

	public function getId(): string {
		return self::id();
	}

	public function getName(): string {
		return __( 'ArionPay (crypto)', 'arionpay-for-givewp' );
	}

	public function getPaymentMethodLabel(): string {
		$title = Plugin::option( 'checkout_title' );
		return '' !== $title ? $title : __( 'Crypto (Bitcoin, USDT and more)', 'arionpay-for-givewp' );
	}

	public function formSettings( int $formId ): array {
		return array(
			'message' => __( 'After you donate, you go to the secure ArionPay page to choose a coin and pay. Your donation is confirmed as soon as the payment arrives.', 'arionpay-for-givewp' )
				. ( give_is_test_mode() ? ' ' . __( 'Test mode: no real money is taken.', 'arionpay-for-givewp' ) : '' ),
		);
	}

	/** Visual form builder (v3) forms. */
	public function enqueueScript( int $formId ) {
		wp_enqueue_script( 'arionpay-givewp', plugins_url( 'assets/gateway.js', ARIONPAY_GIVEWP_FILE ), array( 'react' ), ARIONPAY_GIVEWP_VERSION, true );
	}

	/** Legacy (v2) forms. */
	public function getLegacyFormFieldMarkup( int $formId, array $args ): string {
		return '<fieldset class="no-fields"><p>' . esc_html( $this->formSettings( $formId )['message'] ) . '</p></fieldset>';
	}

	/**
	 * @param Donation $donation
	 * @param array    $gatewayData
	 * @return RedirectOffsite
	 * @throws Exception
	 */
	public function createPayment( Donation $donation, $gatewayData ) {
		$currency = strtoupper( $donation->amount->getCurrency()->getCode() );
		if ( ! in_array( $currency, self::CURRENCIES, true ) ) {
			throw new Exception( sprintf( 'ArionPay cannot take donations in %s.', $currency ) );
		}
		if ( ! Plugin::is_configured() ) {
			throw new Exception( 'ArionPay is not set up yet.' );
		}
		$url = function ( $key ) use ( $gatewayData ) {
			$v = isset( $gatewayData[ $key ] ) ? (string) $gatewayData[ $key ] : '';
			return false !== strpos( $v, '%3A' ) ? rawurldecode( $v ) : $v; // v3 forms pass the URLs encoded
		};
		$amount = number_format( (float) $donation->amount->formatToDecimal(), 2, '.', '' );
		$test   = $donation->mode->isTest();
		$list   = self::json_meta( $donation->id, self::META_INVOICES );
		$site   = substr( hash( 'sha256', home_url() . '|' . DB_NAME ), 0, 12 );
		try {
			$invoice = Plugin::client()->createInvoice(
				array(
					'amount'         => $amount,
					'currency'       => $currency,
					'orderId'        => (string) $donation->id,
					'description'    => mb_substr( sprintf( 'Donation #%s: %s', $donation->id, $donation->formTitle ), 0, 500 ),
					'successUrl'     => $url( 'successUrl' ) ? $url( 'successUrl' ) : home_url( '/' ),
					'cancelUrl'      => $url( 'cancelUrl' ) ? $url( 'cancelUrl' ) : home_url( '/' ),
					'callbackUrl'    => $this->generateGatewayRouteUrl( 'arionpayWebhook' ),
					'metadata'       => array(
						'give_donation_id'  => (int) $donation->id,
						'give_purchase_key' => (string) $donation->purchaseKey,
						'platform'          => 'givewp',
					),
					'idempotencyKey' => 'givewp-' . $site . '-' . $donation->id . '-' . ( count( $list ) + 1 ),
					'test'           => $test,
				)
			);
		} catch ( \Throwable $e ) {
			Plugin::log( 'Could not create an ArionPay invoice for donation ' . $donation->id . ': ' . $e->getMessage() );
			throw new Exception( __( 'The crypto payment could not be started right now. Please try again in a minute.', 'arionpay-for-givewp' ) );
		}
		$list[] = array( 'id' => $invoice['id'], 'amount' => $amount, 'currency' => $currency, 'test' => $test );
		give_update_meta( $donation->id, self::META_INVOICES, wp_json_encode( $list ) );
		$this->note( $donation->id, ( $test ? '[TEST - no real money] ' : '' ) . sprintf( 'Donor sent to ArionPay to pay invoice %s (%s %s).', $invoice['id'], $amount, $currency ) );
		return new RedirectOffsite( $invoice['invoiceUrl'] );
	}

	public function refundDonation( Donation $donation ): PaymentRefunded {
		throw new Exception( __( 'Crypto donations are refunded from your own wallet. Mark the donation refunded by hand after you send the money back.', 'arionpay-for-givewp' ) );
	}

	public function supportsRefund(): bool {
		return false;
	}

	// ------------------------------------------------------------------ webhook

	/**
	 * Signed ArionPay events. Never changes a donation unless the signature is valid; anything that is not 2xx is
	 * delivered again by ArionPay for about 3 days.
	 */
	protected function arionpayWebhook( array $queryParams ) {
		$secret = Plugin::option( 'webhook_secret' );
		if ( '' === $secret ) {
			$this->respond( 503, array( 'error' => 'not_configured' ) );
		}
		$raw = file_get_contents( 'php://input', false, null, 0, 262145 );
		if ( false === $raw || strlen( $raw ) > 262144 ) {
			$this->respond( 413, array( 'error' => 'payload_too_large' ) );
		}
		$header = isset( $_SERVER['HTTP_ARIONPAY_SIGNATURE'] ) ? (string) wp_unslash( $_SERVER['HTTP_ARIONPAY_SIGNATURE'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- verified below
		try {
			$event = \ArionPay\Webhook::verify( $raw, $header, $secret );
		} catch ( \ArionPay\SignatureException $e ) {
			Plugin::log( 'Webhook refused: ' . $e->getMessage() );
			$this->respond( 401, array( 'error' => 'invalid_signature' ) );
		}
		if ( \ArionPay\Webhook::isTestEvent( $event ) ) {
			$this->respond( 200, array( 'received' => true, 'test_event' => true ) );
		}
		if ( ! in_array( $event['type'], \ArionPay\Webhook::EVENT_TYPES, true ) ) {
			$this->respond( 200, array( 'received' => true, 'ignored' => 'unknown_event' ) );
		}
		$remote   = $event['data']['invoice'];
		$metadata = isset( $remote['metadata'] ) && is_array( $remote['metadata'] ) ? $remote['metadata'] : array();
		$id       = isset( $metadata['give_donation_id'] ) ? (int) $metadata['give_donation_id'] : 0;
		if ( $id <= 0 ) {
			$this->respond( 200, array( 'received' => true, 'ignored' => 'not_a_givewp_invoice' ) );
		}
		if ( ! Plugin::lock( $id ) ) {
			header( 'Retry-After: 30' );
			$this->respond( 503, array( 'error' => 'busy' ) );
		}
		try {
			$result = $this->apply( $id, $event, $remote, $metadata );
		} finally {
			Plugin::unlock( $id );
		}
		Plugin::log( 'Webhook ' . $event['type'] . ' for invoice ' . ( isset( $remote['id'] ) ? $remote['id'] : '?' ) . ': ' . $result . '.' );
		$codes = array( 'mismatch' => 409, 'not_found' => 404 );
		$this->respond( isset( $codes[ $result ] ) ? $codes[ $result ] : 200, array( 'received' => ! isset( $codes[ $result ] ), 'result' => $result ) );
	}

	private function apply( $id, array $event, array $remote, array $metadata ) {
		$donation = Donation::find( $id );
		if ( ! $donation ) {
			return 'not_found';
		}
		$invoiceId = isset( $remote['id'] ) ? (string) $remote['id'] : '';
		$list      = self::json_meta( $id, self::META_INVOICES );
		$mine      = wp_list_pluck( $list, 'id' );
		if ( ! in_array( $invoiceId, $mine, true ) || ! hash_equals( (string) $donation->purchaseKey, (string) ( isset( $metadata['give_purchase_key'] ) ? $metadata['give_purchase_key'] : '' ) )
			|| (string) ( isset( $remote['orderId'] ) ? $remote['orderId'] : '' ) !== (string) $id
			|| (bool) ( isset( $remote['test'] ) ? $remote['test'] : false ) !== $donation->mode->isTest() ) {
			return 'mismatch';
		}
		$seen = self::json_meta( $id, self::META_EVENTS );
		if ( in_array( $event['id'], $seen, true ) ) {
			return 'duplicate';
		}
		$complete = $donation->status->isComplete();
		$latest   = end( $list );
		$current  = is_array( $latest ) && $latest['id'] === $invoiceId;

		switch ( $event['type'] ) {
			case 'invoice.paid':
				$this->on_paid( $donation, $remote, $complete );
				break;
			case 'invoice.pending':
				if ( $current && ! $complete ) {
					$this->note( $id, $this->text( 'ArionPay invoice {id}: {paid} {asset} was seen on the network and is waiting for confirmations.', $remote ) );
				}
				break;
			case 'invoice.partial':
				if ( $current && ! $complete ) {
					$this->note( $id, $this->text( 'ArionPay invoice {id}: partial payment of {paid} of {due} {asset}. The invoice stays open for the rest.', $remote ) );
				}
				break;
			case 'invoice.expired':
				if ( $current && ! $complete ) {
					$this->note( $id, $this->text( 'ArionPay invoice {id} expired without full payment. The donation stays pending; a late payment can still arrive and complete it.', $remote ) );
				}
				break;
			case 'invoice.needs_review':
				$this->note( $id, $this->text( 'ArionPay invoice {id} needs your review in the ArionPay dashboard: {reason}', $remote ) );
				break;
		}
		$seen[] = (string) $event['id'];
		give_update_meta( $id, self::META_EVENTS, wp_json_encode( array_slice( $seen, -40 ) ) );
		return 'processed';
	}

	private function on_paid( Donation $donation, array $remote, $complete ) {
		if ( $complete ) {
			$this->note( $donation->id, $this->text( 'ArionPay reports invoice {id} as paid, but this donation is already complete. If it was paid another way, this may be a double payment: check your wallet.', $remote ) );
			return;
		}
		if ( $donation->status->isRefunded() ) {
			$this->note( $donation->id, $this->text( 'ArionPay reports invoice {id} as paid, but this donation is refunded. It was not changed.', $remote ) );
			return;
		}
		$expected = number_format( (float) $donation->amount->formatToDecimal(), 2, '.', '' );
		$paid     = isset( $remote['amountFiat'] ) && is_numeric( $remote['amountFiat'] ) ? number_format( (float) $remote['amountFiat'], 2, '.', '' ) : '';
		$currency = strtoupper( isset( $remote['currency'] ) ? (string) $remote['currency'] : '' );
		if ( $currency !== strtoupper( $donation->amount->getCurrency()->getCode() ) || $paid !== $expected ) {
			$this->note( $donation->id, sprintf( 'ArionPay invoice %s is paid (%s %s), but that does not match this donation (%s %s), so it was NOT completed. Please check it.', $remote['id'], '' !== $paid ? $paid : '?', $currency, $expected, $donation->amount->getCurrency()->getCode() ) );
			return;
		}
		$txid = '';
		if ( ! empty( $remote['transactions'] ) && is_array( $remote['transactions'] ) ) {
			$last = end( $remote['transactions'] );
			$txid = is_array( $last ) && isset( $last['txid'] ) && is_scalar( $last['txid'] ) ? (string) $last['txid'] : '';
		}
		$donation->status               = DonationStatus::COMPLETE();
		$donation->gatewayTransactionId = '' !== $txid ? $txid : (string) $remote['id'];
		$donation->save();
		$parts = array( $this->text( 'ArionPay invoice {id} is paid: {paid} {asset} received on {network}. Transaction: {tx}.', $remote ) );
		if ( ! empty( $remote['late'] ) ) {
			$parts[] = 'Paid late: the payment arrived after the invoice expired.';
		}
		if ( ! empty( $remote['manual'] ) ) {
			$parts[] = 'You accepted this payment by hand in the ArionPay dashboard.';
		}
		if ( ! empty( $remote['underpaidWithinTolerance'] ) ) {
			$parts[] = 'The donor paid slightly less, and ArionPay accepted it under your store\'s payment tolerance.';
		}
		if ( ! empty( $remote['overpaid'] ) ) {
			$parts[] = 'The donor paid more than the invoice amount.';
		}
		$this->note( $donation->id, implode( ' ', $parts ) );
	}

	// ------------------------------------------------------------------ helpers

	private function note( $id, $text ) {
		DonationNote::create( array( 'donationId' => (int) $id, 'content' => $text ) );
	}

	private function text( $template, array $remote ) {
		$s  = function ( $k ) use ( $remote ) {
			return isset( $remote[ $k ] ) && is_scalar( $remote[ $k ] ) ? mb_substr( wp_strip_all_tags( (string) $remote[ $k ] ), 0, 300 ) : '';
		};
		$tx = '';
		if ( ! empty( $remote['transactions'] ) && is_array( $remote['transactions'] ) ) {
			$last = end( $remote['transactions'] );
			$tx   = is_array( $last ) && isset( $last['txid'] ) && is_scalar( $last['txid'] ) ? (string) $last['txid'] : '';
		}
		$text = strtr(
			$template,
			array(
				'{id}'      => $s( 'id' ),
				'{paid}'    => $s( 'paidCrypto' ),
				'{due}'     => $s( 'amountCrypto' ),
				'{asset}'   => $s( 'asset' ),
				'{network}' => $s( 'network' ),
				'{reason}'  => '' !== $s( 'reviewReason' ) ? $s( 'reviewReason' ) : '-',
				'{tx}'      => '' !== $tx ? $tx : '-',
			)
		);
		return ( ! empty( $remote['test'] ) ? '[TEST - no real money] ' : '' ) . $text;
	}

	private static function json_meta( $id, $key ) {
		$list = json_decode( (string) give_get_meta( $id, $key, true ), true );
		return is_array( $list ) ? array_values( $list ) : array();
	}

	private function respond( $status, array $body ) {
		status_header( $status );
		header( 'Content-Type: application/json; charset=utf-8' );
		echo wp_json_encode( $body );
		exit;
	}
}
