<?php
/**
 * Service quotes, invoices and project status (WPEssential.org Figma 45–48).
 *
 * A quote is a private post. The customer opens it from a secret link, approves it, and pays the
 * first milestone as a WooCommerce order (the invoice), so every payment method of the store works.
 * The same link then shows the project's milestones.
 *
 * @package WPEssential\Server
 */

namespace WPEssential\Server;

defined( 'ABSPATH' ) || exit;

/**
 * Quotes.
 */
class Quotes {

	const TYPE  = 'wpes_quote';
	const QUERY = 'wpes_quote';

	/**
	 * Hooks.
	 */
	public static function hooks() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'boxes' ) );
		add_action( 'save_post_' . self::TYPE, array( __CLASS__, 'save' ), 10, 2 );
		add_filter( 'query_vars', array( __CLASS__, 'query_var' ) );
		add_action( 'template_redirect', array( __CLASS__, 'route' ) );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'paid' ) );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'paid' ) );
	}

	/**
	 * Post type: private, managed in wp-admin only.
	 */
	public static function register() {
		register_post_type(
			self::TYPE,
			array(
				'label'        => __( 'Service quotes', 'wpessential-server' ),
				'labels'       => array(
					'singular_name' => __( 'Service quote', 'wpessential-server' ),
					'add_new_item'  => __( 'New quote', 'wpessential-server' ),
				),
				'public'       => false,
				'show_ui'      => true,
				'show_in_menu' => 'woocommerce',
				'supports'     => array( 'title' ),
				'map_meta_cap' => true,
			)
		);
	}

	/**
	 * Fields of a quote and their defaults.
	 *
	 * @return array
	 */
	public static function fields() {
		return array(
			'email'      => '',
			'company'    => '',
			'valid'      => '',
			'items'      => '',
			'terms'      => '50% upfront, 50% on delivery',
			'deposit'    => 50,
			'milestones' => '',
			'start'      => '',
			'delivery'   => '',
			'status'     => 'awaiting',
			'note'       => '',
		);
	}

	/**
	 * One quote's data.
	 *
	 * @param int $id Quote.
	 * @return array
	 */
	public static function get( $id ) {
		$out = array();
		foreach ( self::fields() as $key => $default ) {
			$value       = get_post_meta( $id, '_wpes_q_' . $key, true );
			$out[ $key ] = '' === $value ? $default : $value;
		}
		$out['id']     = (int) $id;
		$out['title']  = get_the_title( $id );
		$out['number'] = sprintf( 'VSN-Q-%s-%03d', get_the_date( 'Y', $id ), $id );
		$out['lines']  = self::lines( (string) $out['items'], array( 'label', 'qty', 'amount' ) );
		$out['steps']  = self::lines( (string) $out['milestones'], array( 'label', 'state' ) );
		$out['total']  = self::total( $out['lines'] );
		$out['token']  = (string) get_post_meta( $id, '_wpes_q_token', true );
		$out['orders'] = array_filter( array_map( 'absint', (array) get_post_meta( $id, '_wpes_q_orders', true ) ) );
		return $out;
	}

	/**
	 * Sum of qty × amount.
	 *
	 * @param array $lines Rows from lines().
	 * @return float
	 */
	public static function total( array $lines ) {
		return (float) array_sum( array_map( static fn( $l ) => (float) $l['qty'] * (float) $l['amount'], $lines ) );
	}

	/**
	 * "a | b | c" lines to rows.
	 *
	 * @param string $text Lines.
	 * @param array  $keys Column keys.
	 * @return array
	 */
	public static function lines( $text, array $keys ) {
		$rows = array();
		foreach ( preg_split( '/\r?\n/', trim( $text ) ) as $line ) {
			$cells = array_map( 'trim', explode( '|', $line ) );
			if ( '' === $cells[0] ) {
				continue;
			}
			$rows[] = array_combine( $keys, array_pad( array_slice( $cells, 0, count( $keys ) ), count( $keys ), '' ) );
		}
		return $rows;
	}

	/**
	 * Customer link of a quote.
	 *
	 * @param array $q Quote.
	 * @return string
	 */
	public static function link( array $q ) {
		return add_query_arg( self::QUERY, $q['token'], home_url( '/' ) );
	}

	/**
	 * Edit screen box.
	 */
	public static function boxes() {
		add_meta_box( 'wpes-quote', __( 'Quote', 'wpessential-server' ), array( __CLASS__, 'box' ), self::TYPE, 'normal', 'high' );
	}

	/**
	 * Edit screen fields.
	 *
	 * @param \WP_Post $post Quote.
	 */
	public static function box( $post ) {
		$q      = self::get( $post->ID );
		$labels = array(
			'email'      => __( 'Customer email', 'wpessential-server' ),
			'company'    => __( 'Customer company', 'wpessential-server' ),
			'valid'      => __( 'Valid until (YYYY-MM-DD)', 'wpessential-server' ),
			'items'      => __( 'Scope: one line each, "Item | Qty | Amount"', 'wpessential-server' ),
			'terms'      => __( 'Payment terms', 'wpessential-server' ),
			'deposit'    => __( 'First invoice (% of total)', 'wpessential-server' ),
			'milestones' => __( 'Milestones: one line each, "Name | done / active / next"', 'wpessential-server' ),
			'start'      => __( 'Started (YYYY-MM-DD)', 'wpessential-server' ),
			'delivery'   => __( 'Estimated delivery (YYYY-MM-DD)', 'wpessential-server' ),
			'status'     => __( 'Status', 'wpessential-server' ),
		);
		wp_nonce_field( 'wpes_quote', 'wpes_quote_nonce' );
		echo '<table class="form-table">';
		foreach ( $labels as $key => $label ) {
			echo '<tr><th><label for="wpes_q_' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td>';
			if ( in_array( $key, array( 'items', 'milestones' ), true ) ) {
				echo '<textarea class="large-text code" rows="5" id="wpes_q_' . esc_attr( $key ) . '" name="wpes_q[' . esc_attr( $key ) . ']">' . esc_textarea( (string) $q[ $key ] ) . '</textarea>';
			} elseif ( 'status' === $key ) {
				echo '<select id="wpes_q_status" name="wpes_q[status]">';
				foreach ( self::statuses() as $value => $name ) {
					echo '<option value="' . esc_attr( $value ) . '"' . selected( $q['status'], $value, false ) . '>' . esc_html( $name ) . '</option>';
				}
				echo '</select>';
			} else {
				echo '<input class="regular-text" id="wpes_q_' . esc_attr( $key ) . '" name="wpes_q[' . esc_attr( $key ) . ']" value="' . esc_attr( (string) $q[ $key ] ) . '">';
			}
			echo '</td></tr>';
		}
		echo '<tr><th>' . esc_html__( 'Final invoice', 'wpessential-server' ) . '</th><td><label><input type="checkbox" name="wpes_q_final" value="1"> ' . esc_html__( 'Create the invoice for the remaining amount', 'wpessential-server' ) . '</label></td></tr>';
		echo '</table>';
		if ( $q['token'] ) {
			echo '<p>' . esc_html__( 'Customer link:', 'wpessential-server' ) . ' <code>' . esc_html( self::link( $q ) ) . '</code></p>';
		}
		if ( $q['note'] ) {
			echo '<p><strong>' . esc_html__( 'Change request from the customer:', 'wpessential-server' ) . '</strong> ' . esc_html( (string) $q['note'] ) . '</p>';
		}
	}

	/**
	 * Status names.
	 *
	 * @return array
	 */
	public static function statuses() {
		return array(
			'awaiting' => __( 'Awaiting approval', 'wpessential-server' ),
			'changes'  => __( 'Changes requested', 'wpessential-server' ),
			'approved' => __( 'Approved', 'wpessential-server' ),
			'active'   => __( 'In progress', 'wpessential-server' ),
			'done'     => __( 'Delivered', 'wpessential-server' ),
		);
	}

	/**
	 * Save the box.
	 *
	 * @param int      $id   Quote.
	 * @param \WP_Post $post Post.
	 */
	public static function save( $id, $post ) {
		if ( ! isset( $_POST['wpes_quote_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['wpes_quote_nonce'] ) ), 'wpes_quote' ) || ! current_user_can( 'edit_post', $id ) || wp_is_post_revision( $id ) ) {
			return;
		}
		$in = isset( $_POST['wpes_q'] ) ? (array) wp_unslash( $_POST['wpes_q'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized per field below.
		foreach ( array_keys( self::fields() ) as $key ) {
			if ( ! isset( $in[ $key ] ) ) {
				continue;
			}
			$value = in_array( $key, array( 'items', 'milestones' ), true ) ? sanitize_textarea_field( $in[ $key ] ) : sanitize_text_field( $in[ $key ] );
			if ( 'email' === $key ) {
				$value = sanitize_email( $value );
			} elseif ( 'deposit' === $key ) {
				$value = (string) min( 100, max( 1, absint( $value ) ) );
			} elseif ( 'status' === $key && ! isset( self::statuses()[ $value ] ) ) {
				$value = 'awaiting';
			}
			update_post_meta( $id, '_wpes_q_' . $key, $value );
		}
		if ( ! get_post_meta( $id, '_wpes_q_token', true ) ) {
			update_post_meta( $id, '_wpes_q_token', wp_generate_password( 32, false ) );
		}
		if ( ! empty( $_POST['wpes_q_final'] ) ) {
			$q    = self::get( $id );
			$left = $q['total'] - self::paid_total( $q );
			if ( $left > 0 ) {
				self::invoice( $q, $left, __( 'Final milestone', 'wpessential-server' ) );
			}
		}
		unset( $post );
	}

	/**
	 * Query var.
	 *
	 * @param array $vars Vars.
	 * @return array
	 */
	public static function query_var( $vars ) {
		$vars[] = self::QUERY;
		return $vars;
	}

	/**
	 * Find a quote by its token.
	 *
	 * @param string $token Token.
	 * @return array|null
	 */
	public static function by_token( $token ) {
		if ( strlen( $token ) < 20 ) {
			return null;
		}
		$ids = get_posts(
			array(
				'post_type'   => self::TYPE,
				'post_status' => 'publish',
				'numberposts' => 1,
				'fields'      => 'ids',
				'meta_key'    => '_wpes_q_token', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- One lookup by a unique token.
				'meta_value'  => $token, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- One lookup by a unique token.
			)
		);
		return $ids ? self::get( $ids[0] ) : null;
	}

	/**
	 * Customer page: quote, or project status once approved. Handles Approve / Request changes.
	 */
	public static function route() {
		$token = (string) get_query_var( self::QUERY );
		if ( '' === $token ) {
			return;
		}
		$q = self::by_token( sanitize_text_field( $token ) );
		if ( ! $q ) {
			return;
		}
		if ( isset( $_POST['wpes_quote_action'] ) && check_admin_referer( 'wpes_quote_' . $q['id'] ) ) {
			$action = sanitize_key( wp_unslash( $_POST['wpes_quote_action'] ) );
			if ( 'approve' === $action && in_array( $q['status'], array( 'awaiting', 'changes' ), true ) ) {
				update_post_meta( $q['id'], '_wpes_q_status', 'approved' );
				$order = self::invoice( $q, $q['total'] * (int) $q['deposit'] / 100, sprintf( /* translators: %d: percent. */ __( 'Milestone 1 (%d%%)', 'wpessential-server' ), (int) $q['deposit'] ) );
				if ( $order ) {
					wp_safe_redirect( $order->get_checkout_payment_url() );
					exit;
				}
			} elseif ( 'changes' === $action ) {
				$note = isset( $_POST['wpes_quote_note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['wpes_quote_note'] ) ) : '';
				update_post_meta( $q['id'], '_wpes_q_status', 'changes' );
				update_post_meta( $q['id'], '_wpes_q_note', $note );
				wp_mail( get_option( 'admin_email' ), sprintf( /* translators: %s: quote number. */ __( 'Changes requested on %s', 'wpessential-server' ), $q['number'] ), $note );
			}
			wp_safe_redirect( self::link( $q ) );
			exit;
		}
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow', true );
		$html = in_array( $q['status'], array( 'awaiting', 'changes' ), true ) ? self::quote_html( $q ) : self::project_html( $q );
		self::page( $html );
	}

	/**
	 * Print a page with the theme's header and footer.
	 *
	 * @param string $html Main content (escaped).
	 */
	private static function page( $html ) {
		if ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) {
			// Block themes print this global through template-canvas.php.
			$GLOBALS['_wp_current_template_content'] = '<!-- wp:template-part {"slug":"header","tagName":"div"} /--><main class="wp-block-group wpe-main wpes-service">' . $html . '</main><!-- wp:template-part {"slug":"footer","tagName":"div"} /-->'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Core's template global.
			include ABSPATH . WPINC . '/template-canvas.php';
			exit;
		}
		get_header();
		echo '<main class="wpes-service">' . $html . '</main>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built escaped.
		get_footer();
		exit;
	}

	/**
	 * Quote view (Figma 45).
	 *
	 * @param array $q Quote.
	 * @return string
	 */
	private static function quote_html( array $q ) {
		$rows = '';
		foreach ( $q['lines'] as $line ) {
			$rows .= '<tr><td>' . esc_html( $line['label'] ) . '</td><td>' . esc_html( $line['qty'] ) . '</td><td>' . wp_kses_post( wc_price( (float) $line['qty'] * (float) $line['amount'], array( 'decimals' => 0 ) ) ) . '</td></tr>';
		}
		$valid = $q['valid'] ? ' · ' . sprintf( /* translators: %s: date. */ __( 'valid until %s', 'wpessential-server' ), date_i18n( 'j M Y', strtotime( $q['valid'] ) ) ) : '';
		$form  = '<form method="post" class="wpes-q__actions">' . wp_nonce_field( 'wpes_quote_' . $q['id'], '_wpnonce', true, false )
			. '<button type="submit" name="wpes_quote_action" value="approve" class="is-primary">' . esc_html__( 'Approve quote', 'wpessential-server' ) . '</button>'
			. '<details><summary>' . esc_html__( 'Request changes', 'wpessential-server' ) . '</summary><textarea name="wpes_quote_note" rows="3" required></textarea><button type="submit" name="wpes_quote_action" value="changes">' . esc_html__( 'Send', 'wpessential-server' ) . '</button></details>'
			. '<button type="button" onclick="window.print()">' . esc_html__( 'Download PDF', 'wpessential-server' ) . '</button></form>';
		return '<div class="wpes-q"><p class="wpes-q__over">— ' . esc_html( sprintf( /* translators: %s: quote number. */ __( 'Quote %s', 'wpessential-server' ), $q['number'] ) ) . '</p>'
			. '<h1 class="wpes-q__title">' . esc_html( $q['title'] ) . '</h1>'
			/* translators: %s: customer company. */
			. '<p class="wpes-q__lead">' . esc_html( sprintf( __( 'Prepared by Vertex Systems Network for %s', 'wpessential-server' ), $q['company'] ? $q['company'] : __( 'you', 'wpessential-server' ) ) . $valid ) . '</p>'
			. '<div class="wpes-q__grid"><section class="wpes-q__card"><h2>' . esc_html__( 'Scope of work', 'wpessential-server' ) . '</h2><table class="wpes-q__table"><thead><tr><th>' . esc_html__( 'Item', 'wpessential-server' ) . '</th><th>' . esc_html__( 'Qty', 'wpessential-server' ) . '</th><th>' . esc_html__( 'Amount', 'wpessential-server' ) . '</th></tr></thead><tbody>' . $rows . '</tbody>'
			. '<tfoot><tr><th colspan="2">' . esc_html__( 'Total', 'wpessential-server' ) . '</th><th>' . wp_kses_post( wc_price( $q['total'], array( 'decimals' => 0 ) ) ) . '</th></tr></tfoot></table>'
			. '<p class="wpes-q__terms"><strong>' . esc_html__( 'Payment', 'wpessential-server' ) . '</strong> ' . esc_html( (string) $q['terms'] ) . '</p></section>'
			. '<aside class="wpes-q__card wpes-q__side"><span class="wpes-q__badge">' . esc_html( self::statuses()[ $q['status'] ] ) . '</span><p>' . esc_html__( 'Approve to receive the first invoice. Pay by Payoneer, PayPal, Stripe or bank transfer.', 'wpessential-server' ) . '</p>' . $form . '</aside></div></div>';
	}

	/**
	 * Project status (Figma 48).
	 *
	 * @param array $q Quote.
	 * @return string
	 */
	private static function project_html( array $q ) {
		$names = array(
			'done'   => __( 'Done', 'wpessential-server' ),
			'active' => __( 'In progress', 'wpessential-server' ),
			'next'   => __( 'Next', 'wpessential-server' ),
		);
		$steps = '';
		foreach ( $q['steps'] as $step ) {
			$state  = isset( $names[ strtolower( $step['state'] ) ] ) ? strtolower( $step['state'] ) : 'next';
			$steps .= '<li class="is-' . esc_attr( $state ) . '"><span>' . esc_html( $names[ $state ] ) . '</span>' . esc_html( $step['label'] ) . '</li>';
		}
		$paid    = self::paid_total( $q );
		$due     = self::open_invoice( $q );
		$dates   = array_filter(
			array(
				/* translators: %s: date. */
				$q['start'] ? sprintf( __( 'Started %s', 'wpessential-server' ), date_i18n( 'j M Y', strtotime( $q['start'] ) ) ) : '',
				/* translators: %s: date. */
				$q['delivery'] ? sprintf( __( 'Estimated delivery %s', 'wpessential-server' ), date_i18n( 'j M Y', strtotime( $q['delivery'] ) ) ) : '',
			)
		);
		$support = get_page_by_path( 'support' );
		return '<div class="wpes-q"><p class="wpes-q__over">— ' . esc_html__( 'Project', 'wpessential-server' ) . '</p><h1 class="wpes-q__title">' . esc_html( $q['title'] ) . '</h1>'
			. '<p class="wpes-q__lead">' . esc_html( implode( ' · ', $dates ) ) . '</p>'
			. '<div class="wpes-q__grid"><section class="wpes-q__card"><h2>' . esc_html__( 'Milestones', 'wpessential-server' ) . '</h2><ol class="wpes-q__steps">' . $steps . '</ol></section>'
			. '<aside class="wpes-q__card wpes-q__side"><dl class="wpes-q__money"><div><dt>' . esc_html__( 'Paid', 'wpessential-server' ) . '</dt><dd>' . wp_kses_post( wc_price( $paid, array( 'decimals' => 0 ) ) ) . '</dd></div>'
			. '<div><dt>' . esc_html__( 'Remaining', 'wpessential-server' ) . '</dt><dd>' . wp_kses_post( wc_price( max( 0, $q['total'] - $paid ), array( 'decimals' => 0 ) ) ) . '</dd></div>'
			. '<div><dt>' . esc_html__( 'Next invoice', 'wpessential-server' ) . '</dt><dd>' . ( $due ? '<a href="' . esc_url( $due->get_checkout_payment_url() ) . '">' . esc_html__( 'Pay now', 'wpessential-server' ) . '</a>' : esc_html__( 'On delivery', 'wpessential-server' ) ) . '</dd></div></dl>'
			. ( $support ? '<p class="wpes-q__actions"><a class="is-primary" href="' . esc_url( (string) get_permalink( $support ) ) . '">' . esc_html__( 'Message the team', 'wpessential-server' ) . '</a></p>' : '' )
			. '</aside></div></div>';
	}

	/**
	 * Create an invoice (a pending WooCommerce order) for part of a quote.
	 *
	 * @param array  $q      Quote.
	 * @param float  $amount Amount.
	 * @param string $label  Line label.
	 * @return \WC_Order|null
	 */
	public static function invoice( array $q, $amount, $label ) {
		if ( ! function_exists( 'wc_create_order' ) || $amount <= 0 ) {
			return null;
		}
		$user  = $q['email'] ? get_user_by( 'email', $q['email'] ) : false;
		$order = wc_create_order( array( 'customer_id' => $user ? $user->ID : 0 ) );
		if ( is_wp_error( $order ) ) {
			return null;
		}
		$fee = new \WC_Order_Item_Fee();
		$fee->set_name( $label . ' — ' . $q['title'] );
		$fee->set_amount( (string) $amount );
		$fee->set_total( (string) $amount );
		$fee->set_tax_status( 'none' );
		$order->add_item( $fee );
		$order->set_billing_email( (string) $q['email'] );
		$order->set_billing_company( (string) $q['company'] );
		$order->update_meta_data( '_wpes_quote', $q['id'] );
		$order->calculate_totals( false );
		$order->set_status( 'pending' );
		$order->save();
		$orders   = $q['orders'];
		$orders[] = $order->get_id();
		update_post_meta( $q['id'], '_wpes_q_orders', $orders );
		return $order;
	}

	/**
	 * Amount paid on a quote's invoices.
	 *
	 * @param array $q Quote.
	 * @return float
	 */
	public static function paid_total( array $q ) {
		$sum = 0.0;
		foreach ( $q['orders'] as $id ) {
			$order = wc_get_order( $id );
			if ( $order && $order->is_paid() ) {
				$sum += (float) $order->get_total();
			}
		}
		return $sum;
	}

	/**
	 * The first unpaid invoice of a quote.
	 *
	 * @param array $q Quote.
	 * @return \WC_Order|null
	 */
	public static function open_invoice( array $q ) {
		foreach ( $q['orders'] as $id ) {
			$order = wc_get_order( $id );
			if ( $order && $order->needs_payment() ) {
				return $order;
			}
		}
		return null;
	}

	/**
	 * First invoice paid: the project starts.
	 *
	 * @param int $order_id Order.
	 */
	public static function paid( $order_id ) {
		$order = wc_get_order( $order_id );
		$id    = $order ? (int) $order->get_meta( '_wpes_quote' ) : 0;
		if ( $id && 'approved' === get_post_meta( $id, '_wpes_q_status', true ) ) {
			update_post_meta( $id, '_wpes_q_status', 'active' );
			if ( ! get_post_meta( $id, '_wpes_q_start', true ) ) {
				update_post_meta( $id, '_wpes_q_start', gmdate( 'Y-m-d' ) );
			}
		}
	}

	/**
	 * Project link for an order paid against a quote (used by the theme's order-received page).
	 *
	 * @param \WC_Order $order Order.
	 * @return string
	 */
	public static function project_url( $order ) {
		$id = $order ? (int) $order->get_meta( '_wpes_quote' ) : 0;
		return $id ? self::link( self::get( $id ) ) : '';
	}
}
