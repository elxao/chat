<?php
/**
 * ELXAO Chat - REST endpoint to mark messages as READ
 */

if ( ! defined('ABSPATH') ) { exit; }

add_action('rest_api_init', function(){
  register_rest_route('elxao/v1', '/messages/read', [
    'methods'  => 'POST',
    'permission_callback' => function(){ return is_user_logged_in(); },
    'args' => [
      'ids' => [
        'type'     => 'array',
        'required' => true,
        'items'    => [ 'type' => 'integer' ],
      ],
    ],
    'callback' => function(WP_REST_Request $req){
      $ids = array_filter(array_map('intval', (array) $req->get_param('ids')));
      if ( empty( $ids ) ) {
        return new WP_Error( 'elxao_chat_invalid_ids', __( 'No valid message IDs were provided.', 'elxao-chat' ), [ 'status' => 400 ] );
      }

      global $wpdb;
      $table = $wpdb->prefix . ELXAO_CHAT_TABLE;
      $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
      $sql = "SELECT id, chat_id, sender_id FROM {$table} WHERE id IN ({$placeholders})";
      $prepared = call_user_func_array( [ $wpdb, 'prepare' ], array_merge( [ $sql ], $ids ) );
      $rows = $wpdb->get_results( $prepared, ARRAY_A );
      if ( empty( $rows ) ) {
        return new WP_REST_Response( [ 'ok' => true, 'updated' => 0 ], 200 );
      }

      $user_id = get_current_user_id();
      $per_chat_max = [];
      $updated = 0;

      foreach ( $rows as $row ) {
        $chat_id    = isset( $row['chat_id'] ) ? (int) $row['chat_id'] : 0;
        $message_id = isset( $row['id'] ) ? (int) $row['id'] : 0;
        $sender_id  = isset( $row['sender_id'] ) ? (int) $row['sender_id'] : 0;

        if ( ! $chat_id || ! $message_id ) {
          continue;
        }
        if ( $sender_id === $user_id ) {
          continue;
        }

        $project_id = (int) get_post_meta( $chat_id, 'project_id', true );
        if ( ! $project_id ) {
          continue;
        }
        if ( ! elxao_chat_user_can_access( $project_id, $user_id ) ) {
          continue;
        }

        if ( ! isset( $per_chat_max[ $chat_id ] ) || $message_id > $per_chat_max[ $chat_id ] ) {
          $per_chat_max[ $chat_id ] = $message_id;
        }
        $updated++;
      }

      if ( empty( $per_chat_max ) ) {
        return new WP_REST_Response( [ 'ok' => true, 'updated' => 0 ], 200 );
      }

      foreach ( $per_chat_max as $chat_id => $max_id ) {
        ELXAO_Chat_Ajax::mark_read_upto( $chat_id, $user_id, $max_id );
      }

      return new WP_REST_Response( [ 'ok' => true, 'updated' => $updated ], 200 );
    }
  ]);
});
