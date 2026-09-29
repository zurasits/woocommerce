<?php
/**
 * Plugin Name: Junior Cart Helper
 * Description: A safe wrapper for cart operations that protects junior developers from architecture pitfalls.
 * Version: 1.0.0
 * Author: Analyse Labor
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

class CartHelper {

    /**
     * WHY DOES THIS FUNCTION EXIST? (The Price Tag Illusion)
     * Many junior developers try to alter the price using filters like `woocommerce_get_price_html`.
     * However, this only changes the visual output (the price tag) - the old price is still used for the actual cart calculation!
     * 
     * HOW DOES THIS FUNCTION PROTECT YOU?
     * It hooks into `woocommerce_before_calculate_totals`. This is the ONLY CORRECT moment 
     * to set the actual price for the final background calculation.
     */
    public static function set_custom_price() {
        add_action( 'woocommerce_before_calculate_totals', function( $cart ) {
            // Prevent execution in the admin backend (e.g. for manual orders)
            if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
                return;
            }

            $target_product_id = get_option('jch_target_product_id');
            $new_price = get_option('jch_new_price');

            // Safely fallback if not configured
            if (empty($target_product_id) || empty($new_price) || !is_numeric($target_product_id) || !is_numeric($new_price)) {
                return;
            }

            // Loop through all items in the cart
            foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
                // Check if it is the target product (product or variation)
                if ( $cart_item['product_id'] == $target_product_id || $cart_item['variation_id'] == $target_product_id ) {
                    // Set the price firmly on the data object, not in HTML!
                    $cart_item['data']->set_price( (float) $new_price );
                }
            }
        }, 10, 1 );
    }

    /**
     * WHY DOES THIS FUNCTION EXIST? (The Circular Call)
     * If fees are added incorrectly and totals are accidentally recalculated 
     * (e.g. by calling $cart->calculate_totals()), an infinite loop occurs. The server will crash.
     * Another mistake is writing directly to the `cart_contents` array, which WooCommerce ignores.
     * 
     * HOW DOES THIS FUNCTION PROTECT YOU?
     * We strictly use the `woocommerce_cart_calculate_fees` hook and the official `add_fee()` method.
     * This guarantees that the fee is calculated at exactly the right time, without causing a boomerang effect.
     */
    public static function add_safe_fee() {
        add_action( 'woocommerce_cart_calculate_fees', function( $cart ) {
            if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
                return;
            }
            
            $enable_fee = get_option('jch_enable_handling_fee');
            if ( ! $enable_fee ) {
                return;
            }

            // Add the fee via the API (no direct access to $cart->cart_contents!)
            $cart->add_fee( 'Handling Fee', 5.00, true ); // true = taxable by default
        }, 10, 1 );
    }

    /**
     * WHY DOES THIS FUNCTION EXIST? (The Two Parallel Entry Families)
     * In the past, hooking rules into `woocommerce_check_cart_items` was enough. Since block themes, 
     * there is the Store API, which takes a completely different route! If you forget this, 
     * smart customers can bypass your rules (e.g. max order quantity) by using the Block Checkout.
     * 
     * HOW DOES THIS FUNCTION PROTECT YOU?
     * It fully automatically registers your rule for both worlds: 
     * 1. The classic AJAX/PHP route
     * 2. The new Store API Block route
     *
     * @param callable $rule_function Your validation function. 
     *                                Must return an array ['message' => 'Error'] (on error) 
     *                                or true (on success).
     */
    public static function add_global_cart_rule( $rule_function ) {
        
        // 1. The Classic Route (AJAX / Shortcode Checkout)
        add_action( 'woocommerce_check_cart_items', function() use ( $rule_function ) {
            $result = call_user_func( $rule_function );
            if ( is_array( $result ) && isset( $result['message'] ) ) {
                wc_add_notice( $result['message'], 'error' );
            }
        });

        // 2. The Block Route (Store API Checkout)
        add_action( 'woocommerce_store_api_cart_errors', function( $errors ) use ( $rule_function ) {
            $result = call_user_func( $rule_function );
            if ( is_array( $result ) && isset( $result['message'] ) ) {
                $errors->add( 
                    'junior_cart_helper_error', 
                    $result['message'], 
                    array( 'status' => 400 ) 
                );
            }
        }, 10, 1 );
    }
}


/**
 * Initialize settings and hooks
 */
add_action('init', function() {
    if (class_exists('CartHelper')) {
        CartHelper::set_custom_price();
        CartHelper::add_safe_fee();
    }
});

class CartHelperAdmin {
    public static function init() {
        add_action('admin_menu', [self::class, 'add_menu_page']);
        add_action('admin_init', [self::class, 'register_settings']);
    }

    public static function add_menu_page() {
        add_submenu_page(
            'woocommerce',
            __('Cart Helper', 'junior-cart-helper'),
            __('Cart Helper', 'junior-cart-helper'),
            'manage_woocommerce',
            'junior-cart-helper',
            [self::class, 'render_settings_page']
        );
    }

    public static function register_settings() {
        register_setting('junior_cart_helper_settings', 'jch_target_product_id');
        register_setting('junior_cart_helper_settings', 'jch_new_price');
        register_setting('junior_cart_helper_settings', 'jch_enable_handling_fee');

        add_settings_section(
            'jch_main_section',
            __('Cart Helper Settings', 'junior-cart-helper'),
            null,
            'junior-cart-helper'
        );

        add_settings_field(
            'jch_target_product_id',
            __('Target Product ID', 'junior-cart-helper'),
            [self::class, 'render_target_product_id_field'],
            'junior-cart-helper',
            'jch_main_section'
        );

        add_settings_field(
            'jch_new_price',
            __('New Price', 'junior-cart-helper'),
            [self::class, 'render_new_price_field'],
            'junior-cart-helper',
            'jch_main_section'
        );

        add_settings_field(
            'jch_enable_handling_fee',
            __('Enable Handling Fee', 'junior-cart-helper'),
            [self::class, 'render_enable_handling_fee_field'],
            'junior-cart-helper',
            'jch_main_section'
        );
    }

    public static function render_target_product_id_field() {
        $val = get_option('jch_target_product_id', '');
        $products = wc_get_products(array('limit' => -1, 'status' => 'publish'));
        
        echo '<select class="wc-enhanced-select" name="jch_target_product_id" data-placeholder="' . esc_attr__('Select a product...', 'junior-cart-helper') . '" style="width: 300px;">';
        echo '<option value=""></option>';
        foreach ($products as $product) {
            echo '<option value="' . esc_attr($product->get_id()) . '" ' . selected($val, $product->get_id(), false) . '>' . wp_kses_post($product->get_formatted_name()) . '</option>';
        }
        echo '</select>';
    }

    public static function render_new_price_field() {
        $val = get_option('jch_new_price', '');
        echo '<input type="text" name="jch_new_price" id="jch_new_price" value="' . esc_attr($val) . '" class="regular-text">';
    }

    public static function render_enable_handling_fee_field() {
        $val = get_option('jch_enable_handling_fee', '0');
        echo '<input type="checkbox" name="jch_enable_handling_fee" value="1" ' . checked(1, $val, false) . '>';
        echo ' ' . esc_html__('(Adds a 5.00 fee)', 'junior-cart-helper');
    }

    public static function render_settings_page() {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Cart Helper', 'junior-cart-helper'); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('junior_cart_helper_settings');
                do_settings_sections('junior-cart-helper');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }
}
CartHelperAdmin::init();

// Inline Translation (Fallback to German)
add_filter('gettext', function($translated_text, $text, $domain) {
    if ($domain === 'junior-cart-helper' && strpos(get_user_locale(), 'de') === 0) {
        $de = [
            'Cart Helper' => 'Warenkorb Helfer',
            'Cart Helper Settings' => 'Warenkorb Helfer Einstellungen',
            'Target Product ID' => 'Ziel-Produkt',
            'Search for a product...' => 'Nach einem Produkt suchen...',
            'Select a product...' => 'Wähle ein Produkt...',
            'New Price' => 'Neuer Preis',
            'Enable Handling Fee' => 'Bearbeitungsgebühr aktivieren',
            '(Adds a 5.00 fee)' => '(Fügt eine Gebühr von 5,00 hinzu)',
        ];
        if (isset($de[$text])) {
            return $de[$text];
        }
    }
    return $translated_text;
}, 10, 3);

// AJAX Handler for fetching product price
add_action('wp_ajax_jch_get_product_price', function() {
    $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
    if ($product_id) {
        $product = wc_get_product($product_id);
        if ($product) {
            wp_send_json_success(['price' => $product->get_price()]);
        }
    }
    wp_send_json_error();
});

// Admin JS for updating price field on product change
add_action('admin_footer', function() {
    $screen = get_current_screen();
    if ($screen && $screen->id === 'woocommerce_page_junior-cart-helper') {
        ?>
        <script>
        jQuery(document).ready(function($) {
            var $priceField = $('#jch_new_price');
            var $feeCheckbox = $('input[name="jch_enable_handling_fee"]');
            
            function toggleFeeCheckbox() {
                var val = $priceField.val().trim();
                if (val === '' || val === 'Lade...') {
                    $feeCheckbox.prop('disabled', true);
                    if (val === '') {
                        $feeCheckbox.prop('checked', false);
                    }
                } else {
                    $feeCheckbox.prop('disabled', false);
                }
            }

            toggleFeeCheckbox();
            $priceField.on('input', toggleFeeCheckbox);

            $('select[name="jch_target_product_id"]').on('change', function() {
                var productId = $(this).val();
                if (productId) {
                    $priceField.val('Lade...');
                    toggleFeeCheckbox();
                    
                    $.post(ajaxurl, {
                        action: 'jch_get_product_price',
                        product_id: productId
                    }, function(response) {
                        if (response.success && response.data.price !== undefined) {
                            $priceField.val(response.data.price);
                        } else {
                            $priceField.val('');
                        }
                        toggleFeeCheckbox();
                    }).fail(function() {
                        $priceField.val('');
                        toggleFeeCheckbox();
                    });
                } else {
                    $priceField.val('');
                    toggleFeeCheckbox();
                }
            });
        });
        </script>
        <?php
    }
});