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
      $ids = array_values( array_unique( array_filter( array_map( 'intval', (array) $req->get_param('ids') ) ) ) );
      if ( empty( $ids ) ) {
        return new WP_REST_Response( [ 'ok' => false, 'updated' => 0 ], 200 );
      }

      global $wpdb;
      $messages_table = $wpdb->prefix . ELXAO_CHAT_TABLE;
      $placeholders   = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
      $rows = $wpdb->get_results(
        $wpdb->prepare(
          "SELECT id, chat_id FROM {$messages_table} WHERE id IN ($placeholders)",
          $ids
        ),
        ARRAY_A
      );

      if ( empty( $rows ) ) {
        return new WP_REST_Response( [ 'ok' => true, 'updated' => 0 ], 200 );
      }

      $by_chat = [];
      foreach ( $rows as $row ) {
        $chat_id = (int) $row['chat_id'];
        $id      = (int) $row['id'];
        if ( ! isset( $by_chat[ $chat_id ] ) || $id > $by_chat[ $chat_id ] ) {
          $by_chat[ $chat_id ] = $id;
        }
      }

      $user_id = get_current_user_id();
      $updated = 0;
      foreach ( $by_chat as $chat_id => $max_id ) {
        $project_id = (int) get_post_meta( $chat_id, 'project_id', true );
        if ( ! $project_id || ! elxao_chat_user_can_access( $project_id, $user_id ) ) {
          continue;
        }
        if ( function_exists( 'elxao_chat_update_participant_progress' ) ) {
          elxao_chat_update_participant_progress( $chat_id, $user_id, 'last_read_message_id', $max_id );
          elxao_chat_update_participant_progress( $chat_id, $user_id, 'last_delivered_message_id', $max_id );
        }
        $updated++;
      }

      return new WP_REST_Response( [ 'ok' => true, 'updated' => $updated ], 200 );
    }
  ]);
});
