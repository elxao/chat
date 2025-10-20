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
      $ids = array_map('intval', (array) $req->get_param('ids'));
      $user_id = get_current_user_id();

      // TODO: remplace par ta logique d’autorisation (le user peut-il lire ces messages ?)
      foreach($ids as $mid){
        // Si tes messages sont des posts :
        // - Vérifie l’appartenance: elxao_user_can_read_message($user_id, $mid)
        // - Ici, on passe delivered|sent -> read, on date read_at
        $current = get_post_meta($mid, 'elxao_status', true);
        if ($current !== 'read'){
          update_post_meta($mid, 'elxao_status', 'read');
          update_post_meta($mid, 'elxao_read_at', time());
        }
      }

      return new WP_REST_Response(['ok'=>true,'updated'=>count($ids)], 200);
    }
  ]);
});
