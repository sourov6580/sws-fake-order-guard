<?php
/**
 * Plugin Name:       SWS Fake Order Guard
 * Plugin URI:        https://sabbirwebsolutions.com
 * Description:       WooCommerce ফেক অর্ডার প্রতিরোধ: মোবাইল নম্বর যাচাই, একই নম্বর/IP থেকে নির্দিষ্ট সময়ে অর্ডারের লিমিট, এবং ফোন নম্বর ব্লকলিস্ট (অর্ডার লিস্ট থেকে এক ক্লিকে ব্লক/আনব্লক)।
 * Version:           1.2.0
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

final class SWS_Fake_Order_Guard {

	const OPT     = 'sws_fog_settings';
	const VERSION = '1.2.0';
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
	}

	/* ---------------------------------------------------------------- settings */

	public static function defaults() {
		return array(
			'enabled'        => 1,
			'validate_phone' => 1,
			'window_hours'   => 12,
			'phone_limit'    => 1,
			'ip_limit'       => 1,
			'contact'        => '',
			'msg_phone'      => 'সঠিক মোবাইল নম্বর দিন — ০১ দিয়ে শুরু ১১ সংখ্যার নম্বর।',
			'msg_dup'        => 'একটু অপেক্ষা করুন, আপনি সম্প্রতি একটি অর্ডার করেছেন। প্রয়োজনে যোগাযোগ করুনঃ {contact}',
			'msg_ip'         => 'আপনার অর্ডারটি গ্রহণ করা যায়নি। সমস্যাটি সমাধানে যোগাযোগ করুনঃ {contact}',
			'msg_block'      => 'দুঃখিত, এই নম্বর থেকে অর্ডার গ্রহণ করা সম্ভব নয়। প্রয়োজনে যোগাযোগ করুনঃ {contact}',
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
		add_submenu_page( $parent, 'Fake Order Guard', 'Fake Order Guard', 'manage_woocommerce', 'sws-fake-order-guard', array( $this, 'render_page' ) );
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
			'window_hours'   => max( 0.25, min( 720, (float) ( $in['window_hours'] ?? $d['window_hours'] ) ) ),
			'phone_limit'    => max( 0, min( 100, (int) ( $in['phone_limit'] ?? $d['phone_limit'] ) ) ),
			'ip_limit'       => max( 0, min( 100, (int) ( $in['ip_limit'] ?? $d['ip_limit'] ) ) ),
			'contact'        => sanitize_text_field( $in['contact'] ?? '' ),
		);
		foreach ( array( 'msg_phone', 'msg_dup', 'msg_ip', 'msg_block' ) as $k ) {
			$v         = sanitize_textarea_field( $in[ $k ] ?? '' );
			$out[ $k ] = '' === trim( $v ) ? $d[ $k ] : $v;
		}
		return $out;
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$o = wp_parse_args( (array) get_option( self::OPT, array() ), self::defaults() );
		$n = self::OPT;
		?>
		<div class="wrap">
			<h1>SWS Fake Order Guard</h1>
			<p>মোবাইল নম্বর যাচাই এবং একই নম্বর/IP থেকে বারবার অর্ডার ঠেকানোর সেটিংস। কোনো অর্ডার <b>ডিলিট / ট্র্যাশ / Completed</b> করলে সেই নম্বর ও IP-এর লিমিট সাথে সাথে খুলে যায়।</p>
			<form method="post" action="options.php">
				<?php settings_fields( 'sws_fog_group' ); ?>
				<table class="form-table" role="presentation">
					<tr><th scope="row">গার্ড চালু</th><td><label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[enabled]" value="1" <?php checked( $o['enabled'], 1 ); ?>> অর্ডার লিমিট চালু রাখুন</label></td></tr>
					<tr><th scope="row">মোবাইল নম্বর যাচাই</th><td><label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[validate_phone]" value="1" <?php checked( $o['validate_phone'], 1 ); ?>> ০১ দিয়ে শুরু ১১ সংখ্যার বাংলাদেশি নম্বর বাধ্যতামূলক</label></td></tr>
					<tr><th scope="row"><label for="fog-win">সময়সীমা (ঘণ্টা)</label></th><td><input id="fog-win" type="number" min="0.25" max="720" step="0.25" class="small-text" name="<?php echo esc_attr( $n ); ?>[window_hours]" value="<?php echo esc_attr( $o['window_hours'] ); ?>"><p class="description">এই সময়ের মধ্যে লিমিট গোনা হবে। যেমন 12 = ১২ ঘণ্টা।</p></td></tr>
					<tr><th scope="row"><label for="fog-pl">একই নম্বরে সর্বোচ্চ অর্ডার</label></th><td><input id="fog-pl" type="number" min="0" max="100" class="small-text" name="<?php echo esc_attr( $n ); ?>[phone_limit]" value="<?php echo esc_attr( $o['phone_limit'] ); ?>"><p class="description">0 দিলে নম্বরের লিমিট বন্ধ থাকবে।</p></td></tr>
					<tr><th scope="row"><label for="fog-il">একই IP-তে সর্বোচ্চ অর্ডার</label></th><td><input id="fog-il" type="number" min="0" max="100" class="small-text" name="<?php echo esc_attr( $n ); ?>[ip_limit]" value="<?php echo esc_attr( $o['ip_limit'] ); ?>"><p class="description">0 দিলে IP লিমিট বন্ধ থাকবে।</p></td></tr>
					<tr><th scope="row"><label for="fog-ct">যোগাযোগের নম্বর</label></th><td><input id="fog-ct" type="text" class="regular-text" name="<?php echo esc_attr( $n ); ?>[contact]" value="<?php echo esc_attr( $o['contact'] ); ?>" placeholder="01XXXXXXXXX"><p class="description">মেসেজের <code>{contact}</code> এর জায়গায় এই নম্বর বসবে।</p></td></tr>
					<tr><th scope="row"><label for="fog-m1">ভুল নম্বরের মেসেজ</label></th><td><textarea id="fog-m1" rows="2" class="large-text" name="<?php echo esc_attr( $n ); ?>[msg_phone]"><?php echo esc_textarea( $o['msg_phone'] ); ?></textarea></td></tr>
					<tr><th scope="row"><label for="fog-m2">একই নম্বরের লিমিট মেসেজ</label></th><td><textarea id="fog-m2" rows="2" class="large-text" name="<?php echo esc_attr( $n ); ?>[msg_dup]"><?php echo esc_textarea( $o['msg_dup'] ); ?></textarea></td></tr>
					<tr><th scope="row"><label for="fog-m3">একই IP-এর লিমিট মেসেজ</label></th><td><textarea id="fog-m3" rows="2" class="large-text" name="<?php echo esc_attr( $n ); ?>[msg_ip]"><?php echo esc_textarea( $o['msg_ip'] ); ?></textarea></td></tr>
					<tr><th scope="row"><label for="fog-m4">ব্লকড নম্বরের মেসেজ</label></th><td><textarea id="fog-m4" rows="2" class="large-text" name="<?php echo esc_attr( $n ); ?>[msg_block]"><?php echo esc_textarea( $o['msg_block'] ); ?></textarea><p class="description">যেকোনো মেসেজে <code>{contact}</code> = যোগাযোগের নম্বর, <code>{wait}</code> = বাকি সময় (যেমন "৩ ঘণ্টা ২০ মিনিট")।</p></td></tr>
				</table>
				<?php submit_button( 'সেভ করুন' ); ?>
			</form>
			<?php $this->render_blocklist(); ?>
			<p style="color:#787c82">Developed by <a href="https://sabbirwebsolutions.com" target="_blank" rel="noopener">Sabbir Web Solutions</a></p>
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
		<hr style="margin:30px 0 20px">
		<h2 id="sws-fog-blocklist">ব্লক করা নম্বর (<?php echo esc_html( self::bn( count( self::blocklist() ) ) ); ?>টি)</h2>
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
			<input type="search" name="fog_s" value="<?php echo esc_attr( $s ); ?>" placeholder="নম্বর খুঁজুন">
			<button class="button">খুঁজুন</button>
			<?php if ( '' !== $s ) : ?><a class="button-link" href="<?php echo esc_url( self::page_url() . '#sws-fog-blocklist' ); ?>">সব দেখুন</a><?php endif; ?>
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
				$url = self::page_url( array_filter( array( 'fog_p' => $i, 'fog_s' => $s ) ) ) . '#sws-fog-blocklist';
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

	/* ---------------------------------------------------------------- checkout */

	public function validate( $data, $errors ) {
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
		$d = self::order_digits( $order );
		if ( ! self::is_valid_phone( $d ) || ! self::is_blocked( $d ) ) {
			return;
		}
		if ( class_exists( '\\Automattic\\WooCommerce\\StoreApi\\Exceptions\\RouteException' ) ) {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'sws_fog_blocked', self::msg( 'msg_block' ), 400 );
		}
		throw new \Exception( self::msg( 'msg_block' ) );
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
}

add_action( 'plugins_loaded', array( 'SWS_Fake_Order_Guard', 'instance' ) );

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
} );
