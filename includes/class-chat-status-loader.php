<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! class_exists('ELXAO_Chat_Status_Loader') ) {
class ELXAO_Chat_Status_Loader {
    public static function boot(){
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue']);
    }
    public static function enqueue(){
        // Styles
        wp_register_style( 'elxao-chat-status', ELXAO_CHAT_URL . 'assets/css/status.css', [], ELXAO_CHAT_VERSION );
        wp_enqueue_style( 'elxao-chat-status' );
        // Scripts
        wp_register_script( 'elxao-chat-status', ELXAO_CHAT_URL . 'assets/js/status.js', [], ELXAO_CHAT_VERSION, true );
        wp_enqueue_script( 'elxao-chat-status' );
        wp_register_script( 'elxao-chat-inbox', ELXAO_CHAT_URL . 'assets/js/chat-inbox.js', ['jquery'], ELXAO_CHAT_VERSION, true );
        wp_enqueue_script( 'elxao-chat-inbox' );
        // Vars for REST
        wp_localize_script( 'elxao-chat-status', 'ELXAO_STATUS', [
            'restUrl' => esc_url_raw( rest_url( 'elxao/v1/messages/read' ) ),
            'nonce'   => wp_create_nonce( 'wp_rest' ),
        ]);
        // Icon + color tokens
        $tick = ELXAO_CHAT_URL . 'assets/icons/tick.svg';
        $double = ELXAO_CHAT_URL . 'assets/icons/tick-double.svg';
        $css = ':root{--elxao-tick-url:url("'.esc_url( $tick ).'");--elxao-tick-double-url:url("'.esc_url( $double ).'");--elxao-status-grey:#8391a1;--elxao-status-blue:#34B7F1;}';
        wp_add_inline_style( 'elxao-chat-status', $css );
    }
}
ELXAO_Chat_Status_Loader::boot();
}
