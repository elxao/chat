<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! class_exists( 'ELXAO_Chat_Realtime' ) ) {
class ELXAO_Chat_Realtime {
    public static function init() {
        add_action( 'init', [ __CLASS__, 'maybe_stream' ], 0 );
    }

    public static function maybe_stream() {
        if ( empty( $_GET['elxao_chat_stream'] ) ) {
            return;
        }

        if ( ! is_user_logged_in() ) {
            self::render_error( 401 );
        }

        $nonce = isset( $_GET['nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['nonce'] ) ) : '';
        if ( ! $nonce || ! wp_verify_nonce( $nonce, 'elxao_chat_nonce' ) ) {
            self::render_error( 403 );
        }

        $chat_id   = isset( $_GET['chat_id'] ) ? absint( $_GET['chat_id'] ) : 0;
        $last_id   = isset( $_GET['last_id'] ) ? intval( $_GET['last_id'] ) : 0;
        $user_id   = get_current_user_id();
        $project_id = $chat_id ? (int) get_post_meta( $chat_id, 'project_id', true ) : 0;

        if ( ! $chat_id || ! $project_id || ! elxao_chat_user_can_access( $project_id, $user_id ) ) {
            self::render_error( 403 );
        }

        self::stream( $chat_id, $user_id, $last_id );
    }

    private static function render_error( $status ) {
        status_header( $status );
        header( 'Content-Type: application/json; charset=utf-8' );
        echo wp_json_encode( [ 'error' => $status ] );
        exit;
    }

    private static function stream( $chat_id, $user_id, $last_id ) {
        if ( ! defined( 'DOING_AJAX' ) ) {
            define( 'DOING_AJAX', true );
        }

        ignore_user_abort( true );
        nocache_headers();

        if ( function_exists( 'header_remove' ) ) {
            header_remove( 'Transfer-Encoding' );
        }

        header( 'Content-Type: text/event-stream; charset=utf-8' );
        header( 'Cache-Control: no-store, no-cache, must-revalidate' );
        header( 'X-Accel-Buffering: no' );

        @ini_set( 'output_buffering', 'off' );
        @ini_set( 'zlib.output_compression', 'Off' );
        @set_time_limit( 0 );

        if ( function_exists( 'session_write_close' ) ) {
            @session_write_close();
        }

        self::emit_comment( 'connected' );

        $timeout = (int) apply_filters( 'elxao_chat_stream_window', 25 );
        $interval = (int) apply_filters( 'elxao_chat_stream_interval', 3 );
        $limit    = (int) apply_filters( 'elxao_chat_stream_limit', 50 );
        $limit    = max( 1, min( 200, $limit ) );

        $start = time();

        while ( ! connection_aborted() && ( time() - $start ) < $timeout ) {
            $payload = ELXAO_Chat_Ajax::get_messages_payload(
                $chat_id,
                $user_id,
                [
                    'after_id' => $last_id,
                    'limit'    => $limit,
                ]
            );

            if ( ! empty( $payload['messages'] ) ) {
                $last_id = isset( $payload['meta']['newest_id'] ) ? (int) $payload['meta']['newest_id'] : $last_id;
                self::emit_event( 'messages', $payload );
            } else {
                self::emit_comment( 'heartbeat' );
            }

            self::flush_output();

            if ( connection_aborted() ) {
                break;
            }

            sleep( max( 1, $interval ) );
        }

        self::emit_comment( 'closing' );
        self::flush_output();
        exit;
    }

    private static function emit_event( $event, $data ) {
        echo 'event: ' . sanitize_key( $event ) . "\n";
        echo 'data: ' . wp_json_encode( $data ) . "\n\n";
    }

    private static function emit_comment( $message ) {
        echo ': ' . sanitize_text_field( $message ) . "\n\n";
    }

    private static function flush_output() {
        if ( ob_get_level() ) {
            @ob_flush();
        }
        flush();
    }
}
}

ELXAO_Chat_Realtime::init();
