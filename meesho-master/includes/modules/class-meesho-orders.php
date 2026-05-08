<?php

class Meesho_Master_Orders {
private $undo;
const STATUS_PENDING    = 'pending';
const STATUS_ORDERED    = 'ordered_on_meesho';
const STATUS_TRACKING   = 'tracking_received';
const STATUS_DISPATCHED = 'dispatched';
const STATUS_DELIVERED  = 'delivered';
const STATUS_CANCELLED  = 'cancelled';
const STATUS_RETURNED   = 'returned';
const SLA_THRESHOLD_SECONDS = 14400;

public function __construct() {
add_action( 'wp_ajax_meesho_get_orders', array( $this, 'ajax_get_orders' ) );
add_action( 'wp_ajax_meesho_update_order', array( $this, 'ajax_update_order' ) );
add_action( 'wp_ajax_meesho_check_cod_risk', array( $this, 'ajax_check_cod_risk' ) );
add_action( 'wp_ajax_meesho_get_accounts', array( $this, 'ajax_get_accounts' ) );
add_action( 'woocommerce_new_order', array( $this, 'on_new_wc_order' ), 10, 1 );
}

private function undo() {
if ( ! $this->undo ) {
$this->undo = new Meesho_Master_Undo();
}
return $this->undo;
}

public function on_new_wc_order( $order_id ) {
global $wpdb;
$table = MM_DB::table( 'orders' );
$exists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE wc_order_id = %d", $order_id ) );
if ( $exists ) {
return;
}
$wpdb->insert(
$table,
array(
'wc_order_id'        => $order_id,
'fulfillment_status' => self::STATUS_PENDING,
'created_at'         => current_time( 'mysql' ),
'updated_at'         => current_time( 'mysql' ),
),
array( '%d', '%s', '%s', '%s' )
);
$order = wc_get_order( $order_id );
if ( $order && 'cod' === $order->get_payment_method() ) {
$this->assess_cod_risk( $order );
}
}

public function ajax_get_orders() {
meesho_master_verify_ajax_nonce();
if ( ! current_user_can( 'manage_options' ) ) {
wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
}
global $wpdb;
$table  = MM_DB::table( 'orders' );
$page   = max( 1, absint( $_POST['page'] ?? 1 ) );
$limit  = 25;
$offset = ( $page - 1 ) * $limit;
$where = array( '1=1' );
$params = array();
$status = sanitize_text_field( wp_unslash( $_POST['status'] ?? '' ) );
$search = sanitize_text_field( wp_unslash( $_POST['search'] ?? '' ) );
if ( '' !== $status ) {
$where[]  = 'fulfillment_status = %s';
$params[] = $status;
}
if ( '' !== $search ) {
$like = '%' . $wpdb->esc_like( $search ) . '%';
$where[]  = '(meesho_order_id LIKE %s OR meesho_tracking_id LIKE %s OR CAST(wc_order_id AS CHAR) LIKE %s)';
$params[] = $like;
$params[] = $like;
$params[] = $like;
}
$params[] = $limit;
$params[] = $offset;
$sql = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY created_at DESC LIMIT %d OFFSET %d';
$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$params ) );
$total_params = array_slice( $params, 0, -2 );
$total_sql = "SELECT COUNT(*) FROM {$table} WHERE " . implode( ' AND ', $where );
$total = empty( $total_params ) ? (int) $wpdb->get_var( $total_sql ) : (int) $wpdb->get_var( $wpdb->prepare( $total_sql, ...$total_params ) );
$orders = array();
foreach ( $rows as $row ) {
$wc_order = wc_get_order( $row->wc_order_id );
$order = array(
'id'                 => (int) $row->id,
'wc_order_id'        => (int) $row->wc_order_id,
'meesho_order_id'    => $row->meesho_order_id,
'tracking_id'        => $row->meesho_tracking_id,
'account_used'       => $row->meesho_account,
'fulfillment_status' => $row->fulfillment_status,
'sla_status'         => $this->check_sla( $row ) ? 'breached' : 'ok',
'created_at'         => mysql2date( 'd/m/Y H:i', $row->created_at ),
);
if ( $wc_order ) {
$order['customer_name'] = trim( $wc_order->get_billing_first_name() . ' ' . $wc_order->get_billing_last_name() );
$order['phone']         = $wc_order->get_billing_phone();
$order['address']       = $wc_order->get_formatted_shipping_address() ?: $wc_order->get_formatted_billing_address();
$order['payment_method'] = 'cod' === $wc_order->get_payment_method() ? 'COD' : 'Prepaid';
$order['order_total']   = $wc_order->get_total();
$order['cod_risk']      = $row->cod_risk_flag ? 'high' : 'low';
$order['items']         = array();
foreach ( $wc_order->get_items() as $item ) {
$product = $item->get_product();
$order['items'][] = array(
'name' => $item->get_name(),
'sku'  => $product ? $product->get_sku() : '',
'size' => $item->get_meta( 'pa_size' ) ?: $item->get_meta( 'size' ) ?: '',
'qty'  => $item->get_quantity(),
);
}
}
$orders[] = $order;
}
wp_send_json_success( array( 'orders' => $orders, 'total' => $total, 'page' => $page ) );
}

public function ajax_update_order() {
meesho_master_verify_ajax_nonce();
if ( ! current_user_can( 'manage_options' ) ) {
wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
}
global $wpdb;
$table = MM_DB::table( 'orders' );
$order_id = absint( $_POST['order_id'] ?? 0 );
$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $order_id ) );
if ( ! $row ) {
wp_send_json_error( array( 'message' => 'Order not found' ), 404 );
}
$update = array(
'fulfillment_status' => sanitize_text_field( wp_unslash( $_POST['fulfillment_status'] ?? $row->fulfillment_status ) ),
'meesho_order_id'    => sanitize_text_field( wp_unslash( $_POST['meesho_order_id'] ?? $row->meesho_order_id ) ),
'meesho_tracking_id' => sanitize_text_field( wp_unslash( $_POST['tracking_id'] ?? $row->meesho_tracking_id ) ),
'meesho_account'     => sanitize_text_field( wp_unslash( $_POST['account_used'] ?? $row->meesho_account ) ),
'fulfilled_by'       => 'manual',
'updated_at'         => current_time( 'mysql' ),
);
$notes = sanitize_textarea_field( wp_unslash( $_POST['notes'] ?? '' ) );
if ( '' !== $notes ) {
$update['notes'] = trim( (string) $row->notes . "\n[" . wp_date( 'd/m/Y H:i' ) . '] ' . $notes );
}
( new MM_Logger() )->log_before_change( 'order_update', 'order', (int) $row->wc_order_id, (array) $row, $update, 0, 'manual' );
$wpdb->update( $table, $update, array( 'id' => $order_id ) );
wp_send_json_success( 'Order updated.' );
}

private function check_sla( $row ) {
if ( self::STATUS_PENDING !== $row->fulfillment_status ) {
return false;
}
$created = strtotime( $row->created_at );
return $created && ( time() - $created ) > self::SLA_THRESHOLD_SECONDS;
}

public function assess_cod_risk( $order ) {
$settings  = new Meesho_Master_Settings();
$threshold = (float) $settings->get( 'cod_risk_threshold', 2000 );
$window    = (int) $settings->get( 'cod_repeat_window_hrs', 24 );
$reasons   = array();
$phone     = preg_replace( '/\D+/', '', $order->get_billing_phone() );
$address   = trim( $order->get_shipping_address_1() . ' ' . $order->get_shipping_address_2() );
if ( (float) $order->get_total() > $threshold ) {
$reasons[] = 'COD order value exceeds threshold.';
}
if ( strlen( $phone ) !== 10 || in_array( $phone[0] ?? '', array( '0', '1' ), true ) ) {
$reasons[] = 'Phone number is invalid.';
}
if ( strlen( $address ) < 15 ) {
$reasons[] = 'Address appears incomplete.';
}
if ( 0 === $this->count_recent_orders( $phone, 3650 ) && 'cod' === $order->get_payment_method() ) {
$reasons[] = 'First COD order for this phone number.';
}
if ( $this->count_recent_orders( $phone, $window ) >= 2 ) {
$reasons[] = 'Multiple recent orders from same phone number.';
}
update_post_meta( $order->get_id(), '_meesho_cod_risk', ! empty( $reasons ) ? 'high' : 'low' );
update_post_meta( $order->get_id(), '_meesho_cod_risk_reasons', $reasons );
global $wpdb;
$wpdb->update( MM_DB::table( 'orders' ), array( 'cod_risk_flag' => ! empty( $reasons ) ? 1 : 0 ), array( 'wc_order_id' => $order->get_id() ), array( '%d' ), array( '%d' ) );
$this->update_customer_risk( $phone, ! empty( $reasons ) );
return $reasons;
}

public function ajax_check_cod_risk() {
meesho_master_verify_ajax_nonce();
if ( ! current_user_can( 'manage_options' ) ) {
wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
}
$order = wc_get_order( absint( $_POST['wc_order_id'] ?? 0 ) );
if ( ! $order ) {
wp_send_json_error( array( 'message' => 'Order not found' ), 404 );
}
$reasons = $this->assess_cod_risk( $order );
wp_send_json_success( array( 'is_risky' => ! empty( $reasons ), 'reasons' => $reasons ) );
}

private function get_customer_history( $phone ) {
global $wpdb;
return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . MM_DB::table( 'customers' ) . ' WHERE phone_number = %s', $phone ) );
}

private function update_customer_risk( $phone, $is_risky ) {
global $wpdb;
$table = MM_DB::table( 'customers' );
$existing = $this->get_customer_history( $phone );
if ( $existing ) {
$wpdb->update( $table, array( 'risk_score' => $is_risky ? ( (int) $existing->risk_score + 1 ) : (int) $existing->risk_score ), array( 'id' => $existing->id ), array( '%d' ), array( '%d' ) );
} else {
$wpdb->insert( $table, array( 'phone_number' => $phone, 'risk_score' => $is_risky ? 1 : 0, 'history_summary' => '', 'created_at' => current_time( 'mysql' ) ), array( '%s', '%d', '%s', '%s' ) );
}
}

private function count_recent_orders( $phone, $window_hours ) {
global $wpdb;
$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $window_hours * HOUR_IN_SECONDS ) );
return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = '_billing_phone' AND pm.meta_value = %s AND p.post_type IN ('shop_order','shop_order_placehold') AND p.post_date_gmt >= %s", $phone, $cutoff ) );
}

public function ajax_get_accounts() {
meesho_master_verify_ajax_nonce();
if ( ! current_user_can( 'manage_options' ) ) {
wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
}
wp_send_json_success( ( new Meesho_Master_Settings() )->get_accounts() );
}
}
