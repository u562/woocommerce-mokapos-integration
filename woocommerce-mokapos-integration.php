<?php
/**
 * Plugin Name: WooCommerce Moka POS Integration
 * Plugin URI: https://example.com/moka-woocommerce
 * Description: Синхронизация товаров между WooCommerce и Moka POS (Moka United)
 * Version: 1.0.1
 * Author: Your Name
 * License: GPL v2 or later
 * Text Domain: woocommerce-mokapos
 * WC requires at least: 5.0
 * WC tested up to: 8.0
 */

defined( 'ABSPATH' ) || exit;

// Проверка наличия WooCommerce (без загрузки классов)
if ( ! class_exists( 'WooCommerce', false ) ) {
    add_action( 'admin_notices', function() {
        echo '<div class="error"><p><strong>WooCommerce Moka POS Integration</strong> требует активного плагина WooCommerce.</p></div>';
    } );
    return;
}

define( 'MOKA_POS_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'MOKA_POS_VERSION', '1.0.1' );

// Подключаем классы, которые НЕ зависят от WC_Integration (можно загружать сразу)
require_once MOKA_POS_PLUGIN_PATH . 'includes/class-moka-api-client.php';
require_once MOKA_POS_PLUGIN_PATH . 'includes/class-moka-sync-manager.php';

/**
 * Главный класс плагина
 */
class WC_MokaPOS_Integration {

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'init', array( $this, 'init' ) );
        add_action( 'woocommerce_init', array( $this, 'init_integration' ) );
        register_activation_hook( __FILE__, array( $this, 'activate' ) );
        register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );
    }

    public function init() {
        load_plugin_textdomain( 'woocommerce-mokapos', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
    }

    /**
     * Инициализация интеграции с WooCommerce (вызывается после загрузки WC)
     */
    public function init_integration() {
        // Теперь WooCommerce полностью загружен, можно подключать классы, наследующие WC_Integration
        require_once MOKA_POS_PLUGIN_PATH . 'includes/class-moka-admin-settings.php';
        require_once MOKA_POS_PLUGIN_PATH . 'includes/class-moka-cron-jobs.php';

        // Регистрируем интеграцию в списке WooCommerce
        add_filter( 'woocommerce_integrations', array( $this, 'add_moka_integration' ) );

        // Добавляем кнопку ручной синхронизации на страницу настроек
        add_action( 'woocommerce_settings_tabs_integration', array( $this, 'add_sync_button' ) );
        add_action( 'admin_post_moka_manual_sync', array( $this, 'handle_manual_sync' ) );

        // Запускаем планировщик
        new Moka_Cron_Jobs();
    }

    public function add_moka_integration( $integrations ) {
        $integrations[] = 'Moka_Admin_Settings';
        return $integrations;
    }

    public function add_sync_button() {
        $screen = get_current_screen();
        if ( $screen && $screen->id === 'woocommerce_page_wc-settings' && isset( $_GET['section'] ) && $_GET['section'] === 'moka_pos' ) {
            ?>
            <div style="margin: 20px 0; padding: 15px; background: #f1f1f1; border-left: 4px solid #007cba;">
                <h3><?php esc_html_e( 'Ручная синхронизация', 'woocommerce-mokapos' ); ?></h3>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="moka_manual_sync">
                    <?php wp_nonce_field( 'moka_manual_sync' ); ?>
                    <button type="submit" class="button button-primary"><?php esc_html_e( 'Выполнить синхронизацию товаров сейчас', 'woocommerce-mokapos' ); ?></button>
                </form>
                <p class="description"><?php esc_html_e( 'Запускает синхронизацию всех опубликованных товаров WooCommerce в Moka POS.', 'woocommerce-mokapos' ); ?></p>
            </div>
            <?php
        }
    }

    public function handle_manual_sync() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'Недостаточно прав' );
        }
        check_admin_referer( 'moka_manual_sync' );

        $dealer_code = get_option( 'woocommerce_moka_pos_dealer_code' );
        $username    = get_option( 'woocommerce_moka_pos_username' );
        $password    = get_option( 'woocommerce_moka_pos_password' );
        $test_mode   = get_option( 'woocommerce_moka_pos_test_mode' ) === 'yes';

        if ( ! $dealer_code || ! $username || ! $password ) {
            wp_die( 'Заполните все поля API в настройках интеграции.' );
        }

        try {
            $api_client    = new Moka_API_Client( $dealer_code, $username, $password, $test_mode );
            $sync_manager  = new Moka_Sync_Manager( $api_client );
            $result        = $sync_manager->sync_all_products();

            $message = sprintf( 'Синхронизация завершена. Успешно: %d, Ошибок: %d', $result['success'], $result['failed'] );
            if ( ! empty( $result['errors'] ) ) {
                $message .= '<br>Детали ошибок:<br>' . implode( '<br>', array_slice( $result['errors'], 0, 10 ) );
            }
            set_transient( 'moka_sync_result', $message, 60 );
        } catch ( Exception $e ) {
            set_transient( 'moka_sync_result', 'Ошибка: ' . $e->getMessage(), 60 );
        }

        wp_safe_redirect( add_query_arg( 'sync_result', '1', wp_get_referer() ) );
        exit;
    }

    public function activate() {
        if ( get_option( 'woocommerce_moka_pos_auto_sync' ) === 'yes' && ! wp_next_scheduled( 'moka_daily_sync' ) ) {
            wp_schedule_event( time(), 'daily', 'moka_daily_sync' );
        }
    }

    public function deactivate() {
        $timestamp = wp_next_scheduled( 'moka_daily_sync' );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, 'moka_daily_sync' );
        }
    }
}

// Запуск плагина
WC_MokaPOS_Integration::get_instance();

// Добавляем ссылку на настройки на странице плагинов
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function( $links ) {
    $settings_link = '<a href="' . admin_url( 'admin.php?page=wc-settings&tab=integration&section=moka_pos' ) . '">' . __( 'Настройки', 'woocommerce-mokapos' ) . '</a>';
    array_unshift( $links, $settings_link );
    return $links;
} );
