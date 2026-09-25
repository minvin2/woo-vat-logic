<?php
/**
 * Plugin Name: WooCommerce EU VAT / OSS Rules
 * Description: Applies EU B2B 0% VAT based on the company checkout field, keeps Lithuanian VAT for Lithuania, and supports an OSS on/off switch for EU B2C taxation.
 * Version: 1.0.3
 * Author: Custom
 * Requires Plugins: woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class WCEU_VAT_OSS_Rules {
    const OPTION_OSS = 'wceu_vat_oss_enabled';
    const SESSION_B2B = 'wceu_b2b_state';

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'admin_menu' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );

        // Classic checkout.
        add_action( 'woocommerce_checkout_update_order_review', [ $this, 'capture_checkout_state' ] );
        add_action( 'woocommerce_before_calculate_totals', [ $this, 'set_recalculate_flag' ], 1 );

        // Blocks / Store API: persist custom checkout field values when available.
        add_action( 'woocommerce_store_api_checkout_update_order_from_request', [ $this, 'capture_blocks_state' ], 10, 2 );

        // Tax calculation.
        add_filter( 'woocommerce_product_get_tax_class', [ $this, 'filter_tax_class' ], 999, 2 );
        add_filter( 'woocommerce_product_variation_get_tax_class', [ $this, 'filter_tax_class' ], 999, 2 );
        add_filter( 'woocommerce_product_tax_class', [ $this, 'filter_tax_class' ], 999, 2 );

        // If a product's tax class is overridden, force a matching custom class.
        add_filter( 'woocommerce_find_rates', [ $this, 'filter_find_rates' ], 999, 2 );

        // Checkout refresh when custom fields change.
        add_action( 'wp_footer', [ $this, 'checkout_js' ] );

        // Order metadata for audit trail.
        add_action( 'woocommerce_checkout_create_order', [ $this, 'save_order_meta' ], 10, 2 );

    }

    public function admin_menu() {
        add_submenu_page(
            'woocommerce',
            'EU VAT / OSS',
            'EU VAT / OSS',
            'manage_woocommerce',
            'wceu-vat-oss',
            [ $this, 'settings_page' ]
        );
    }

    public function register_settings() {
        register_setting( 'wceu_vat_oss_group', self::OPTION_OSS, [
            'type' => 'boolean',
            'sanitize_callback' => function( $v ) { return ! empty( $v ) ? 1 : 0; },
            'default' => 1,
        ] );

    }

    public function settings_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) return;
        $oss = (bool) get_option( self::OPTION_OSS, 1 );
        ?>
        <div class="wrap">
            <h1>WooCommerce EU VAT / OSS</h1>
            <p>Šis papildinys perskaičiuoja WooCommerce PVM pagal pirkėjo šalį, B2B lauką ir VAT numerį. Esami WooCommerce Tax Rates nėra keičiami.</p>

            <form method="post" action="options.php">
                <?php settings_fields( 'wceu_vat_oss_group' ); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">OSS aktyvus</th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr( self::OPTION_OSS ); ?>" value="1" <?php checked( $oss, true ); ?>>
                                Taikyti WooCommerce nustatytą pirkėjo ES šalies PVM fiziniams asmenims.
                            </label>
                            <p class="description">
                                Įjungta: ES B2C naudoja tos šalies WooCommerce Tax Rate. Išjungta: ES B2C naudoja Lietuvos PVM tarifą.
                                Pažymėjus „Perka kaip įmonė“, ES pirkėjui už Lietuvos ribų taikomas 0 %.
                            </p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>

            <h2>Veikimo taisyklės</h2>
            <ol>
                <li><strong>Perka kaip LT įmonė</strong> → naudojamas LT WooCommerce PVM tarifas (21 %).</li>
                <li><strong>Perka kaip kita ES įmonė (pažymėta „Perka kaip įmonė“)</strong> → 0 %.</li>
                <li><strong>ES užsienio B2C + OSS ON</strong> → naudojamas tos šalies WooCommerce Tax Rate.</li>
                <li><strong>ES užsienio B2C + OSS OFF</strong> → naudojamas Lietuvos PVM tarifas.</li>
                <li>Ne ES šalims šis papildinys specialiai neperrašo tarifų – paliekamas WooCommerce sprendimas.</li>
            </ol>

            <h2>Naudojami Custom Checkout Fields laukų ID</h2>
            <code>billing_as_b2b</code>, <code>billing_b2b_company</code>, <code>billing_b2b_imones_kodas</code>,
            <code>billing_pvm_moketojo_kodas</code>, <code>billing_imones_adresas</code>, <code>billing_country</code>
        </div>
        <?php
    }

    private function is_eu_country( $country ) {
        $eu = [
            'AT','BE','BG','HR','CY','CZ','DE','DK','EE','EL','GR','ES','FI','FR',
            'HU','IE','IT','LT','LU','LV','MT','NL','PL','PT','RO','SE','SI','SK'
        ];
        return in_array( strtoupper( $country ), $eu, true );
    }

    private function get_b2b_state() {
        $value = '';

        if ( isset( $_POST['billing_as_b2b'] ) ) {
            $value = wc_clean( wp_unslash( $_POST['billing_as_b2b'] ) );
        } elseif ( isset( $_POST['post_data'] ) ) {
            parse_str( wp_unslash( $_POST['post_data'] ), $data );
            if ( isset( $data['billing_as_b2b'] ) ) {
                $value = wc_clean( $data['billing_as_b2b'] );
            }
        } elseif ( WC()->session ) {
            $value = WC()->session->get( self::SESSION_B2B, '' );
        }

        return in_array( strtolower( (string) $value ), [ '1','yes','on','true' ], true );
    }

    private function get_country() {
        $country = '';

        if ( isset( $_POST['billing_country'] ) ) {
            $country = wc_clean( wp_unslash( $_POST['billing_country'] ) );
        } elseif ( isset( $_POST['post_data'] ) ) {
            parse_str( wp_unslash( $_POST['post_data'] ), $data );
            if ( isset( $data['billing_country'] ) ) {
                $country = wc_clean( $data['billing_country'] );
            }
        }

        if ( ! $country && WC()->customer ) {
            $country = WC()->customer->get_billing_country();
        }

        return strtoupper( $country );
    }

    private function b2b_zero_vat_applies( $country ) {
        if ( 'LT' === $country || ! $this->is_eu_country( $country ) ) {
            return false;
        }

        return $this->get_b2b_state();
    }

    public function capture_checkout_state( $post_data ) {
        parse_str( wp_unslash( $post_data ), $data );

        if ( WC()->session ) {
            $b2b = isset( $data['billing_as_b2b'] ) && in_array(
                strtolower( (string) $data['billing_as_b2b'] ),
                [ '1','yes','on','true' ],
                true
            );
            WC()->session->set( self::SESSION_B2B, $b2b ? '1' : '0' );
        }
    }

    public function capture_blocks_state( $checkout, $request ) {
        $data = $request->get_param( 'billing_address' );
        if ( is_array( $data ) && WC()->session ) {
            $b2b = ! empty( $data['billing_as_b2b'] );
            WC()->session->set( self::SESSION_B2B, $b2b ? '1' : '0' );
        }
    }

    public function set_recalculate_flag( $cart ) {
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) return;
        if ( $cart && WC()->session ) {
            // Ensures taxes are recalculated after checkout session changes.
            WC()->session->set( 'wceu_force_tax_recalc', time() );
        }
    }

    /**
     * We don't replace products' tax class. Instead we use a temporary custom class:
    * zero-rate for foreign EU B2B; for B2C we let WooCommerce's normal tax lookup work.
     */
    public function filter_tax_class( $tax_class, $product ) {
        $country = $this->get_country();
        if ( ! $country ) return $tax_class;

        if ( $this->b2b_zero_vat_applies( $country ) ) {
            return 'zero-rate';
        }

        // OSS OFF: for EU purchases without B2B 0%, use the standard LT rate.
        if ( $this->is_eu_country( $country ) && 'LT' !== $country && ! (bool) get_option( self::OPTION_OSS, 1 ) ) {
            return '';
        }

        return $tax_class;
    }

    /**
     * Filter the rates WooCommerce found. This is the key part: existing Tax Rates stay untouched.
     */
    public function filter_find_rates( $matched_tax_rates, $args ) {
        $country = isset( $args['country'] ) ? strtoupper( $args['country'] ) : $this->get_country();
        if ( ! $country ) return $matched_tax_rates;

        // EU B2B outside LT = 0%.
        if ( $this->b2b_zero_vat_applies( $country ) ) {
            return $this->zero_rate_from_matches( $matched_tax_rates );
        }

        // EU purchases without B2B 0% and with OSS OFF use the matching LT WooCommerce rates.
        if ( $this->is_eu_country( $country ) && 'LT' !== $country && ! (bool) get_option( self::OPTION_OSS, 1 ) ) {
            $lt_args = $args;
            $lt_args['country'] = 'LT';
            $lt_args['state'] = '';
            $lt_args['postcode'] = '';
            $lt_args['city'] = '';

            return WC_Tax::find_rates( $lt_args );
        }

        return $matched_tax_rates;
    }

    private function zero_rate_from_matches( $rates ) {
        return [
            'wceu_zero_rate' => [
                'rate'     => '0',
                'label'    => '0% PVM',
                'shipping' => 'yes',
                'compound' => 'no',
                'priority' => 1,
            ],
        ];
    }

    public function save_order_meta( $order, $data ) {
        $country = isset( $data['billing_country'] ) ? strtoupper( wc_clean( $data['billing_country'] ) ) : '';
        $b2b = ! empty( $data['billing_as_b2b'] );
        $vat = isset( $data['billing_pvm_moketojo_kodas'] ) ? wc_clean( $data['billing_pvm_moketojo_kodas'] ) : '';

        $order->update_meta_data( '_wceu_b2b', $b2b ? 'yes' : 'no' );
        $order->update_meta_data( '_wceu_vat_number', $vat );
        $order->update_meta_data( '_wceu_oss_enabled', get_option( self::OPTION_OSS, 1 ) ? 'yes' : 'no' );

        if ( $b2b && $vat && $this->is_eu_country( $country ) && 'LT' !== $country ) {
            $order->update_meta_data( '_wceu_vat_validation', 'not_checked' );
        }
    }

    public function checkout_js() {
        if ( ! is_checkout() || is_order_received_page() ) return;
        ?>
        <script>
        jQuery(function($){
            var timer = null;
            function wceu_refresh(){
                clearTimeout(timer);
                timer = setTimeout(function(){
                    $(document.body).trigger('update_checkout');
                }, 250);
            }

            $(document.body).on('change input',
                '#billing_country, [name="billing_country"], #billing_as_b2b, [name="billing_as_b2b"], #billing_pvm_moketojo_kodas, [name="billing_pvm_moketojo_kodas"]',
                wceu_refresh
            );

            // Custom Checkout Fields plugins sometimes render fields dynamically.
            $(document).on('change input',
                '[name="billing_as_b2b"], [name="billing_pvm_moketojo_kodas"], [name="billing_country"]',
                wceu_refresh
            );
        });
        </script>
        <?php
    }
}

add_action( 'plugins_loaded', function() {
    if ( class_exists( 'WooCommerce' ) ) {
        new WCEU_VAT_OSS_Rules();
    }
} );
