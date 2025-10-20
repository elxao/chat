<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! class_exists('ELXAO_Chat_Ajax') ) {
class ELXAO_Chat_Ajax {
    private const CURSOR_READ      = 'read';
    private const CURSOR_DELIVERED = 'delivered';

    private static function cursor_table(){
        global $wpdb;
        return $wpdb->prefix . ELXAO_CHAT_CURSOR_TABLE;
    }

    private static function update_cursor( $chat_id, $user_id, $type, $message_id ){
        global $wpdb;

        $message_id = (int) $message_id;
        if ( $message_id <= 0 ) {
            return;
        }

        $table = self::cursor_table();
        $now   = current_time( 'mysql', true );

        $sql = "INSERT INTO {$table} (chat_id, user_id, cursor_type, message_id, updated_at)
            VALUES (%d, %d, %s, %d, %s)
            ON DUPLICATE KEY UPDATE
                message_id = GREATEST(message_id, VALUES(message_id)),
                updated_at = IF(VALUES(message_id) > message_id, VALUES(updated_at), updated_at)";

        $wpdb->query( $wpdb->prepare( $sql, $chat_id, $user_id, $type, $message_id, $now ) );
    }

    private static function fetch_cursor_map( $chat_id, array $user_ids, $type ){
        global $wpdb;

        $user_ids = array_values( array_filter( array_map( 'intval', $user_ids ) ) );
        if ( empty( $user_ids ) ) {
            return [];
        }

        $table = self::cursor_table();
        $placeholders = implode( ',', array_fill( 0, count( $user_ids ), '%d' ) );
        $params = array_merge( [ $chat_id, $type ], $user_ids );
        $sql = "SELECT user_id, message_id FROM {$table} WHERE chat_id=%d AND cursor_type=%s AND user_id IN ({$placeholders})";
        $prepared = call_user_func_array( [ $wpdb, 'prepare' ], array_merge( [ $sql ], $params ) );
        $rows = $wpdb->get_results( $prepared, ARRAY_A );

        $map = [];
        foreach ( (array) $rows as $row ) {
            $uid = isset( $row['user_id'] ) ? (int) $row['user_id'] : 0;
            $mid = isset( $row['message_id'] ) ? (int) $row['message_id'] : 0;
            if ( $uid ) {
                $map[ $uid ] = $mid;
            }
        }

        return $map;
    }

    private static function get_participants( $chat_id ) {
        $pm_id     = (int) get_post_meta( $chat_id, 'pm_id', true );
        $client_id = (int) get_post_meta( $chat_id, 'client_id', true );
        $participants = array_unique( array_filter( [ $pm_id, $client_id ] ) );
        return $participants;
    }

    private static function others_cursor_upto( $chat_id, $sender_id, $type ) {
        $participants = self::get_participants( $chat_id );
        $others = array_filter( $participants, function( $uid ) use ( $sender_id ) {
            return $uid && (int) $uid !== (int) $sender_id;
        } );

        if ( empty( $others ) ) {
            return 0;
        }

        $map = self::fetch_cursor_map( $chat_id, $others, $type );

        if ( empty( $map ) ) {
            return 0;
        }

        $min = PHP_INT_MAX;
        foreach ( $others as $uid ) {
            $value = isset( $map[ $uid ] ) ? (int) $map[ $uid ] : 0;
            if ( $value <= 0 ) {
                return 0;
            }
            if ( $value < $min ) {
                $min = $value;
            }
        }

        return $min === PHP_INT_MAX ? 0 : $min;
    }

    private static function update_read_marker( $chat_id, $user_id, $last_id ){
        self::update_cursor( $chat_id, $user_id, self::CURSOR_READ, $last_id );
    }

    public static function mark_read_upto( $chat_id, $user_id, $last_id ){
        self::update_read_marker( $chat_id, $user_id, $last_id );
    }

    public static function send_message(){
        check_ajax_referer( 'elxao_chat_nonce', 'nonce' );
        if ( ! is_user_logged_in() ) wp_send_json_error( ['message' => 'Not logged in'], 401 );
        $chat_id = isset($_POST['chat_id']) ? intval($_POST['chat_id']) : 0;
        $text    = isset($_POST['text']) ? wp_unslash( $_POST['text'] ) : '';
        if ( ! $chat_id || '' === trim( $text ) ) wp_send_json_error( ['message' => 'Missing data'], 400 );
        $project_id = (int) get_post_meta( $chat_id, 'project_id', true );
        if ( ! $project_id ) wp_send_json_error( ['message' => 'Invalid chat'], 400 );
        $user_id = get_current_user_id(); if ( ! elxao_chat_user_can_access( $project_id, $user_id ) ) wp_send_json_error( ['message' => 'Forbidden'], 403 );
        global $wpdb; $table = $wpdb->prefix . ELXAO_CHAT_TABLE;
        $ins = $wpdb->insert( $table, ['chat_id'=>$chat_id,'sender_id'=>$user_id,'message'=>wp_kses_post($text),'created_at'=>current_time('mysql')], ['%d','%d','%s','%s'] );
        if ( ! $ins ) wp_send_json_error( ['message' => 'DB insert failed'], 500 );
        wp_send_json_success( [
            'message'    => 'ok',
            'message_id' => (int) $wpdb->insert_id,
        ] );
    }

    public static function mark_read(){
        check_ajax_referer( 'elxao_chat_nonce', 'nonce' );
        if ( ! is_user_logged_in() ) wp_send_json_error( ['message' => 'Not logged in'], 401 );
        $chat_id = isset($_POST['chat_id']) ? intval($_POST['chat_id']) : 0;
        $last_id = isset($_POST['last_id']) ? intval($_POST['last_id']) : 0;
        if ( ! $chat_id || ! $last_id ) wp_send_json_error( ['message' => 'Missing data'], 400 );
        $project_id = (int) get_post_meta( $chat_id, 'project_id', true );
        if ( ! $project_id ) wp_send_json_error( ['message' => 'Invalid chat'], 400 );
        $user_id = get_current_user_id(); if ( ! elxao_chat_user_can_access( $project_id, $user_id ) ) wp_send_json_error( ['message' => 'Forbidden'], 403 );
        self::update_read_marker( $chat_id, $user_id, $last_id );
        wp_send_json_success( ['ok'=>true] );
    }

    private static function others_read_upto( $chat_id, $sender_id ){
        return self::others_cursor_upto( $chat_id, $sender_id, self::CURSOR_READ );
    }

    private static function others_delivered_upto( $chat_id, $sender_id ){
        return self::others_cursor_upto( $chat_id, $sender_id, self::CURSOR_DELIVERED );
    }

    private static function mark_delivered_for_user( $chat_id, $user_id, $rows ){
        if ( empty( $rows ) ) {
            return;
        }

        $max = 0;
        foreach ( $rows as $r ) {
            if ( (int) $r['sender_id'] === (int) $user_id ) {
                continue;
            }
            $id = isset( $r['id'] ) ? (int) $r['id'] : 0;
            if ( $id > $max ) {
                $max = $id;
            }
        }

        if ( $max ) {
            self::update_cursor( $chat_id, $user_id, self::CURSOR_DELIVERED, $max );
        }
    }

    public static function get_messages_payload( $chat_id, $user_id, $args = [] ) {
        global $wpdb;

        $limit = isset( $args['limit'] ) ? (int) $args['limit'] : 50;
        $limit = max( 1, min( 200, $limit ) );
        $after_id  = isset( $args['after_id'] ) ? max( 0, (int) $args['after_id'] ) : 0;
        $before_id = isset( $args['before_id'] ) ? max( 0, (int) $args['before_id'] ) : 0;

        $table = $wpdb->prefix . ELXAO_CHAT_TABLE;
        $query_limit = $limit + 1;
        $has_more_before = false;

        if ( $after_id > 0 ) {
            $sql  = $wpdb->prepare( "SELECT id, sender_id, message, created_at FROM {$table} WHERE chat_id=%d AND id>%d ORDER BY id ASC LIMIT %d", $chat_id, $after_id, $limit );
            $rows = $wpdb->get_results( $sql, ARRAY_A );
        } else {
            if ( $before_id > 0 ) {
                $sql = $wpdb->prepare( "SELECT id, sender_id, message, created_at FROM {$table} WHERE chat_id=%d AND id<%d ORDER BY id DESC LIMIT %d", $chat_id, $before_id, $query_limit );
            } else {
                $sql = $wpdb->prepare( "SELECT id, sender_id, message, created_at FROM {$table} WHERE chat_id=%d ORDER BY id DESC LIMIT %d", $chat_id, $query_limit );
            }

            $rows = $wpdb->get_results( $sql, ARRAY_A );
            if ( count( $rows ) > $limit ) {
                $has_more_before = true;
                array_pop( $rows );
            }
            $rows = array_reverse( $rows );
        }

        self::mark_delivered_for_user( $chat_id, $user_id, $rows );
        $others_read_upto      = self::others_read_upto( $chat_id, $user_id );
        $others_delivered_upto = self::others_delivered_upto( $chat_id, $user_id );

        $out = [];
        foreach ( (array) $rows as $r ) {
            $mine = ( $user_id === (int) $r['sender_id'] );
            $id   = (int) $r['id'];
            $read = $mine ? ( $id <= $others_read_upto ) : false;
            $status = '';
            $delivered = false;
            if ( $mine ) {
                if ( $id <= $others_read_upto ) {
                    $status = 'read';
                    $delivered = true;
                } elseif ( $id <= $others_delivered_upto ) {
                    $status = 'delivered';
                    $delivered = true;
                } else {
                    $status = 'sent';
                }
            }
            $out[] = [
                'id'           => $id,
                'sender_id'    => (int) $r['sender_id'],
                'sender'       => self::fmt( (int) $r['sender_id'] ),
                'message'      => wpautop( $r['message'] ),
                'time'         => mysql2date( 'Y-m-d H:i', $r['created_at'], true ),
                'mine'         => $mine,
                'incoming'     => $mine ? 0 : 1,
                'status'       => $status,
                'delivered'    => $delivered,
                'read'         => $read,
                'delivered_at' => null,
                'read_at'      => null,
            ];
        }

        $first_id = ! empty( $out ) ? (int) $out[0]['id'] : null;
        $last_id  = ! empty( $out ) ? (int) $out[ count( $out ) - 1 ]['id'] : null;

        return [
            'messages' => $out,
            'meta'     => [
                'has_more_before' => (bool) $has_more_before,
                'oldest_id'       => $first_id,
                'newest_id'       => $last_id,
                'limit'           => $limit,
            ],
        ];
    }

    public static function fetch_messages(){
        check_ajax_referer( 'elxao_chat_nonce', 'nonce' );
        if ( ! is_user_logged_in() ) wp_send_json_error( ['message' => 'Not logged in'], 401 );
        $chat_id   = isset($_POST['chat_id']) ? intval($_POST['chat_id']) : 0;
        $after_id  = isset($_POST['after_id']) ? intval($_POST['after_id']) : 0;
        $before_id = isset($_POST['before_id']) ? intval($_POST['before_id']) : 0;
        $limit     = isset($_POST['limit']) ? intval($_POST['limit']) : 0;
        if ( ! $chat_id ) wp_send_json_error( ['message' => 'Missing chat_id'], 400 );
        $project_id = (int) get_post_meta( $chat_id, 'project_id', true ); if ( ! $project_id ) wp_send_json_error( ['message' => 'Invalid chat'], 400 );
        $user_id = get_current_user_id(); if ( ! elxao_chat_user_can_access( $project_id, $user_id ) ) wp_send_json_error( ['message' => 'Forbidden'], 403 );

        $payload = self::get_messages_payload( $chat_id, $user_id, [
            'after_id'  => $after_id,
            'before_id' => $before_id,
            'limit'     => $limit,
        ] );

        wp_send_json_success( $payload );
    }

    private static function fmt($uid){ $u = get_userdata($uid); if(!$u) return 'User '.$uid; $n = $u->display_name ? $u->display_name : $u->user_login; if ( user_can($uid,'administrator') ) return $n.' (Admin)'; return $n; }
}
}
