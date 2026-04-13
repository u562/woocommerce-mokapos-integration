
<?php
class Moka_Admin_Settings {
    public function __construct() {
        add_filter('woocommerce_integrations', [$this, 'add_integration']);
    }

    public function add_integration($integrations) {
        $integrations[] = 'WC_MokaPOS_Integration';
        return $integrations;
    }

    public function init_form_fields() {
        return [
            'dealer_code' => [
                'title' => 'Код дилера',
                'type' => 'text',
                'description' => 'Dealer code issued by the Moka United system[reference:5]',
                'required' => true,
            ],
            'username' => [
                'title' => 'Имя пользователя API',
                'type' => 'text',
                'required' => true,
            ],
            'password' => [
                'title' => 'Пароль API',
                'type' => 'password',
                'required' => true,
            ],
            'test_mode' => [
                'title' => 'Тестовый режим',
                'type' => 'checkbox',
                'description' => 'Использовать тестовую среду Moka',
            ],
            'auto_sync' => [
                'title' => 'Авто-синхронизация',
                'type' => 'checkbox',
                'description' => 'Автоматически синхронизировать товары при сохранении',
            ],
        ];
    }
}
