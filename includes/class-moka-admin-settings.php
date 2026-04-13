<?php
/**
 * Класс настроек интеграции Moka POS
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Moka_Admin_Settings extends WC_Integration {

    /**
     * Конструктор
     */
    public function __construct() {
        $this->id                 = 'moka_pos';
        $this->method_title       = __( 'Moka POS', 'woocommerce-mokapos' );
        $this->method_description = __( 'Интеграция с Moka POS для синхронизации товаров и остатков', 'woocommerce-mokapos' );

        // Загрузка настроек
        $this->init_form_fields();
        $this->init_settings();

        // Сохранение настроек
        add_action( 'woocommerce_update_options_integration_' . $this->id, array( $this, 'process_admin_options' ) );
    }

    /**
     * Поля формы настроек
     */
    public function init_form_fields() {
        $this->form_fields = array(
            'dealer_code' => array(
                'title'       => __( 'Код дилера', 'woocommerce-mokapos' ),
                'type'        => 'text',
                'description' => __( 'Dealer code issued by the Moka United system', 'woocommerce-mokapos' ),
                'desc_tip'    => true,
                'default'     => '',
            ),
            'username' => array(
                'title'       => __( 'Имя пользователя API', 'woocommerce-mokapos' ),
                'type'        => 'text',
                'description' => __( 'API username from Moka United', 'woocommerce-mokapos' ),
                'desc_tip'    => true,
                'default'     => '',
            ),
            'password' => array(
                'title'       => __( 'Пароль API', 'woocommerce-mokapos' ),
                'type'        => 'password',
                'description' => __( 'API password from Moka United', 'woocommerce-mokapos' ),
                'desc_tip'    => true,
                'default'     => '',
            ),
            'test_mode' => array(
                'title'       => __( 'Тестовый режим', 'woocommerce-mokapos' ),
                'type'        => 'checkbox',
                'label'       => __( 'Использовать тестовую среду Moka', 'woocommerce-mokapos' ),
                'description' => __( 'Включите для работы с тестовым API (service.refmokaunited.com)', 'woocommerce-mokapos' ),
                'default'     => 'no',
            ),
            'auto_sync' => array(
                'title'       => __( 'Авто-синхронизация', 'woocommerce-mokapos' ),
                'type'        => 'checkbox',
                'label'       => __( 'Автоматически синхронизировать товары при сохранении', 'woocommerce-mokapos' ),
                'description' => __( 'При включении каждый раз при сохранении товара будет отправляться запрос в Moka', 'woocommerce-mokapos' ),
                'default'     => 'no',
            ),
        );
    }

    /**
     * Получить значение настройки (обёртка)
     */
    public function get_option_key() {
        return 'woocommerce_' . $this->id . '_settings';
    }
}
