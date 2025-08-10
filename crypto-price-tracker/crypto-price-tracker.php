<?php
/**
 * Plugin Name: Crypto Price Tracker
 * Plugin URI:  https://github.com/cryptoball7/crypto-price-tracker
 * Description: Displays live cryptocurrency prices using CoinGecko (server-side cached) with shortcode, widget, and admin settings.
 * Version:     1.0.0
 * Author:      Cryptoball cryptoball7@gmail.com
 * Author URI:  https://github.com/cryptoball7
 * License:     GPLv3
 * Text Domain: crypto-price-tracker
 * Domain Path: /languages
 *
 * Installation:
 * 1. Create a folder named `crypto-price-tracker` in wp-content/plugins
 * 2. Save this file as `crypto-price-tracker.php` inside that folder
 * 3. Activate plugin from the WordPress admin Plugins screen
 *
 * Usage:
 * - Shortcode: [crypto_price symbol="BTC" currency="USD" refresh="60"]
 * - Widget: Appearance > Widgets > Add "Crypto Price (CPT)"
 * - Admin settings: Settings > Crypto Price Tracker
 *
 * Notes:
 * - This plugin uses CoinGecko's /simple/price API (no API key required). It caches results with WordPress transients to reduce requests.
 * - You can change default currency and cache duration in the settings page.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Crypto_Price_Tracker {
    private static $instance = null;
    private $option_name = 'cpt_settings';

    public static function instance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        register_activation_hook( __FILE__, array( $this, 'activation' ) );
        register_deactivation_hook( __FILE__, array( $this, 'deactivation' ) );

        add_action( 'init', array( $this, 'load_textdomain' ) );
        add_action( 'admin_menu', array( $this, 'admin_menu' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );

        add_shortcode( 'crypto_price', array( $this, 'shortcode_crypto_price' ) );

        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'wp_ajax_cpt_fetch_price', array( $this, 'ajax_fetch_price' ) );
        add_action( 'wp_ajax_nopriv_cpt_fetch_price', array( $this, 'ajax_fetch_price' ) );

        add_action( 'widgets_init', array( $this, 'register_widget' ) );
    }

    public function activation() {
        $defaults = array(
            'currency' => 'USD',
            'cache_seconds' => 60,
        );
        if ( ! get_option( $this->option_name ) ) {
            add_option( $this->option_name, $defaults );
        }
    }

    public function deactivation() {
        // keep settings by default; remove options here if you prefer cleanup
    }

    public function load_textdomain() {
        load_plugin_textdomain( 'crypto-price-tracker', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
    }

    public function admin_menu() {
        add_options_page(
            __( 'Crypto Price Tracker', 'crypto-price-tracker' ),
            __( 'Crypto Price Tracker', 'crypto-price-tracker' ),
            'manage_options',
            'crypto-price-tracker',
            array( $this, 'settings_page' )
        );
    }

    public function register_settings() {
        register_setting( 'cpt_settings_group', $this->option_name, array( $this, 'validate_settings' ) );

        add_settings_section( 'cpt_main', __( 'Main Settings', 'crypto-price-tracker' ), null, 'crypto-price-tracker' );

        add_settings_field( 'currency', __( 'Default Currency', 'crypto-price-tracker' ), array( $this, 'field_currency' ), 'crypto-price-tracker', 'cpt_main' );
        add_settings_field( 'cache_seconds', __( 'Cache (seconds)', 'crypto-price-tracker' ), array( $this, 'field_cache' ), 'crypto-price-tracker', 'cpt_main' );
    }

    public function validate_settings( $input ) {
        $valid = array();
        $valid['currency'] = strtoupper( sanitize_text_field( $input['currency'] ) );
        $valid['cache_seconds'] = absint( $input['cache_seconds'] );
        if ( $valid['cache_seconds'] < 5 ) {
            $valid['cache_seconds'] = 5;
        }
        return $valid;
    }

    public function field_currency() {
        $opts = get_option( $this->option_name );
        $currency = isset( $opts['currency'] ) ? esc_attr( $opts['currency'] ) : 'USD';
        echo '<input type="text" name="' . esc_attr( $this->option_name ) . '[currency]" value="' . $currency . '" />';
        echo '<p class="description">' . __( 'Default fiat currency (e.g. USD, EUR). Uppercase.', 'crypto-price-tracker' ) . '</p>';
    }

    public function field_cache() {
        $opts = get_option( $this->option_name );
        $cache = isset( $opts['cache_seconds'] ) ? absint( $opts['cache_seconds'] ) : 60;
        echo '<input type="number" min="5" name="' . esc_attr( $this->option_name ) . '[cache_seconds]" value="' . $cache . '" />';
        echo '<p class="description">' . __( 'How long to cache API responses (in seconds).', 'crypto-price-tracker' ) . '</p>';
    }

    public function settings_page() {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Crypto Price Tracker Settings', 'crypto-price-tracker' ); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields( 'cpt_settings_group' );
                do_settings_sections( 'crypto-price-tracker' );
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    public function enqueue_assets() {
        wp_register_script( 'cpt-script', plugins_url( 'assets/cpt.js', __FILE__ ), array( 'jquery' ), '1.0', true );
        wp_localize_script( 'cpt-script', 'cpt_ajax', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'cpt_nonce' ),
        ) );
        wp_enqueue_script( 'cpt-script' );

        wp_register_style( 'cpt-style', plugins_url( 'assets/cpt.css', __FILE__ ) );
        wp_enqueue_style( 'cpt-style' );
    }

    public function shortcode_crypto_price( $atts ) {
        $atts = shortcode_atts( array(
            'symbol'   => 'BTC',
            'currency' => '',
            'refresh'  => 60, // seconds (client-side refresh)
        ), $atts, 'crypto_price' );

        $symbol = strtoupper( sanitize_text_field( $atts['symbol'] ) );
        $refresh = absint( $atts['refresh'] );
        if ( $refresh < 5 ) $refresh = 5;

        $opts = get_option( $this->option_name );
        $currency = $atts['currency'] ? strtoupper( sanitize_text_field( $atts['currency'] ) ) : ( isset( $opts['currency'] ) ? $opts['currency'] : 'USD' );

        // initial server-rendered price (fast cached)
        $price_data = $this->get_price( $symbol, $currency );

        ob_start();
        ?>
        <span class="cpt-price" data-symbol="<?php echo esc_attr( $symbol ); ?>" data-currency="<?php echo esc_attr( $currency ); ?>" data-refresh="<?php echo esc_attr( $refresh ); ?>">
            <?php if ( isset( $price_data['price'] ) ) : ?>
                <span class="cpt-price-value"><?php echo esc_html( $price_data['formatted'] ); ?></span>
            <?php else: ?>
                <span class="cpt-price-value">—</span>
            <?php endif; ?>
            <small class="cpt-price-meta">
                <?php if ( isset( $price_data['updated_at'] ) ) : ?>
                    <?php printf( __( 'Updated: %s', 'crypto-price-tracker' ), esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $price_data['updated_at'] ) ) ); ?>
                <?php endif; ?>
            </small>
        </span>
        <?php
        return ob_get_clean();
    }

    private function map_symbol_to_id( $symbol ) {
        // small built-in mapping for popular symbols
        $map = array(
            'BTC' => 'bitcoin',
            'ETH' => 'ethereum',
            'LTC' => 'litecoin',
            'BCH' => 'bitcoin-cash',
            'DOGE'=> 'dogecoin',
            'XRP' => 'ripple',
            'ADA' => 'cardano',
            'SOL' => 'solana',
            'DOT' => 'polkadot',
        );
        $symbol = strtoupper( $symbol );
        return isset( $map[ $symbol ] ) ? $map[ $symbol ] : strtolower( $symbol );
    }

    public function get_price( $symbol, $currency ) {
        $symbol = strtoupper( $symbol );
        $currency = strtoupper( $currency );

        $opts = get_option( $this->option_name );
        $cache_seconds = isset( $opts['cache_seconds'] ) ? absint( $opts['cache_seconds'] ) : 60;

        // transient key per symbol+currency
        $transient_key = 'cpt_' . md5( $symbol . '_' . $currency );

        $cached = get_transient( $transient_key );
        if ( $cached && isset( $cached['price'] ) ) {
            return $cached;
        }

        $id = $this->map_symbol_to_id( $symbol );
        $vs_currency = strtolower( $currency );

        $url = add_query_arg( array(
            'ids' => rawurlencode( $id ),
            'vs_currencies' => rawurlencode( $vs_currency ),
            'include_last_updated_at' => 'true',
        ), 'https://api.coingecko.com/api/v3/simple/price' );

        $response = wp_remote_get( $url, array( 'timeout' => 10 ) );
        if ( is_wp_error( $response ) ) {
            return array( 'error' => $response->get_error_message() );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( $code !== 200 || ! is_array( $data ) ) {
            return array( 'error' => __( 'Failed to fetch price', 'crypto-price-tracker' ) );
        }

        // data example: ["bitcoin"] => ["usd"] => 12345
        $price = null;
        $updated_at = null;
        if ( isset( $data[ $id ] ) ) {
            $item = $data[ $id ];
            if ( isset( $item[ $vs_currency ] ) ) {
                $price = $item[ $vs_currency ];
            }
            if ( isset( $item['last_updated_at'] ) ) {
                $updated_at = intval( $item['last_updated_at'] );
            }
        }

        if ( $price === null ) {
            return array( 'error' => __( 'Price not available', 'crypto-price-tracker' ) );
        }

        $formatted = $this->format_price( $price, $currency );

        $result = array(
            'symbol' => $symbol,
            'currency' => $currency,
            'price' => $price,
            'formatted' => $formatted,
            'updated_at' => $updated_at ? $updated_at : time(),
        );

        // cache it
        set_transient( $transient_key, $result, $cache_seconds );

        return $result;
    }

    private function format_price( $price, $currency ) {
        // simple formatting: if price < 1 show 6 decimals, else 2 decimals
        if ( $price < 1 ) {
            $decimals = 6;
        } else {
            $decimals = 2;
        }
        return number_format_i18n( $price, $decimals ) . ' ' . esc_html( strtoupper( $currency ) );
    }

    public function ajax_fetch_price() {
        check_ajax_referer( 'cpt_nonce', 'nonce' );

        $symbol = isset( $_REQUEST['symbol'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['symbol'] ) ) : '';
        $currency = isset( $_REQUEST['currency'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['currency'] ) ) : '';

        if ( empty( $symbol ) || empty( $currency ) ) {
            wp_send_json_error( array( 'message' => __( 'Missing parameters', 'crypto-price-tracker' ) ) );
        }

        $data = $this->get_price( $symbol, $currency );
        if ( isset( $data['error'] ) ) {
            wp_send_json_error( $data );
        }

        wp_send_json_success( $data );
    }

    public function register_widget() {
        register_widget( 'CPT_Widget' );
    }
}

// Simple widget class
class CPT_Widget extends WP_Widget {
    public function __construct() {
        parent::__construct(
            'cpt_widget',
            __( 'Crypto Price (CPT)', 'crypto-price-tracker' ),
            array( 'description' => __( 'Shows a crypto price (shortcode-like)', 'crypto-price-tracker' ) )
        );
    }

    public function widget( $args, $instance ) {
        echo $args['before_widget'];
        if ( ! empty( $instance['title'] ) ) {
            echo $args['before_title'] . apply_filters( 'widget_title', $instance['title'] ) . $args['after_title'];
        }

        $symbol = isset( $instance['symbol'] ) ? $instance['symbol'] : 'BTC';
        $currency = isset( $instance['currency'] ) ? $instance['currency'] : ''; // will use default
        $refresh = isset( $instance['refresh'] ) ? absint( $instance['refresh'] ) : 60;

        echo do_shortcode( sprintf( '[crypto_price symbol="%s" currency="%s" refresh="%d"]', esc_attr( $symbol ), esc_attr( $currency ), $refresh ) );

        echo $args['after_widget'];
    }

    public function form( $instance ) {
        $title = isset( $instance['title'] ) ? $instance['title'] : '';
        $symbol = isset( $instance['symbol'] ) ? $instance['symbol'] : 'BTC';
        $currency = isset( $instance['currency'] ) ? $instance['currency'] : '';
        $refresh = isset( $instance['refresh'] ) ? absint( $instance['refresh'] ) : 60;
        ?>
        <p>
            <label><?php _e( 'Title:' ); ?></label>
            <input class="widefat" name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" value="<?php echo esc_attr( $title ); ?>">
        </p>
        <p>
            <label><?php _e( 'Symbol (e.g. BTC):' ); ?></label>
            <input class="widefat" name="<?php echo esc_attr( $this->get_field_name( 'symbol' ) ); ?>" value="<?php echo esc_attr( $symbol ); ?>">
        </p>
        <p>
            <label><?php _e( 'Currency (leave blank for default):' ); ?></label>
            <input class="widefat" name="<?php echo esc_attr( $this->get_field_name( 'currency' ) ); ?>" value="<?php echo esc_attr( $currency ); ?>">
        </p>
        <p>
            <label><?php _e( 'Refresh (seconds):' ); ?></label>
            <input class="widefat" type="number" min="5" name="<?php echo esc_attr( $this->get_field_name( 'refresh' ) ); ?>" value="<?php echo esc_attr( $refresh ); ?>">
        </p>
        <?php
    }

    public function update( $new_instance, $old_instance ) {
        $instance = array();
        $instance['title'] = sanitize_text_field( $new_instance['title'] );
        $instance['symbol'] = strtoupper( sanitize_text_field( $new_instance['symbol'] ) );
        $instance['currency'] = strtoupper( sanitize_text_field( $new_instance['currency'] ) );
        $instance['refresh'] = absint( $new_instance['refresh'] );
        if ( $instance['refresh'] < 5 ) $instance['refresh'] = 5;
        return $instance;
    }
}

// Instantiate plugin
Crypto_Price_Tracker::instance();

?>
