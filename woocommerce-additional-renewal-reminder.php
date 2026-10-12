<?php
/**
 * Plugin Name: WooCommerce Additional Renewal Reminder
 * Description: Adds a second renewal reminder to WooCommerce Subscriptions using existing notification emails.
 * Author: FirstTracks Marketing
 * Author URI: https://firsttracksmarketing.com/
 * Version: 1.0.1
 */

defined( 'ABSPATH' ) || exit;

final class CEG_Second_Renewal_Reminder {
    const ENABLED = 'ceg_second_renewal_reminder_enabled';
    const NUMBER  = 'ceg_second_renewal_reminder_number';
    const UNIT    = 'ceg_second_renewal_reminder_unit';
    const SEND    = 'ceg_second_renewal_reminder_send';
    const BATCH   = 'ceg_second_renewal_reminder_batch';
    const SWEEP   = 'ceg_second_renewal_reminder_reconcile';
    const GROUP   = 'ceg_second_renewal_reminder';
    const META    = '_ceg_second_renewal_reminder_attempted_for';
    private static $dirty = false;

    public static function boot() {
        add_action( 'before_woocommerce_init', function () {
            if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
                \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
            }
        } );
        add_action( 'plugins_loaded', array( __CLASS__, 'init' ), 30 );
    }

    public static function init() {
        if ( ! class_exists( 'WC_Subscriptions_Email_Notifications' ) ||
             ! class_exists( 'WCS_Action_Scheduler_Customer_Notifications' ) ) {
            add_action( 'admin_notices', function () {
                echo '<div class="notice notice-error"><p>CEG Second Renewal Reminder requires WooCommerce Subscriptions with its built-in notification feature (6.9 or newer).</p></div>';
            } );
            return;
        }
        add_filter( 'woocommerce_subscription_settings', array( __CLASS__, 'settings' ), 100 );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_assets' ), 30 );
        add_filter( 'woocommerce_admin_settings_sanitize_option_' . self::NUMBER, array( __CLASS__, 'sanitize_number' ) );
        add_filter( 'woocommerce_admin_settings_sanitize_option_' . self::UNIT, array( __CLASS__, 'sanitize_unit' ) );
        add_action( 'updated_option', array( __CLASS__, 'option_changed' ), 10, 3 );
        add_action( 'added_option', array( __CLASS__, 'option_changed' ), 10, 2 );
        add_action( 'shutdown', array( __CLASS__, 'flush_changes' ) );
        add_action( 'init', array( __CLASS__, 'ensure_jobs' ), 30 );
        add_action( self::BATCH, array( __CLASS__, 'batch' ), 10, 2 );
        add_action( self::SWEEP, array( __CLASS__, 'reconcile' ) );
        add_action( self::SEND, array( __CLASS__, 'send' ) );
        add_action( 'woocommerce_after_subscription_object_save', array( __CLASS__, 'sync' ), 20 );
        add_action( 'woocommerce_subscription_date_updated', array( __CLASS__, 'sync' ), 20 );
        add_action( 'woocommerce_subscription_date_deleted', array( __CLASS__, 'sync' ), 20 );
        add_action( 'woocommerce_subscription_status_updated', array( __CLASS__, 'sync' ), 20 );
        add_action( 'woocommerce_before_delete_subscription', array( __CLASS__, 'remove' ) );
        add_action( 'woocommerce_before_trash_subscription', array( __CLASS__, 'remove' ) );
        add_action( 'before_delete_post', array( __CLASS__, 'remove_legacy' ) );
        add_action( 'wp_trash_post', array( __CLASS__, 'remove_legacy' ) );
    }

    public static function settings( $settings ) {
        $extra = array(
            array(
                'name' => '', 'id' => self::ENABLED,
                'type' => 'checkbox', 'default' => 'no', 'autoload' => false,
                'desc' => 'Send a second reminder before subscription renewal',
            ),
            array(
                'name' => 'Second reminder timing', 'id' => self::NUMBER,
                'type' => 'number', 'default' => '14', 'autoload' => false,
                'custom_attributes' => array( 'min' => '1', 'max' => '3650', 'step' => '1' ),
                'desc' => 'Set when the second reminder is sent before renewal.',
            ),
            array(
                'name' => 'Second reminder unit', 'id' => self::UNIT,
                'type' => 'select', 'default' => 'days', 'autoload' => false,
                'options' => array( 'days' => 'Days', 'weeks' => 'Weeks', 'months' => 'Months' ),
            ),
        );
        $out = array();
        $inserted = false;
        $target = WC_Subscriptions_Admin::$option_prefix . WC_Subscriptions_Email_Notifications::$offset_setting_string;
        foreach ( $settings as $field ) {
            $out[] = $field;
            if ( ! $inserted && isset( $field['id'] ) && $field['id'] === $target ) {
                $out = array_merge( $out, $extra );
                $inserted = true;
            }
        }
        if ( ! $inserted ) {
            $out[] = array( 'name' => 'Second renewal reminder', 'type' => 'title', 'id' => 'ceg_second_reminder_section' );
            $out = array_merge( $out, $extra );
            $out[] = array( 'type' => 'sectionend', 'id' => 'ceg_second_reminder_section' );
        }
        return $out;
    }

    public static function admin_assets() {
        // Only load on the Subscriptions settings screen; settings retain their existing IDs.
        if ( ! isset( $_GET['page'], $_GET['tab'] ) ||
            'wc-settings' !== $_GET['page'] || 'subscriptions' !== $_GET['tab'] ) {
            return;
        }
        wp_enqueue_style( 'ceg-second-reminder-settings', plugins_url( 'assets/settings.css', __FILE__ ), array(), '1.0.1' );
        wp_enqueue_script( 'ceg-second-reminder-settings', plugins_url( 'assets/settings.js', __FILE__ ), array(), '1.0.1', true );
    }

    public static function sanitize_number( $value ) {
        return max( 1, min( 3650, absint( $value ) ) );
    }

    public static function sanitize_unit( $value ) {
        return in_array( $value, array( 'days', 'weeks', 'months' ), true ) ? $value : 'days';
    }

    private static function option_names() {
        $prefix = WC_Subscriptions_Admin::$option_prefix;
        return array( self::ENABLED, self::NUMBER, self::UNIT,
            $prefix . WC_Subscriptions_Email_Notifications::$offset_setting_string,
            $prefix . WC_Subscriptions_Email_Notifications::$switch_setting_string );
    }

    private static function fingerprint() {
        return md5( wp_json_encode( array_map( 'get_option', self::option_names() ) ) );
    }

    public static function option_changed( $option ) {
        if ( in_array( $option, self::option_names(), true ) ) {
            self::$dirty = true;
        }
    }

    public static function flush_changes() {
        if ( self::$dirty && function_exists( 'as_enqueue_async_action' ) ) {
            self::rebuild();
        }
    }

    private static function enabled() {
        return 'yes' === get_option( self::ENABLED, 'no' ) &&
            WC_Subscriptions_Email_Notifications::notifications_globally_enabled();
    }

    private static function seconds( $number, $unit ) {
        $units = array( 'days' => DAY_IN_SECONDS, 'weeks' => WEEK_IN_SECONDS,
            'months' => MONTH_IN_SECONDS, 'years' => YEAR_IN_SECONDS );
        return isset( $units[ $unit ] ) ? (int) $number * $units[ $unit ] : 0;
    }

    private static function offset() {
        return self::seconds( self::sanitize_number( get_option( self::NUMBER, 14 ) ),
            self::sanitize_unit( get_option( self::UNIT, 'days' ) ) );
    }

    public static function ensure_jobs() {
        if ( ! function_exists( 'as_schedule_single_action' ) ) {
            return;
        }
        if ( get_option( 'ceg_second_reminder_configuration' ) !== self::fingerprint() ) {
            self::rebuild();
        }
        if ( ! as_has_scheduled_action( self::SWEEP, array(), self::GROUP ) ) {
            as_schedule_recurring_action( time() + DAY_IN_SECONDS, DAY_IN_SECONDS, self::SWEEP, array(), self::GROUP, true );
        }
    }

    private static function rebuild() {
        as_unschedule_all_actions( self::SEND, null, self::GROUP );
        as_unschedule_all_actions( self::BATCH, null, self::GROUP );
        update_option( 'ceg_second_reminder_configuration', self::fingerprint(), false );
        self::reconcile();
    }

    public static function reconcile() {
        if ( self::enabled() ) {
            as_enqueue_async_action( self::BATCH, array( 'page' => 1, 'signature' => self::fingerprint() ), self::GROUP, true );
        }
    }

    public static function batch( $page, $signature ) {
        if ( $signature !== self::fingerprint() || ! self::enabled() ) {
            return;
        }
        $subscriptions = wcs_get_subscriptions( array(
            'subscriptions_per_page' => 100, 'offset' => ( max( 1, absint( $page ) ) - 1 ) * 100,
            'orderby' => 'ID', 'order' => 'ASC', 'subscription_status' => 'active',
        ) );
        foreach ( $subscriptions as $subscription ) {
            self::sync( $subscription );
        }
        if ( count( $subscriptions ) === 100 ) {
            as_enqueue_async_action( self::BATCH, array( 'page' => absint( $page ) + 1, 'signature' => $signature ), self::GROUP, true );
        }
    }

    private static function eligible( $subscription ) {
        return self::enabled() && $subscription && $subscription->has_status( 'active' ) &&
            $subscription->get_total() > 0 &&
            ! WCS_Action_Scheduler_Customer_Notifications::is_subscription_period_too_short( $subscription ) &&
            in_array( 'next_payment', WCS_Action_Scheduler_Customer_Notifications::get_valid_notifications( $subscription ), true );
    }

    private static function same_as_primary( $subscription, $offset ) {
        $primary = get_option( WC_Subscriptions_Admin::$option_prefix . WC_Subscriptions_Email_Notifications::$offset_setting_string,
            array( 'number' => 3, 'unit' => 'days' ) );
        $seconds = self::seconds( $primary['number'] ?? 3, $primary['unit'] ?? 'days' );
        $seconds = apply_filters( 'woocommerce_subscription_customer_notification_time_offset', $seconds, $subscription, 'next_payment' );
        return (int) $seconds === $offset;
    }

    public static function sync( $subscription ) {
        if ( ! $subscription || ! $subscription->get_id() || ! function_exists( 'as_schedule_single_action' ) ) {
            return;
        }
        $id = $subscription->get_id();
        if ( ! self::eligible( $subscription ) ) {
            self::remove( $id );
            return;
        }
        $renewal = (int) $subscription->get_time( 'next_payment', 'gmt' );
        $offset = self::offset();
        $due = $renewal - $offset;
        if ( self::same_as_primary( $subscription, $offset ) || $due <= time() ||
            (string) $subscription->get_meta( self::META, true ) === (string) $renewal ) {
            self::remove( $id );
            return;
        }
        $args = array( 'subscription_id' => $id );
        if ( $due === as_next_scheduled_action( self::SEND, $args, self::GROUP ) ) {
            return;
        }
        self::remove( $id );
        as_schedule_single_action( $due, self::SEND, $args, self::GROUP, true );
    }

    public static function remove( $id ) {
        if ( function_exists( 'as_unschedule_all_actions' ) ) {
            // Use identical arguments for scheduling and cancellation (including legacy stores).
            as_unschedule_all_actions( self::SEND, array( 'subscription_id' => absint( $id ) ), self::GROUP );
        }
    }

    public static function remove_legacy( $id ) {
        if ( 'shop_subscription' === get_post_type( $id ) ) {
            self::remove( $id );
        }
    }

    public static function send( $id ) {
        $subscription = wcs_get_subscription( $id );
        if ( ! $subscription ) {
            return;
        }
        $renewal = (int) $subscription->get_time( 'next_payment', 'gmt' );
        $offset = self::offset();
        // Recheck at execution: queued jobs may be stale after a settings or date change.
        if ( ! self::eligible( $subscription ) ||
            ! WC_Subscriptions_Email_Notifications::should_send_notification() ||
            self::same_as_primary( $subscription, $offset ) ||
            (int) $renewal <= time() || (int) $renewal - (int) $offset > time() ||
            (string) $subscription->get_meta( self::META, true ) === (string) $renewal ) {
            return;
        }
        $emails = WC()->mailer()->get_emails();
        $key = $subscription->is_manual() ? 'WCS_Email_Customer_Notification_Manual_Renewal' : 'WCS_Email_Customer_Notification_Auto_Renewal';
        if ( empty( $emails[ $key ] ) || ! $emails[ $key ]->is_enabled() || ! $subscription->get_billing_email() ) {
            return;
        }
        // Save the attempt marker without saving the subscription/dates or causing rescheduling.
        $subscription->update_meta_data( self::META, (string) $renewal );
        $subscription->save_meta_data();
        do_action( 'woocommerce_scheduled_subscription_customer_notification_renewal', $id );
    }

    public static function deactivate() {
        if ( function_exists( 'as_unschedule_all_actions' ) ) {
            foreach ( array( self::SEND, self::BATCH, self::SWEEP ) as $hook ) {
                as_unschedule_all_actions( $hook, null, self::GROUP );
            }
        }
        delete_option( 'ceg_second_reminder_configuration' );
    }
}

CEG_Second_Renewal_Reminder::boot();
register_deactivation_hook( __FILE__, array( 'CEG_Second_Renewal_Reminder', 'deactivate' ) );
