
<?php
class Moka_Cron_Jobs {
    public function __construct() {
        add_action('wp', [$this, 'schedule_sync']);
        add_action('moka_daily_sync', [$this, 'run_scheduled_sync']);
    }

    public function schedule_sync() {
        if (!wp_next_scheduled('moka_daily_sync') && get_option('moka_auto_sync_enabled')) {
            wp_schedule_event(time(), 'daily', 'moka_daily_sync');
        }
    }

    public function run_scheduled_sync() {
        $sync_manager = new Moka_Sync_Manager(new Moka_API_Client(
            get_option('moka_dealer_code'),
            get_option('moka_username'),
            get_option('moka_password'),
            get_option('moka_test_mode')
        ));
        
        $sync_manager->sync_all_products();
    }
}
