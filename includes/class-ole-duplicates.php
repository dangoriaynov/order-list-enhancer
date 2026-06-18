<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Виявлення замовлень одного клієнта (union-find) + деталі груп для модалки.
 */
class OLE_Duplicates {

	public static function build( $opts ) {
		$orders = self::fetch( $opts );
		$map    = self::group( $orders );

		// Прозорец за „дубликат" (поръчки близо във времето), в дни — от настройките.
		$win_days = isset( $opts['dup_window_days'] ) ? (int) $opts['dup_window_days'] : 3;
		$win_days = (int) apply_filters( 'ole_duplicate_window_days', $win_days );
		$win      = max( 1, $win_days ) * DAY_IN_SECONDS;

		// Събиране на членовете на всяка група (id, ts, статус, име).
		$members = array();
		foreach ( $map as $id => $info ) {
			$g = (int) $info['g'];
			$o = isset( $orders[ $id ] ) ? $orders[ $id ] : array();
			if ( ! isset( $members[ $g ] ) ) {
				$members[ $g ] = array(
					'reason' => (string) $info['r'],
					'n'      => (int) $info['n'],
					'list'   => array(),
				);
			}
			$members[ $g ]['list'][] = array(
				'id'     => (int) $id,
				'ts'     => isset( $o['ts'] ) ? (int) $o['ts'] : 0,
				'status' => isset( $o['status'] ) ? (string) $o['status'] : '',
				'name'   => isset( $o['name'] ) ? (string) $o['name'] : '',
			);
		}

		$groups = array();
		foreach ( $members as $g => $m ) {
			$list  = $m['list'];
			$tsAll = array();
			$proc  = 0;
			$name  = '';
			$maxts = -1;
			foreach ( $list as $it ) {
				if ( $it['ts'] ) {
					$tsAll[] = $it['ts'];
				}
				if ( 'processing' === $it['status'] ) {
					++$proc;
				}
				if ( $it['ts'] >= $maxts ) {
					$maxts = $it['ts'];
					$name  = $it['name'];
				}
			}
			sort( $tsAll );
			$first = ! empty( $tsAll ) ? $tsAll[0] : 0;
			$last  = ! empty( $tsAll ) ? end( $tsAll ) : 0;
			$count = $m['n'];

			// Флаг „дубликат" — за всяка поръчка поотделно: има ли ДРУГА поръчка
			// на същия клиент в рамките на прозореца (или 2+ в обработка).
			$any_dup = false;
			foreach ( $list as $it ) {
				$is_dup = ( 'processing' === $it['status'] && $proc >= 2 );
				if ( ! $is_dup && $it['ts'] ) {
					foreach ( $list as $other ) {
						if ( $other['id'] === $it['id'] || ! $other['ts'] ) {
							continue;
						}
						if ( abs( $it['ts'] - $other['ts'] ) <= $win ) {
							$is_dup = true;
							break;
						}
					}
				}
				if ( $is_dup ) {
					$map[ (string) $it['id'] ]['dup'] = 1;
					$any_dup                            = true;
				}
			}

			$freq = '';
			if ( $first && $last > $first && $count >= 2 ) {
				$interval = (int) round( ( $last - $first ) / DAY_IN_SECONDS / ( $count - 1 ) );
				if ( $interval < 1 ) {
					$interval = 1;
				}
				/* translators: %d: average number of days between orders. */
				$freq = sprintf( __( 'once every %d days', 'order-list-enhancer' ), $interval );
			} elseif ( $first && $last <= $first ) {
				$freq = __( 'same day', 'order-list-enhancer' );
			}

			$ids = array();
			foreach ( $list as $it ) {
				$ids[] = $it['id'];
			}

			$groups[ $g ] = array(
				'name'   => '' !== $name ? $name : __( 'Customer', 'order-list-enhancer' ),
				'n'      => $count,
				'reason' => $m['reason'],
				'first'  => $first ? wp_date( 'd.m.Y', $first ) : '',
				'freq'   => $freq,
				'dup'    => $any_dup,
				'ids'    => $ids,
			);
		}

		return array(
			'map'    => $map,
			'groups' => $groups,
		);
	}

	private static function norm( $s ) {
		$s = (string) $s;
		$s = mb_strtolower( $s, 'UTF-8' );
		$s = preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $s );
		return trim( preg_replace( '/\s+/u', ' ', $s ) );
	}

	private static function keys( $r, $opts ) {
		$keys = array();

		if ( OLE_Settings::is_yes( $opts, 'match_phone' ) ) {
			$phone  = ! empty( $r['b_phone'] ) ? $r['b_phone'] : ( isset( $r['s_phone'] ) ? $r['s_phone'] : '' );
			$digits = preg_replace( '/\D+/', '', (string) $phone );
			if ( strlen( $digits ) >= 9 ) {
				$keys[] = 'p:' . substr( $digits, -9 );
			}
		}
		if ( OLE_Settings::is_yes( $opts, 'match_email' ) ) {
			$email = strtolower( trim( (string) ( $r['b_email'] ?? '' ) ) );
			if ( '' !== $email && false !== strpos( $email, '@' ) ) {
				$keys[] = 'e:' . $email;
			}
		}

		$name = trim( ( $r['b_first'] ?? '' ) . ' ' . ( $r['b_last'] ?? '' ) );
		if ( '' === $name ) {
			$name = trim( ( $r['s_first'] ?? '' ) . ' ' . ( $r['s_last'] ?? '' ) );
		}
		if ( OLE_Settings::is_yes( $opts, 'match_name' ) ) {
			$nn = self::norm( $name );
			if ( '' !== $nn && mb_strlen( $nn ) >= 4 ) {
				$keys[] = 'n:' . $nn;
			}
		}
		if ( OLE_Settings::is_yes( $opts, 'match_address' ) ) {
			$sname = trim( ( $r['s_first'] ?? '' ) . ' ' . ( $r['s_last'] ?? '' ) );
			if ( '' === $sname ) {
				$sname = $name;
			}
			$parts = array( $sname, $r['s_a1'] ?? '', $r['s_a2'] ?? '', $r['s_city'] ?? '', $r['s_post'] ?? '' );
			$addr  = self::norm( implode( ' ', array_filter( $parts ) ) );
			if ( '' !== $addr && mb_strlen( $addr ) >= 10 ) {
				$keys[] = 'a:' . $addr;
			}
		}
		return array_values( array_unique( $keys ) );
	}

	private static function hpos() {
		return class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}

	private static function fetch( $opts ) {
		global $wpdb;
		$limit = (int) $opts['scan_limit'];

		// Дублі шукаємо по ВСІХ статусах (окрім кошика/чернеток), а не лише в
		// поточному фільтрі — щоб ловити клієнтів, що повертаються, навіть коли
		// попереднє замовлення ще не завершене або не позначене як некоректне.
		$rows = array();
		if ( self::hpos() ) {
			$ot = $wpdb->prefix . 'wc_orders';
			$at = $wpdb->prefix . 'wc_order_addresses';
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$sql  = $wpdb->prepare(
				"SELECT o.id, o.date_created_gmt, o.status,
				        ba.first_name b_first, ba.last_name b_last, ba.phone b_phone, ba.email b_email,
				        sa.first_name s_first, sa.last_name s_last, sa.phone s_phone,
				        sa.address_1 s_a1, sa.address_2 s_a2, sa.city s_city, sa.postcode s_post
				   FROM {$ot} o
				   LEFT JOIN {$at} ba ON ba.order_id = o.id AND ba.address_type = 'billing'
				   LEFT JOIN {$at} sa ON sa.order_id = o.id AND sa.address_type = 'shipping'
				  WHERE o.type = 'shop_order'
				    AND o.status NOT IN ('trash','auto-draft','wc-checkout-draft')
				  ORDER BY o.date_created_gmt DESC
				  LIMIT %d",
				$limit
			);
			$rows = $wpdb->get_results( $sql, ARRAY_A );
			// phpcs:enable
		} else {
			$q    = array(
				'limit'   => $limit,
				'orderby' => 'date',
				'order'   => 'DESC',
				'return'  => 'objects',
				'type'    => 'shop_order',
				'status'  => array_keys( wc_get_order_statuses() ),
			);
			$objs = function_exists( 'wc_get_orders' ) ? wc_get_orders( $q ) : array();
			foreach ( $objs as $o ) {
				$rows[] = array(
					'id'              => $o->get_id(),
					'date_created_gmt' => ( $o->get_date_created() ? gmdate( 'Y-m-d H:i:s', $o->get_date_created()->getTimestamp() ) : '' ),
					'status'          => $o->get_status(),
					'b_first'         => $o->get_billing_first_name(),
					'b_last'          => $o->get_billing_last_name(),
					'b_phone'         => $o->get_billing_phone(),
					'b_email'         => $o->get_billing_email(),
					's_first'         => $o->get_shipping_first_name(),
					's_last'          => $o->get_shipping_last_name(),
					's_phone'         => '',
					's_a1'            => $o->get_shipping_address_1(),
					's_a2'            => $o->get_shipping_address_2(),
					's_city'          => $o->get_shipping_city(),
					's_post'          => $o->get_shipping_postcode(),
				);
			}
		}

		$orders = array();
		foreach ( (array) $rows as $r ) {
			$id = (int) $r['id'];
			if ( ! $id ) {
				continue;
			}
			$keys = self::keys( $r, $opts );
			if ( empty( $keys ) ) {
				continue;
			}
			$name = trim( ( $r['b_first'] ?? '' ) . ' ' . ( $r['b_last'] ?? '' ) );
			if ( '' === $name ) {
				$name = trim( ( $r['s_first'] ?? '' ) . ' ' . ( $r['s_last'] ?? '' ) );
			}
			$ts     = ! empty( $r['date_created_gmt'] ) ? (int) strtotime( $r['date_created_gmt'] . ' UTC' ) : 0;
			$status = isset( $r['status'] ) ? preg_replace( '/^wc-/', '', (string) $r['status'] ) : '';

			$orders[ $id ] = array(
				'keys'   => $keys,
				'name'   => $name,
				'ts'     => $ts,
				'status' => $status,
			);
		}
		return $orders;
	}

	private static function group( $orders ) {
		if ( empty( $orders ) ) {
			return array();
		}
		$buckets = array();
		foreach ( $orders as $id => $o ) {
			foreach ( $o['keys'] as $k ) {
				$buckets[ $k ][] = $id;
			}
		}
		$parent = array();
		foreach ( $orders as $id => $o ) {
			$parent[ $id ] = $id;
		}
		$find = function ( $x ) use ( &$parent ) {
			while ( $parent[ $x ] !== $x ) {
				$parent[ $x ] = $parent[ $parent[ $x ] ];
				$x            = $parent[ $x ];
			}
			return $x;
		};
		foreach ( $buckets as $ids ) {
			$n = count( $ids );
			if ( $n < 2 ) {
				continue;
			}
			$ra = $find( $ids[0] );
			for ( $i = 1; $i < $n; $i++ ) {
				$rb = $find( $ids[ $i ] );
				if ( $ra !== $rb ) {
					$parent[ $rb ] = $ra;
				}
			}
		}
		$comp = array();
		foreach ( $orders as $id => $o ) {
			$comp[ $find( $id ) ][] = $id;
		}
		$groups = array();
		foreach ( $comp as $ids ) {
			if ( count( $ids ) >= 2 ) {
				$groups[] = $ids;
			}
		}
		if ( empty( $groups ) ) {
			return array();
		}
		usort(
			$groups,
			function ( $a, $b ) {
				return max( $b ) <=> max( $a );
			}
		);

		$labels = array(
			'p' => __( 'phone', 'order-list-enhancer' ),
			'e' => __( 'e-mail', 'order-list-enhancer' ),
			'n' => __( 'name', 'order-list-enhancer' ),
			'a' => __( 'address', 'order-list-enhancer' ),
		);

		$map  = array();
		$gnum = 0;
		foreach ( $groups as $ids ) {
			++$gnum;
			$count = count( $ids );
			$kv    = array();
			foreach ( $ids as $id ) {
				foreach ( $orders[ $id ]['keys'] as $k ) {
					$kv[ $k ][] = $id;
				}
			}
			$reasons = array();
			foreach ( $kv as $k => $members ) {
				if ( count( array_unique( $members ) ) >= 2 ) {
					$reasons[ $labels[ $k[0] ] ] = true;
				}
			}
			$reason = implode( ', ', array_keys( $reasons ) );
			foreach ( $ids as $id ) {
				$map[ (string) $id ] = array(
					'g' => $gnum,
					'n' => $count,
					'r' => $reason,
				);
			}
		}
		return $map;
	}

	private static function order_edit_url( $id, $hpos ) {
		if ( $hpos ) {
			return admin_url( 'admin.php?page=wc-orders&action=edit&id=' . (int) $id );
		}
		$url = get_edit_post_link( $id, 'raw' );
		return $url ? $url : admin_url( 'post.php?post=' . (int) $id . '&action=edit' );
	}

	/**
	 * Детайли за конкретни поръчки (за AJAX дозареждане на модала).
	 * Зарежда само подадените ID-та, подредени по дата (най-новата отгоре).
	 * Артикулите се връщат като списък { name, qty } за таблица.
	 */
	public static function details_for_ids( $ids ) {
		if ( empty( $ids ) || ! function_exists( 'wc_get_order' ) ) {
			return array();
		}
		$hpos   = self::hpos();
		$orders = array();
		foreach ( $ids as $oid ) {
			$oid = (int) $oid;
			if ( ! $oid ) {
				continue;
			}
			$o = wc_get_order( $oid );
			if ( ! $o ) {
				continue;
			}
			$items = array();
			foreach ( $o->get_items() as $item ) {
				$items[] = array(
					'name' => $item->get_name(),
					'qty'  => $item->get_quantity(),
				);
			}
			$date     = $o->get_date_created();
			$orders[] = array(
				'num'    => (string) $o->get_order_number(),
				'url'    => self::order_edit_url( $oid, $hpos ),
				'date'   => $date ? $date->date_i18n( 'd.m.Y' ) : '',
				'ts'     => $date ? $date->getTimestamp() : 0,
				'items'  => $items,
				'total'  => html_entity_decode( wp_strip_all_tags( $o->get_formatted_order_total() ), ENT_QUOTES, 'UTF-8' ),
				'status' => wc_get_order_status_name( $o->get_status() ),
			);
		}
		usort(
			$orders,
			function ( $a, $b ) {
				return $b['ts'] <=> $a['ts'];
			}
		);
		foreach ( $orders as &$ord ) {
			unset( $ord['ts'] );
		}
		unset( $ord );
		return $orders;
	}
}
