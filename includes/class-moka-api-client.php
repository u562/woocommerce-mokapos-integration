
<?php
class Moka_API_Client {
    private $dealer_code;
    private $username;
    private $password;
    private $api_url = 'https://service.mokaunited.com';
    private $test_url = 'https://service.refmokaunited.com';
    private $is_test_mode = false;

    public function __construct($dealer_code, $username, $password, $test_mode = false) {
        $this->dealer_code = $dealer_code;
        $this->username = $username;
        $this->password = $password;
        $this->is_test_mode = $test_mode;
    }

    private function get_base_url() {
        return $this->is_test_mode ? $this->test_url : $this->api_url;
    }

    private function generate_check_key() {
        $raw_key = $this->dealer_code . 'MK' . $this->username . 'PD' . $this->password;
        return hash('sha256', $raw_key);
    }

    private function request($endpoint, $data = []) {
        $url = $this->get_base_url() . $endpoint;
        $payload = [
            'DealerSaleAuthentication' => [
                'DealerCode' => $this->dealer_code,
                'Username' => $this->username,
                'Password' => $this->password,
                'CheckKey' => $this->generate_check_key(),
            ],
        ];

        if (!empty($data)) {
            $payload['DealerSaleRequest'] = $data;
        }

        $response = wp_remote_post($url, [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode($payload),
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            throw new Exception($response->get_error_message());
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if ($body['ResultCode'] !== 'Success') {
            throw new Exception($body['ResultMessage'] ?: $body['ResultCode']);
        }

        return $body['Data'];
    }

    public function get_products() {
        return $this->request('/DealerSale/GetProductList');
    }

    public function add_product($product_data) {
        return $this->request('/DealerSale/AddProduct', $product_data);
    }

    public function update_product($dealer_product_id, $product_data) {
        return $this->request('/DealerSale/UpdateProduct', array_merge(
            ['DealerProductId' => $dealer_product_id],
            $product_data
        ));
    }

    public function update_stock($dealer_product_id, $stock_quantity) {
        return $this->request('/DealerSale/UpdateProductStock', [
            'DealerProductId' => $dealer_product_id,
            'StockQuantity' => $stock_quantity,
        ]);
    }
}
