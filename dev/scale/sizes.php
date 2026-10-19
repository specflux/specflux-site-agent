<?php
/** Row counts and on-disk sizes (data + index, KB) of every table the harness cares about, as JSON. */
global $wpdb;
$rows = $wpdb->get_results(
	"SELECT table_name AS t, table_rows AS approx_rows, ROUND(data_length/1024) AS data_kb, ROUND(index_length/1024) AS index_kb
	 FROM information_schema.tables WHERE table_schema = DATABASE()
	 AND (table_name REGEXP 'specflux|belayline|actionscheduler|_posts$|_postmeta$|wc_orders|wc_order|woocommerce_order_items|_options$')
	 ORDER BY table_name",
	ARRAY_A
);
$exact = array();
foreach ( array( 'specflux_site_agent_runs', 'specflux_site_agent_steps', 'belayline_audit_log', 'actionscheduler_actions', 'actionscheduler_logs', 'posts', 'wc_orders' ) as $t ) {
	$exact[ $t ] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}{$t}" );
}
$total = (float) $wpdb->get_var( 'SELECT ROUND(SUM(data_length+index_length)/1048576,1) FROM information_schema.tables WHERE table_schema = DATABASE()' );
WP_CLI::log( wp_json_encode( array( 'db_total_mb' => $total, 'exact_rows' => $exact, 'tables' => $rows ), JSON_PRETTY_PRINT ) );
