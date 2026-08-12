<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Перетворює зіставлені екстри (Product Add-Ons / Checkout Add-Ons) на окремі товарні рядки
 * при створенні замовлення й розділяє комбо-товари на базовий товар + компоненти.
 * Чиста логіка - в [[ORDELIST_Extras_Matcher]].
 */
class ORDELIST_Extras {

	/** Реєстрація хуків (викликається з ORDELIST_Plugin, якщо фіча увімкнена). */
	public static function init() {
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'on_order_processed' ), 20, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'on_order_processed' ), 20, 1 );
		add_action( 'woocommerce_after_order_itemmeta', array( __CLASS__, 'render_item_provenance' ), 10, 2 );
		add_filter( 'woocommerce_hidden_order_itemmeta', array( __CLASS__, 'hidden_itemmeta' ) );
		add_filter( 'woocommerce_order_actions', array( __CLASS__, 'order_actions' ) );
		add_action( 'woocommerce_order_action_ordelist_convert_extras', array( __CLASS__, 'run_order_action' ) );
	}

	public static function on_order_processed( $order ) {
		$order = ( $order instanceof WC_Order ) ? $order : wc_get_order( $order );
		if ( $order ) {
			self::convert( $order );
		}
	}

	/** Ручний прогін зі сторінки замовлення - для замовлень, створених до появи мапінгу. */
	public static function order_actions( $actions ) {
		$actions['ordelist_convert_extras'] = __( 'OLE - split combos / convert extras now', 'ordelist' );
		return $actions;
	}

	/** Обробник ручної дії: конвертує навіть якщо прапорець замовлення вже стоїть. */
	public static function run_order_action( $order ) {
		$order = ( $order instanceof WC_Order ) ? $order : wc_get_order( $order );
		if ( $order ) {
			self::convert( $order, true );
		}
	}

	/**
	 * Головна конвертація. Повертає к-сть перетворених екстр. Ідемпотентна.
	 * $force ігнорує прапорець замовлення (для ручної дії); рядкові позначки все одно
	 * не дають конвертувати те саме двічі.
	 */
	public static function convert( WC_Order $order, $force = false ) {
		if ( ! $force && $order->get_meta( '_ordelist_extras_converted' ) ) {
			return 0;
		}
		$opts = ORDELIST_Settings::get();
		if ( ! ORDELIST_Settings::is_yes( $opts, 'extras_enabled' ) ) {
			return 0;
		}
		$index  = ORDELIST_Extras_Matcher::index( $opts['extras_map'] );
		$combos = ORDELIST_Extras_Matcher::combo_index( $opts['combo_map'] );
		if ( empty( $index ) && empty( $combos ) ) {
			return 0;
		}

		$notes = array();
		$count = 0;
		if ( ! empty( $index ) ) {
			$count += self::convert_product_addons( $order, $index, $notes );
			$count += self::convert_checkout_addons( $order, $index, $notes );
		}
		if ( ! empty( $combos ) ) {
			$count += self::convert_combo_lines( $order, $combos, $notes );
		}

		if ( $count > 0 ) {
			$order->add_order_note( __( 'OLE - extras converted to product lines:', 'ordelist' ) . "\n" . implode( "\n", $notes ) );
			$order->update_meta_data( '_ordelist_extras_converted', 1 );
			$order->save();
		}
		return $count;
	}

	/** Конвертує Product Add-Ons на товарних рядках. */
	private static function convert_product_addons( WC_Order $order, $index, &$notes ) {
		$count = 0;
		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			$pao = $item->get_meta( '_pao_ids' );
			if ( ! is_array( $pao ) || empty( $pao ) ) {
				continue;
			}
			$pao       = array_values( $pao );
			$addons    = ORDELIST_Extras_Matcher::parse_addons( $pao );
			$pao_total = (float) $item->get_meta( '_pao_total' );

			// Safety: only proceed if the parsed add-on prices reconcile with _pao_total.
			if ( ! ORDELIST_Extras_Matcher::prices_balance( $addons, $pao_total ) ) {
				continue;
			}

			$keep_pao   = array();   // _pao_ids entries to keep (unconverted)
			$moved      = array();   // provenance for the parent
			$drop_label = array();   // visible field=>label rows to drop

			// Знімок ДО змін: податок батьківського рядка ділимо пропорційно до ціни,
			// щоб перенести його частку на винесені рядки й лишити суму податку сталою.
			$orig_subtotal = (float) $item->get_subtotal();
			$orig_taxes    = $item->get_taxes();
			$moved_price   = 0.0;

			foreach ( $addons as $idx => $a ) {
				$pid = ORDELIST_Extras_Matcher::match( $index, $a['label'] );
				if ( ! $pid || 'flat_fee' !== $a['price_type'] ) {
					$keep_pao[] = $pao[ $idx ];
					continue;
				}
				$price      = (float) $a['price'];
				$qty        = ORDELIST_Extras_Matcher::parse_qty( $a['label'] );
				$line_taxes = ( $orig_subtotal > 0 )
					? ORDELIST_Extras_Matcher::scale_taxes( $orig_taxes, $price / $orig_subtotal )
					: null;
				$new_id     = self::add_product_line( $order, $pid, $price, array(
					'source'   => 'pa',
					'label'    => $a['label'],
					'price'    => $price,
					'src_item' => $item_id,
				), $qty, $line_taxes );
				if ( 0 === $new_id ) {
					$keep_pao[] = $pao[ $idx ];
					continue;
				}
				// Reduce parent line by the add-on price.
				$item->set_subtotal( (float) $item->get_subtotal() - $price );
				$item->set_total( (float) $item->get_total() - $price );
				$moved_price += $price;
				$drop_label[ $a['field'] ][] = $a['label'];
				$moved[] = array( 'label' => $a['label'], 'price' => $price, 'item' => $new_id );
				$notes[] = sprintf( '«%s» → %s (%s)', $a['label'], self::product_name( $pid ), self::money( $price, $order ) );
				++$count;
			}

			if ( empty( $moved ) ) {
				continue;
			}

			// Лишаємо батьківському рядку тільки його частку податку (решта пішла на винесені).
			if ( $orig_subtotal > 0 ) {
				$remaining = max( 0.0, 1.0 - $moved_price / $orig_subtotal );
				$item->set_taxes( ORDELIST_Extras_Matcher::scale_taxes( $orig_taxes, $remaining ) );
			}

			// Rewrite _pao_ids / _pao_total to keep only unconverted add-ons.
			$kept_total = 0.0;
			foreach ( $keep_pao as $k ) {
				$kept_total += isset( $k['raw_price'] ) ? (float) $k['raw_price'] : 0.0;
			}
			if ( $keep_pao ) {
				$item->update_meta_data( '_pao_ids', array_values( $keep_pao ) );
				$item->update_meta_data( '_pao_total', $kept_total );
			} else {
				$item->delete_meta_data( '_pao_ids' );
				$item->delete_meta_data( '_pao_total' );
			}

			// Remove the visible field=>label rows for converted add-ons (keep unconverted values).
			foreach ( $drop_label as $field => $converted_vals ) {
				$remaining = array();
				foreach ( $item->get_meta( $field, false ) as $meta ) {
					if ( ! in_array( (string) $meta->value, $converted_vals, true ) ) {
						$remaining[] = (string) $meta->value;
					}
				}
				$item->delete_meta_data( $field );
				foreach ( $remaining as $v ) {
					$item->add_meta_data( $field, $v, false );
				}
			}

			// Provenance for admin display (hidden from invoices).
			$item->update_meta_data( '_ordelist_extra_moved', $moved );
			$item->save();
		}
		return $count;
	}

	/**
	 * Додає новий товарний рядок із заданою ціною та provenance-метою.
	 * $taxes - масив податків ['subtotal'=>[rate=>amt],'total'=>[...]] для переносу
	 * частки податку з джерела (щоб сума податку замовлення не змінилась); null - без податку.
	 * $subtotal - ціна до знижки (null = така сама, як $price).
	 * $sync_cost - записати собівартість рядка з каталожного поля товару.
	 */
	public static function add_product_line( WC_Order $order, $product_id, $price, $origin, $qty = 1, $taxes = null, $subtotal = null, $sync_cost = false ) {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return 0;
		}
		$qty  = max( 1, (int) $qty );
		$line = new WC_Order_Item_Product();
		$line->set_product( $product );
		$line->set_quantity( $qty );
		$line->set_subtotal( null === $subtotal ? (float) $price : (float) $subtotal );
		$line->set_total( (float) $price );
		if ( is_array( $taxes ) ) {
			$line->set_taxes( $taxes ); // set_taxes і сам перерахує subtotal_tax/total_tax як суми
		} else {
			$line->set_subtotal_tax( 0 );
			$line->set_total_tax( 0 );
		}
		if ( $sync_cost ) {
			self::sync_item_cost( $line, $product, $qty );
		}
		$line->add_meta_data( '_ordelist_addon_origin', $origin, true );

		// Якщо запас замовлення вже списаний - списуємо й новий товар ТА тегуємо рядок
		// _reduced_stock, інакше WooCommerce не поверне цей запас при скасуванні/поверненні.
		if ( 'yes' === $order->get_meta( '_order_stock_reduced' ) && $product->managing_stock() ) {
			wc_update_product_stock( $product, $qty, 'decrease' );
			$line->add_meta_data( '_reduced_stock', $qty, true );
		}
		$order->add_item( $line );
		$line->save();
		return $line->get_id();
	}

	/**
	 * Розділяє комбо-рядки: базовий товар лишається на тому самому рядку (щоб не
	 * зламати зв'язок з бандлом), компоненти йдуть окремими рядками. Гроші діляться
	 * пропорційно до каталожних цін, залишок - на базовий рядок, тож сума рядка стала.
	 */
	private static function convert_combo_lines( WC_Order $order, $combos, &$notes ) {
		$count     = 0;
		$precision = function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;
		$reduced   = 'yes' === $order->get_meta( '_order_stock_reduced' );

		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			if ( $item->get_meta( '_ordelist_combo_split' ) ) {
				continue; // цей рядок уже розділено
			}
			$vid = (int) $item->get_variation_id();
			$pid = (int) $item->get_product_id();
			$key = isset( $combos[ $vid ] ) ? $vid : ( isset( $combos[ $pid ] ) ? $pid : 0 );
			if ( ! $key ) {
				continue;
			}
			$base = wc_get_product( $combos[ $key ]['base'] );
			if ( ! $base ) {
				continue;
			}

			// Опорні (каталожні) ціни: база + кожен компонент з його кількістю.
			$line_qty = max( 1, (int) $item->get_quantity() );
			$refs     = array( 'base' => (float) $base->get_price() );
			$parts    = array();
			foreach ( $combos[ $key ]['parts'] as $i => $p ) {
				$prod = wc_get_product( $p['product'] );
				if ( ! $prod ) {
					$parts = null;
					break;
				}
				$parts[ $i ] = array(
					'product' => $prod,
					'qty'     => (int) $p['qty'] * $line_qty,
				);
				$refs[ $i ]  = (float) $prod->get_price() * (int) $p['qty'];
			}
			if ( empty( $parts ) ) {
				continue; // компонент не знайдено - рядок не чіпаємо
			}

			$orig_name     = $item->get_name();
			$orig_subtotal = (float) $item->get_subtotal();
			$orig_total    = (float) $item->get_total();
			$orig_taxes    = $item->get_taxes();
			$orig_cost     = (float) $item->get_meta( '_wc_cog_item_total_cost' );
			$sub_share     = ORDELIST_Extras_Matcher::split_amount( $orig_subtotal, $refs, 'base', $precision );
			$tot_share     = ORDELIST_Extras_Matcher::split_amount( $orig_total, $refs, 'base', $precision );

			// Компоненти - окремими рядками; податок переносимо часткою від subtotal.
			$moved      = array();
			$cost_delta = 0.0;
			foreach ( $parts as $i => $part ) {
				$taxes  = ( $orig_subtotal > 0 )
					? ORDELIST_Extras_Matcher::scale_taxes( $orig_taxes, $sub_share[ $i ] / $orig_subtotal )
					: null;
				$new_id = self::add_product_line(
					$order,
					$part['product']->get_id(),
					$tot_share[ $i ],
					array(
						'source'   => 'combo',
						'label'    => $orig_name,
						'price'    => $tot_share[ $i ],
						'src_item' => $item_id,
					),
					$part['qty'],
					$taxes,
					$sub_share[ $i ],
					true
				);
				if ( 0 === $new_id ) {
					continue;
				}
				$cost_delta += (float) self::cost_of( $part['product'], $part['qty'] );
				$moved[]     = array(
					'name'   => self::product_name( $part['product']->get_id() ),
					'qty'    => $part['qty'],
					'amount' => $tot_share[ $i ],
					'item'   => $new_id,
				);
				++$count;
			}
			if ( empty( $moved ) ) {
				continue;
			}

			// Базовий рядок: міняємо товар на місці (зв'язок з бандлом лишається цілим).
			$old_product = $item->get_product();
			if ( $old_product && $old_product->is_type( 'variation' ) ) {
				// Ті самі ключі, які ставить WC_Order_Item_Product::set_product().
				$old_attrs = is_callable( array( $old_product, 'get_variation_attributes' ) )
					? $old_product->get_variation_attributes()
					: (array) $old_product->get_attributes();
				foreach ( array_keys( (array) $old_attrs ) as $akey ) {
					$item->delete_meta_data( str_replace( 'attribute_', '', $akey ) );
				}
			}
			$item->set_product( $base );
			$item->set_variation_id( $base->is_type( 'variation' ) ? $base->get_id() : 0 );
			$item->set_subtotal( $sub_share['base'] );
			$item->set_total( $tot_share['base'] );
			if ( $orig_subtotal > 0 ) {
				$item->set_taxes( ORDELIST_Extras_Matcher::scale_taxes( $orig_taxes, $sub_share['base'] / $orig_subtotal ) );
			}
			$new_base_cost = self::sync_item_cost( $item, $base, $line_qty );
			$cost_delta   += ( null === $new_base_cost ? $orig_cost : $new_base_cost ) - $orig_cost;
			$item->update_meta_data(
				'_ordelist_combo_split',
				array(
					'label' => $orig_name,
					'price' => $orig_total,
					'base'  => array(
						'name'   => self::product_name( $base->get_id() ),
						'amount' => $tot_share['base'],
					),
					'parts' => $moved,
				)
			);
			if ( $reduced ) {
				self::move_line_stock( $item, $old_product, $base, $line_qty );
			}
			$item->save();

			self::adjust_order_cost( $order, $cost_delta );

			$summary = array( sprintf( '%s (%s)', self::product_name( $base->get_id() ), self::money( $tot_share['base'], $order ) ) );
			foreach ( $moved as $m ) {
				$summary[] = sprintf( '%s x%d (%s)', $m['name'], $m['qty'], self::money( $m['amount'], $order ) );
			}
			$notes[] = sprintf( '«%s» (%s) → %s', $orig_name, self::money( $orig_total, $order ), implode( ' + ', $summary ) );
		}
		return $count;
	}

	/**
	 * Переносить списаний запас зі старого товару рядка на новий - лише якщо запасом
	 * керує інший товар (для 5102→5103 обидва ведуть на того самого батька, тож нічого).
	 */
	private static function move_line_stock( $item, $old_product, $new_product, $qty ) {
		$old_sid = ( $old_product && $old_product->managing_stock() ) ? (int) $old_product->get_stock_managed_by_id() : 0;
		$new_sid = ( $new_product && $new_product->managing_stock() ) ? (int) $new_product->get_stock_managed_by_id() : 0;
		if ( $old_sid === $new_sid ) {
			return;
		}
		if ( $old_sid ) {
			wc_update_product_stock( $old_product, $qty, 'increase' );
		}
		if ( $new_sid ) {
			wc_update_product_stock( $new_product, $qty, 'decrease' );
			$item->update_meta_data( '_reduced_stock', $qty );
		} else {
			$item->delete_meta_data( '_reduced_stock' );
		}
	}

	/** Собівартість товару з каталогу (_wc_cog_cost), з відкатом на батька варіації; '' якщо поля немає. */
	private static function product_cost( $product ) {
		if ( ! $product ) {
			return '';
		}
		$cost = $product->get_meta( '_wc_cog_cost', true );
		if ( '' === $cost || null === $cost ) {
			$parent = $product->get_parent_id() ? wc_get_product( $product->get_parent_id() ) : null;
			$cost   = $parent ? $parent->get_meta( '_wc_cog_cost', true ) : '';
		}
		return ( '' === $cost || null === $cost ) ? '' : (float) $cost;
	}

	/** Собівартість рядка = каталожна за одиницю * кількість (0, якщо поля немає). */
	private static function cost_of( $product, $qty ) {
		$unit = self::product_cost( $product );
		return ( '' === $unit ) ? 0.0 : (float) $unit * max( 1, (int) $qty );
	}

	/** Записує собівартість рядка з каталогу. Повертає суму по рядку або null, якщо даних немає. */
	private static function sync_item_cost( $item, $product, $qty ) {
		$unit = self::product_cost( $product );
		if ( '' === $unit ) {
			return null;
		}
		$total = (float) $unit * max( 1, (int) $qty );
		$item->update_meta_data( '_wc_cog_item_cost', wc_format_decimal( $unit, 4, true ) );
		$item->update_meta_data( '_wc_cog_item_total_cost', wc_format_decimal( $total, 4, true ) );
		return $total;
	}

	/** Підправляє собівартість замовлення на дельту, щоб вона й далі дорівнювала сумі рядків. */
	private static function adjust_order_cost( WC_Order $order, $delta ) {
		$cur = $order->get_meta( '_wc_cog_order_total_cost' );
		if ( '' === $cur || null === $cur || abs( (float) $delta ) < 0.00001 ) {
			return;
		}
		$order->update_meta_data( '_wc_cog_order_total_cost', wc_format_decimal( (float) $cur + (float) $delta, 4, true ) );
	}

	private static function product_name( $product_id ) {
		$p = wc_get_product( $product_id );
		return $p ? wp_strip_all_tags( $p->get_formatted_name() ) : ( '#' . (int) $product_id );
	}

	private static function money( $amount, WC_Order $order ) {
		return html_entity_decode( wp_strip_all_tags( wc_price( $amount, array( 'currency' => $order->get_currency() ) ) ) );
	}

	/** Ховає сирі _ordelist_* ключі зі стандартного відображення метаданих рядка. */
	public static function hidden_itemmeta( $keys ) {
		$keys[] = '_ordelist_addon_origin';
		$keys[] = '_ordelist_extra_moved';
		$keys[] = '_ordelist_combo_split';
		return $keys;
	}

	/** Адмін-підказка під рядком: звідки сконвертовано / що винесено. Лише в адмінці. */
	public static function render_item_provenance( $item_id, $item ) {
		if ( ! is_a( $item, 'WC_Order_Item' ) ) {
			return;
		}
		$split = $item->get_meta( '_ordelist_combo_split' );
		if ( is_array( $split ) && ! empty( $split['label'] ) ) {
			$parts = array();
			if ( ! empty( $split['base']['name'] ) ) {
				$parts[] = sprintf( '%s (%s)', $split['base']['name'], wc_format_localized_price( isset( $split['base']['amount'] ) ? $split['base']['amount'] : 0 ) );
			}
			foreach ( (array) ( isset( $split['parts'] ) ? $split['parts'] : array() ) as $p ) {
				if ( empty( $p['name'] ) ) {
					continue;
				}
				$parts[] = sprintf( '%s x%d (%s)', $p['name'], isset( $p['qty'] ) ? (int) $p['qty'] : 1, wc_format_localized_price( isset( $p['amount'] ) ? $p['amount'] : 0 ) );
			}
			printf(
				'<div class="ole-prov ole-prov--moved">➡ %s</div>',
				/* translators: 1: the combo product name, 2: the price the combo line had, 3: the resulting lines with their prices. */
				esc_html( sprintf( __( 'Was: «%1$s» (%2$s) - split into: %3$s', 'ordelist' ), $split['label'], wc_format_localized_price( isset( $split['price'] ) ? $split['price'] : 0 ), implode( ' + ', $parts ) ) )
			);
		}
		$origin = $item->get_meta( '_ordelist_addon_origin' );
		if ( is_array( $origin ) && ! empty( $origin['label'] ) && isset( $origin['source'] ) && 'combo' === $origin['source'] ) {
			printf(
				'<div class="ole-prov ole-prov--from">↩ %s</div>',
				/* translators: 1: the combo product name, 2: the share of the combo price this line took. */
				esc_html( sprintf( __( 'Split out of combo «%1$s» (%2$s)', 'ordelist' ), $origin['label'], wc_format_localized_price( isset( $origin['price'] ) ? $origin['price'] : 0 ) ) )
			);
			$origin = null;
		}
		if ( is_array( $origin ) && ! empty( $origin['label'] ) ) {
			printf(
				'<div class="ole-prov ole-prov--from">↩ %s</div>',
				/* translators: 1: the add-on extra label, 2: the price it was converted from. */
				esc_html( sprintf( __( 'Converted from extra: «%1$s» (was %2$s)', 'ordelist' ), $origin['label'], wc_format_localized_price( isset( $origin['price'] ) ? $origin['price'] : 0 ) ) )
			);
		}
		$moved = $item->get_meta( '_ordelist_extra_moved' );
		if ( is_array( $moved ) ) {
			foreach ( $moved as $m ) {
				if ( empty( $m['label'] ) ) {
					continue;
				}
				printf(
					'<div class="ole-prov ole-prov--moved">➡ %s</div>',
					/* translators: %s: the add-on extra label. */
					esc_html( sprintf( __( 'Extra «%s» moved to its own line', 'ordelist' ), $m['label'] ) )
				);
			}
		}
	}

	/** Конвертує Checkout Add-Ons (fee-рядки). */
	private static function convert_checkout_addons( WC_Order $order, $index, &$notes ) {
		$count = 0;
		foreach ( $order->get_items( 'fee' ) as $fee_id => $fee ) {
			$pid = ORDELIST_Extras_Matcher::match( $index, $fee->get_name() );
			if ( ! $pid ) {
				continue;
			}
			$price = (float) $fee->get_total();
			$qty   = ORDELIST_Extras_Matcher::parse_qty( (string) $fee->get_meta( '_wc_checkout_add_on_label' ) );
			// Увесь fee переноситься - віддаємо новому рядку весь його податок (fee має
			// лише 'total'; для товарного рядка subtotal_tax == total_tax при відсутності знижки).
			$fee_taxes = $fee->get_taxes();
			$fee_total = ( isset( $fee_taxes['total'] ) && is_array( $fee_taxes['total'] ) ) ? $fee_taxes['total'] : array();
			$taxes     = array(
				'total'    => $fee_total,
				'subtotal' => $fee_total,
			);
			$new_id = self::add_product_line( $order, $pid, $price, array(
				'source'   => 'ca',
				'label'    => $fee->get_name(),
				'price'    => $price,
				'src_item' => $fee->get_name(),
			), $qty, $taxes );
			if ( 0 === $new_id ) {
				continue;
			}
			$order->remove_item( $fee_id );
			$notes[] = sprintf( '«%s» → %s (%s)', $fee->get_name(), self::product_name( $pid ), self::money( $price, $order ) );
			++$count;
		}
		return $count;
	}
}
