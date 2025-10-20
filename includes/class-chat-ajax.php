<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! class_exists('ELXAO_Chat_Ajax') ) {
class ELXAO_Chat_Ajax {
    private static function key_read( $chat_id, $user_id ){ return '_elxao_chat_read_' . intval($chat_id); }
    private static function key_delivered( $chat_id, $user_id ){ return '_elxao_chat_delivered_' . intval($chat_id); }

    private static function update_read_marker( $chat_id, $user_id, $last_id ){
        $last_id = intval( $last_id );
        if ( $last_id <= 0 ) {
            return;
        }
        $key     = self::key_read( $chat_id, $user_id );
        $current = (int) get_user_meta( $user_id, $key, true );
        if ( $last_id > $current ) {
            update_user_meta( $user_id, $key, $last_id );
        }
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
        wp_send_json_success( ['message' => 'ok'] );
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
        $pm_id = (int) get_post_meta( $chat_id, 'pm_id', true );
        $client_id = (int) get_post_meta( $chat_id, 'client_id', true );
        $participants = array_filter([$pm_id,$client_id]);
        $others = array_filter($participants, function($uid) use($sender_id){ return $uid && $uid != $sender_id; });
        if ( empty($others) ) return 0;
        $min = PHP_INT_MAX;
        foreach( $others as $uid ){
            $v = (int) get_user_meta( $uid, self::key_read($chat_id,$uid), true );
            if ( $v < $min ) $min = $v;
        }
        return $min === PHP_INT_MAX ? 0 : $min;
    }

    private static function others_delivered_upto( $chat_id, $sender_id ){
        $pm_id = (int) get_post_meta( $chat_id, 'pm_id', true );
        $client_id = (int) get_post_meta( $chat_id, 'client_id', true );
        $participants = array_filter([$pm_id,$client_id]);
        $others = array_filter($participants, function($uid) use($sender_id){ return $uid && $uid != $sender_id; });
        if ( empty($others) ) return 0;
        $min = PHP_INT_MAX;
        foreach( $others as $uid ){
            $v = (int) get_user_meta( $uid, self::key_delivered($chat_id,$uid), true );
            if ( $v < $min ) $min = $v;
        }
        return $min === PHP_INT_MAX ? 0 : $min;
    }

    private static function mark_delivered_for_user( $chat_id, $user_id, $rows ){
        if ( empty( $rows ) ) return;
        $max = 0;
        foreach( $rows as $r ){
            if ( (int) $r['sender_id'] === $user_id ) continue;
            $id = (int) $r['id'];
            if ( $id > $max ) $max = $id;
        }
        if ( ! $max ) return;
        $key     = self::key_delivered( $chat_id, $user_id );
        $current = (int) get_user_meta( $user_id, $key, true );
        if ( $max > $current ) update_user_meta( $user_id, $key, $max );
    }

    public static function fetch_messages(){
        check_ajax_referer( 'elxao_chat_nonce', 'nonce' );
        if ( ! is_user_logged_in() ) wp_send_json_error( ['message' => 'Not logged in'], 401 );
        $chat_id = isset($_POST['chat_id']) ? intval($_POST['chat_id']) : 0;
        $after_id = isset($_POST['after_id']) ? intval($_POST['after_id']) : 0;
        if ( ! $chat_id ) wp_send_json_error( ['message' => 'Missing chat_id'], 400 );
        $project_id = (int) get_post_meta( $chat_id, 'project_id', true ); if ( ! $project_id ) wp_send_json_error( ['message' => 'Invalid chat'], 400 );
        $user_id = get_current_user_id(); if ( ! elxao_chat_user_can_access( $project_id, $user_id ) ) wp_send_json_error( ['message' => 'Forbidden'], 403 );
        global $wpdb; $table = $wpdb->prefix . ELXAO_CHAT_TABLE;
        if ( $after_id > 0 ) {
            $rows = $wpdb->get_results( $wpdb->prepare("SELECT id, sender_id, message, created_at FROM {$table} WHERE chat_id=%d AND id>%d ORDER BY id ASC LIMIT 200", $chat_id, $after_id ), ARRAY_A );
        } else {
            $rows = $wpdb->get_results( $wpdb->prepare("SELECT id, sender_id, message, created_at FROM {$table} WHERE chat_id=%d ORDER BY id ASC LIMIT 200", $chat_id ), ARRAY_A );
        }
        self::mark_delivered_for_user( $chat_id, $user_id, $rows );
        $others_read_upto      = self::others_read_upto( $chat_id, $user_id );
        $others_delivered_upto = self::others_delivered_upto( $chat_id, $user_id );
        $out = [];
        foreach( $rows as $r ){
            $mine = ( $user_id == (int)$r['sender_id'] );
            $id   = (int) $r['id'];
            $read = $mine ? ( $id <= $others_read_upto ) : false;
            $status = '';
            $delivered = false;
            if ( $mine ){
                if ( $id <= $others_read_upto ){
                    $status = 'read';
                    $delivered = true;
                } elseif ( $id <= $others_delivered_upto ){
                    $status = 'delivered';
                    $delivered = true;
                } else {
                    $status = 'sent';
                }
            }
            $out[] = [
                'id'           => $id,
                'sender_id'    => (int) $r['sender_id'],
                'sender'       => self::fmt((int)$r['sender_id']),
                'message'      => wpautop($r['message']),
                'time'         => mysql2date('Y-m-d H:i', $r['created_at'], true ),
                'mine'         => $mine,
                'incoming'     => $mine ? 0 : 1,
                'status'       => $status,
                'delivered'    => $delivered,
                'read'         => $read,
                'delivered_at' => null,
                'read_at'      => null,
            ];
        }
        wp_send_json_success( ['messages'=>$out] );
    }

    private static function fmt($uid){ $u = get_userdata($uid); if(!$u) return 'User '.$uid; $n = $u->display_name ? $u->display_name : $u->user_login; if ( user_can($uid,'administrator') ) return $n.' (Admin)'; return $n; }
}
}
