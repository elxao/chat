<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! class_exists('ELXAO_Chat_PostType') ) {
class ELXAO_Chat_PostType {
    public static function register_cpt(){
        $labels = ['name'=>'ELXAO Chats','singular_name'=>'ELXAO Chat','add_new'=>'Add New','add_new_item'=>'Add New Chat','edit_item'=>'Edit Chat','new_item'=>'New Chat','view_item'=>'View Chat','search_items'=>'Search Chats','not_found'=>'No chats found','not_found_in_trash'=>'No chats found in Trash','menu_name'=>'ELXAO Chats'];
        $args = ['labels'=>$labels,'public'=>false,'show_ui'=>true,'show_in_menu'=>true,'capability_type'=>'post','map_meta_cap'=>true,'hierarchical'=>false,'supports'=>['title'],'menu_position'=>26,'menu_icon'=>'dashicons-format-chat'];
        register_post_type( 'elxao_chat', $args );
    }
    public static function maybe_create_chat_for_project( $post_ID, $post, $update ){
        if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || 'project' !== $post->post_type ) return;
        $pm_id = (int) get_post_meta( $post_ID, 'pm_user', true );
        $client_id = (int) get_post_meta( $post_ID, 'client_user', true );
        if ( $pm_id || $client_id ) {
            if ( ! get_post_meta( $post_ID, '_elxao_chat_id', true ) ) {
                elxao_chat_get_or_create_chat_for_project( $post_ID );
            } else {
                $chat_id = (int) get_post_meta( $post_ID, '_elxao_chat_id', true );
                if ( $chat_id ) {
                    update_post_meta( $chat_id, 'pm_id', $pm_id );
                    update_post_meta( $chat_id, 'client_id', $client_id );
                }
            }
        }
    }
}
}
