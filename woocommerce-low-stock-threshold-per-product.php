<?php
/**
 * Plugin Name: WooCommerce Low Stock Threshold per Product
 * Plugin URI: http://woothemes.com/products/woocommerce-low-stock-threshold-per-product/
 * Description: Adds a custom field to the inventory tab on the edit product screen and enables shop managers to set a low stock threshold per product
 * Version: 1.0.0
 * Author: WooCommerce
 * Author URI: http://woocommerce.com/
 * Developer: Pie Web Ltd
 * Developer URI: http://pie.co.de
 * WC requires at least: 3.0.0
 * WC tested up to: 3.3.5
 * Requires at least: 4.5.0
 * Tested up to: 4.9.6
 * Text Domain: woocommerce-low-stock-threshold-per-product
 *
 * Copyright: © 2018 WooCommerce
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}
if ( ! class_exists( 'WooCommerce_Low_Stock_Threshold_Per_Product' ) ) {
	//Load plugin when WooCommerce is up and running
	add_action( 'woocommerce_init', 'woocommerce_low_stock_threshold_plugin_load' );
	function woocommerce_low_stock_threshold_plugin_load() {
		$plugin = new WooCommerce_Low_Stock_Threshold_Per_Product();
		$plugin->load_hooks();
	}

	/**
	 * Class WooCommerce_Low_Stock_Threshold_Per_Product
	 */
	class WooCommerce_Low_Stock_Threshold_Per_Product {

		/**
		 * Load plugin hooks
		 */
		public function load_hooks() {
			if ( is_admin() ) {
				add_action( 'woocommerce_product_options_stock_fields', array( $this, 'add_low_stock_threshold_field' ) );
				add_action( 'woocommerce_process_product_meta', array( $this, 'save_low_stock_threshold_meta' ) );
				add_action( 'woocommerce_variation_options_inventory', array( $this, 'add_low_stock_threshold_field_for_variation' ), 10, 3 );
				add_action( 'woocommerce_save_product_variation', array( $this, 'save_low_stock_threshold_meta_for_variation' ), 10, 2 );
			}
			add_action( 'woocommerce_product_set_stock', array( $this, 'maybe_send_low_stock_notification' ) );
			add_action( 'woocommerce_variation_set_stock', array( $this, 'maybe_send_low_stock_notification' ) );
		}

		/**
		 * Add low stock threshold field to product inventory options
		 */
		public function add_low_stock_threshold_field() {
			woocommerce_wp_text_input( array(
				'id'                => '_woocommerce_product_notify_low_stock_amount',
				'label'             => __( 'Low stock threshold', 'woocommerce-low-stock-threshold-per-product' ),
				'desc_tip'          => true,
				'description'       => __( 'When product stock reaches this amount you will be notified by email', 'woocommerce-low-stock-threshold-per-product' ),
				'type'              => 'number',
				'custom_attributes' => array(
					'step' => 'any',
				),
				'data_type'         => 'stock',
			) );
		}

		/**
		 * @param $loop
		 * @param $variation_data
		 * @param $variation
		 */
		public function add_low_stock_threshold_field_for_variation( $loop, $variation_data, $variation ) {
			if ( isset( $variation_data['_woocommerce_product_notify_low_stock_amount'] ) ) {
				$value = $variation_data['_woocommerce_product_notify_low_stock_amount'][$loop];
			} else {
				$value = get_option( 'woocommerce_notify_low_stock_amount', 2 );
			}
			woocommerce_wp_text_input(
				array(
					'id'                => "_woocommerce_product_notify_low_stock_amount{$loop}",
					'name'              => "_woocommerce_product_notify_low_stock_amount[{$loop}]",
					'label'             => __( 'Low stock threshold', 'woocommerce' ),
					'desc_tip'          => true,
					'description'       => __( 'When product stock reaches this amount you will be notified by email', 'woocommerce-low-stock-threshold-per-product' ),
					'type'              => 'number',
					'custom_attributes' => array(
						'step' => 'any',
					),
					'value'             => $value,
					'data_type'         => 'stock',
					'wrapper_class'     => 'form-row form-row-first',
				)
			);
		}

		/**
		 * Save low stock threshold option into post meta when post is updated
		 *
		 * @param $post_id
		 */
		public function save_low_stock_threshold_meta( $post_id ) {
			if ( isset( $_POST['_woocommerce_product_notify_low_stock_amount'] ) ) {
				update_post_meta( $post_id, '_woocommerce_product_notify_low_stock_amount', absint( $_POST['_woocommerce_product_notify_low_stock_amount'] ) );
			}
		}

		/**
		 * Save low stock threshold option for variations
		 *
		 * @param $variation_id
		 * @param $loop
		 */
		public function save_low_stock_threshold_meta_for_variation( $variation_id, $loop ) {
			if ( isset( $_POST['_woocommerce_product_notify_low_stock_amount'][$loop] ) ) {
				update_post_meta( $variation_id, '_woocommerce_product_notify_low_stock_amount', absint( $_POST['_woocommerce_product_notify_low_stock_amount'][$loop] ) );
			}
		}

		/**
		 * Send custom low stock notification depending on product options
		 *
		 * @param WC_Product $product
		 */
		public function maybe_send_low_stock_notification( WC_Product $product ) {
			if ( 'no' === get_option( 'woocommerce_notify_low_stock', 'yes' ) ) {
				return;
			}
			if ( $product->is_type( 'variation' ) ) {
				$product = $this->get_product_controlling_stock( $product );
			}
			if ( ! $product->managing_stock() ) {
				return;
			}
			$low_stock_threshold = absint( get_post_meta( $product->get_id(), '_woocommerce_product_notify_low_stock_amount', true ) );
			if ( ! is_int( $low_stock_threshold ) ) {
				$low_stock_threshold = get_option( 'woocommerce_notify_low_stock_amount', 2 );
			}
			if ( $low_stock_threshold >= absint( $product->get_stock_quantity() ) ) {
				$this->send_low_stock_notification( $product );
			}
		}

		/**
		 * Return ID of the product managing stock
		 *
		 * @param WC_Product $product
		 *
		 * @return WC_Product $product
		 */
		protected function get_product_controlling_stock( WC_Product $product ) {
			if ( $product->managing_stock() ) {
				return $product;
			}
			$parent = wc_get_product( $product->get_parent_id() );
			if ( $parent->managing_stock() ) {
				return $parent;
			}
			return $product;
		}

		/**
		 * Send low stock notification email
		 *
		 * @param WC_Product $product
		 */
		protected function send_low_stock_notification( WC_Product $product ) {
			$subject = sprintf( '[%s] %s', wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES ), __( 'Product low in stock', 'woocommerce' ) );
			$message = sprintf(
			/* translators: 1: product name 2: items in stock */
				__( '%1$s is low in stock. There are %2$d left.', 'woocommerce' ),
				html_entity_decode( strip_tags( $product->get_formatted_name() ), ENT_QUOTES, get_bloginfo( 'charset' ) ),
				html_entity_decode( strip_tags( $product->get_stock_quantity() ) )
			);

			wp_mail(
				apply_filters( 'woocommerce_email_recipient_low_stock', get_option( 'woocommerce_stock_email_recipient' ), $product ),
				apply_filters( 'woocommerce_email_subject_low_stock', $subject, $product ),
				apply_filters( 'woocommerce_email_content_low_stock', $message, $product ),
				apply_filters( 'woocommerce_email_headers', '', 'low_stock', $product ),
				apply_filters( 'woocommerce_email_attachments', array(), 'low_stock', $product )
			);
		}
	}
}