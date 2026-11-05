<?php
/**
 * Plugin Name:       SWS Fake Order Guard
 * Plugin URI:        https://sabbirwebsolutions.com
 * Description:       WooCommerce ফেক অর্ডার প্রতিরোধ: মোবাইল নম্বর যাচাই, একই নম্বর/IP থেকে নির্দিষ্ট সময়ে অর্ডারের লিমিট, ফোন নম্বর ব্লকলিস্ট, টেস্ট/এলোমেলো লেখা দিয়ে অর্ডার ব্লক, শুধু যাচাই করা অর্ডারে Facebook Purchase ইভেন্ট (Conversions API), এবং অসম্পূর্ণ অর্ডার (Incomplete Orders) সেভ করে কল/WhatsApp দিয়ে উদ্ধার।
 * Version:           1.4.4
 * Author:            Sabbir Web Solutions
 * Author URI:        https://sabbirwebsolutions.com
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * WC requires at least: 7.0
 * Text Domain:       sws-fake-order-guard
 * Update URI:        https://github.com/sourov6580/sws-fake-order-guard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SWS_FOG_FILE', __FILE__ );
require_once __DIR__ . '/includes/class-sws-fog-incomplete.php';
require_once __DIR__ . '/includes/class-sws-fog-orders.php';

final class SWS_Fake_Order_Guard {

	const OPT     = 'sws_fog_settings';
	const VERSION = '1.4.4';
	const CAPI_VER = 'v22.0';
	const BL      = 'sws_fog_blocklist';
	const PER_PAGE = 50;

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'before_woocommerce_init', array( $this, 'declare_hpos' ) );
		add_action( 'admin_menu', array( $this, 'admin_menu' ), 99 );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'action_links' ) );
		add_action( 'admin_notices', array( $this, 'wc_missing_notice' ) );

		add_action( 'woocommerce_after_checkout_validation', array( $this, 'validate' ), 20, 2 );
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'record_order' ), 10, 1 );

		// Blocklist: Store API (block checkout).
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( $this, 'store_api_check' ), 10, 2 );

		// Blocklist: admin actions.
		add_action( 'admin_post_sws_fog_block', array( $this, 'handle_block' ) );
		add_action( 'admin_post_sws_fog_unblock', array( $this, 'handle_unblock' ) );
		add_action( 'admin_post_sws_fog_bl_add', array( $this, 'handle_bl_add' ) );
		add_action( 'admin_post_sws_fog_bl_bulk', array( $this, 'handle_bl_bulk' ) );
		add_action( 'admin_notices', array( $this, 'action_notices' ) );
		add_action( 'admin_head', array( $this, 'admin_css' ) );

		// Orders list column + bulk action (legacy posts table and HPOS).
		add_filter( 'manage_edit-shop_order_columns', array( $this, 'add_column' ), 20 );
		add_action( 'manage_shop_order_posts_custom_column', array( $this, 'render_column_legacy' ), 20, 2 );
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( $this, 'add_column' ), 20 );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( $this, 'render_column_hpos' ), 20, 2 );
		add_filter( 'bulk_actions-edit-shop_order', array( $this, 'add_bulk_action' ) );
		add_filter( 'handle_bulk_actions-edit-shop_order', array( $this, 'handle_order_bulk' ), 10, 3 );
		add_filter( 'bulk_actions-woocommerce_page_wc-orders', array( $this, 'add_bulk_action' ) );
		add_filter( 'handle_bulk_actions-woocommerce_page_wc-orders', array( $this, 'handle_order_bulk' ), 10, 3 );

		// Single order screen.
		add_action( 'woocommerce_admin_order_data_after_billing_address', array( $this, 'order_screen_box' ) );

		// Verified Purchase: Meta Conversions API.
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'capture_tracking' ), 20, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'capture_tracking' ), 20, 1 );
		add_action( 'woocommerce_order_status_changed', array( $this, 'maybe_queue_purchase' ), 20, 4 );
		add_action( 'sws_fog_capi_send', array( $this, 'send_purchase' ), 10, 1 );
		add_filter( 'woocommerce_order_actions', array( $this, 'order_action' ) );
		add_action( 'woocommerce_order_action_sws_fog_capi_send', array( $this, 'order_action_run' ) );
		add_action( 'wp_head', array( $this, 'purchase_guard' ), 0 );
	}

	/* ---------------------------------------------------------------- settings */

	public static function defaults() {
		return array(
			'enabled'        => 1,
			'validate_phone' => 1,
			'block_test'     => 1,
			'junk_words'     => "test\ntesting\ntester\ntst\ndemo\ndummy\nasdf\nasd\nqwe\nqwerty\nটেস্ট\nটেষ্ট\nটেস্টিং\nটেষ্টিং\nপরীক্ষা\nপরীক্ষামূলক\nডেমো\nভুয়া",
			'block_gibberish'=> 1,
			'window_hours'   => 12,
			'phone_limit'    => 1,
			'ip_limit'       => 1,
			'contact'        => '',
			'msg_phone'      => 'সঠিক মোবাইল নম্বর দিন — ০১ দিয়ে শুরু ১১ সংখ্যার নম্বর।',
			'msg_dup'        => 'একটু অপেক্ষা করুন, আপনি সম্প্রতি একটি অর্ডার করেছেন। প্রয়োজনে যোগাযোগ করুনঃ {contact}',
			'msg_ip'         => 'আপনার অর্ডারটি গ্রহণ করা যায়নি। সমস্যাটি সমাধানে যোগাযোগ করুনঃ {contact}',
			'msg_block'      => 'দুঃখিত, এই নম্বর থেকে অর্ডার গ্রহণ করা সম্ভব নয়। প্রয়োজনে যোগাযোগ করুনঃ {contact}',
			'msg_junk'       => 'অনুগ্রহ করে আপনার সঠিক নাম ও পূর্ণ ঠিকানা লিখুন। টেস্ট বা এলোমেলো লেখা দিয়ে অর্ডার করা যাবে না।',
			'capi_enabled'   => 0,
			'capi_pixel'     => '',
			'capi_token'     => '',
			'capi_test'      => '',
			'capi_status'    => array( 'completed' ),
			'capi_guard'     => 1,
		);
	}

	public static function get( $key ) {
		$o = wp_parse_args( (array) get_option( self::OPT, array() ), self::defaults() );
		return isset( $o[ $key ] ) ? $o[ $key ] : null;
	}

	public function declare_hpos() {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}

	public function wc_missing_notice() {
		if ( ! class_exists( 'WooCommerce' ) && current_user_can( 'activate_plugins' ) ) {
			echo '<div class="notice notice-warning"><p><b>SWS Fake Order Guard:</b> কাজ করার জন্য WooCommerce চালু থাকতে হবে।</p></div>';
		}
	}

	public function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=sws-fake-order-guard' ) ) . '">Settings</a>' );
		return $links;
	}

	public function admin_menu() {
		$parent = class_exists( 'WooCommerce' ) ? 'woocommerce' : 'options-general.php';
		$n     = class_exists( 'SWS_FOG_Incomplete' ) ? SWS_FOG_Incomplete::menu_count() : 0;
		$label = 'Fake Order Guard' . ( $n ? ' <span class="awaiting-mod">' . (int) $n . '</span>' : '' );
		add_submenu_page( $parent, 'Fake Order Guard', $label, 'manage_woocommerce', 'sws-fake-order-guard', array( $this, 'render_page' ) );
	}

	public function register_settings() {
		register_setting( 'sws_fog_group', self::OPT, array( 'sanitize_callback' => array( $this, 'sanitize' ) ) );
	}

	public function sanitize( $in ) {
		$in  = (array) $in;
		$d   = self::defaults();
		$out = array(
			'enabled'        => empty( $in['enabled'] ) ? 0 : 1,
			'validate_phone' => empty( $in['validate_phone'] ) ? 0 : 1,
			'block_test'     => empty( $in['block_test'] ) ? 0 : 1,
			'block_gibberish'=> empty( $in['block_gibberish'] ) ? 0 : 1,
			'junk_words'     => '' === trim( (string) ( $in['junk_words'] ?? '' ) ) ? $d['junk_words'] : sanitize_textarea_field( $in['junk_words'] ),
			'window_hours'   => max( 0.25, min( 720, (float) ( $in['window_hours'] ?? $d['window_hours'] ) ) ),
			'phone_limit'    => max( 0, min( 100, (int) ( $in['phone_limit'] ?? $d['phone_limit'] ) ) ),
			'ip_limit'       => max( 0, min( 100, (int) ( $in['ip_limit'] ?? $d['ip_limit'] ) ) ),
			'contact'        => sanitize_text_field( $in['contact'] ?? '' ),
			'capi_enabled'   => empty( $in['capi_enabled'] ) ? 0 : 1,
			'capi_pixel'     => preg_replace( '/[^0-9]/', '', (string) ( $in['capi_pixel'] ?? '' ) ),
			'capi_token'     => preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) ( $in['capi_token'] ?? '' ) ),
			'capi_test'      => sanitize_text_field( $in['capi_test'] ?? '' ),
			'capi_status'    => array_values( array_intersect( array( 'processing', 'completed' ), (array) ( $in['capi_status'] ?? array() ) ) ),
			'capi_guard'     => empty( $in['capi_guard'] ) ? 0 : 1,
		);
		if ( ! $out['capi_status'] ) {
			$out['capi_status'] = array( 'completed' );
		}
		foreach ( array( 'msg_phone', 'msg_dup', 'msg_ip', 'msg_block', 'msg_junk' ) as $k ) {
			$v         = sanitize_textarea_field( $in[ $k ] ?? '' );
			$out[ $k ] = '' === trim( $v ) ? $d[ $k ] : $v;
		}
		// Settings page has two forms (main / CAPI); keep the other form's saved values.
		$section = $in['_section'] ?? '';
		if ( 'main' === $section || 'capi' === $section ) {
			$old = wp_parse_args( (array) get_option( self::OPT, array() ), $d );
			foreach ( array_keys( $out ) as $k ) {
				$is_capi = 0 === strpos( $k, 'capi_' );
				if ( ( 'main' === $section ) === $is_capi ) {
					$out[ $k ] = $old[ $k ];
				}
			}
		}
		return $out;
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$o    = wp_parse_args( (array) get_option( self::OPT, array() ), self::defaults() );
		$n    = self::OPT;
		$tabs = array(
			'general'    => 'General',
			'blocklist'  => 'ফোন নম্বর ব্লক',
			'capi'       => 'Facebook Conversions API',
			'incomplete' => 'Incomplete Orders',
			'orders'     => 'Order Management',
		);
		$tab  = sanitize_key( $_GET['tab'] ?? 'general' ); // phpcs:ignore
		$tab  = isset( $tabs[ $tab ] ) ? $tab : 'general';
		$icn  = class_exists( 'SWS_FOG_Incomplete' ) ? SWS_FOG_Incomplete::menu_count() : 0;
		?>
		<div class="wrap sws-fog-wrap">
			<h1>SWS Fake Order Guard</h1>
			<?php settings_errors(); ?>
			<nav class="nav-tab-wrapper" style="margin-bottom:16px">
				<?php foreach ( $tabs as $k => $l ) : ?>
					<a href="<?php echo esc_url( self::page_url( 'general' === $k ? array() : array( 'tab' => $k ) ) ); ?>" class="nav-tab<?php echo $k === $tab ? ' nav-tab-active' : ''; ?>"><?php echo esc_html( $l ); ?><?php if ( 'incomplete' === $k && $icn ) : ?> <span class="awaiting-mod count-<?php echo (int) $icn; ?>" style="display:inline-block;vertical-align:top;box-sizing:border-box;margin:1px 0 -1px 4px;padding:0 5px;min-width:18px;height:18px;border-radius:9px;background:#d63638;color:#fff;font-size:11px;line-height:1.6;text-align:center"><?php echo (int) $icn; ?></span><?php endif; ?></a>
				<?php endforeach; ?>
			</nav>
			<?php if ( 'general' === $tab ) : ?>
			<p>মোবাইল নম্বর যাচাই এবং একই নম্বর/IP থেকে বারবার অর্ডার ঠেকানোর সেটিংস। কোনো অর্ডার <b>ডিলিট / ট্র্যাশ / Completed</b> করলে সেই নম্বর ও IP-এর লিমিট সাথে সাথে খুলে যায়।</p>
			<form method="post" action="options.php">
				<?php settings_fields( 'sws_fog_group' ); ?>
				<table class="form-table" role="presentation">
					<tr><th scope="row">গার্ড চালু</th><td><label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[enabled]" value="1" <?php checked( $o['enabled'], 1 ); ?>> অর্ডার লিমিট চালু রাখুন</label></td></tr>
					<tr><th scope="row">মোবাইল নম্বর যাচাই</th><td><label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[validate_phone]" value="1" <?php checked( $o['validate_phone'], 1 ); ?>> ০১ দিয়ে শুরু ১১ সংখ্যার বাংলাদেশি নম্বর বাধ্যতামূলক</label><p class="description">ভুয়া প্যাটার্নের নম্বরও বাতিল হবে: ভুল অপারেটর কোড (010/011/012), সিরিয়াল ডিজিট (01312345678, 01987654321), একই ডিজিট বারবার (01700000000) বা জোড়া পুনরাবৃত্তি (01712121212)।</p></td></tr>
					<tr><th scope="row"><label for="fog-jw">টেস্ট অর্ডার ব্লক</label></th><td><label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[block_test]" value="1" <?php checked( $o['block_test'], 1 ); ?>> নাম, ঠিকানা, ইমেইল বা নোটে নিচের কোনো শব্দ থাকলে অর্ডার হবে না</label><br><textarea id="fog-jw" rows="5" class="large-text" style="max-width:420px;margin-top:8px" name="<?php echo esc_attr( $n ); ?>[junk_words]"><?php echo esc_textarea( $o['junk_words'] ); ?></textarea><p class="description">প্রতি লাইনে একটি শব্দ বা বাক্যাংশ। বড়/ছোট হাতের অক্ষর আর অক্ষর দ্বিগুণ লেখা (যেমন TEST, teest, test123) সবই ধরা পড়বে। পুরো শব্দ মিললেই ব্লক হবে, তাই "contest" বা "Testament" এর মতো শব্দ আটকাবে না।</p></td></tr>
					<tr><th scope="row">এলোমেলো লেখা ব্লক</th><td><label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[block_gibberish]" value="1" <?php checked( $o['block_gibberish'], 1 ); ?>> নাম ও ঠিকানায় এলোমেলো অক্ষর দিলে অর্ডার হবে না</label><p class="description">যেমন: asdfgh, sdfgh, kkkkk, ককককক, অথবা অক্ষর ছাড়া শুধু সংখ্যা/চিহ্ন (123, ...)।</p></td></tr>
					<tr><th scope="row"><label for="fog-win">সময়সীমা (ঘণ্টা)</label></th><td><input id="fog-win" type="number" min="0.25" max="720" step="0.25" class="small-text" name="<?php echo esc_attr( $n ); ?>[window_hours]" value="<?php echo esc_attr( $o['window_hours'] ); ?>"><p class="description">এই সময়ের মধ্যে লিমিট গোনা হবে। যেমন 12 = ১২ ঘণ্টা।</p></td></tr>
					<tr><th scope="row"><label for="fog-pl">একই নম্বরে সর্বোচ্চ অর্ডার</label></th><td><input id="fog-pl" type="number" min="0" max="100" class="small-text" name="<?php echo esc_attr( $n ); ?>[phone_limit]" value="<?php echo esc_attr( $o['phone_limit'] ); ?>"><p class="description">0 দিলে নম্বরের লিমিট বন্ধ থাকবে।</p></td></tr>
					<tr><th scope="row"><label for="fog-il">একই IP-তে সর্বোচ্চ অর্ডার</label></th><td><input id="fog-il" type="number" min="0" max="100" class="small-text" name="<?php echo esc_attr( $n ); ?>[ip_limit]" value="<?php echo esc_attr( $o['ip_limit'] ); ?>"><p class="description">0 দিলে IP লিমিট বন্ধ থাকবে।</p></td></tr>
					<tr><th scope="row"><label for="fog-ct">যোগাযোগের নম্বর</label></th><td><input id="fog-ct" type="text" class="regular-text" name="<?php echo esc_attr( $n ); ?>[contact]" value="<?php echo esc_attr( $o['contact'] ); ?>" placeholder="01XXXXXXXXX"><p class="description">মেসেজের <code>{contact}</code> এর জায়গায় এই নম্বর বসবে।</p></td></tr>
					<tr><th scope="row"><label for="fog-m1">ভুল নম্বরের মেসেজ</label></th><td><textarea id="fog-m1" rows="2" class="large-text" name="<?php echo esc_attr( $n ); ?>[msg_phone]"><?php echo esc_textarea( $o['msg_phone'] ); ?></textarea></td></tr>
					<tr><th scope="row"><label for="fog-m2">একই নম্বরের লিমিট মেসেজ</label></th><td><textarea id="fog-m2" rows="2" class="large-text" name="<?php echo esc_attr( $n ); ?>[msg_dup]"><?php echo esc_textarea( $o['msg_dup'] ); ?></textarea></td></tr>
					<tr><th scope="row"><label for="fog-m3">একই IP-এর লিমিট মেসেজ</label></th><td><textarea id="fog-m3" rows="2" class="large-text" name="<?php echo esc_attr( $n ); ?>[msg_ip]"><?php echo esc_textarea( $o['msg_ip'] ); ?></textarea></td></tr>
					<tr><th scope="row"><label for="fog-m4">ব্লকড নম্বরের মেসেজ</label></th><td><textarea id="fog-m4" rows="2" class="large-text" name="<?php echo esc_attr( $n ); ?>[msg_block]"><?php echo esc_textarea( $o['msg_block'] ); ?></textarea></td></tr>
					<tr><th scope="row"><label for="fog-m5">টেস্ট/এলোমেলো লেখার মেসেজ</label></th><td><textarea id="fog-m5" rows="2" class="large-text" name="<?php echo esc_attr( $n ); ?>[msg_junk]"><?php echo esc_textarea( $o['msg_junk'] ); ?></textarea><p class="description">যেকোনো মেসেজে <code>{contact}</code> = যোগাযোগের নম্বর, <code>{wait}</code> = বাকি সময় (যেমন "৩ ঘণ্টা ২০ মিনিট")।</p></td></tr>
				</table>
				<input type="hidden" name="<?php echo esc_attr( $n ); ?>[_section]" value="main">
				<?php submit_button( 'সেভ করুন' ); ?>
			</form>
			<?php elseif ( 'blocklist' === $tab ) : ?>
				<?php $this->render_blocklist(); ?>
			<?php elseif ( 'capi' === $tab ) : ?>
			<form method="post" action="options.php">
				<?php settings_fields( 'sws_fog_group' ); ?>
				<input type="hidden" name="<?php echo esc_attr( $n ); ?>[_section]" value="capi">
				<h2 style="margin-top:0">যাচাই করা অর্ডারে Facebook Purchase (Conversions API)</h2>
				<p style="max-width:820px">ক্যাশ অন ডেলিভারি বা ম্যানুয়াল বিকাশ/নগদে অর্ডার হলেই Purchase ইভেন্ট গেলে ফেক অর্ডারও Facebook-এ "সেল" হিসেবে গোনা হয়, আর অ্যাড ভুল অডিয়েন্স শেখে। এটা চালু করলে Purchase ইভেন্ট যাবে শুধু তখন, যখন আপনি অর্ডার যাচাই করে নিচের স্ট্যাটাসে নেবেন। ইভেন্ট সার্ভার থেকে সরাসরি Meta-তে যায়, তাই অ্যাড ব্লকারেও আটকায় না।</p>
				<table class="form-table" role="presentation">
					<tr><th scope="row">চালু করুন</th><td><label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[capi_enabled]" value="1" <?php checked( $o['capi_enabled'], 1 ); ?>> যাচাই করা অর্ডারে Purchase ইভেন্ট পাঠান</label></td></tr>
					<tr><th scope="row"><label for="fog-px">Pixel (Dataset) ID</label></th><td><input id="fog-px" type="text" class="regular-text" name="<?php echo esc_attr( $n ); ?>[capi_pixel]" value="<?php echo esc_attr( $o['capi_pixel'] ); ?>" placeholder="123456789012345"></td></tr>
					<tr><th scope="row"><label for="fog-tk">Conversions API Access Token</label></th><td><input id="fog-tk" type="password" autocomplete="off" class="large-text" style="max-width:520px" name="<?php echo esc_attr( $n ); ?>[capi_token]" value="<?php echo esc_attr( $o['capi_token'] ); ?>"><p class="description">Events Manager → আপনার Pixel → Settings → Conversions API → <b>Generate access token</b>।</p></td></tr>
					<tr><th scope="row">কোন স্ট্যাটাসে পাঠাবে</th><td>
						<?php foreach ( array( 'completed' => 'Completed', 'processing' => 'Processing' ) as $sk => $sl ) : ?>
							<label style="margin-right:16px"><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[capi_status][]" value="<?php echo esc_attr( $sk ); ?>" <?php checked( in_array( $sk, (array) $o['capi_status'], true ) ); ?>> <?php echo esc_html( $sl ); ?></label>
						<?php endforeach; ?>
						<p class="description">অর্ডার প্রথমবার এই স্ট্যাটাসে গেলে একবারই ইভেন্ট যাবে। On-hold, Pending, Cancelled বা Failed অর্ডারে কখনো যাবে না।</p></td></tr>
					<tr><th scope="row">ব্রাউজার Purchase আটকান</th><td><label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[capi_guard]" value="1" <?php checked( $o['capi_guard'], 1 ); ?>> যাচাই না হওয়া অর্ডারের থ্যাংক-ইউ পেজে অন্য প্লাগইনের ব্রাউজার Purchase ইভেন্ট আটকান</label><p class="description">PixelYourSite, Facebook for WooCommerce ইত্যাদির ব্রাউজার (fbq) Purchase আটকাবে। তবে ওদের <b>সার্ভার-সাইড</b> Purchase আটকানো যায় না, তাই সবচেয়ে ভালো হলো ওই প্লাগইনে Purchase ইভেন্ট বন্ধ করে দেওয়া। বাকি ইভেন্ট (PageView, AddToCart, InitiateCheckout) আগের মতোই চলবে।</p></td></tr>
					<tr><th scope="row"><label for="fog-te">Test Event Code</label></th><td><input id="fog-te" type="text" class="regular-text" name="<?php echo esc_attr( $n ); ?>[capi_test]" value="<?php echo esc_attr( $o['capi_test'] ); ?>" placeholder="TEST12345"><p class="description">শুধু টেস্টের সময় দিন (Events Manager → Test events)। টেস্ট শেষে অবশ্যই খালি করুন।</p></td></tr>
				</table>
				<?php submit_button( 'সেভ করুন' ); ?>
			</form>
			<?php elseif ( 'incomplete' === $tab && class_exists( 'SWS_FOG_Incomplete' ) ) : ?>
				<?php SWS_FOG_Incomplete::instance()->render_page(); ?>
			<?php elseif ( 'orders' === $tab && class_exists( 'SWS_FOG_Orders' ) ) : ?>
				<?php SWS_FOG_Orders::instance()->render_page(); ?>
			<?php endif; ?>
			<p style="color:#787c82;margin-top:30px">Developed by <a href="https://sabbirwebsolutions.com" target="_blank" rel="noopener">Sabbir Web Solutions</a></p>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------- blocklist */

	public static function blocklist() {
		$l = get_option( self::BL, array() );
		return is_array( $l ) ? $l : array();
	}

	public static function is_blocked( $digits ) {
		$l = self::blocklist();
		return '' !== $digits && isset( $l[ $digits ] );
	}

	private static function save_blocklist( $l ) {
		if ( false === get_option( self::BL, false ) ) {
			add_option( self::BL, $l, '', 'no' );
		} else {
			update_option( self::BL, $l, false );
		}
	}

	/** @return bool true if newly added. */
	public static function block( $digits, $order_id = 0 ) {
		if ( ! self::is_valid_phone( $digits ) ) {
			return false;
		}
		$l = self::blocklist();
		if ( isset( $l[ $digits ] ) ) {
			return false;
		}
		$l[ $digits ] = array( 't' => time(), 'o' => (int) $order_id, 'u' => get_current_user_id() );
		self::save_blocklist( $l );
		return true;
	}

	public static function unblock( $digits ) {
		$l = self::blocklist();
		if ( ! isset( $l[ $digits ] ) ) {
			return false;
		}
		unset( $l[ $digits ] );
		self::save_blocklist( $l );
		return true;
	}

	private static function order_digits( $order ) {
		return $order ? self::normalize_phone( $order->get_billing_phone() ) : '';
	}

	private static function bn( $n ) {
		return strtr( (string) $n, array( '0' => '০', '1' => '১', '2' => '২', '3' => '৩', '4' => '৪', '5' => '৫', '6' => '৬', '7' => '৭', '8' => '৮', '9' => '৯' ) );
	}

	private static function page_url( $args = array() ) {
		$parent = class_exists( 'WooCommerce' ) ? 'admin.php' : 'options-general.php';
		return add_query_arg( array_merge( array( 'page' => 'sws-fake-order-guard' ), $args ), admin_url( $parent ) );
	}

	private static function back( $args ) {
		$ref = wp_get_referer();
		$ref = $ref ? $ref : self::page_url();
		$ref = remove_query_arg( array( 'sws_fog_msg', 'sws_fog_n', 'sws_fog_x' ), $ref );
		wp_safe_redirect( add_query_arg( $args, $ref ) );
		exit;
	}

	private static function block_url( $order_id ) {
		return wp_nonce_url( admin_url( 'admin-post.php?action=sws_fog_block&order_id=' . (int) $order_id ), 'sws_fog_block_' . (int) $order_id );
	}

	private static function unblock_url( $digits ) {
		return wp_nonce_url( admin_url( 'admin-post.php?action=sws_fog_unblock&phone=' . rawurlencode( $digits ) ), 'sws_fog_unblock_' . $digits );
	}

	private function render_blocklist() {
		$all = self::blocklist();
		$s   = isset( $_GET['fog_s'] ) ? self::normalize_phone( sanitize_text_field( wp_unslash( $_GET['fog_s'] ) ) ) : ''; // phpcs:ignore
		if ( '' !== $s ) {
			$all = array_filter( $all, function ( $k ) use ( $s ) {
				return false !== strpos( (string) $k, $s );
			}, ARRAY_FILTER_USE_KEY );
		}
		uasort( $all, function ( $a, $b ) {
			return (int) ( $b['t'] ?? 0 ) - (int) ( $a['t'] ?? 0 );
		} );
		$total = count( $all );
		$pages = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		$paged = isset( $_GET['fog_p'] ) ? max( 1, min( $pages, (int) $_GET['fog_p'] ) ) : 1; // phpcs:ignore
		$rows  = array_slice( $all, ( $paged - 1 ) * self::PER_PAGE, self::PER_PAGE, true );
		?>
		<h2 id="sws-fog-blocklist" style="margin-top:0">ব্লক করা নম্বর (<?php echo esc_html( self::bn( count( self::blocklist() ) ) ); ?>টি)</h2>
		<p>এই লিস্টের কোনো নম্বর দিয়ে ওয়েবসাইটে অর্ডার করা যাবে না। লিস্ট থেকে রিমুভ করলে আবার অর্ডার করা যাবে। অর্ডার লিস্টের <b>ফেক গার্ড</b> কলাম থেকেও এক ক্লিকে ব্লক/আনব্লক করা যায়।</p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:640px;margin-bottom:20px">
			<input type="hidden" name="action" value="sws_fog_bl_add">
			<?php wp_nonce_field( 'sws_fog_bl_add' ); ?>
			<label for="fog-bl-add"><b>নতুন নম্বর যোগ করুন</b></label>
			<textarea id="fog-bl-add" name="numbers" rows="4" class="large-text" placeholder="01XXXXXXXXX&#10;01YYYYYYYYY, +8801ZZZZZZZZZ"></textarea>
			<p class="description">প্রতি লাইনে একটি, অথবা কমা দিয়ে আলাদা করে যত ইচ্ছা নম্বর দিন। +880 / বাংলা সংখ্যা সব ফরম্যাট চলবে।</p>
			<?php submit_button( 'ব্লকলিস্টে যোগ করুন', 'primary', 'submit', false ); ?>
		</form>

		<form method="get" style="margin-bottom:10px">
			<input type="hidden" name="page" value="sws-fake-order-guard">
			<input type="hidden" name="tab" value="blocklist">
			<input type="search" name="fog_s" value="<?php echo esc_attr( $s ); ?>" placeholder="নম্বর খুঁজুন">
			<button class="button">খুঁজুন</button>
			<?php if ( '' !== $s ) : ?><a class="button-link" href="<?php echo esc_url( self::page_url( array( 'tab' => 'blocklist' ) ) ); ?>">সব দেখুন</a><?php endif; ?>
		</form>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="sws_fog_bl_bulk">
			<?php wp_nonce_field( 'sws_fog_bl_bulk' ); ?>
			<table class="widefat striped" style="max-width:900px">
				<thead><tr>
					<td class="check-column"><input type="checkbox" onclick="var c=this.checked;this.closest('table').querySelectorAll('tbody input[type=checkbox]').forEach(function(i){i.checked=c;});"></td>
					<th>নম্বর</th><th>যেভাবে যোগ হয়েছে</th><th>তারিখ</th><th style="width:110px"></th>
				</tr></thead>
				<tbody>
				<?php if ( ! $rows ) : ?>
					<tr><td colspan="5"><?php echo '' !== $s ? 'কোনো নম্বর পাওয়া যায়নি।' : 'এখনো কোনো নম্বর ব্লক করা হয়নি।'; ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $rows as $ph => $e ) :
					$ph    = (string) $ph;
					$oid   = (int) ( $e['o'] ?? 0 );
					$order = $oid && function_exists( 'wc_get_order' ) ? wc_get_order( $oid ) : false;
					?>
					<tr>
						<th scope="row" class="check-column"><input type="checkbox" name="phones[]" value="<?php echo esc_attr( $ph ); ?>"></th>
						<td><b><?php echo esc_html( $ph ); ?></b></td>
						<td><?php
						if ( $order ) {
							echo 'অর্ডার <a href="' . esc_url( $order->get_edit_order_url() ) . '">#' . esc_html( $order->get_order_number() ) . '</a> থেকে';
						} elseif ( $oid ) {
							echo 'অর্ডার #' . esc_html( $oid ) . ' থেকে';
						} else {
							echo 'ম্যানুয়ালি';
						}
						?></td>
						<td><?php echo ! empty( $e['t'] ) ? esc_html( wp_date( 'd M Y, h:i A', (int) $e['t'] ) ) : '—'; ?></td>
						<td><a class="button button-small" href="<?php echo esc_url( self::unblock_url( $ph ) ); ?>">রিমুভ</a></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php if ( $rows ) : ?>
				<p><button class="button" onclick="return confirm('সিলেক্ট করা নম্বরগুলো ব্লকলিস্ট থেকে রিমুভ করবেন?');">সিলেক্ট করা নম্বর রিমুভ করুন</button></p>
			<?php endif; ?>
		</form>
		<?php
		if ( $pages > 1 ) {
			echo '<p class="sws-fog-pages">';
			for ( $i = 1; $i <= $pages; $i++ ) {
				$url = self::page_url( array_filter( array( 'tab' => 'blocklist', 'fog_p' => $i, 'fog_s' => $s ) ) );
				echo $i === $paged ? '<span class="button button-primary button-small">' . esc_html( $i ) . '</span> ' : '<a class="button button-small" href="' . esc_url( $url ) . '">' . esc_html( $i ) . '</a> ';
			}
			echo '</p>';
		}
	}

	/* ---------------------------------------------------------------- blocklist: admin actions */

	private static function can() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'অনুমতি নেই।', 403 );
		}
	}

	public function handle_block() {
		self::can();
		$oid = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
		check_admin_referer( 'sws_fog_block_' . $oid );
		$order  = wc_get_order( $oid );
		$digits = self::order_digits( $order );
		if ( ! self::is_valid_phone( $digits ) ) {
			self::back( array( 'sws_fog_msg' => 'invalid' ) );
		}
		if ( self::block( $digits, $oid ) ) {
			$order->add_order_note( 'Fake Order Guard: নম্বর ' . $digits . ' ব্লকলিস্টে যোগ করা হয়েছে।' );
			self::back( array( 'sws_fog_msg' => 'blocked', 'sws_fog_x' => $digits ) );
		}
		self::back( array( 'sws_fog_msg' => 'exists', 'sws_fog_x' => $digits ) );
	}

	public function handle_unblock() {
		self::can();
		$digits = isset( $_GET['phone'] ) ? self::normalize_phone( sanitize_text_field( wp_unslash( $_GET['phone'] ) ) ) : '';
		check_admin_referer( 'sws_fog_unblock_' . $digits );
		self::unblock( $digits );
		self::back( array( 'sws_fog_msg' => 'unblocked', 'sws_fog_x' => $digits ) );
	}

	public function handle_bl_add() {
		self::can();
		check_admin_referer( 'sws_fog_bl_add' );
		$raw   = isset( $_POST['numbers'] ) ? sanitize_textarea_field( wp_unslash( $_POST['numbers'] ) ) : '';
		$parts = preg_split( '/[\r\n,;]+/', $raw );
		$l     = self::blocklist();
		$added = 0;
		$bad   = 0;
		foreach ( (array) $parts as $p ) {
			if ( '' === trim( $p ) ) {
				continue;
			}
			$d = self::normalize_phone( $p );
			if ( ! self::is_valid_phone( $d ) ) {
				$bad++;
				continue;
			}
			if ( ! isset( $l[ $d ] ) ) {
				$l[ $d ] = array( 't' => time(), 'o' => 0, 'u' => get_current_user_id() );
				$added++;
			}
		}
		if ( $added ) {
			self::save_blocklist( $l );
		}
		self::back( array( 'sws_fog_msg' => 'added', 'sws_fog_n' => $added, 'sws_fog_x' => $bad ) );
	}

	public function handle_bl_bulk() {
		self::can();
		check_admin_referer( 'sws_fog_bl_bulk' );
		$phones = isset( $_POST['phones'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['phones'] ) ) : array();
		$l      = self::blocklist();
		$n      = 0;
		foreach ( $phones as $p ) {
			$d = self::normalize_phone( $p );
			if ( isset( $l[ $d ] ) ) {
				unset( $l[ $d ] );
				$n++;
			}
		}
		if ( $n ) {
			self::save_blocklist( $l );
		}
		self::back( array( 'sws_fog_msg' => 'removed', 'sws_fog_n' => $n ) );
	}

	public function action_notices() {
		if ( empty( $_GET['sws_fog_msg'] ) || ! current_user_can( 'manage_woocommerce' ) ) { // phpcs:ignore
			return;
		}
		$c = sanitize_key( $_GET['sws_fog_msg'] ); // phpcs:ignore
		$n = isset( $_GET['sws_fog_n'] ) ? (int) $_GET['sws_fog_n'] : 0; // phpcs:ignore
		$x = isset( $_GET['sws_fog_x'] ) ? sanitize_text_field( wp_unslash( $_GET['sws_fog_x'] ) ) : ''; // phpcs:ignore
		$t = 'success';
		switch ( $c ) {
			case 'blocked':
				$m = 'নম্বর <b>' . esc_html( $x ) . '</b> ব্লক করা হয়েছে। এই নম্বর থেকে আর অর্ডার করা যাবে না।';
				break;
			case 'exists':
				$m = 'নম্বর <b>' . esc_html( $x ) . '</b> আগে থেকেই ব্লকলিস্টে আছে।';
				$t = 'info';
				break;
			case 'unblocked':
				$m = 'নম্বর <b>' . esc_html( $x ) . '</b> ব্লকলিস্ট থেকে রিমুভ করা হয়েছে। এখন আবার অর্ডার করা যাবে।';
				break;
			case 'added':
				$m = self::bn( $n ) . 'টি নতুন নম্বর ব্লকলিস্টে যোগ হয়েছে।';
				if ( (int) $x > 0 ) {
					$m .= ' ' . self::bn( (int) $x ) . 'টি নম্বর সঠিক না হওয়ায় বাদ দেওয়া হয়েছে।';
					$t  = 'warning';
				}
				break;
			case 'removed':
				$m = self::bn( $n ) . 'টি নম্বর ব্লকলিস্ট থেকে রিমুভ করা হয়েছে।';
				break;
			case 'bulkblocked':
				$m = self::bn( $n ) . 'টি অর্ডারের নম্বর ব্লক করা হয়েছে।';
				break;
			case 'invalid':
				$m = 'এই অর্ডারে সঠিক মোবাইল নম্বর পাওয়া যায়নি, তাই ব্লক করা যায়নি।';
				$t = 'error';
				break;
			default:
				return;
		}
		echo '<div class="notice notice-' . esc_attr( $t ) . ' is-dismissible"><p><b>Fake Order Guard:</b> ' . wp_kses_post( $m ) . '</p></div>';
	}

	public function admin_css() {
		echo '<style>.column-sws_fog{width:110px}.sws-fog-badge{display:inline-block;background:#d63638;color:#fff;border-radius:3px;padding:1px 7px;font-size:11px;font-weight:600;margin-bottom:3px}.sws-fog-col .button-small{min-height:24px;line-height:22px}</style>';
	}

	/* ---------------------------------------------------------------- blocklist: orders list */

	public function add_column( $cols ) {
		$out = array();
		foreach ( $cols as $k => $v ) {
			$out[ $k ] = $v;
			if ( 'order_status' === $k ) {
				$out['sws_fog'] = 'ফেক গার্ড';
			}
		}
		if ( ! isset( $out['sws_fog'] ) ) {
			$out['sws_fog'] = 'ফেক গার্ড';
		}
		return $out;
	}

	public function render_column_legacy( $col, $post_id ) {
		if ( 'sws_fog' === $col ) {
			$this->column_html( wc_get_order( $post_id ) );
		}
	}

	public function render_column_hpos( $col, $order ) {
		if ( 'sws_fog' === $col ) {
			$this->column_html( is_object( $order ) ? $order : wc_get_order( $order ) );
		}
	}

	private function column_html( $order ) {
		$d = self::order_digits( $order );
		if ( ! self::is_valid_phone( $d ) ) {
			echo '<span style="color:#a7aaad">—</span>';
			return;
		}
		echo '<div class="sws-fog-col">';
		if ( self::is_blocked( $d ) ) {
			echo '<span class="sws-fog-badge">ব্লকড</span><br><a class="button button-small" href="' . esc_url( self::unblock_url( $d ) ) . '">আনব্লক</a>';
		} else {
			echo '<a class="button button-small" style="color:#d63638;border-color:#d63638" href="' . esc_url( self::block_url( $order->get_id() ) ) . '" onclick="return confirm(\'' . esc_js( $d ) . ' নম্বরটি ব্লক করবেন? এই নম্বর থেকে আর অর্ডার করা যাবে না।\');">ব্লক</a>';
		}
		echo '</div>';
	}

	public function add_bulk_action( $actions ) {
		$actions['sws_fog_block'] = 'নম্বর ব্লক করুন (Fake Order Guard)';
		return $actions;
	}

	public function handle_order_bulk( $redirect, $action, $ids ) {
		if ( 'sws_fog_block' !== $action || ! current_user_can( 'manage_woocommerce' ) ) {
			return $redirect;
		}
		$n = 0;
		foreach ( (array) $ids as $id ) {
			$order = wc_get_order( $id );
			$d     = self::order_digits( $order );
			if ( self::block( $d, $order ? $order->get_id() : 0 ) ) {
				$order->add_order_note( 'Fake Order Guard: নম্বর ' . $d . ' ব্লকলিস্টে যোগ করা হয়েছে।' );
				$n++;
			}
		}
		return add_query_arg( array( 'sws_fog_msg' => 'bulkblocked', 'sws_fog_n' => $n ), remove_query_arg( array( 'sws_fog_msg', 'sws_fog_n', 'sws_fog_x' ), $redirect ) );
	}

	public function order_screen_box( $order ) {
		$d = self::order_digits( $order );
		if ( ! self::is_valid_phone( $d ) || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		echo '<p class="form-field form-field-wide"><strong>Fake Order Guard:</strong><br>';
		if ( self::is_blocked( $d ) ) {
			echo '<span class="sws-fog-badge">ব্লকড</span> <a class="button button-small" href="' . esc_url( self::unblock_url( $d ) ) . '">আনব্লক করুন</a>';
		} else {
			echo '<a class="button button-small" style="color:#d63638;border-color:#d63638" href="' . esc_url( self::block_url( $order->get_id() ) ) . '" onclick="return confirm(\'' . esc_js( $d ) . ' নম্বরটি ব্লক করবেন?\');">এই নম্বর ব্লক করুন</a>';
		}
		echo '</p>';
	}

	/* ---------------------------------------------------------------- helpers */

	private static function window() {
		return (int) round( (float) self::get( 'window_hours' ) * HOUR_IN_SECONDS );
	}

	public static function normalize_phone( $phone ) {
		$phone  = preg_replace_callback( '/[\x{09E6}-\x{09EF}]/u', function ( $m ) {
			return (string) ( mb_ord( $m[0], 'UTF-8' ) - 0x09E6 );
		}, (string) $phone );
		$digits = preg_replace( '/[^0-9]/', '', $phone );
		if ( 13 === strlen( $digits ) && '880' === substr( $digits, 0, 3 ) ) {
			$digits = substr( $digits, 2 );
		}
		if ( 10 === strlen( $digits ) && '1' === substr( $digits, 0, 1 ) ) {
			$digits = '0' . $digits;
		}
		return $digits;
	}

	/**
	 * Fake-looking number: wrong operator code (010/011/012) or a made-up
	 * subscriber part like 12345678, 87654321, 00000000, 12121212.
	 */
	public static function is_fake_phone( $d ) {
		if ( ! self::is_valid_phone( $d ) ) {
			return true;
		}
		if ( ! preg_match( '/^01[3-9]/', $d ) ) {
			return true;
		}
		$sub = substr( $d, 3 ); // 8 digits after operator code.
		if ( preg_match( '/(\d)\1{5,}/', $sub ) ) {
			return true; // same digit 6+ times in a row.
		}
		if ( substr( $sub, 0, 2 ) === substr( $sub, 2, 2 ) && substr( $sub, 0, 4 ) === substr( $sub, 4, 4 ) ) {
			return true; // 12121212 style.
		}
		$up   = 1;
		$down = 1;
		for ( $i = 1; $i < 8; $i++ ) {
			$a    = (int) $sub[ $i - 1 ];
			$b    = (int) $sub[ $i ];
			$up   = ( ( $a + 1 ) % 10 === $b ) ? $up + 1 : 1;
			$down = ( ( $a + 9 ) % 10 === $b ) ? $down + 1 : 1;
			if ( $up >= 6 || $down >= 6 ) {
				return true; // 6+ digits in sequence: 123456, 987654, 789012.
			}
		}
		return false;
	}

	public static function is_valid_phone( $d ) {
		return 11 === strlen( $d ) && '01' === substr( $d, 0, 2 );
	}

	private static function ip() {
		$ip = class_exists( 'WC_Geolocation' ) ? WC_Geolocation::get_ip_address() : ( $_SERVER['REMOTE_ADDR'] ?? '' ); // phpcs:ignore
		$ip = filter_var( trim( (string) wp_unslash( $ip ) ), FILTER_VALIDATE_IP );
		return $ip ? $ip : '';
	}

	private static function human_wait( $s ) {
		$h = (int) floor( $s / 3600 );
		$m = (int) ceil( ( $s % 3600 ) / 60 );
		if ( 60 === $m ) {
			$h++;
			$m = 0;
		}
		$bn = function ( $n ) {
			return strtr( (string) $n, array( '0' => '০', '1' => '১', '2' => '২', '3' => '৩', '4' => '৪', '5' => '৫', '6' => '৬', '7' => '৭', '8' => '৮', '9' => '৯' ) );
		};
		if ( $h > 0 && $m > 0 ) {
			return $bn( $h ) . ' ঘণ্টা ' . $bn( $m ) . ' মিনিট';
		}
		if ( $h > 0 ) {
			return $bn( $h ) . ' ঘণ্টা';
		}
		return $bn( max( 1, $m ) ) . ' মিনিট';
	}

	private static function msg( $key, $wait = 0 ) {
		return strtr( (string) self::get( $key ), array(
			'{contact}' => (string) self::get( 'contact' ),
			'{wait}'    => $wait ? self::human_wait( $wait ) : '',
			'%s'        => $wait ? self::human_wait( $wait ) : '',
		) );
	}

	/**
	 * Entries still counting toward the limit. An entry stops counting once its
	 * window passes or its order is deleted, trashed or completed.
	 */
	private static function active_entries( $key ) {
		$list = get_transient( $key );
		if ( ! is_array( $list ) ) {
			return array();
		}
		$now = time();
		$win = self::window();
		$out = array();
		foreach ( $list as $e ) {
			$t = (int) ( $e['t'] ?? 0 );
			$o = (int) ( $e['o'] ?? 0 );
			if ( ( $now - $t ) >= $win ) {
				continue;
			}
			if ( $o ) {
				$order = wc_get_order( $o );
				if ( ! $order || $order->has_status( array( 'completed', 'trash' ) ) ) {
					continue;
				}
			}
			$out[] = array( 't' => $t, 'o' => $o );
		}
		return $out;
	}

	private static function seconds_left( $key, $limit ) {
		if ( $limit < 1 ) {
			return 0;
		}
		$list = self::active_entries( $key );
		if ( count( $list ) < $limit ) {
			return 0;
		}
		$times = wp_list_pluck( $list, 't' );
		sort( $times );
		$blocking = $times[ count( $times ) - $limit ];
		return max( 1, self::window() - ( time() - (int) $blocking ) );
	}

	private static function record( $key, $order_id ) {
		$list   = self::active_entries( $key );
		$list[] = array( 't' => time(), 'o' => (int) $order_id );
		set_transient( $key, $list, self::window() + 60 );
	}

	/* ---------------------------------------------------------------- junk / test text */

	/** Lowercase and collapse repeated letters: "TEEST" -> "test". */
	private static function squeeze( $s ) {
		$s = function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $s, 'UTF-8' ) : strtolower( (string) $s );
		return (string) preg_replace( '/(\p{L})\1+/u', '$1', $s );
	}

	private static function junk_words() {
		$out = array();
		foreach ( (array) preg_split( '/[\r\n,]+/u', (string) self::get( 'junk_words' ) ) as $w ) {
			$w = trim( self::squeeze( $w ) );
			if ( '' !== $w ) {
				$out[] = $w;
			}
		}
		return array_unique( $out );
	}

	public static function has_test_word( $text ) {
		$t = self::squeeze( $text );
		foreach ( self::junk_words() as $w ) {
			$q = str_replace( '\ ', '\s+', preg_quote( $w, '/' ) );
			$q = preg_replace( '/\s+/u', '\\s+', $q );
			if ( preg_match( '/(?<![\p{L}\p{M}])' . $q . '(?![\p{L}\p{M}])/u', $t ) ) {
				return true;
			}
		}
		return false;
	}

	public static function is_gibberish( $text ) {
		$t = function_exists( 'mb_strtolower' ) ? mb_strtolower( trim( (string) $text ), 'UTF-8' ) : strtolower( trim( (string) $text ) );
		if ( '' === $t ) {
			return false;
		}
		if ( ! preg_match( '/\p{L}/u', $t ) ) {
			return true; // only digits / symbols.
		}
		if ( preg_match( '/(\p{L}\p{M}*)\1{3,}/u', $t ) ) {
			return true; // same letter 4+ times: aaaa, কককক.
		}
		foreach ( array( 'asdf', 'sdfg', 'dfgh', 'fghj', 'ghjk', 'hjkl', 'qwer', 'zxcv', 'xcvb', 'cvbn', 'vbnm', 'uiop' ) as $k ) {
			if ( false !== strpos( $t, $k ) ) {
				return true; // keyboard row mashing.
			}
		}
		foreach ( (array) preg_split( '/[^a-z]+/', $t ) as $w ) {
			if ( strlen( $w ) >= 5 && ! preg_match( '/[aeiouy]/', $w ) ) {
				return true; // no vowel: sdfgh, bcdfk.
			}
			if ( preg_match( '/[bcdfghjklmnpqrstvwxz]{6,}/', $w ) ) {
				return true; // 6+ consonants in a row.
			}
		}
		return false;
	}

	/** @return bool true when name/address/notes look like a test or random text. */
	private static function junk_check( $data ) {
		$test = (int) self::get( 'block_test' );
		$gib  = (int) self::get( 'block_gibberish' );
		if ( ! $test && ! $gib ) {
			return false;
		}
		$gib_fields = array( 'billing_first_name', 'billing_last_name', 'billing_address_1', 'billing_city', 'shipping_first_name', 'shipping_last_name', 'shipping_address_1', 'shipping_city' );
		$all        = array_merge( $gib_fields, array( 'billing_company', 'billing_address_2', 'billing_email', 'shipping_company', 'shipping_address_2', 'order_comments' ) );
		foreach ( $all as $k ) {
			$v = isset( $data[ $k ] ) ? trim( (string) $data[ $k ] ) : '';
			if ( '' === $v ) {
				continue;
			}
			if ( $test && self::has_test_word( $v ) ) {
				return true;
			}
			if ( $gib && in_array( $k, $gib_fields, true ) && self::is_gibberish( $v ) ) {
				return true;
			}
		}
		return false;
	}

	/* ---------------------------------------------------------------- checkout */

	public function validate( $data, $errors ) {
		if ( self::junk_check( (array) $data ) ) {
			$errors->add( 'sws_fog_junk', self::msg( 'msg_junk' ) );
			return;
		}
		$raw = isset( $data['billing_phone'] ) ? trim( (string) $data['billing_phone'] ) : '';
		$on  = (int) self::get( 'enabled' );

		if ( '' !== $raw ) {
			$digits = self::normalize_phone( $raw );
			if ( ! self::is_valid_phone( $digits ) ) {
				if ( self::get( 'validate_phone' ) && ! $errors->get_error_message( 'billing_phone_validation' ) ) {
					$errors->add( 'sws_fog_invalid_phone', self::msg( 'msg_phone' ) );
				}
				return;
			}
			if ( self::get( 'validate_phone' ) && self::is_fake_phone( $digits ) ) {
				$errors->add( 'sws_fog_fake_phone', self::msg( 'msg_phone' ) );
				return;
			}
			if ( self::is_blocked( $digits ) ) {
				$errors->add( 'sws_fog_blocked', self::msg( 'msg_block' ) );
				return;
			}
			if ( $on ) {
				$left = self::seconds_left( 'sws_fog_p_' . md5( $digits ), (int) self::get( 'phone_limit' ) );
				if ( $left > 0 ) {
					$errors->add( 'sws_fog_duplicate_order', self::msg( 'msg_dup', $left ) );
					return;
				}
			}
		}

		if ( $on ) {
			$ip = self::ip();
			if ( '' !== $ip ) {
				$left = self::seconds_left( 'sws_fog_i_' . md5( $ip ), (int) self::get( 'ip_limit' ) );
				if ( $left > 0 ) {
					$errors->add( 'sws_fog_ip_limit', self::msg( 'msg_ip', $left ) );
				}
			}
		}
	}

	/** Block checkout (Store API). */
	public function store_api_check( $order, $request ) {
		$data = array( 'order_comments' => $order->get_customer_note() );
		foreach ( array( 'billing', 'shipping' ) as $t ) {
			foreach ( array( 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city' ) as $f ) {
				$m = "get_{$t}_{$f}";
				$data[ "{$t}_{$f}" ] = $order->$m();
			}
		}
		$data['billing_email'] = $order->get_billing_email();
		if ( self::junk_check( $data ) ) {
			self::store_api_fail( 'sws_fog_junk', self::msg( 'msg_junk' ) );
		}
		$d = self::order_digits( $order );
		if ( '' !== $d && self::get( 'validate_phone' ) && self::is_fake_phone( $d ) ) {
			self::store_api_fail( 'sws_fog_fake_phone', self::msg( 'msg_phone' ) );
		}
		if ( self::is_valid_phone( $d ) && self::is_blocked( $d ) ) {
			self::store_api_fail( 'sws_fog_blocked', self::msg( 'msg_block' ) );
		}
	}

	private static function store_api_fail( $code, $msg ) {
		if ( class_exists( '\\Automattic\\WooCommerce\\StoreApi\\Exceptions\\RouteException' ) ) {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( $code, $msg, 400 );
		}
		throw new \Exception( $msg );
	}

	public function record_order( $order_id ) {
		if ( ! self::get( 'enabled' ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$digits = self::normalize_phone( $order->get_billing_phone() );
		if ( self::is_valid_phone( $digits ) ) {
			self::record( 'sws_fog_p_' . md5( $digits ), $order_id );
		}
		$ip = self::ip();
		if ( '' !== $ip ) {
			self::record( 'sws_fog_i_' . md5( $ip ), $order_id );
		}
	}
	/* ---------------------------------------------------------------- verified purchase (Meta CAPI) */

	private static function capi_ready() {
		return self::get( 'capi_enabled' ) && '' !== (string) self::get( 'capi_pixel' ) && '' !== (string) self::get( 'capi_token' );
	}

	private static function verified_statuses() {
		$s = (array) self::get( 'capi_status' );
		return $s ? $s : array( 'completed' );
	}

	/** Save browser ids at checkout so the server event can be matched later. */
	public function capture_tracking( $order ) {
		$order = is_object( $order ) ? $order : wc_get_order( $order );
		if ( ! $order || $order->get_meta( '_sws_fog_ua' ) ) {
			return;
		}
		$c = function ( $k ) {
			return isset( $_COOKIE[ $k ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ $k ] ) ) : ''; // phpcs:ignore
		};
		$order->update_meta_data( '_sws_fog_fbp', $c( '_fbp' ) );
		$order->update_meta_data( '_sws_fog_fbc', $c( '_fbc' ) );
		$order->update_meta_data( '_sws_fog_ip', self::ip() );
		$order->update_meta_data( '_sws_fog_ua', isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 400 ) : '-' ); // phpcs:ignore
		$ref = wp_get_referer();
		$order->update_meta_data( '_sws_fog_src', $ref ? esc_url_raw( $ref ) : home_url( '/' ) );
		$order->save();
	}

	public function maybe_queue_purchase( $order_id, $from, $to, $order ) {
		if ( ! self::capi_ready() || ! in_array( $to, self::verified_statuses(), true ) ) {
			return;
		}
		$order = $order ? $order : wc_get_order( $order_id );
		if ( ! $order || $order->get_meta( '_sws_fog_capi_sent' ) ) {
			return;
		}
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( 'sws_fog_capi_send', array( (int) $order_id ), 'sws-fog' );
		} else {
			$this->send_purchase( $order_id );
		}
	}

	public function order_action( $actions ) {
		if ( self::capi_ready() ) {
			$actions['sws_fog_capi_send'] = 'Facebook-এ Purchase ইভেন্ট পাঠান (Fake Order Guard)';
		}
		return $actions;
	}

	public function order_action_run( $order ) {
		$this->send_purchase( $order->get_id(), true );
	}

	private static function h( $v ) {
		$v = strtolower( trim( (string) $v ) );
		return '' === $v ? '' : hash( 'sha256', $v );
	}

	private static function build_event( $order ) {
		$phone = self::normalize_phone( $order->get_billing_phone() );
		$ct    = preg_replace( '/[^a-z]/', '', strtolower( (string) $order->get_billing_city() ) );
		$fbc   = (string) $order->get_meta( '_sws_fog_fbc' );
		if ( '' === $fbc ) {
			$entry = (string) $order->get_meta( '_wc_order_attribution_session_entry' );
			if ( $entry && preg_match( '/[?&]fbclid=([^&#]+)/', $entry, $m ) ) {
				$created = $order->get_date_created();
				$fbc     = 'fb.1.' . ( ( $created ? $created->getTimestamp() : time() ) * 1000 ) . '.' . rawurldecode( $m[1] );
			}
		}
		$ip   = (string) $order->get_meta( '_sws_fog_ip' );
		$ua   = (string) $order->get_meta( '_sws_fog_ua' );
		$user = array(
			'em'                => array( self::h( $order->get_billing_email() ) ),
			'ph'                => array( self::is_valid_phone( $phone ) ? self::h( '88' . $phone ) : '' ),
			'fn'                => array( self::h( $order->get_billing_first_name() ) ),
			'ln'                => array( self::h( $order->get_billing_last_name() ) ),
			'ct'                => array( self::h( $ct ) ),
			'country'           => array( self::h( $order->get_billing_country() ? $order->get_billing_country() : 'bd' ) ),
			'external_id'       => array( self::h( $order->get_customer_id() ? 'u' . $order->get_customer_id() : $order->get_billing_email() ) ),
			'client_ip_address' => '' !== $ip ? $ip : (string) $order->get_customer_ip_address(),
			'client_user_agent' => ( '' !== $ua && '-' !== $ua ) ? $ua : (string) $order->get_customer_user_agent(),
			'fbp'               => (string) $order->get_meta( '_sws_fog_fbp' ),
			'fbc'               => $fbc,
		);
		foreach ( $user as $k => $v ) {
			if ( is_array( $v ) ) {
				$v = array_values( array_filter( $v ) );
			}
			if ( empty( $v ) ) {
				unset( $user[ $k ] );
			} else {
				$user[ $k ] = $v;
			}
		}

		$contents = array();
		$ids      = array();
		$qty      = 0;
		foreach ( $order->get_items() as $item ) {
			$pid        = (string) ( $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id() );
			$q          = (int) $item->get_quantity();
			$ids[]      = $pid;
			$qty       += $q;
			$contents[] = array( 'id' => $pid, 'quantity' => $q, 'item_price' => round( (float) $order->get_item_total( $item, true ), 2 ) );
		}

		$created = $order->get_date_created();
		$t       = $created ? $created->getTimestamp() : time();
		if ( time() - $t > 6 * DAY_IN_SECONDS ) {
			$t = time(); // Meta rejects events older than 7 days.
		}
		$event_id = (string) $order->get_meta( '_pys_purchase_event_id' ); // dedupe with PixelYourSite if its browser event fired.
		if ( '' === $event_id ) {
			$event_id = 'sws_fog_purchase_' . $order->get_id();
		}

		return array(
			'event_name'       => 'Purchase',
			'event_time'       => $t,
			'event_id'         => $event_id,
			'action_source'    => 'website',
			'event_source_url' => (string) ( $order->get_meta( '_sws_fog_src' ) ? $order->get_meta( '_sws_fog_src' ) : home_url( '/' ) ),
			'user_data'        => $user,
			'custom_data'      => array(
				'currency'     => $order->get_currency(),
				'value'        => round( (float) $order->get_total(), 2 ),
				'content_type' => 'product',
				'content_ids'  => array_values( array_unique( $ids ) ),
				'contents'     => $contents,
				'num_items'    => $qty,
				'order_id'     => (string) $order->get_order_number(),
			),
		);
	}

	public function send_purchase( $order_id, $force = false ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || ! self::capi_ready() ) {
			return;
		}
		if ( ! $force && ( $order->get_meta( '_sws_fog_capi_sent' ) || ! $order->has_status( self::verified_statuses() ) ) ) {
			return;
		}
		$body = array(
			'data'         => array( self::build_event( $order ) ),
			'access_token' => (string) self::get( 'capi_token' ),
		);
		$test = trim( (string) self::get( 'capi_test' ) );
		if ( '' !== $test ) {
			$body['test_event_code'] = $test;
		}
		$ver = apply_filters( 'sws_fog_capi_version', self::CAPI_VER );
		$res = wp_remote_post( 'https://graph.facebook.com/' . $ver . '/' . rawurlencode( (string) self::get( 'capi_pixel' ) ) . '/events', array(
			'timeout' => 15,
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( $body ),
		) );
		if ( is_wp_error( $res ) ) {
			$order->add_order_note( 'Fake Order Guard: Facebook Purchase পাঠানো যায়নি — ' . $res->get_error_message() );
			return;
		}
		$json = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( 200 === (int) wp_remote_retrieve_response_code( $res ) && ! empty( $json['events_received'] ) ) {
			$order->update_meta_data( '_sws_fog_capi_sent', time() );
			$order->save();
			$order->add_order_note( 'Fake Order Guard: যাচাই করা অর্ডার — Facebook-এ Purchase ইভেন্ট পাঠানো হয়েছে (' . $order->get_currency() . ' ' . $order->get_total() . ')' . ( '' !== $test ? ' [Test]' : '' ) . '।' );
		} else {
			$err = isset( $json['error']['message'] ) ? $json['error']['message'] : wp_remote_retrieve_body( $res );
			$order->add_order_note( 'Fake Order Guard: Facebook Purchase পাঠানো যায়নি — ' . substr( wp_strip_all_tags( (string) $err ), 0, 300 ) );
		}
	}

	/** Order shown on the current thank-you page (WooCommerce or CartFlows), key-checked. */
	private static function thankyou_order() {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return false;
		}
		$id = 0;
		if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-received' ) ) {
			$id = absint( get_query_var( 'order-received' ) );
		}
		foreach ( array( 'wcf-order', 'order-received', 'order_id' ) as $k ) {
			if ( ! $id && ! empty( $_GET[ $k ] ) ) { // phpcs:ignore
				$id = absint( $_GET[ $k ] ); // phpcs:ignore
			}
		}
		if ( ! $id ) {
			return false;
		}
		$key = '';
		foreach ( array( 'key', 'wcf-key' ) as $k ) {
			if ( '' === $key && ! empty( $_GET[ $k ] ) ) { // phpcs:ignore
				$key = sanitize_text_field( wp_unslash( $_GET[ $k ] ) ); // phpcs:ignore
			}
		}
		$order = wc_get_order( $id );
		return ( $order && '' !== $key && hash_equals( (string) $order->get_order_key(), $key ) ) ? $order : false;
	}

	/** Drop browser fbq Purchase calls on the thank-you page of an unverified order. */
	public function purchase_guard() {
		if ( is_admin() || ! self::get( 'capi_enabled' ) || ! self::get( 'capi_guard' ) ) {
			return;
		}
		$order = self::thankyou_order();
		if ( ! $order || $order->has_status( self::verified_statuses() ) ) {
			return;
		}
		?>
<script id="sws-fog-purchase-guard">(function(w){if(typeof Proxy==='undefined')return;function g(f){if(typeof f!=='function'||f.__swsFog)return f;try{f.__swsFog=1;}catch(e){}return new Proxy(f,{apply:function(t,s,a){var n=a[0]==='track'?a[1]:(a[0]==='trackSingle'?a[2]:null);if(n==='Purchase')return;return Reflect.apply(t,s,a);}});}if(w.fbq){w.fbq=g(w.fbq);return;}var c;try{Object.defineProperty(w,'fbq',{configurable:true,get:function(){return c;},set:function(v){c=g(v);}});}catch(e){}})(window);</script>
		<?php
	}
}

add_action( 'plugins_loaded', array( 'SWS_Fake_Order_Guard', 'instance' ) );
add_action( 'plugins_loaded', array( 'SWS_FOG_Incomplete', 'instance' ) );
add_action( 'plugins_loaded', array( 'SWS_FOG_Orders', 'instance' ) );

/*
 * Auto update from GitHub releases.
 * Publish a new release on GitHub (tag e.g. v1.3.0) with a "sws-fake-order-guard.zip"
 * asset, and every site running this plugin will see "Update now" in Plugins.
 */
if ( ! defined( 'SWS_FOG_UPDATE_REPO' ) ) {
	define( 'SWS_FOG_UPDATE_REPO', 'https://github.com/sourov6580/sws-fake-order-guard/' );
}
if ( file_exists( __DIR__ . '/lib/plugin-update-checker/plugin-update-checker.php' ) ) {
	require_once __DIR__ . '/lib/plugin-update-checker/plugin-update-checker.php';
	if ( class_exists( '\\YahnisElsts\\PluginUpdateChecker\\v5\\PucFactory' ) ) {
		$sws_fog_updater = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
			SWS_FOG_UPDATE_REPO,
			__FILE__,
			'sws-fake-order-guard'
		);
		$sws_fog_updater->setBranch( 'main' );
		$sws_fog_updater->getVcsApi()->enableReleaseAssets( '/sws-fake-order-guard\.zip($|[?&#])/i' );
	}
}

register_activation_hook( __FILE__, function () {
	if ( false === get_option( SWS_Fake_Order_Guard::OPT ) ) {
		add_option( SWS_Fake_Order_Guard::OPT, SWS_Fake_Order_Guard::defaults() );
	}
	if ( false === get_option( SWS_Fake_Order_Guard::BL, false ) ) {
		add_option( SWS_Fake_Order_Guard::BL, array(), '', 'no' );
	}
	SWS_FOG_Incomplete::install();
} );

register_deactivation_hook( __FILE__, array( 'SWS_FOG_Incomplete', 'deactivate' ) );
