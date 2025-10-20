<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! class_exists('ELXAO_Chat_List') ) {
class ELXAO_Chat_List {
    private static function can_admin_all( $user_id ){ return user_can( $user_id, 'administrator' ); }
    private static function get_user_chats( $user_id, $limit = 200 ){
        $args = ['post_type'=>'elxao_chat','posts_per_page'=>$limit,'fields'=>'ids'];
        if ( ! self::can_admin_all( $user_id ) ){
            $args['meta_query'] = ['relation'=>'OR',['key'=>'pm_id','value'=>$user_id,'compare'=>'='],['key'=>'client_id','value'=>$user_id,'compare'=>'=']];
        }
        $q = new WP_Query($args); $chat_ids = $q->posts;
        if ( empty( $chat_ids ) ) return $chat_ids;

        global $wpdb; $table = $wpdb->prefix . ELXAO_CHAT_TABLE;
        $params = array_map('intval', $chat_ids);
        $last_activity = [];
        if ( ! empty( $params ) ) {
            $placeholders = implode(',', array_fill(0, count($params), '%d'));
            $query = "SELECT chat_id, MAX(created_at) AS last_created FROM {$table} WHERE chat_id IN ({$placeholders}) GROUP BY chat_id";
            $prepared = call_user_func_array( [$wpdb, 'prepare'], array_merge( [$query], $params ) );
            $rows = $wpdb->get_results( $prepared, ARRAY_A );
            foreach ( $rows as $row ) {
                $cid = (int) $row['chat_id'];
                $last_activity[$cid] = $row['last_created'] ? mysql2date('U', $row['last_created'], true) : 0;
            }
        }

        $fallback_dates = [];
        foreach ( $chat_ids as $cid ) {
            if ( isset( $last_activity[$cid] ) ) continue;
            $modified = get_post_field( 'post_modified_gmt', $cid );
            $created = $modified ? $modified : get_post_field( 'post_date_gmt', $cid );
            $fallback_dates[$cid] = $created ? mysql2date('U', $created, true) : 0;
        }

        $original_order = array_flip( $chat_ids );
        usort( $chat_ids, function( $a, $b ) use ( $last_activity, $fallback_dates, $original_order ){
            $time_a = isset( $last_activity[$a] ) ? $last_activity[$a] : ( isset( $fallback_dates[$a] ) ? $fallback_dates[$a] : 0 );
            $time_b = isset( $last_activity[$b] ) ? $last_activity[$b] : ( isset( $fallback_dates[$b] ) ? $fallback_dates[$b] : 0 );
            if ( $time_a === $time_b ) {
                return $original_order[$a] <=> $original_order[$b];
            }
            return ( $time_a > $time_b ) ? -1 : 1;
        } );

        return $chat_ids;
    }
    private static function get_last_message( $chat_id ){
        global $wpdb; $table = $wpdb->prefix . ELXAO_CHAT_TABLE;
        $row = $wpdb->get_row( $wpdb->prepare("SELECT id,sender_id,message,created_at FROM {$table} WHERE chat_id=%d ORDER BY id DESC LIMIT 1", $chat_id ), ARRAY_A );
        if ( ! $row ) return null;
        $u = get_userdata( (int)$row['sender_id'] ); $s = $u ? ( $u->display_name ? $u->display_name : $u->user_login ) : ('User '.$row['sender_id']);
        return ['id'=>(int)$row['id'],'sender'=>$s,'message'=> wp_strip_all_tags($row['message']),'created'=> mysql2date('Y-m-d H:i',$row['created_at'],true) ];
    }
    public static function shortcode_chat_list( $atts ){
        if ( ! is_user_logged_in() ) return '<div class="elxao-chat-error">Please log in to view your chats.</div>';
        $atts = shortcode_atts(['per_page'=>100,'inline'=>'1','class'=>''], $atts, 'elxao_chat_list' );
        $user_id = get_current_user_id(); $per = max(1,intval($atts['per_page'])); $chat_ids = self::get_user_chats($user_id,$per);
        wp_enqueue_style('elxao-chat'); wp_enqueue_script('elxao-chat'); wp_register_script('elxao-chat-list', ELXAO_CHAT_URL . 'assets/js/chat-list.js',['jquery'],ELXAO_CHAT_VERSION,true); wp_enqueue_script('elxao-chat-list');
        ob_start(); ?>
        <div class="elxao-chat-list <?php echo esc_attr( $atts['class'] ); ?>">
            <div class="elxao-chat-list-header"><h3>Your Chats</h3><input type="search" class="elxao-chat-search" placeholder="Search by project or message…" /></div>
            <?php if ( empty($chat_ids) ): ?><div class="elxao-chat-empty">No chats found.</div><?php else: ?><div class="elxao-chat-cards">
            <?php foreach( $chat_ids as $chat_id ): $pid = (int)get_post_meta($chat_id,'project_id',true); $pt = $pid? get_the_title($pid):('Project #'.$pid); $last = self::get_last_message($chat_id); ?>
                <div class="elxao-chat-card" data-chat="<?php echo esc_attr($chat_id); ?>">
                    <div class="elxao-chat-card-head"><div class="title"><?php echo esc_html($pt); ?></div><div class="meta">Chat #<?php echo esc_html($chat_id); ?><?php if($last): ?> • Last: <?php echo esc_html($last['created']); ?><?php endif; ?></div></div>
                    <div class="elxao-chat-card-body"><?php if($last): ?><div class="preview"><strong><?php echo esc_html($last['sender']); ?>:</strong> <?php echo esc_html( wp_trim_words($last['message'],20) ); ?></div><?php else: ?><div class="preview empty">No messages yet.</div><?php endif; ?></div>
                    <div class="elxao-chat-card-actions"><?php if($pid): ?><a class="btn btn-link" href="<?php echo esc_url( add_query_arg('open_project',$pid, home_url('/dashboard/')) ); ?>">Open Project</a><?php endif; ?><?php if($atts['inline']==='1' && $pid): ?><button class="btn elxao-toggle-chat" data-project="<?php echo esc_attr($pid); ?>">Open Chat</button><?php endif; ?></div>
                    <?php if($atts['inline']==='1' && $pid): ?><div class="elxao-chat-inline" style="display:none;"><?php echo do_shortcode('[elxao_chat_window post_id="'.$pid.'"]'); ?></div><?php endif; ?>
                </div>
            <?php endforeach; ?></div><?php endif; ?>
        </div>
        <?php return ob_get_clean();
    }
}
}
