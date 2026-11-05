<?php
/**
 * Order Management: customers grouped by mobile number - name, address, order count,
 * last order date, and a customer quality tag (High / Medium / Low). CSV (Excel) export.
 *
 * @package SWS_Fake_Order_Guard
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SWS_FOG_Orders {

	const QOPT     = 'sws_fog_quality';
	const PER_PAGE = 50;

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_ajax_sws_fog_quality', array( $this, 'ajax_quality' ) );
		add_action( 'admin_post_sws_fog_orders_export', array( $this, 'export' ) );
	}

	/* ---------------------------------------------------------------- data */

	public static function ranges() {
		return array(
			'today'      => 'Today',
			'yesterday'  => 'Yesterday',
			'last7'      => 'Last 7 Days',
			'this_month' => 'This Month',
			'last_month' => 'Last Month',
			'this_year'  => 'This Year',
			'max'        => 'Maximum',
		);
	}

	public static function qualities() {
		return array(
			'high'   => 'High',
			'medium' => 'Medium',
			'low'    => 'Low',
		);
	}

	private static function current_range() {
		$r = sanitize_key( $_REQUEST['range'] ?? 'max' ); // phpcs:ignore
		return isset( self::ranges()[ $r ] ) ? $r : 'max';
	}

	/** @return array [from_gmt|null, to_gmt|null] for the range, in the site's timezone. */
	private static function bounds( $range ) {
		$tz  = wp_timezone();
		$now = new DateTimeImmutable( 'now', $tz );
		$day = $now->setTime( 0, 0 );
		switch ( $range ) {
			case 'today':
				$from = $day;
				$to   = null;
				break;
			case 'yesterday':
				$from = $day->modify( '-1 day' );
				$to   = $day;
				break;
			case 'last7':
				$from = $day->modify( '-6 days' );
				$to   = null;
				break;
			case 'this_month':
				$from = $day->modify( 'first day of this month' );
				$to   = null;
				break;
			case 'last_month':
				$from = $day->modify( 'first day of last month' );
				$to   = $day->modify( 'first day of this month' );
				break;
			case 'this_year':
				$from = $day->setDate( (int) $day->format( 'Y' ), 1, 1 );
				$to   = null;
				break;
			default:
				return array( null, null );
		}
		$utc = new DateTimeZone( 'UTC' );
		return array(
			$from->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
			$to ? $to->setTimezone( $utc )->format( 'Y-m-d H:i:s' ) : null,
		);
	}

	private static function hpos() {
		return class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}

	/** Raw order rows (id, phone, name, address, date) within the range. */
	private static function rows( $range ) {
		global $wpdb;
		list( $from, $to ) = self::bounds( $range );
		$skip              = "'trash','auto-draft','wc-checkout-draft','draft'";
		if ( self::hpos() ) {
			$o     = $wpdb->prefix . 'wc_orders';
			$a     = $wpdb->prefix . 'wc_order_addresses';
			$where = "o.type = 'shop_order' AND o.status NOT IN ($skip)";
			if ( $from ) {
				$where .= $wpdb->prepare( ' AND o.date_created_gmt >= %s', $from );
			}
			if ( $to ) {
				$where .= $wpdb->prepare( ' AND o.date_created_gmt < %s', $to );
			}
			$sql = "SELECT o.id, o.date_created_gmt AS d, b.phone, b.first_name AS fn, b.last_name AS ln, b.address_1 AS a1, b.address_2 AS a2, b.city, b.state,
				s.phone AS sphone
				FROM $o o
				LEFT JOIN $a b ON b.order_id = o.id AND b.address_type = 'billing'
				LEFT JOIN $a s ON s.order_id = o.id AND s.address_type = 'shipping'
				WHERE $where";
		} else {
			$p     = $wpdb->posts;
			$m     = $wpdb->postmeta;
			$where = "p.post_type = 'shop_order' AND p.post_status NOT IN ($skip)";
			if ( $from ) {
				$where .= $wpdb->prepare( ' AND p.post_date_gmt >= %s', $from );
			}
			if ( $to ) {
				$where .= $wpdb->prepare( ' AND p.post_date_gmt < %s', $to );
			}
			$meta = function ( $key, $as ) use ( $m ) {
				return "(SELECT meta_value FROM $m WHERE post_id = p.ID AND meta_key = '$key' LIMIT 1) AS $as";
			};
			$sql  = 'SELECT p.ID AS id, p.post_date_gmt AS d, ' . implode(
				', ',
				array(
					$meta( '_billing_phone', 'phone' ),
					$meta( '_billing_first_name', 'fn' ),
					$meta( '_billing_last_name', 'ln' ),
					$meta( '_billing_address_1', 'a1' ),
					$meta( '_billing_address_2', 'a2' ),
					$meta( '_billing_city', 'city' ),
					$meta( '_billing_state', 'state' ),
					$meta( '_shipping_phone', 'sphone' ),
				)
			) . " FROM $p p WHERE $where";
		}
		return (array) $wpdb->get_results( $sql ); // phpcs:ignore
	}

	private static function state_name( $code ) {
		static $states = null;
		if ( null === $states ) {
			$states = function_exists( 'WC' ) && WC()->countries ? (array) WC()->countries->get_states( 'BD' ) : array();
		}
		return $states[ $code ] ?? $code;
	}

	/** Customers grouped by normalized mobile number, newest first. */
	public static function customers( $range, $search = '' ) {
		$out = array();
		foreach ( self::rows( $range ) as $r ) {
			$ph = SWS_Fake_Order_Guard::normalize_phone( $r->phone ? $r->phone : $r->sphone );
			if ( '' === $ph ) {
				continue;
			}
			$ts = (int) strtotime( $r->d . ' UTC' );
			if ( ! isset( $out[ $ph ] ) ) {
				$out[ $ph ] = array( 'phone' => $ph, 'count' => 0, 'last' => 0, 'order_id' => 0, 'name' => '', 'address' => '' );
			}
			$c = &$out[ $ph ];
			++$c['count'];
			if ( $ts >= $c['last'] ) {
				$name = trim( $r->fn . ' ' . $r->ln );
				$addr = implode( ', ', array_filter( array_map( 'trim', array( (string) $r->a1, (string) $r->a2, (string) $r->city, $r->state ? (string) self::state_name( $r->state ) : '' ) ) ) );
				$c['last']     = $ts;
				$c['order_id'] = (int) $r->id;
				$c['name']     = $name ? $name : $c['name'];
				$c['address']  = $addr ? $addr : $c['address'];
			}
			unset( $c );
		}
		if ( '' !== $search ) {
			$out = array_filter(
				$out,
				function ( $c ) use ( $search ) {
					return false !== strpos( $c['phone'], $search )
						|| false !== mb_stripos( $c['name'], $search )
						|| false !== mb_stripos( $c['address'], $search );
				}
			);
		}
		uasort(
			$out,
			function ( $a, $b ) {
				return $b['last'] - $a['last'];
			}
		);
		return $out;
	}

	public static function quality_map() {
		$q = get_option( self::QOPT, array() );
		return is_array( $q ) ? $q : array();
	}

	/* ---------------------------------------------------------------- actions */

	public function ajax_quality() {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! check_ajax_referer( 'sws_fog_quality', 'nonce', false ) ) {
			wp_send_json_error( null, 403 );
		}
		$ph = SWS_Fake_Order_Guard::normalize_phone( sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) ) );
		$q  = sanitize_key( $_POST['q'] ?? '' );
		if ( '' === $ph || ( '' !== $q && ! isset( self::qualities()[ $q ] ) ) ) {
			wp_send_json_error( null, 400 );
		}
		$map = self::quality_map();
		if ( '' === $q ) {
			unset( $map[ $ph ] );
		} else {
			$map[ $ph ] = $q;
		}
		if ( false === get_option( self::QOPT, false ) ) {
			add_option( self::QOPT, $map, '', 'no' );
		} else {
			update_option( self::QOPT, $map, false );
		}
		wp_send_json_success();
	}

	public function export() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'অনুমতি নেই।', 403 );
		}
		check_admin_referer( 'sws_fog_orders_export' );
		$range  = self::current_range();
		$search = sanitize_text_field( wp_unslash( $_REQUEST['s'] ?? '' ) ); // phpcs:ignore
		$list   = self::customers( $range, $search );
		$qmap   = self::quality_map();
		$qs     = self::qualities();
		$file   = 'customers-' . $range . '-' . wp_date( 'Y-m-d' ) . '.csv';

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $file . '"' );
		$fh = fopen( 'php://output', 'w' );
		fwrite( $fh, "\xEF\xBB\xBF" ); // UTF-8 BOM so Excel shows Bangla correctly.
		fputcsv( $fh, array( 'নাম', 'মোবাইল নম্বর', 'ঠিকানা', 'অর্ডার সংখ্যা', 'লাস্ট অর্ডার ডেট', 'কাস্টমার কোয়ালিটি' ) );
		foreach ( $list as $c ) {
			fputcsv(
				$fh,
				array(
					self::csv_safe( $c['name'] ),
					'="' . $c['phone'] . '"', // keep the leading 0 in Excel.
					self::csv_safe( $c['address'] ),
					$c['count'],
					wp_date( 'Y-m-d H:i', $c['last'] ),
					isset( $qmap[ $c['phone'] ], $qs[ $qmap[ $c['phone'] ] ] ) ? $qs[ $qmap[ $c['phone'] ] ] : '',
				)
			);
		}
		fclose( $fh );
		exit;
	}

	/** Stop spreadsheet formula injection from customer-typed text. */
	private static function csv_safe( $v ) {
		$v = (string) $v;
		return ( '' !== $v && false !== strpos( '=+-@', $v[0] ) ) ? "'" . $v : $v;
	}

	/* ---------------------------------------------------------------- page */

	public function render_page() {
		$ranges = self::ranges();
		$range  = self::current_range();
		$search = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) ); // phpcs:ignore
		$list   = self::customers( $range, $search );
		$total  = count( $list );
		$pages  = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		$paged  = max( 1, min( $pages, (int) ( $_GET['paged'] ?? 1 ) ) ); // phpcs:ignore
		$rows   = array_slice( $list, ( $paged - 1 ) * self::PER_PAGE, self::PER_PAGE, true );
		$qmap   = self::quality_map();
		$qs     = self::qualities();
		$orders = array_sum( wp_list_pluck( $list, 'count' ) );
		$base   = array(
			'page'  => 'sws-fake-order-guard',
			'tab'   => 'orders',
			'range' => $range,
		);
		if ( '' !== $search ) {
			$base['s'] = $search;
		}
		$export = wp_nonce_url( add_query_arg( array_merge( $base, array( 'action' => 'sws_fog_orders_export' ) ), admin_url( 'admin-post.php' ) ), 'sws_fog_orders_export' );
		$count  = array( 'high' => 0, 'medium' => 0, 'low' => 0 );
		foreach ( $list as $c ) {
			if ( isset( $qmap[ $c['phone'] ], $count[ $qmap[ $c['phone'] ] ] ) ) {
				++$count[ $qmap[ $c['phone'] ] ];
			}
		}
		?>
		<div class="sws-om">
			<style>
				.sws-om .sws-om-bar{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin:6px 0 14px}
				.sws-om .sws-om-bar .sws-om-search{margin-left:auto;display:flex;gap:6px}
				.sws-om .sws-dd{position:relative;display:inline-flex}
				.sws-om .sws-dd-menu{display:none;position:absolute;top:100%;left:0;z-index:50;min-width:150px;margin-top:4px;background:#fff;border:1px solid #c3c4c7;border-radius:4px;box-shadow:0 4px 14px rgba(0,0,0,.12);padding:4px 0}
				.sws-om .sws-dd.open .sws-dd-menu{display:block}
				.sws-om .sws-dd-menu a{display:block;padding:7px 14px;text-decoration:none;color:#1d2327;white-space:nowrap}
				.sws-om .sws-dd-menu a:hover{background:#f0f6fc;color:#2271b1}
				.sws-om .sws-dd-menu a.cur{font-weight:600;color:#2271b1}
				.sws-om .sws-q{display:inline-flex;border-radius:4px;overflow:hidden;border:1px solid #c3c4c7;background:#f6f7f7}
				.sws-om .sws-q button{border:0;background:transparent;cursor:pointer;font-size:12px;line-height:26px;padding:0 10px;color:#50575e}
				.sws-om .sws-q .sws-q-arrow{border-left:1px solid rgba(0,0,0,.15);padding:0 7px}
				.sws-om .sws-q.q-high{background:#e7f6ea;border-color:#68b97a}.sws-om .sws-q.q-high button{color:#1a7f37;font-weight:600}
				.sws-om .sws-q.q-medium{background:#fcf0e3;border-color:#e0a458}.sws-om .sws-q.q-medium button{color:#9a5b13;font-weight:600}
				.sws-om .sws-q.q-low{background:#fbeaea;border-color:#e38b8b}.sws-om .sws-q.q-low button{color:#b32d2e;font-weight:600}
				.sws-om .sws-om-stats{display:flex;gap:12px;flex-wrap:wrap;margin:0 0 14px}
				.sws-om .sws-om-stat{background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:8px 14px;min-width:110px}
				.sws-om .sws-om-stat b{display:block;font-size:20px;line-height:1.3}
				.sws-om table td{vertical-align:middle}
				.sws-om small{color:#646970}
			</style>

			<div class="sws-om-bar">
				<div class="sws-dd">
					<button type="button" class="button sws-dd-btn">📅 <?php echo esc_html( $ranges[ $range ] ); ?> ▾</button>
					<div class="sws-dd-menu">
						<?php foreach ( $ranges as $k => $l ) : ?>
							<a href="<?php echo esc_url( add_query_arg( array_merge( $base, array( 'range' => $k ) ), admin_url( 'admin.php' ) ) ); ?>"<?php echo $k === $range ? ' class="cur"' : ''; ?>><?php echo esc_html( $l ); ?></a>
						<?php endforeach; ?>
					</div>
				</div>
				<a class="button button-primary" href="<?php echo esc_url( $export ); ?>">⬇ Export (Excel)</a>
				<form method="get" class="sws-om-search">
					<input type="hidden" name="page" value="sws-fake-order-guard">
					<input type="hidden" name="tab" value="orders">
					<input type="hidden" name="range" value="<?php echo esc_attr( $range ); ?>">
					<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="নাম, নম্বর বা ঠিকানা">
					<button class="button">খুঁজুন</button>
				</form>
			</div>

			<div class="sws-om-stats">
				<div class="sws-om-stat"><small>কাস্টমার</small><b><?php echo (int) $total; ?></b></div>
				<div class="sws-om-stat"><small>মোট অর্ডার</small><b><?php echo (int) $orders; ?></b></div>
				<div class="sws-om-stat"><small>High</small><b style="color:#1a7f37"><?php echo (int) $count['high']; ?></b></div>
				<div class="sws-om-stat"><small>Medium</small><b style="color:#9a5b13"><?php echo (int) $count['medium']; ?></b></div>
				<div class="sws-om-stat"><small>Low</small><b style="color:#b32d2e"><?php echo (int) $count['low']; ?></b></div>
			</div>

			<table class="wp-list-table widefat fixed striped">
				<thead><tr>
					<th>নাম</th><th style="width:130px">মোবাইল নম্বর</th><th>ঠিকানা</th><th style="width:100px">অর্ডার সংখ্যা</th><th style="width:150px">লাস্ট অর্ডার ডেট</th><th style="width:160px">কাস্টমার কোয়ালিটি</th>
				</tr></thead>
				<tbody>
				<?php if ( ! $rows ) : ?>
					<tr><td colspan="6">এই সময়ের মধ্যে কোনো অর্ডার নেই।</td></tr>
				<?php endif; ?>
				<?php
				foreach ( $rows as $c ) :
					$q   = isset( $qmap[ $c['phone'] ], $qs[ $qmap[ $c['phone'] ] ] ) ? $qmap[ $c['phone'] ] : '';
					$ord = admin_url( 'post.php?post=' . (int) $c['order_id'] . '&action=edit' );
					if ( self::hpos() ) {
						$ord = admin_url( 'admin.php?page=wc-orders&action=edit&id=' . (int) $c['order_id'] );
					}
					$all = self::hpos()
						? admin_url( 'admin.php?page=wc-orders&s=' . rawurlencode( $c['phone'] ) )
						: admin_url( 'edit.php?post_type=shop_order&s=' . rawurlencode( $c['phone'] ) );
					?>
					<tr>
						<td><b><?php echo esc_html( $c['name'] ? $c['name'] : '—' ); ?></b></td>
						<td><?php echo esc_html( $c['phone'] ); ?><?php if ( SWS_Fake_Order_Guard::is_blocked( $c['phone'] ) ) : ?><br><small style="color:#b32d2e">ব্লকড</small><?php endif; ?></td>
						<td><?php echo esc_html( $c['address'] ? $c['address'] : '—' ); ?></td>
						<td><a href="<?php echo esc_url( $all ); ?>"><?php echo (int) $c['count']; ?></a></td>
						<td><a href="<?php echo esc_url( $ord ); ?>"><?php echo esc_html( wp_date( 'j M Y', $c['last'] ) ); ?></a><br><small><?php echo esc_html( wp_date( 'g:i a', $c['last'] ) ); ?></small></td>
						<td>
							<div class="sws-dd">
								<div class="sws-q<?php echo $q ? ' q-' . esc_attr( $q ) : ''; ?>" data-phone="<?php echo esc_attr( $c['phone'] ); ?>">
									<button type="button" class="sws-dd-btn sws-q-label"><?php echo esc_html( $q ? $qs[ $q ] : 'সেট করুন' ); ?></button>
									<button type="button" class="sws-dd-btn sws-q-arrow" aria-label="কোয়ালিটি বাছাই">▾</button>
								</div>
								<div class="sws-dd-menu">
									<?php foreach ( $qs as $k => $l ) : ?>
										<a href="#" class="sws-q-opt<?php echo $k === $q ? ' cur' : ''; ?>" data-q="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $l ); ?></a>
									<?php endforeach; ?>
									<a href="#" class="sws-q-opt" data-q="" style="color:#787c82;border-top:1px solid #f0f0f1">মুছে ফেলুন</a>
								</div>
							</div>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php if ( $pages > 1 ) : ?>
				<div class="tablenav bottom"><div class="tablenav-pages"><span class="displaying-num"><?php echo (int) $total; ?> জন</span>
					<?php
					echo paginate_links( // phpcs:ignore
						array(
							'base'    => add_query_arg( array_merge( $base, array( 'paged' => '%#%' ) ), admin_url( 'admin.php' ) ),
							'format'  => '',
							'current' => $paged,
							'total'   => $pages,
						)
					);
					?>
				</div></div>
			<?php endif; ?>
			<p class="description" style="margin-top:12px">একই মোবাইল নম্বরের সব অর্ডার একজন কাস্টমার হিসেবে গোনা হয়। নাম ও ঠিকানা নেওয়া হয় সর্বশেষ অর্ডার থেকে। ট্র্যাশ ও ড্রাফট অর্ডার বাদ।</p>
		</div>
		<script>
		(function(){
			var ajax = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>, nonce = <?php echo wp_json_encode( wp_create_nonce( 'sws_fog_quality' ) ); ?>;
			var labels = <?php echo wp_json_encode( $qs ); ?>;
			function closeAll(except){ document.querySelectorAll('.sws-om .sws-dd.open').forEach(function(d){ if(d!==except){ d.classList.remove('open'); } }); }
			document.addEventListener('click', function(e){
				var btn = e.target.closest('.sws-om .sws-dd-btn');
				if (btn) { var dd = btn.closest('.sws-dd'); closeAll(dd); dd.classList.toggle('open'); return; }
				var opt = e.target.closest('.sws-om .sws-q-opt');
				if (opt) {
					e.preventDefault();
					var dd = opt.closest('.sws-dd'), box = dd.querySelector('.sws-q'), q = opt.dataset.q;
					var f = new FormData(); f.append('action','sws_fog_quality'); f.append('nonce',nonce); f.append('phone',box.dataset.phone); f.append('q',q);
					fetch(ajax,{method:'POST',body:f,credentials:'same-origin'}).then(function(r){ return r.json(); }).then(function(r){
						if (!r || !r.success) { alert('সেভ করা যায়নি, আবার চেষ্টা করুন।'); return; }
						box.className = 'sws-q' + (q ? ' q-' + q : '');
						box.querySelector('.sws-q-label').textContent = q ? labels[q] : 'সেট করুন';
						dd.querySelectorAll('.sws-q-opt').forEach(function(a){ a.classList.toggle('cur', !!q && a.dataset.q === q); });
					}).catch(function(){ alert('সেভ করা যায়নি, আবার চেষ্টা করুন।'); });
					dd.classList.remove('open');
					return;
				}
				if (!e.target.closest('.sws-om .sws-dd')) { closeAll(null); }
			});
		})();
		</script>
		<?php
	}
}
