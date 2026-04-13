<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Moka_Cron_Jobs {

    public function __construct() {
        add_action( 'wp', array( $this, 'schedule_sync' ) );
        add_action( 'moka_daily_sync', array( $this, 'run_scheduled_sync' ) );
    }

    public function schedule_sync() {
        if ( ! wp_next_scheduled( 'moka_daily_sync' ) && get_option( 'woocommerce_moka_pos_auto_sync' ) === 'yes' ) {
            wp_schedule_event( time(), 'daily', 'moka_daily_sync' );
        }
    }

    public function run_scheduled_sync() {
        $dealer_code = get_option( 'woocommerce_moka_pos_dealer_code' );
        $username    = get_option( 'woocommerce_moka_pos_username' );
        $password    = get_option( 'woocommerce_moka_pos_password' );
        $test_mode   = get_option( 'woocommerce_moka_pos_test_mode' ) === 'yes';

        if ( ! $dealer_code || ! $username || ! $password ) {
            return;
        }

        try {
            $api_client   = new Moka_API_Client( $dealer_code, $username, $password, $test_mode );
            $sync_manager = new Moka_Sync_Manager( $api_client );
            $sync_manager->sync_all_products();
        } catch ( Exception $e ) {
            error_log( 'Moka POS daily sync error: ' . $e->getMessage() );
        }
    }
}
