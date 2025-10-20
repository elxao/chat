<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! class_exists('ELXAO_Chat_Render') ) {
class ELXAO_Chat_Render {
    public static function register_assets(){
        wp_register_style( 'elxao-chat', ELXAO_CHAT_URL . 'assets/css/chat.css', [], ELXAO_CHAT_VERSION );
        wp_register_script( 'elxao-chat', ELXAO_CHAT_URL . 'assets/js/chat.js', ['jquery'], ELXAO_CHAT_VERSION, true );
    }
    public static function enqueue_assets( $chat_id ){
        wp_enqueue_style( 'elxao-chat' );
        wp_enqueue_script( 'elxao-chat' );
        $nonce = wp_create_nonce( 'elxao_chat_nonce' );
        wp_localize_script( 'elxao-chat', 'ELXAO_CHAT', [
            'ajaxurl'   => admin_url( 'admin-ajax.php' ),
            'nonce'     => $nonce,
            'chatId'    => (int) $chat_id,
            'fetchFreq' => 4000,
        ]);
    }
    public static function shortcode_chat_window( $atts ){
        $atts = shortcode_atts(['project_id'=>0,'post_id'=>0,'class'=>''], $atts, 'elxao_chat_window' );
        $project_id = (int) $atts['project_id']; $post_id = (int) $atts['post_id'];
        if ( ! $project_id && $post_id ) $project_id = $post_id;
        if ( ! $project_id && is_singular('project') ) $project_id = get_the_ID();
        if ( ! $project_id ) { global $post; if ( $post && 'project' === get_post_type( $post ) ) $project_id = (int) $post->ID; }
        if ( ! $project_id ) $project_id = (int) ( $_GET['project_id'] ?? 0 );
        if ( ! $project_id ) return '<div class="elxao-chat-error">No project_id provided.</div>';
        if ( ! is_user_logged_in() ) return '<div class="elxao-chat-error">Please log in to use the project chat.</div>';
        $user_id = get_current_user_id();
        $role = function_exists('elxao_chat_get_user_role_in_chat') ? elxao_chat_get_user_role_in_chat( $project_id, $user_id ) : 'client'; if ( ! elxao_chat_user_can_access( $project_id, $user_id ) ) return '<div class="elxao-chat-error">You do not have access to this chat.</div>';
        $chat_id = elxao_chat_get_or_create_chat_for_project( $project_id ); if ( ! $chat_id ) return '<div class="elxao-chat-error">Unable to initialize chat for this project.</div>';
        self::enqueue_assets( $chat_id );
        ob_start(); ?>
        <div class="elxao-chat-window <?php echo esc_attr( $atts['class'] ); ?>" data-chat="<?php echo esc_attr( $chat_id ); ?>">
            <div class="elxao-chat-messages" id="elxao-chat-messages-<?php echo esc_attr( $chat_id ); ?>" data-last="0"><div class="elxao-chat-loading">Loading messages…</div></div>
            <div class="elxao-chat-input">
                <textarea rows="2" placeholder="" aria-label="Type a message"></textarea>
                <button class="elxao-chat-send send-icon" data-chat="<?php echo esc_attr( $chat_id ); ?>" aria-label="Send message" title="Send">
                    <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true">
                        <path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z" fill="currentColor"></path>
                    </svg>
                </button>
            </div>
        </div>
        <?php return ob_get_clean();
    }
}
}
