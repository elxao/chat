<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! class_exists('ELXAO_Chat_Inbox') ) {
class ELXAO_Chat_Inbox {
    private static function can_admin_all( $user_id ){ return user_can( $user_id, 'administrator' ); }
    private static function get_user_chats_sorted( $user_id, $limit = 200 ){
        global $wpdb;
        $table = $wpdb->prefix . ELXAO_CHAT_TABLE;
        $access = "";
        if ( ! self::can_admin_all( $user_id ) ){
            $access = $wpdb->prepare(" AND ((pm.meta_value=%d) OR (client.meta_value=%d)) ", $user_id, $user_id);
        }
        $sql = "
        SELECT c.ID as chat_id, COALESCE(MAX(m.created_at), c.post_date) AS last_time
        FROM {$wpdb->posts} c
        LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id=c.ID AND pm.meta_key='pm_id'
        LEFT JOIN {$wpdb->postmeta} client ON client.post_id=c.ID AND client.meta_key='client_id'
        LEFT JOIN {$table} m ON m.chat_id=c.ID
        WHERE c.post_type='elxao_chat' AND c.post_status='publish' {$access}
        GROUP BY c.ID
        ORDER BY last_time DESC
        LIMIT %d";
        $rows = $wpdb->get_results( $wpdb->prepare($sql, $limit), ARRAY_A );
        return array_map(function($r){ return (int)$r['chat_id']; }, $rows );
    }
    private static function color_for_project( $pid ){
        $p = ['#0ea5e9','#10b981','#f59e0b','#ef4444','#8b5cf6','#06b6d4','#f97316','#14b8a6','#64748b','#84cc16'];
        if(!$pid) return $p[0]; $i = absint($pid) % count($p); return $p[$i];
    }
    private static function key_read( $chat_id ){ return '_elxao_chat_read_' . intval( $chat_id ); }
    private static function get_unread_count( $chat_id, $user_id ){
        global $wpdb; $table = $wpdb->prefix . ELXAO_CHAT_TABLE;
        $last_read = (int) get_user_meta( $user_id, self::key_read( $chat_id ), true );
        $sql = "SELECT COUNT(*) FROM {$table} WHERE chat_id=%d AND id>%d AND sender_id!=%d";
        $count = $wpdb->get_var( $wpdb->prepare( $sql, $chat_id, $last_read, $user_id ) );
        return $count ? (int) $count : 0;
    }
    private static function get_last_message( $chat_id ){
        global $wpdb; $table = $wpdb->prefix . ELXAO_CHAT_TABLE;
        $row = $wpdb->get_row( $wpdb->prepare("SELECT id,sender_id,message,created_at FROM {$table} WHERE chat_id=%d ORDER BY id DESC LIMIT 1", $chat_id ), ARRAY_A );
        if ( ! $row ) return null;
        $u = get_userdata( (int)$row['sender_id'] ); $s = $u ? ( $u->display_name ? $u->display_name : $u->user_login ) : ('User '.$row['sender_id']);
        return ['id'=>(int)$row['id'],'sender'=>$s,'message'=> wp_strip_all_tags($row['message']),'created'=> mysql2date('Y-m-d H:i',$row['created_at'],true) ];
    }
    public static function shortcode_chat_inbox( $atts ){
        if ( ! is_user_logged_in() ) return '<div class="elxao-chat-error">Please log in to view your chats.</div>';
        $atts = shortcode_atts(['per_page'=>200,'class'=>''], $atts, 'elxao_chat_inbox' );
        $user_id = get_current_user_id(); $per = max(1,intval($atts['per_page']));
        $ids = self::get_user_chats_sorted($user_id,$per);
        wp_enqueue_style('elxao-chat'); wp_enqueue_script('elxao-chat'); wp_register_script('elxao-chat-inbox', ELXAO_CHAT_URL . 'assets/js/chat-inbox.js',['jquery'],ELXAO_CHAT_VERSION,true); wp_enqueue_script('elxao-chat-inbox'); $nonce = wp_create_nonce('elxao_chat_nonce'); wp_localize_script( 'elxao-chat', 'ELXAO_CHAT', ['ajaxurl'=>admin_url('admin-ajax.php'), 'nonce'=>$nonce, 'fetchFreq'=>1500 ] );
        ob_start(); ?>
        <div class="elxao-inbox <?php echo esc_attr($atts['class']); ?>">
          <div class="inbox-left">
            <div class="inbox-search"><input type="search" class="inbox-search-input" placeholder="Search chats…" /></div>
            <div class="inbox-list">
            <?php foreach( $ids as $i => $cid ): $pid = (int)get_post_meta($cid,'project_id',true); $title = $pid? get_the_title($pid):('Project #'.$pid); $last = self::get_last_message($cid); $active = $i===0 ? ' active' : ''; $color=self::color_for_project($pid); $unread = self::get_unread_count($cid,$user_id); ?>
              <div class="thread<?php echo $active; ?>" data-chat="<?php echo esc_attr($cid); ?>" data-project="<?php echo esc_attr($pid); ?>">
                <div class="avatar" style="background: <?php echo esc_attr($color); ?>;">
                  <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true">
                    <path fill="#fff" d="M4 7a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V7zm2 0h12v3H6V7zm0 5h5v5H6v-5zm7 0h5v5h-5v-5z"/>
                  </svg>
                </div>
                <div class="meta">
                  <div class="row"><div class="title-wrap"><div class="title"><?php echo esc_html($title); ?></div><?php if($unread): ?><span class="badge" aria-label="<?php echo esc_attr($unread); ?> new messages"><?php echo esc_html($unread); ?></span><?php endif; ?></div><div class="time"><?php echo $last? esc_html($last['created']):''; ?></div></div>
                  <div class="preview"><?php echo $last? esc_html( wp_trim_words($last['message'],14) ) : 'No messages yet.'; ?></div>
                </div>
              </div>
            <?php endforeach; ?>
            </div>
          </div>
          <div class="inbox-right">
            <?php if ( ! empty($ids) ): $first = (int)$ids[0]; $first_project = (int)get_post_meta($first,'project_id',true); echo do_shortcode('[elxao_chat_window post_id="'.$first_project.'"]'); else: ?><div class="elxao-chat-empty">No chat selected.</div><?php endif; ?>
          </div>
        </div>
        <?php return ob_get_clean();
    }
}
}
