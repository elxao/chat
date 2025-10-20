<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! class_exists('ELXAO_Chat_PostType') ) {
class ELXAO_Chat_PostType {
    public static function register_cpt(){
        $labels = ['name'=>'ELXAO Chats','singular_name'=>'ELXAO Chat','add_new'=>'Add New','add_new_item'=>'Add New Chat','edit_item'=>'Edit Chat','new_item'=>'New Chat','view_item'=>'View Chat','search_items'=>'Search Chats','not_found'=>'No chats found','not_found_in_trash'=>'No chats found in Trash','menu_name'=>'ELXAO Chats'];
        $args = ['labels'=>$labels,'public'=>false,'show_ui'=>true,'show_in_menu'=>true,'capability_type'=>'post','map_meta_cap'=>true,'hierarchical'=>false,'supports'=>['title'],'menu_position'=>26,'menu_icon'=>'dashicons-format-chat'];
        register_post_type( 'elxao_chat', $args );
    }

    public static function register_admin_hooks(){
        add_action( 'restrict_manage_posts', [ __CLASS__, 'render_delete_all_button' ], 10, 2 );
        add_action( 'admin_init', [ __CLASS__, 'handle_delete_all_messages' ] );
        add_action( 'admin_notices', [ __CLASS__, 'maybe_render_delete_notice' ] );
    }

    public static function render_delete_all_button( $post_type, $which ){
        if ( 'elxao_chat' !== $post_type || 'top' !== $which ) {
            return;
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $url           = wp_nonce_url( add_query_arg( 'elxao_chat_delete_all', '1' ), 'elxao_chat_delete_all' );
        $label         = esc_html__( 'Delete all chat messages', 'elxao-chat' );
        $confirmation  = esc_js( __( 'Are you sure you want to delete all chat messages?', 'elxao-chat' ) );

        printf(
            '<a href="%1$s" class="button button-secondary" onclick="return confirm(\'%2$s\');">%3$s</a>',
            esc_url( $url ),
            $confirmation,
            $label
        );
    }

    public static function handle_delete_all_messages(){
        if ( ! isset( $_GET['elxao_chat_delete_all'] ) || '1' !== $_GET['elxao_chat_delete_all'] ) {
            return;
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        check_admin_referer( 'elxao_chat_delete_all' );

        global $wpdb;
        $table_name = $wpdb->prefix . ELXAO_CHAT_TABLE;
        $wpdb->query( "DELETE FROM {$table_name}" );

        $redirect_url = remove_query_arg( [ 'elxao_chat_delete_all', '_wpnonce' ] );
        $redirect_url = add_query_arg( 'elxao_chat_deleted', '1', $redirect_url );
        wp_safe_redirect( $redirect_url );
        exit;
    }

    public static function maybe_render_delete_notice(){
        if ( ! isset( $_GET['elxao_chat_deleted'] ) || '1' !== $_GET['elxao_chat_deleted'] ) {
            return;
        }
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( ! $screen || 'edit-elxao_chat' !== $screen->id ) {
            return;
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'All chat messages have been deleted.', 'elxao-chat' ) . '</p></div>';
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
