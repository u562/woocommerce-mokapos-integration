
<?php
class Moka_Sync_Manager {
    private $api_client;
    private $woocommerce;

    public function __construct($api_client) {
        $this->api_client = $api_client;
        $this->init_woocommerce_client();
    }

    private function init_woocommerce_client() {
        $consumer_key = get_option('woocommerce_api_consumer_key');
        $consumer_secret = get_option('woocommerce_api_consumer_secret');
        
        $this->woocommerce = new \Automattic\WooCommerce\Client(
            get_site_url(),
            $consumer_key,
            $consumer_secret,
            ['version' => 'wc/v3']
        );
    }

    public function sync_all_products($limit = 100, $page = 1) {
        $products = $this->woocommerce->get('products', [
            'per_page' => $limit,
            'page' => $page,
            'status' => 'publish',
        ]);

        $results = ['success' => 0, 'failed' => 0, 'errors' => []];
        
        foreach ($products as $product) {
            try {
                $this->sync_single_product($product);
                $results['success']++;
            } catch (Exception $e) {
                $results['failed']++;
                $results['errors'][] = "Product ID {$product->id}: " . $e->getMessage();
            }
        }
        
        return $results;
    }

    private function sync_single_product($product) {
        $moka_product_id = get_post_meta($product->id, '_moka_product_id', true);
        
        $product_data = [
            'ProductCode' => $product->sku ?: 'WC-' . $product->id,
            'ProductName' => substr($product->name, 0, 100),
        ];

        if ($moka_product_id) {
            $result = $this->api_client->update_product($moka_product_id, $product_data);
        } else {
            $result = $this->api_client->add_product($product_data);
            update_post_meta($product->id, '_moka_product_id', $result['DealerProductId']);
        }

        if ($product->manage_stock) {
            $this->api_client->update_stock(
                $result['DealerProductId'],
                $product->stock_quantity
            );
        }
    }
}
