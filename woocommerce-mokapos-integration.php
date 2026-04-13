
<?php
/**
 * Plugin Name: WooCommerce Moka POS Integration
 * Plugin URI: https://example.com/moka-woocommerce
 * Description: Синхронизация товаров между WooCommerce и Moka POS (Moka United)
 * Version: 1.0.0
 * Author: Your Name
 * Author URI: https://example.com
 * License: GPL v2 or later
 * Text Domain: woocommerce-mokapos
 * Domain Path: /languages
 * WC requires at least: 5.0
 * WC tested up to: 8.0
 */

defined( 'ABSPATH' ) || exit;

// Проверка наличия WooCommerce
if ( ! in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
    add_action( 'admin_notices', function() {
        echo '<div class="error"><p><strong>WooCommerce Moka POS Integration</strong> требует активного плагина WooCommerce.</p></div>';
    } );
    return;
}

// Определение констант плагина
define( 'MOKA_POS_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'MOKA_POS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'MOKA_POS_VERSION', '1.0.0' );

// Подключение необходимых классов
require_once MOKA_POS_PLUGIN_PATH . 'includes/class-moka-api-client.php';
require_once MOKA_POS_PLUGIN_PATH . 'includes/class-moka-sync-manager.php';
require_once MOKA_POS_PLUGIN_PATH . 'includes/class-moka-admin-settings.php';
require_once MOKA_POS_PLUGIN_PATH . 'includes/class-moka-cron-jobs.php';

/**
 * Главный класс плагина
 */
class WC_MokaPOS_Integration {

    /**
     * Экземпляр класса (Singleton)
     */
    private static $instance = null;

    /**
     * Получить экземпляр
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Конструктор
     */
    private function __construct() {
        add_action( 'init', array( $this, 'init' ) );
        add_action( 'woocommerce_init', array( $this, 'init_integration' ) );
        register_activation_hook( __FILE__, array( $this, 'activate' ) );
        register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );
    }

    /**
     * Инициализация плагина
     */
    public function init() {
        load_plugin_textdomain( 'woocommerce-mokapos', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
    }

    /**
     * Инициализация интеграции WooCommerce
     */
    public function init_integration() {
        if ( class_exists( 'WC_Integration' ) ) {
            new Moka_Admin_Settings();
            new Moka_Cron_Jobs();

            // Добавляем кнопку синхронизации на страницу настроек
            add_action( 'woocommerce_settings_tabs_integration', array( $this, 'add_sync_button' ) );
            add_action( 'admin_post_moka_manual_sync', array( $this, 'handle_manual_sync' ) );
        }
    }

    /**
     * Кнопка ручной синхронизации на странице настроек
     */
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

    /**
     * Обработчик ручной синхронизации
     */
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
            $api_client = new Moka_API_Client( $dealer_code, $username, $password, $test_mode );
            $sync_manager = new Moka_Sync_Manager( $api_client );
            $result = $sync_manager->sync_all_products();

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

    /**
     * Активация плагина
     */
    public function activate() {
        // Создаём расписание WP-Cron, если автосинхронизация включена
        if ( get_option( 'woocommerce_moka_pos_auto_sync' ) === 'yes' && ! wp_next_scheduled( 'moka_daily_sync' ) ) {
            wp_schedule_event( time(), 'daily', 'moka_daily_sync' );
        }
    }

    /**
     * Деактивация плагина
     */
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
} );<?php
/**
 * Plugin Name: WooCommerce Moka POS Integration
 * Plugin URI: https://example.com/moka-woocommerce
 * Description: Синхронизация товаров между WooCommerce и Moka POS (Moka United)
 * Version: 1.0.0
 * Author: Your Name
 * Author URI: https://example.com
 * License: GPL v2 or later
 * Text Domain: woocommerce-mokapos
 * Domain Path: /languages
 * WC requires at least: 5.0
 * WC tested up to: 8.0
 */

defined( 'ABSPATH' ) || exit;

// Проверка наличия WooCommerce
if ( ! in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
    add_action( 'admin_notices', function() {
        echo '<div class="error"><p><strong>WooCommerce Moka POS Integration</strong> требует активного плагина WooCommerce.</p></div>';
    } );
    return;
}

// Определение констант плагина
define( 'MOKA_POS_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'MOKA_POS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'MOKA_POS_VERSION', '1.0.0' );

// Подключение необходимых классов
require_once MOKA_POS_PLUGIN_PATH . 'includes/class-moka-api-client.php';
require_once MOKA_POS_PLUGIN_PATH . 'includes/class-moka-sync-manager.php';
require_once MOKA_POS_PLUGIN_PATH . 'includes/class-moka-admin-settings.php';
require_once MOKA_POS_PLUGIN_PATH . 'includes/class-moka-cron-jobs.php';

/**
 * Главный класс плагина
 */
class WC_MokaPOS_Integration {

    /**
     * Экземпляр класса (Singleton)
     */
    private static $instance = null;

    /**
     * Получить экземпляр
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Конструктор
     */
    private function __construct() {
        add_action( 'init', array( $this, 'init' ) );
        add_action( 'woocommerce_init', array( $this, 'init_integration' ) );
        register_activation_hook( __FILE__, array( $this, 'activate' ) );
        register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );
    }

    /**
     * Инициализация плагина
     */
    public function init() {
        load_plugin_textdomain( 'woocommerce-mokapos', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
    }

    /**
     * Инициализация интеграции WooCommerce
     */
    public function init_integration() {
        if ( class_exists( 'WC_Integration' ) ) {
            new Moka_Admin_Settings();
            new Moka_Cron_Jobs();

            // Добавляем кнопку синхронизации на страницу настроек
            add_action( 'woocommerce_settings_tabs_integration', array( $this, 'add_sync_button' ) );
            add_action( 'admin_post_moka_manual_sync', array( $this, 'handle_manual_sync' ) );
        }
    }

    /**
     * Кнопка ручной синхронизации на странице настроек
     */
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

    /**
     * Обработчик ручной синхронизации
     */
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
            $api_client = new Moka_API_Client( $dealer_code, $username, $password, $test_mode );
            $sync_manager = new Moka_Sync_Manager( $api_client );
            $result = $sync_manager->sync_all_products();

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

    /**
     * Активация плагина
     */
    public function activate() {
        // Создаём расписание WP-Cron, если автосинхронизация включена
        if ( get_option( 'woocommerce_moka_pos_auto_sync' ) === 'yes' && ! wp_next_scheduled( 'moka_daily_sync' ) ) {
            wp_schedule_event( time(), 'daily', 'moka_daily_sync' );
        }
    }

    /**
     * Деактивация плагина
     */
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
