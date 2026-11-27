<?php
/**
 * Kelviq: hosted checkout, customer portal and entitlements over the Kelviq REST API.
 *
 * There is no official PHP SDK, so this mirrors @kelviq/node-sdk over raw HTTP:
 * - Config comes from wp-config.php constants or the environment: KELVIQ_SERVER_API_KEY,
 *   KELVIQ_ENV (production|sandbox) and KELVIQ_WEBHOOK_SECRET; otherwise from Settings → Kelviq
 *   (keys kept in a non-autoloaded option and never shown again, like Lemon Squeezy).
 * - The Kelviq customerId is the signed-in WordPress user ID, always read on the server.
 * - Plans, prices and coupons live in WooCommerce only. Kelviq has one shared one-time plan
 *   (Settings → Kelviq plan identifier); each checkout sends the plan's USD price, less the
 *   coupon, as customAmount. Renewals are new payments, like the WooCommerce flow.
 * - Webhooks are verified (webhook-id.webhook-timestamp.raw-body, HMAC-SHA256, v1,<hex>),
 *   handled once per event id, and answered 403 when the signature is wrong.
 *
 * @package WPEssential\Server
 */

namespace WPEssential\Server;

defined( 'ABSPATH' ) || exit;

/**
 * Kelviq provider.
 */
class Kelviq {

	const HOSTS = array(
		'production' => array( 'https://api.kelviq.com/api/v1', 'https://edge.api.kelviq.com/api/v1' ),
		'sandbox'    => array( 'https://sandboxapi.kelviq.com/api/v1', 'https://edge.sandboxapi.kelviq.com/api/v1' ),
	);

	const SECRETS = 'wpes_kq_secrets';

	/** Accepted webhook clock skew, in seconds. */
	const TOLERANCE = 300;

	/** Events this endpoint subscribes to. */
	const EVENTS = array( 'checkout.completed', 'invoice.payment_failed', 'subscription.created', 'subscription.updated', 'subscription.plan_changed', 'subscription.cancelled' );

	// ---------------------------------------------------------------- config

	/**
	 * A config value from a wp-config.php constant, else the environment.
	 *
	 * @param string $name Variable name.
	 * @return string
	 */
	public static function config( $name ) {
		if ( defined( $name ) ) {
			return (string) constant( $name );
		}
		$value = getenv( $name );
		if ( false !== $value && '' !== $value ) {
			return (string) $value;
		}
		$saved = get_option( self::SECRETS, array() );
		$saved = is_array( $saved ) ? $saved : array();
		switch ( $name ) {
			case 'KELVIQ_SERVER_API_KEY':
				return (string) ( $saved['api_key'] ?? '' );
			case 'KELVIQ_WEBHOOK_SECRET':
				return (string) ( $saved['secret'] ?? '' );
			case 'KELVIQ_ENV':
				return Plugin::settings( 'kq_live' ) ? 'production' : 'sandbox';
		}
		return '';
	}

	/**
	 * Saved secrets (for the settings screen: set or not).
	 *
	 * @return array{api_key:string,secret:string}
	 */
	public static function secrets() {
		$s = get_option( self::SECRETS, array() );
		return array(
			'api_key' => is_array( $s ) && is_string( $s['api_key'] ?? null ) ? $s['api_key'] : '',
			'secret'  => is_array( $s ) && is_string( $s['secret'] ?? null ) ? $s['secret'] : '',
		);
	}

	/**
	 * Environment, like environmentFromEnv(): sandbox when unset, error on anything unknown.
	 *
	 * @param string $value KELVIQ_ENV.
	 * @return string|null production|sandbox, or null when the value is invalid.
	 */
	public static function environment( $value ) {
		$value = strtolower( trim( (string) $value ) );
		if ( '' === $value ) {
			return 'sandbox';
		}
		return isset( self::HOSTS[ $value ] ) ? $value : null;
	}

	/**
	 * Configured with a server key and a valid environment.
	 *
	 * @return bool
	 */
	public static function enabled() {
		return (bool) Plugin::settings( 'kq_enabled' ) && '' !== (string) Plugin::settings( 'kq_plan' ) && '' !== self::config( 'KELVIQ_SERVER_API_KEY' ) && null !== self::environment( self::config( 'KELVIQ_ENV' ) );
	}

	// ---------------------------------------------------------------- pure helpers (unit-tested)

	/**
	 * Amount after a WooCommerce coupon (pure, unit-tested).
	 *
	 * @param float  $price  Plan price.
	 * @param string $type   percent|fixed_cart|fixed_product ('' for none).
	 * @param float  $amount Coupon amount.
	 * @return float Never below zero, rounded to cents.
	 */
	public static function discounted( $price, $type, $amount ) {
		if ( 'percent' === $type ) {
			$price -= $price * min( 100, max( 0, (float) $amount ) ) / 100;
		} elseif ( in_array( $type, array( 'fixed_cart', 'fixed_product' ), true ) ) {
			$price -= max( 0, (float) $amount );
		}
		return round( max( 0, (float) $price ), 2 );
	}

	/**
	 * Valid webhook signature (Standard Webhooks style).
	 *
	 * @param string $id        webhook-id header.
	 * @param string $timestamp webhook-timestamp header (seconds).
	 * @param string $body      Raw body, exactly as received.
	 * @param string $signature webhook-signature header: "v1,<hex>" (several may be space-separated).
	 * @param string $secret    Signing secret.
	 * @param int    $now       Current time.
	 * @return bool
	 */
	public static function valid_signature( $id, $timestamp, $body, $signature, $secret, $now ) {
		if ( '' === (string) $secret || '' === (string) $id || ! ctype_digit( (string) $timestamp ) || abs( (int) $now - (int) $timestamp ) > self::TOLERANCE ) {
			return false;
		}
		$expected = hash_hmac( 'sha256', $id . '.' . $timestamp . '.' . $body, (string) $secret );
		foreach ( preg_split( '/\s+/', trim( (string) $signature ) ) as $part ) {
			$pair = explode( ',', $part, 2 );
			if ( 2 === count( $pair ) && 'v1' === $pair[0] && hash_equals( $expected, strtolower( $pair[1] ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Access from an entitlements response: any matching feature with hasAccess.
	 *
	 * @param array  $response GET /entitlements/ body.
	 * @param string $feature  Feature identifier.
	 * @return bool
	 */
	public static function access_in( $response, $feature ) {
		foreach ( (array) ( $response['entitlements'] ?? array() ) as $row ) {
			if ( is_array( $row ) && (string) ( $row['featureId'] ?? '' ) === $feature && true === ( $row['hasAccess'] ?? false ) ) {
				return true;
			}
		}
		return false;
	}

	// ---------------------------------------------------------------- HTTP

	/**
	 * One API call.
	 *
	 * @param string     $method GET|POST.
	 * @param string     $path   Path with leading and trailing slash.
	 * @param array|null $body   JSON body (POST) or query (GET).
	 * @param bool       $edge   Edge API (entitlements) instead of Core.
	 * @return array{status:int,body:array}|\WP_Error
	 */
	private static function request( $method, $path, $body = null, $edge = false ) {
		$env = self::environment( self::config( 'KELVIQ_ENV' ) );
		$key = self::config( 'KELVIQ_SERVER_API_KEY' );
		if ( null === $env || '' === $key ) {
			return new \WP_Error( 'kelviq_config', __( 'Kelviq billing is not configured.', 'wpessential-server' ) );
		}
		$url  = self::HOSTS[ $env ][ $edge ? 1 : 0 ] . $path;
		$args = array(
			'method'  => $method,
			'timeout' => 15,
			'headers' => array(
				'Authorization' => 'Bearer ' . $key,
				'Accept'        => 'application/json',
				'Content-Type'  => 'application/json',
			),
		);
		if ( 'GET' === $method && $body ) {
			$url = add_query_arg( array_map( 'rawurlencode', $body ), $url );
		} elseif ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}
		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		return array(
			'status' => (int) wp_remote_retrieve_response_code( $response ),
			'body'   => is_array( $decoded ) ? $decoded : array(),
		);
	}

	/**
	 * Make sure the user exists in Kelviq with an email (the portal needs one).
	 *
	 * @param int $user_id WordPress user.
	 * @return true|\WP_Error
	 */
	public static function ensure_customer( $user_id ) {
		$user = get_userdata( (int) $user_id );
		if ( ! $user || '' === (string) $user->user_email ) {
			return new \WP_Error( 'kelviq_customer', __( 'Add an email address to your account first.', 'wpessential-server' ) );
		}
		$result = self::request(
			'POST',
			'/customers/',
			array(
				'customerId' => (string) $user->ID,
				'email'      => (string) $user->user_email,
				'name'       => (string) $user->display_name,
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		// 201 created; 400/409 when the customerId already exists — both mean it is there.
		return $result['status'] < 500 ? true : new \WP_Error( 'kelviq_down', __( 'Billing is unavailable right now. Try again in a minute.', 'wpessential-server' ) );
	}

	// ---------------------------------------------------------------- checkout / portal / entitlements

	/**
	 * Hosted checkout URL for one of our plan keys.
	 *
	 * @param int    $user_id    Buyer (signed-in user, from the server).
	 * @param array  $plan_row   Plan from Plans::from_product() (key e.g. pro_yearly).
	 * @param int    $license_id License to renew/upgrade (0 for a new one).
	 * @param string $mode       buy|renew|upgrade.
	 * @param string $coupon     WooCommerce coupon code (optional; must be synced to Kelviq).
	 * @return string|\WP_Error
	 */
	public static function checkout_url( $user_id, $plan_row, $license_id = 0, $mode = 'buy', $coupon = '' ) {
		$key   = (string) ( $plan_row['key'] ?? '' );
		$price = (float) ( '' !== (string) ( $plan_row['usd_price'] ?? '' ) ? $plan_row['usd_price'] : ( $plan_row['price'] ?? 0 ) );
		if ( '' === $key || $price <= 0 ) {
			return new \WP_Error( 'plan', __( 'That plan has no USD price for card checkout.', 'wpessential-server' ) );
		}
		$used = '';
		if ( '' !== (string) $coupon ) {
			$wc = self::usable_coupon( (string) $coupon, (int) ( $plan_row['product_id'] ?? 0 ) );
			if ( null === $wc ) {
				return new \WP_Error( 'coupon', __( 'That coupon is not valid for this plan.', 'wpessential-server' ) );
			}
			$price = self::discounted( $price, $wc->get_discount_type(), (float) $wc->get_amount() );
			$used  = $wc->get_code();
		}
		if ( $price <= 0 ) {
			return new \WP_Error( 'coupon', __( 'This coupon makes the plan free; ask support to apply it.', 'wpessential-server' ) );
		}
		$customer = self::ensure_customer( $user_id );
		if ( is_wp_error( $customer ) ) {
			return $customer;
		}
		$result = self::request(
			'POST',
			'/checkout/',
			array(
				'planIdentifier'   => (string) Plugin::settings( 'kq_plan' ),
				'chargePeriod'     => 'ONE_TIME',
				'customAmount'     => $price,
				'currencyCode'     => 'USD',
				'taxBehavior'      => Plugin::settings( 'kq_tax_inclusive' ) ? 'INCLUSIVE' : 'EXCLUSIVE',
				'discountsEnabled' => false,
				'customerId'       => (string) (int) $user_id,
				'successUrl'       => self::success_url(),
				'metadata'         => array(
					'wpes_user'    => (string) (int) $user_id,
					'wpes_plan'    => (string) $key,
					'wpes_license' => (string) (int) $license_id,
					'wpes_mode'    => in_array( $mode, array( 'renew', 'upgrade' ), true ) ? $mode : 'buy',
					'wpes_coupon'  => $used,
				),
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$url = (string) ( $result['body']['checkoutUrl'] ?? '' );
		if ( $result['status'] >= 300 || ! wp_http_validate_url( $url ) ) {
			Events::error( 'kelviq.checkout', 'Checkout session failed', array( 'status' => $result['status'] ) );
			return new \WP_Error( 'kelviq_checkout', __( 'Checkout could not start. Try again in a minute.', 'wpessential-server' ) );
		}
		return $url;
	}

	/**
	 * Absolute return URL after checkout (filterable; defaults to the account page).
	 *
	 * @return string
	 */
	public static function success_url() {
		$url = self::config( 'KELVIQ_SUCCESS_URL' );
		if ( '' === $url ) {
			$url = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : home_url( '/' );
		}
		return (string) apply_filters( 'wpes_kelviq_success_url', add_query_arg( 'wpes_checkout', 'done', $url ) );
	}

	/**
	 * Billing portal link for the signed-in user.
	 *
	 * @param int $user_id User.
	 * @return string|\WP_Error
	 */
	public static function portal_url( $user_id ) {
		$customer = self::ensure_customer( $user_id );
		if ( is_wp_error( $customer ) ) {
			return $customer;
		}
		$result = self::request( 'POST', '/portal/session/', array( 'customerId' => (string) (int) $user_id ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( 400 === $result['status'] ) {
			return new \WP_Error( 'kelviq_no_customer', __( 'There is no card billing on this account yet.', 'wpessential-server' ) );
		}
		$base  = (string) ( $result['body']['customerPortalUrl'] ?? '' );
		$token = (string) ( $result['body']['token'] ?? '' );
		if ( $result['status'] >= 300 || '' === $base || '' === $token ) {
			return new \WP_Error( 'kelviq_portal', __( 'Billing is unavailable right now. Try again in a minute.', 'wpessential-server' ) );
		}
		return add_query_arg( 'token', rawurlencode( $token ), $base );
	}

	/**
	 * Whether a customer has a feature. Fails closed: any error means no access.
	 *
	 * @param int    $user_id User.
	 * @param string $feature Feature identifier.
	 * @return bool
	 */
	public static function has_feature( $user_id, $feature ) {
		$result = self::request(
			'GET',
			'/entitlements/',
			array(
				'customer_id' => (string) (int) $user_id,
				'feature_id'  => (string) $feature,
			),
			true
		);
		if ( is_wp_error( $result ) || 200 !== $result['status'] ) {
			return false;
		}
		return self::access_in( $result['body'], (string) $feature );
	}

	// ---------------------------------------------------------------- WooCommerce coupons

	/**
	 * A WooCommerce coupon this customer may use on this plan, or null.
	 * Checks what a cart would: published, not expired, usage left, product restrictions.
	 *
	 * @param string $code       Coupon code.
	 * @param int    $product_id Plan product.
	 * @return \WC_Coupon|null
	 */
	private static function usable_coupon( $code, $product_id ) {
		if ( ! class_exists( 'WC_Coupon' ) || ! function_exists( 'wc_get_coupon_id_by_code' ) ) {
			return null;
		}
		$id = (int) wc_get_coupon_id_by_code( $code );
		if ( ! $id || 'publish' !== get_post_status( $id ) ) {
			return null;
		}
		$coupon  = new \WC_Coupon( $id );
		$expires = $coupon->get_date_expires();
		$limit   = (int) $coupon->get_usage_limit();
		$only    = array_map( 'intval', (array) $coupon->get_product_ids() );
		if ( ( $expires && $expires->getTimestamp() <= time() )
			|| ( $limit > 0 && (int) $coupon->get_usage_count() >= $limit )
			|| ( $only && ! in_array( $product_id, $only, true ) )
			|| in_array( $product_id, array_map( 'intval', (array) $coupon->get_excluded_product_ids() ), true )
			|| ! in_array( $coupon->get_discount_type(), array( 'percent', 'fixed_cart', 'fixed_product' ), true ) ) {
			return null;
		}
		return $coupon;
	}

	// ---------------------------------------------------------------- webhooks

	/**
	 * Webhook endpoint.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function webhook( $request ) {
		$body = (string) $request->get_body();
		$id   = (string) $request->get_header( 'webhook-id' );
		if ( ! self::valid_signature( $id, (string) $request->get_header( 'webhook-timestamp' ), $body, (string) $request->get_header( 'webhook-signature' ), self::config( 'KELVIQ_WEBHOOK_SECRET' ), time() ) ) {
			return new \WP_REST_Response( array( 'success' => false ), 403 );
		}
		$event = json_decode( $body, true );
		if ( ! is_array( $event ) ) {
			return new \WP_REST_Response( array( 'success' => false ), 400 );
		}
		$claim = 'wpes_kq_seen_' . md5( (string) ( $event['id'] ?? $id ) );
		if ( ! add_option( $claim, time(), '', 'no' ) ) {
			return new \WP_REST_Response( array( 'success' => true ), 200 ); // Duplicate delivery.
		}
		Events::begin( 'kelviq' );
		try {
			self::dispatch( (string) ( $event['type'] ?? '' ), is_array( $event['data']['object'] ?? null ) ? $event['data']['object'] : array() );
		} catch ( \Throwable $e ) {
			delete_option( $claim ); // Let Kelviq retry.
			Events::error( 'kelviq.webhook_exception', $e->getMessage(), array( 'event' => (string) ( $event['type'] ?? '' ) ) );
			return new \WP_REST_Response( array( 'success' => false ), 500 );
		}
		return new \WP_REST_Response( array( 'success' => true ), 200 );
	}

	/**
	 * Purchase intent from checkout metadata (pure, unit-tested). The metadata is written by
	 * checkout_url() on the server, and the paying customer must be the same user.
	 *
	 * @param array $item data.object of checkout.completed.
	 * @return array{user:int,plan:string,renew:int,upgrade:int}|null
	 */
	public static function intent( array $item ) {
		$meta     = is_array( $item['metadata'] ?? null ) ? $item['metadata'] : array();
		$user     = (int) ( $meta['wpes_user'] ?? 0 );
		$plan     = (string) ( $meta['wpes_plan'] ?? '' );
		$customer = (string) ( $item['customer_id'] ?? $item['customerId'] ?? $item['customer']['customer_id'] ?? '' );
		if ( $user <= 0 || '' === $plan || ( '' !== $customer && (string) $user !== $customer ) ) {
			return null;
		}
		$license = (int) ( $meta['wpes_license'] ?? 0 );
		$mode    = (string) ( $meta['wpes_mode'] ?? 'buy' );
		return array(
			'user'    => $user,
			'plan'    => $plan,
			'renew'   => 'renew' === $mode ? $license : 0,
			'upgrade' => 'upgrade' === $mode ? $license : 0,
		);
	}

	/**
	 * A paid checkout: create, renew or upgrade the license (the same core as WooCommerce orders).
	 *
	 * @param array $item data.object.
	 */
	private static function checkout_completed( array $item ) {
		$ref    = 'kq:' . (string) ( $item['id'] ?? '' );
		$intent = self::intent( $item );
		$plan   = $intent ? Plans::from_product( Plans::product_id( $intent['plan'] ) ) : null;
		if ( ! $intent || ! $plan || ! get_userdata( $intent['user'] ) ) {
			Events::error( 'kelviq.checkout_unmatched', sprintf( 'Kelviq checkout %s has no matching buyer or plan.', $ref ), array() );
			return;
		}
		foreach ( array( 'renew', 'upgrade' ) as $k ) {
			$row = $intent[ $k ] ? Licenses::get( $intent[ $k ] ) : null;
			if ( $intent[ $k ] && ( ! $row || (int) $row['user_id'] !== $intent['user'] ) ) {
				$intent[ $k ] = 0; // Not the buyer's license: create a new one instead.
			}
		}
		Fulfilment::apply( $intent['user'], $plan, $intent['renew'], $intent['upgrade'], 0, $ref );
		$code = (string) ( $item['metadata']['wpes_coupon'] ?? '' );
		if ( '' !== $code && class_exists( 'WC_Coupon' ) ) {
			( new \WC_Coupon( $code ) )->increase_usage_count( '', null );
		}
	}

	/**
	 * Route one event.
	 *
	 * @param string $type   Event type.
	 * @param array  $item data.object.
	 */
	private static function dispatch( $type, array $item ) {
		switch ( $type ) {
			case 'checkout.completed':
				self::checkout_completed( $item );
				break;
			case 'invoice.payment_failed':
				// TODO: a renewal failed — notify the customer and start the grace period (Renewals).
				break;
			case 'subscription.created':
				// TODO: remember the Kelviq subscription id on the license (like wpes_ls_sub_<license>).
				break;
			case 'subscription.updated':
				// TODO: sync auto-renew / period end on the license.
				break;
			case 'subscription.plan_changed':
				// TODO: change the license tier (sites, white-label) to the new plan.
				break;
			case 'subscription.cancelled':
				// TODO: the subscription has ended (not just scheduled to) — expire the license; never delete data.
				break;
		}
		do_action( 'wpes_kelviq_event', $type, $item );
	}
}
