<?php
/**
 * Incomplete Order Capture: checkout-এ নম্বর দিয়ে অর্ডার না করে চলে গেলে তথ্য সেভ রাখে,
 * যাতে admin কল/WhatsApp করে অর্ডার উদ্ধার করতে পারেন।
 *
 * @package SWS_Fake_Order_Guard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SWS_FOG_Incomplete {

	const OPT      = 'sws_fog_ic_settings';
	const DB_OPT   = 'sws_fog_ic_db';
	const DB_VER   = '1';
	const CRON     = 'sws_fog_ic_cleanup';
	const SLUG     = 'sws-fake-order-guard';
	const COOKIE   = 'sws_fog_ic';
	const PER_PAGE = 30;

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'sws_incomplete_orders';
	}

	private function __construct() {
		add_action( 'init', array( $this, 'maybe_install' ), 5 );
		add_action( self::CRON, array( $this, 'cleanup' ) );

		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_init', array( $this, 'old_page_redirect' ) );
		add_action( 'admin_post_sws_fog_ic', array( $this, 'handle_action' ) );
		add_action( 'admin_post_sws_fog_ic_bulk', array( $this, 'handle_bulk' ) );
		add_action( 'wp_ajax_sws_fog_ic_called', array( $this, 'ajax_called' ) );

		if ( self::get( 'enabled' ) ) {
			add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
			add_action( 'wp_ajax_sws_fog_capture', array( $this, 'ajax_capture' ) );
			add_action( 'wp_ajax_nopriv_sws_fog_capture', array( $this, 'ajax_capture' ) );
		}

		add_action( 'woocommerce_checkout_order_processed', array( $this, 'order_placed' ), 30, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'order_placed' ), 30, 1 );
	}

	/* ---------------------------------------------------------------- settings */

	public static function defaults() {
		return array(
			'enabled'     => 1,
			'minutes'     => 30,
			'keep_days'   => 30,
			'notice'      => 1,
			'notice_text' => 'অর্ডার সহায়তার জন্য আপনার নম্বর সংরক্ষিত হতে পারে।',
			'wa_text'     => 'আসসালামু আলাইকুম {name}, {site} থেকে বলছি। আপনি {product} অর্ডার করতে গিয়ে শেষ করেননি (মোট {total})। অর্ডারটি কনফার্ম করে দেব?',
		);
	}

	public static function get( $key ) {
		$o = wp_parse_args( (array) get_option( self::OPT, array() ), self::defaults() );
		return isset( $o[ $key ] ) ? $o[ $key ] : null;
	}

	public function register_settings() {
		register_setting( 'sws_fog_ic_group', self::OPT, array( 'sanitize_callback' => array( $this, 'sanitize' ) ) );
	}

	public function sanitize( $in ) {
		$in = (array) $in;
		$d  = self::defaults();
		$o  = array(
			'enabled'   => empty( $in['enabled'] ) ? 0 : 1,
			'minutes'   => max( 5, min( 1440, (int) ( $in['minutes'] ?? $d['minutes'] ) ) ),
			'keep_days' => max( 1, min( 365, (int) ( $in['keep_days'] ?? $d['keep_days'] ) ) ),
			'notice'    => empty( $in['notice'] ) ? 0 : 1,
		);
		foreach ( array( 'notice_text', 'wa_text' ) as $k ) {
			$v       = sanitize_textarea_field( $in[ $k ] ?? '' );
			$o[ $k ] = '' === trim( $v ) ? $d[ $k ] : $v;
		}
		return $o;
	}

	/* ---------------------------------------------------------------- install / cleanup */

	public function maybe_install() {
		if ( self::DB_VER !== get_option( self::DB_OPT ) ) {
			self::install();
		}
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$t = self::table();
		$c = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE $t (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  session_key varchar(64) NOT NULL DEFAULT '',
  phone varchar(20) NOT NULL DEFAULT '',
  name varchar(191) NOT NULL DEFAULT '',
  email varchar(191) NOT NULL DEFAULT '',
  address text NULL,
  cart_items longtext NULL,
  cart_total decimal(19,4) NOT NULL DEFAULT 0,
  source_url text NULL,
  ip varchar(45) NOT NULL DEFAULT '',
  user_agent varchar(255) NOT NULL DEFAULT '',
  status varchar(20) NOT NULL DEFAULT 'new',
  order_id bigint(20) unsigned NOT NULL DEFAULT 0,
  note text NULL,
  called_at datetime NULL DEFAULT NULL,
  created_at datetime NULL DEFAULT NULL,
  updated_at datetime NULL DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY session_key (session_key),
  KEY phone (phone),
  KEY status_updated (status,updated_at)
) $c;"
		);
		update_option( self::DB_OPT, self::DB_VER );
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON );
		}
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON );
	}

	public function cleanup() {
		global $wpdb;
		$days = (int) self::get( 'keep_days' );
		$t    = self::table();
		$wpdb->query( $wpdb->prepare( "DELETE FROM $t WHERE updated_at < %s", self::gmt( - $days * DAY_IN_SECONDS ) ) ); // phpcs:ignore
	}

	/* ---------------------------------------------------------------- helpers */

	private static function gmt( $offset = 0 ) {
		return gmdate( 'Y-m-d H:i:s', time() + $offset );
	}

	private static function ip() {
		$ip = class_exists( 'WC_Geolocation' ) ? WC_Geolocation::get_ip_address() : ( $_SERVER['REMOTE_ADDR'] ?? '' ); // phpcs:ignore
		$ip = filter_var( trim( (string) wp_unslash( $ip ) ), FILTER_VALIDATE_IP );
		return $ip ? $ip : '';
	}

	private static function clean_key( $k ) {
		$k = substr( preg_replace( '/[^a-f0-9]/', '', strtolower( (string) $k ) ), 0, 64 );
		return strlen( $k ) >= 16 ? $k : '';
	}

	private static function cut( $s, $len ) {
		return function_exists( 'mb_substr' ) ? mb_substr( $s, 0, $len ) : substr( $s, 0, $len );
	}

	/** @return array [items, total] from the visitor's current cart. */
	private static function cart() {
		if ( ! function_exists( 'WC' ) ) {
			return array( array(), 0 );
		}
		if ( null === WC()->cart && function_exists( 'wc_load_cart' ) ) {
			wc_load_cart();
		}
		$cart = WC()->cart;
		if ( ! $cart || $cart->is_empty() ) {
			return array( array(), 0 );
		}
		$items = array();
		$sum   = 0;
		foreach ( $cart->get_cart() as $ci ) {
			if ( empty( $ci['data'] ) || ! is_object( $ci['data'] ) ) {
				continue;
			}
			$line    = (float) ( $ci['line_total'] ?? 0 ) + (float) ( $ci['line_tax'] ?? 0 );
			$sum    += $line;
			$items[] = array(
				'id'    => (int) $ci['product_id'],
				'vid'   => (int) ( $ci['variation_id'] ?? 0 ),
				'name'  => wp_strip_all_tags( $ci['data']->get_name() ),
				'qty'   => (float) $ci['quantity'],
				'total' => $line,
			);
		}
		$total = (float) $cart->get_total( 'edit' );
		return array( $items, $total > 0 ? $total : $sum );
	}

	/** Most recent still-open row for this visitor (same browser, or same number). */
	private static function find_open( $key, $phone ) {
		global $wpdb;
		$t = self::table();
		return $wpdb->get_row( // phpcs:ignore
			$wpdb->prepare(
				"SELECT * FROM $t WHERE order_id = 0 AND status IN ('new','called','blocked') AND ( session_key = %s OR phone = %s ) AND updated_at > %s ORDER BY ( session_key = %s ) DESC, id DESC LIMIT 1",
				$key,
				$phone,
				self::gmt( -2 * DAY_IN_SECONDS ),
				$key
			)
		);
	}

	/* ---------------------------------------------------------------- frontend */

	public function enqueue() {
		$load = function_exists( 'is_checkout' ) && is_checkout()
			&& ! is_wc_endpoint_url( 'order-received' ) && ! is_wc_endpoint_url( 'order-pay' );
		if ( ! apply_filters( 'sws_fog_ic_load', $load ) ) {
			return;
		}
		wp_enqueue_script( 'sws-fog-capture', plugins_url( 'assets/js/sws-fog-capture.js', SWS_FOG_FILE ), array(), SWS_Fake_Order_Guard::VERSION, true );
		wp_localize_script(
			'sws-fog-capture',
			'swsFogIC',
			array(
				'ajax'   => admin_url( 'admin-ajax.php' ),
				'nonce'  => wp_create_nonce( 'sws_fog_capture' ),
				'notice' => self::get( 'notice' ) ? (string) self::get( 'notice_text' ) : '',
			)
		);
	}

	public function ajax_capture() {
		global $wpdb;
		// Guest nonces are shared and go stale on cached landing/checkout pages (LiteSpeed etc.),
		// so for guests we rely on the rate limit + phone validation instead.
		if ( is_user_logged_in() && ! check_ajax_referer( 'sws_fog_capture', 'nonce', false ) ) {
			wp_send_json_error( null, 403 );
		}
		$ip = self::ip();
		$rk = 'sws_fog_ic_rl_' . md5( $ip );
		$n  = (int) get_transient( $rk );
		if ( $n >= 20 ) {
			wp_send_json_error( null, 429 );
		}
		set_transient( $rk, $n + 1, MINUTE_IN_SECONDS );

		$p     = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification
		$phone = SWS_Fake_Order_Guard::normalize_phone( sanitize_text_field( $p['phone'] ?? '' ) );
		$key   = self::clean_key( $p['key'] ?? '' );
		if ( ! $key || SWS_Fake_Order_Guard::is_fake_phone( $phone ) ) {
			wp_send_json_error( null, 400 );
		}

		$t     = self::table();
		$now   = self::gmt();
		$email = sanitize_email( $p['email'] ?? '' );
		$data  = array(
			'session_key' => $key,
			'phone'       => $phone,
			'name'        => self::cut( sanitize_text_field( $p['name'] ?? '' ), 191 ),
			'email'       => is_email( $email ) ? $email : '',
			'address'     => self::cut( sanitize_textarea_field( $p['address'] ?? '' ), 500 ),
			'ip'          => $ip,
			'user_agent'  => self::cut( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ), 255 ), // phpcs:ignore
			'updated_at'  => $now,
		);
		list( $items, $total ) = self::cart();
		if ( $items ) {
			$data['cart_items'] = wp_json_encode( $items );
			$data['cart_total'] = $total;
		}
		$blocked = SWS_Fake_Order_Guard::is_blocked( $phone );
		$row     = self::find_open( $key, $phone );

		if ( $row ) {
			foreach ( array( 'name', 'email', 'address' ) as $k ) {
				if ( '' === $data[ $k ] ) {
					unset( $data[ $k ] ); // don't wipe what we already have.
				}
			}
			if ( $blocked ) {
				$data['status'] = 'blocked';
			} elseif ( 'blocked' === $row->status ) {
				$data['status'] = 'new';
			}
			$wpdb->update( $t, $data, array( 'id' => (int) $row->id ) ); // phpcs:ignore
			wp_send_json_success();
		}

		// The order was just placed (late beacon from the checkout page) - don't start a new row.
		$done = $wpdb->get_var( // phpcs:ignore
			$wpdb->prepare(
				"SELECT id FROM $t WHERE order_id > 0 AND ( session_key = %s OR phone = %s ) AND updated_at > %s LIMIT 1",
				$key,
				$phone,
				self::gmt( -30 * MINUTE_IN_SECONDS )
			)
		);
		if ( $done ) {
			wp_send_json_success();
		}

		$data['status']     = $blocked ? 'blocked' : 'new';
		$data['created_at'] = $now;
		$data['source_url'] = self::cut( esc_url_raw( $p['src'] ?? '' ), 1000 );
		$wpdb->insert( $t, $data ); // phpcs:ignore
		wp_send_json_success();
	}

	/** Order placed: mark this visitor's open rows as recovered. */
	public function order_placed( $order ) {
		global $wpdb;
		$order = is_a( $order, 'WC_Order' ) ? $order : wc_get_order( $order );
		if ( ! $order ) {
			return;
		}
		$phone = SWS_Fake_Order_Guard::normalize_phone( $order->get_billing_phone() ? $order->get_billing_phone() : $order->get_shipping_phone() );
		$key   = self::clean_key( $_COOKIE[ self::COOKIE ] ?? '' ); // phpcs:ignore
		if ( ! SWS_Fake_Order_Guard::is_valid_phone( $phone ) ) {
			$phone = '-';
		}
		if ( ! $key ) {
			$key = '-';
		}
		if ( '-' === $phone && '-' === $key ) {
			return;
		}
		$t    = self::table();
		$rows = $wpdb->get_results( // phpcs:ignore
			$wpdb->prepare(
				"SELECT id, status, called_at, created_at FROM $t WHERE order_id = 0 AND status IN ('new','called','lost') AND ( session_key = %s OR phone = %s ) AND updated_at > %s",
				$key,
				$phone,
				self::gmt( -7 * DAY_IN_SECONDS )
			)
		);
		$young = (int) self::get( 'minutes' ) * MINUTE_IN_SECONDS;
		foreach ( (array) $rows as $r ) {
			$age = time() - (int) strtotime( $r->created_at . ' UTC' );
			// Ordered within the waiting time without a call: never really abandoned.
			$status = ( 'new' === $r->status && empty( $r->called_at ) && $age < $young ) ? 'converted' : 'recovered';
			$wpdb->update( $t, array( 'status' => $status, 'order_id' => $order->get_id(), 'updated_at' => self::gmt() ), array( 'id' => (int) $r->id ) ); // phpcs:ignore
		}
	}

	/* ---------------------------------------------------------------- admin */

	private static function can() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'অনুমতি নেই।', 403 );
		}
	}

	private static function page_url( $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::SLUG, 'tab' => 'incomplete' ), $args ), admin_url( 'admin.php' ) );
	}

	public static function open_count() {
		global $wpdb;
		$t = self::table();
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE status = 'new' AND updated_at < %s", self::gmt( - (int) self::get( 'minutes' ) * MINUTE_IN_SECONDS ) ) ); // phpcs:ignore
	}

	/** Count of incomplete rows waiting for a call (for the menu badge). */
	public static function menu_count() {
		return self::DB_VER === get_option( self::DB_OPT ) ? self::open_count() : 0;
	}

	/** Old v1.4.1-1.4.2 standalone page now lives in a tab of the main page. */
	public function old_page_redirect() {
		if ( isset( $_GET['page'] ) && 'sws-fog-incomplete' === $_GET['page'] ) { // phpcs:ignore
			wp_safe_redirect( self::page_url() );
			exit;
		}
	}

	private static function action_url( $do, $id ) {
		return wp_nonce_url( admin_url( 'admin-post.php?action=sws_fog_ic&do=' . $do . '&id=' . (int) $id ), 'sws_fog_ic' );
	}

	private static function back( $msg ) {
		$ref = wp_get_referer();
		$ref = $ref ? remove_query_arg( 'sws_ic_msg', $ref ) : self::page_url();
		wp_safe_redirect( add_query_arg( 'sws_ic_msg', $msg, $ref ) );
		exit;
	}

	private static function set_status( $ids, $status ) {
		global $wpdb;
		$ids = array_filter( array_map( 'intval', (array) $ids ) );
		if ( ! $ids ) {
			return;
		}
		$t   = self::table();
		$in  = implode( ',', $ids );
		$now = self::gmt();
		if ( 'called' === $status ) {
			$wpdb->query( $wpdb->prepare( "UPDATE $t SET status = 'called', called_at = COALESCE(called_at, %s) WHERE id IN ($in) AND order_id = 0", $now ) ); // phpcs:ignore
		} else {
			$wpdb->query( $wpdb->prepare( "UPDATE $t SET status = %s WHERE id IN ($in) AND order_id = 0", $status ) ); // phpcs:ignore
		}
	}

	public function handle_action() {
		global $wpdb;
		self::can();
		check_admin_referer( 'sws_fog_ic' );
		$id = (int) ( $_REQUEST['id'] ?? 0 ); // phpcs:ignore
		$do = sanitize_key( $_REQUEST['do'] ?? '' ); // phpcs:ignore
		$t  = self::table();
		switch ( $do ) {
			case 'lost':
			case 'new':
			case 'called':
				self::set_status( array( $id ), $do );
				self::back( 'ok' );
				break;
			case 'delete':
				$wpdb->delete( $t, array( 'id' => $id ) ); // phpcs:ignore
				self::back( 'deleted' );
				break;
			case 'note':
				$note = self::cut( sanitize_textarea_field( wp_unslash( $_POST['note'] ?? '' ) ), 1000 ); // phpcs:ignore
				$wpdb->update( $t, array( 'note' => $note ), array( 'id' => $id ) ); // phpcs:ignore
				self::back( 'ok' );
				break;
			case 'block':
				$phone = (string) $wpdb->get_var( $wpdb->prepare( "SELECT phone FROM $t WHERE id = %d", $id ) ); // phpcs:ignore
				if ( $phone ) {
					SWS_Fake_Order_Guard::block( $phone );
					$wpdb->query( $wpdb->prepare( "UPDATE $t SET status = 'blocked' WHERE phone = %s AND order_id = 0", $phone ) ); // phpcs:ignore
				}
				self::back( 'blocked' );
				break;
		}
		self::back( 'ok' );
	}

	public function handle_bulk() {
		global $wpdb;
		self::can();
		check_admin_referer( 'sws_fog_ic_bulk' );
		$ids = array_filter( array_map( 'intval', (array) ( $_POST['ids'] ?? array() ) ) ); // phpcs:ignore
		$do  = sanitize_key( $_POST['bulk'] ?? '' ); // phpcs:ignore
		if ( ! $ids ) {
			self::back( 'none' );
		}
		if ( 'delete' === $do ) {
			$t = self::table();
			$wpdb->query( 'DELETE FROM ' . $t . ' WHERE id IN (' . implode( ',', $ids ) . ')' ); // phpcs:ignore
			self::back( 'deleted' );
		}
		if ( in_array( $do, array( 'lost', 'called', 'new' ), true ) ) {
			self::set_status( $ids, $do );
		}
		self::back( 'ok' );
	}

	public function ajax_called() {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! check_ajax_referer( 'sws_fog_ic', 'nonce', false ) ) {
			wp_send_json_error( null, 403 );
		}
		global $wpdb;
		$t  = self::table();
		$id = (int) ( $_POST['id'] ?? 0 ); // phpcs:ignore
		$wpdb->query( $wpdb->prepare( "UPDATE $t SET status = 'called', called_at = %s WHERE id = %d AND status = 'new' AND order_id = 0", self::gmt(), $id ) ); // phpcs:ignore
		wp_send_json_success();
	}

	private static function statuses() {
		return array(
			'incomplete' => 'অসম্পূর্ণ',
			'called'     => 'কল করা হয়েছে',
			'recovered'  => 'উদ্ধার হয়েছে',
			'lost'       => 'বাদ',
			'blocked'    => 'ব্লকড',
			'all'        => 'সব',
		);
	}

	private static function wa_link( $r, $products ) {
		$total = html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ) . number_format_i18n( (float) $r->cart_total );
		$text  = strtr(
			(string) self::get( 'wa_text' ),
			array(
				'{name}'    => $r->name ? $r->name : '',
				'{product}' => $products ? $products : 'পণ্য',
				'{total}'   => $total,
				'{site}'    => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			)
		);
		$text = trim( preg_replace( '/\s+/u', ' ', $text ) );
		return 'https://wa.me/88' . $r->phone . '?text=' . rawurlencode( $text );
	}

	public function render_page() {
		global $wpdb;
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		if ( self::DB_VER !== get_option( self::DB_OPT ) ) {
			self::install();
		}
		$t       = self::table();
		$wait    = self::gmt( - (int) self::get( 'minutes' ) * MINUTE_IN_SECONDS );
		$tabs    = self::statuses();
		$tab     = sanitize_key( $_GET['view'] ?? 'incomplete' ); // phpcs:ignore
		$tab     = isset( $tabs[ $tab ] ) ? $tab : 'incomplete';
		$s       = SWS_Fake_Order_Guard::normalize_phone( sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) ) ); // phpcs:ignore
		$paged   = max( 1, (int) ( $_GET['paged'] ?? 1 ) ); // phpcs:ignore
		$visible = "status IN ('new','called','recovered','lost','blocked')";

		$conds = array(
			'incomplete' => $wpdb->prepare( "status = 'new' AND updated_at < %s", $wait ),
			'called'     => "status = 'called'",
			'recovered'  => "status = 'recovered'",
			'lost'       => "status = 'lost'",
			'blocked'    => "status = 'blocked'",
			'all'        => $visible . ' AND NOT ' . $wpdb->prepare( "( status = 'new' AND updated_at >= %s )", $wait ),
		);
		$counts = array();
		foreach ( $conds as $k => $c ) {
			$counts[ $k ] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE $c" ); // phpcs:ignore
		}
		$where = $conds[ $tab ];
		if ( '' !== $s ) {
			$where .= $wpdb->prepare( ' AND phone LIKE %s', '%' . $wpdb->esc_like( $s ) . '%' );
		}
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE $where" ); // phpcs:ignore
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE $where ORDER BY updated_at DESC LIMIT %d OFFSET %d", self::PER_PAGE, ( $paged - 1 ) * self::PER_PAGE ) ); // phpcs:ignore

		$rec_called = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE status = 'recovered' AND called_at IS NOT NULL" ); // phpcs:ignore
		$msgs       = array(
			'ok'      => 'আপডেট হয়েছে।',
			'deleted' => 'মুছে ফেলা হয়েছে।',
			'blocked' => 'নম্বরটি ব্লকলিস্টে যোগ হয়েছে।',
			'none'    => 'কোনো row বাছাই করা হয়নি।',
		);
		$msg        = sanitize_key( $_GET['sws_ic_msg'] ?? '' ); // phpcs:ignore
		$o          = wp_parse_args( (array) get_option( self::OPT, array() ), self::defaults() );
		$n          = self::OPT;
		$labels     = array(
			'new'       => 'অসম্পূর্ণ',
			'called'    => 'কল করা হয়েছে',
			'recovered' => 'উদ্ধার হয়েছে',
			'lost'      => 'বাদ',
			'blocked'   => 'ব্লকড',
		);
		?>
		<div class="sws-ic">
			<style>
				.sws-ic .sws-ic-stats{display:flex;gap:12px;flex-wrap:wrap;margin:14px 0}
				.sws-ic .sws-ic-stat{background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:10px 16px;min-width:130px}
				.sws-ic .sws-ic-stat b{display:block;font-size:22px;line-height:1.3}
				.sws-ic table td{vertical-align:top}
				.sws-ic .sws-ic-st{display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;background:#f0f0f1}
				.sws-ic .st-new{background:#fcf0e3;color:#9a5b13}.sws-ic .st-called{background:#e5f0fa;color:#135e96}
				.sws-ic .st-recovered{background:#e7f6ea;color:#1a7f37}.sws-ic .st-lost{background:#f0f0f1;color:#50575e}.sws-ic .st-blocked{background:#fbeaea;color:#b32d2e}
				.sws-ic .sws-ic-act .button{margin:0 4px 4px 0}
				.sws-ic .sws-ic-wa{background:#25d366!important;border-color:#1ebe5a!important;color:#fff!important}
				.sws-ic .row-actions{position:static;left:auto}
				.sws-ic small{color:#646970}
			</style>
			<p>চেকআউটে মোবাইল নম্বর দিয়ে অর্ডার শেষ না করা গ্রাহকদের তালিকা। <?php echo (int) self::get( 'minutes' ); ?> মিনিট পার হলে তবেই তালিকায় আসে। ভুয়া প্যাটার্নের নম্বর সেভ হয় না।</p>
			<?php if ( isset( $msgs[ $msg ] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $msgs[ $msg ] ); ?></p></div>
			<?php endif; ?>
			<?php if ( ! $o['enabled'] ) : ?>
				<div class="notice notice-warning"><p>ফিচারটি এখন বন্ধ। নিচের সেটিংস থেকে চালু করুন।</p></div>
			<?php endif; ?>

			<div class="sws-ic-stats">
				<div class="sws-ic-stat"><small>অসম্পূর্ণ</small><b><?php echo (int) $counts['incomplete']; ?></b></div>
				<div class="sws-ic-stat"><small>কল করা হয়েছে</small><b><?php echo (int) $counts['called']; ?></b></div>
				<div class="sws-ic-stat"><small>উদ্ধার হয়েছে</small><b><?php echo (int) $counts['recovered']; ?></b><small>কল করার পর: <?php echo (int) $rec_called; ?></small></div>
			</div>

			<ul class="subsubsub">
				<?php
				$links = array();
				foreach ( $tabs as $k => $l ) {
					$links[] = '<li><a href="' . esc_url( self::page_url( array( 'view' => $k ) ) ) . '"' . ( $k === $tab ? ' class="current"' : '' ) . '>' . esc_html( $l ) . ' <span class="count">(' . (int) $counts[ $k ] . ')</span></a>';
				}
				echo implode( ' | </li>', $links ) . '</li>'; // phpcs:ignore
				?>
			</ul>
			<form method="get" style="float:right;margin:8px 0">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>">
				<input type="hidden" name="tab" value="incomplete">
				<input type="hidden" name="view" value="<?php echo esc_attr( $tab ); ?>">
				<input type="search" name="s" value="<?php echo esc_attr( $s ); ?>" placeholder="ফোন নম্বর">
				<button class="button">খুঁজুন</button>
			</form>
			<div style="clear:both"></div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="sws_fog_ic_bulk">
				<?php wp_nonce_field( 'sws_fog_ic_bulk' ); ?>
				<div class="tablenav top">
					<div class="alignleft actions bulkactions">
						<select name="bulk">
							<option value="">Bulk actions</option>
							<option value="called">কল করা হয়েছে</option>
							<option value="lost">বাদ দিন</option>
							<option value="new">অসম্পূর্ণে ফেরত</option>
							<option value="delete">মুছে ফেলুন</option>
						</select>
						<button class="button" onclick="return this.form.bulk.value!=='delete'||confirm('বাছাই করা row মুছে ফেলবেন?');">Apply</button>
					</div>
					<div class="tablenav-pages"><span class="displaying-num"><?php echo (int) $total; ?> টি</span>
						<?php
						echo paginate_links( // phpcs:ignore
							array(
								'base'    => add_query_arg( 'paged', '%#%' ),
								'format'  => '',
								'current' => $paged,
								'total'   => max( 1, (int) ceil( $total / self::PER_PAGE ) ),
							)
						);
						?>
					</div>
				</div>
				<table class="wp-list-table widefat fixed striped">
					<thead><tr>
						<td class="manage-column check-column"><input type="checkbox" onclick="document.querySelectorAll('.sws-ic-cb').forEach(function(c){c.checked=this.checked}.bind(this))"></td>
						<th style="width:110px">সময়</th><th>গ্রাহক</th><th>পণ্য</th><th style="width:90px">মোট</th><th style="width:110px">স্ট্যাটাস</th><th style="width:260px">অ্যাকশন</th>
					</tr></thead>
					<tbody>
					<?php if ( ! $rows ) : ?>
						<tr><td colspan="7">কিছু পাওয়া যায়নি।</td></tr>
					<?php endif; ?>
					<?php
					foreach ( (array) $rows as $r ) :
						$items    = json_decode( (string) $r->cart_items, true );
						$products = array();
						foreach ( (array) $items as $it ) {
							$products[] = ( $it['name'] ?? '' ) . ( ( $it['qty'] ?? 1 ) > 1 ? ' × ' . (float) $it['qty'] : '' );
						}
						$products = implode( ', ', array_filter( $products ) );
						$ts       = (int) strtotime( $r->updated_at . ' UTC' );
						$open     = 0 === (int) $r->order_id;
						?>
						<tr>
							<th scope="row" class="check-column"><input class="sws-ic-cb" type="checkbox" name="ids[]" value="<?php echo (int) $r->id; ?>"></th>
							<td><?php echo esc_html( human_time_diff( $ts ) ); ?> আগে<br><small><?php echo esc_html( wp_date( 'j M, g:i a', $ts ) ); ?></small></td>
							<td>
								<b><?php echo esc_html( $r->name ? $r->name : '—' ); ?></b><br>
								<?php echo esc_html( $r->phone ); ?>
								<?php if ( $r->address ) : ?><br><small><?php echo esc_html( $r->address ); ?></small><?php endif; ?>
								<?php if ( $r->note ) : ?><br><small>📝 <?php echo esc_html( $r->note ); ?></small><?php endif; ?>
							</td>
							<td><?php echo esc_html( $products ? $products : '—' ); ?>
								<?php if ( $r->source_url ) : ?><br><small title="<?php echo esc_attr( $r->source_url ); ?>">উৎস: <?php echo esc_html( wp_parse_url( $r->source_url, PHP_URL_HOST ) . wp_parse_url( $r->source_url, PHP_URL_PATH ) ); ?></small><?php endif; ?>
							</td>
							<td><?php echo $r->cart_total > 0 ? wp_kses_post( wc_price( $r->cart_total ) ) : '—'; ?></td>
							<td>
								<span class="sws-ic-st st-<?php echo esc_attr( $r->status ); ?>" data-id="<?php echo (int) $r->id; ?>"><?php echo esc_html( $labels[ $r->status ] ?? $r->status ); ?></span>
								<?php if ( 'recovered' === $r->status ) : ?>
									<br><small><?php echo $r->called_at ? 'কল করার পর' : 'নিজে থেকে'; ?></small>
								<?php endif; ?>
								<?php
								if ( ! $open ) :
									$ord = wc_get_order( (int) $r->order_id );
									if ( $ord ) :
										?>
									<br><a href="<?php echo esc_url( $ord->get_edit_order_url() ); ?>">অর্ডার #<?php echo esc_html( $ord->get_order_number() ); ?></a>
										<?php
									endif;
								endif;
								?>
							</td>
							<td class="sws-ic-act">
								<a class="button button-small sws-ic-call" data-id="<?php echo (int) $r->id; ?>" href="tel:<?php echo esc_attr( $r->phone ); ?>">📞 কল</a>
								<a class="button button-small sws-ic-wa sws-ic-call" data-id="<?php echo (int) $r->id; ?>" target="_blank" rel="noopener" href="<?php echo esc_url( self::wa_link( $r, $products ) ); ?>">WhatsApp</a>
								<?php if ( $open ) : ?>
								<div class="row-actions">
									<?php if ( 'lost' !== $r->status ) : ?><a href="<?php echo esc_url( self::action_url( 'lost', $r->id ) ); ?>">বাদ দিন</a> | <?php endif; ?>
									<?php if ( 'new' !== $r->status && 'blocked' !== $r->status ) : ?><a href="<?php echo esc_url( self::action_url( 'new', $r->id ) ); ?>">অসম্পূর্ণে ফেরত</a> | <?php endif; ?>
									<a href="#" class="sws-ic-note" data-id="<?php echo (int) $r->id; ?>" data-note="<?php echo esc_attr( (string) $r->note ); ?>">নোট</a> |
									<?php if ( 'blocked' !== $r->status ) : ?><a href="<?php echo esc_url( self::action_url( 'block', $r->id ) ); ?>" style="color:#b32d2e" onclick="return confirm('এই নম্বর ব্লকলিস্টে যোগ করবেন? এই নম্বর থেকে আর অর্ডার করা যাবে না।');">ব্লক</a> | <?php endif; ?>
									<a href="<?php echo esc_url( self::action_url( 'delete', $r->id ) ); ?>" style="color:#b32d2e" onclick="return confirm('মুছে ফেলবেন?');">মুছুন</a>
								</div>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</form>

			<form id="sws-ic-note-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:none">
				<input type="hidden" name="action" value="sws_fog_ic">
				<input type="hidden" name="do" value="note">
				<input type="hidden" name="id" value="">
				<input type="hidden" name="note" value="">
				<?php wp_nonce_field( 'sws_fog_ic' ); ?>
			</form>

			<h2 style="margin-top:36px">সেটিংস</h2>
			<form method="post" action="options.php">
				<?php settings_fields( 'sws_fog_ic_group' ); ?>
				<table class="form-table" role="presentation">
					<tr><th scope="row">চালু করুন</th><td><label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[enabled]" value="1" <?php checked( $o['enabled'], 1 ); ?>> চেকআউটে অসম্পূর্ণ অর্ডার সেভ করুন</label></td></tr>
					<tr><th scope="row"><label for="ic-min">কত মিনিট পরে অসম্পূর্ণ</label></th><td><input id="ic-min" type="number" min="5" max="1440" class="small-text" name="<?php echo esc_attr( $n ); ?>[minutes]" value="<?php echo esc_attr( $o['minutes'] ); ?>"><p class="description">শেষবার ফর্মে লেখার পর এতক্ষণ অর্ডার না হলে তালিকায় দেখাবে।</p></td></tr>
					<tr><th scope="row"><label for="ic-keep">কত দিন রাখবে</label></th><td><input id="ic-keep" type="number" min="1" max="365" class="small-text" name="<?php echo esc_attr( $n ); ?>[keep_days]" value="<?php echo esc_attr( $o['keep_days'] ); ?>"><p class="description">এর চেয়ে পুরোনো ডেটা প্রতিদিন নিজে থেকে মুছে যাবে।</p></td></tr>
					<tr><th scope="row">চেকআউটে নোটিশ</th><td><label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[notice]" value="1" <?php checked( $o['notice'], 1 ); ?>> ফোন নম্বরের ঘরের নিচে ছোট নোটিশ দেখান</label><br><input type="text" class="large-text" style="max-width:520px;margin-top:6px" name="<?php echo esc_attr( $n ); ?>[notice_text]" value="<?php echo esc_attr( $o['notice_text'] ); ?>"></td></tr>
					<tr><th scope="row"><label for="ic-wa">WhatsApp মেসেজ</label></th><td><textarea id="ic-wa" rows="3" class="large-text" style="max-width:520px" name="<?php echo esc_attr( $n ); ?>[wa_text]"><?php echo esc_textarea( $o['wa_text'] ); ?></textarea><p class="description"><code>{name}</code> নাম, <code>{product}</code> পণ্য, <code>{total}</code> মোট টাকা, <code>{site}</code> সাইটের নাম।</p></td></tr>
				</table>
				<?php submit_button( 'সেভ করুন' ); ?>
			</form>
		</div>
		<script>
		(function(){
			var ajax = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>, nonce = <?php echo wp_json_encode( wp_create_nonce( 'sws_fog_ic' ) ); ?>;
			document.addEventListener('click', function(e){
				var a = e.target.closest('.sws-ic-call');
				if (a) {
					var f = new FormData(); f.append('action','sws_fog_ic_called'); f.append('nonce',nonce); f.append('id',a.dataset.id);
					fetch(ajax,{method:'POST',body:f,credentials:'same-origin'}).catch(function(){});
					var st = document.querySelector('.sws-ic-st.st-new[data-id="'+a.dataset.id+'"]');
					if (st) { st.className = 'sws-ic-st st-called'; st.textContent = 'কল করা হয়েছে'; }
					return;
				}
				var n = e.target.closest('.sws-ic-note');
				if (n) {
					e.preventDefault();
					var v = prompt('নোট লিখুন:', n.dataset.note || '');
					if (v === null) { return; }
					var form = document.getElementById('sws-ic-note-form');
					form.elements['id'].value = n.dataset.id; form.elements['note'].value = v; form.submit();
				}
			});
		})();
		</script>
		<?php
	}
}
