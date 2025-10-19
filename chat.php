<?php
/*
Plugin Name: Chat
Description: Private per-project chat (client, PM, admin) with read receipts and WhatsApp-style inbox sorting.
Version: 1.2.3
Author: ELXAO
*/
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! defined('ELXAO_CHAT_VERSION') ) define( 'ELXAO_CHAT_VERSION', '1.2.3' );
if ( ! defined('ELXAO_CHAT_DIR') ) define( 'ELXAO_CHAT_DIR', plugin_dir_path( __FILE__ ) );
if ( ! defined('ELXAO_CHAT_URL') ) define( 'ELXAO_CHAT_URL', plugin_dir_url( __FILE__ ) );
if ( ! defined('ELXAO_CHAT_TABLE') ) define( 'ELXAO_CHAT_TABLE', 'elxao_chat_messages' );

require_once ELXAO_CHAT_DIR . 'includes/class-chat-posttype.php';
require_once ELXAO_CHAT_DIR . 'includes/class-chat-render.php';
require_once ELXAO_CHAT_DIR . 'includes/class-chat-ajax.php';
require_once ELXAO_CHAT_DIR . 'includes/class-chat-list.php';
require_once ELXAO_CHAT_DIR . 'includes/class-chat-inbox.php';

if ( ! function_exists('elxao_chat_activate') ) {
function elxao_chat_activate() {
    global $wpdb;
    $table_name = $wpdb->prefix . ELXAO_CHAT_TABLE;
    $charset_collate = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        chat_id BIGINT UNSIGNED NOT NULL,
        sender_id BIGINT UNSIGNED NOT NULL,
        message LONGTEXT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY chat_id_idx (chat_id),
        KEY sender_id_idx (sender_id)
    ) {$charset_collate};";
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
    if ( class_exists('ELXAO_Chat_PostType') ) { ELXAO_Chat_PostType::register_cpt(); }
    flush_rewrite_rules();
}}
register_activation_hook( __FILE__, 'elxao_chat_activate' );
add_action( 'init', function(){ if ( class_exists('ELXAO_Chat_PostType') ) ELXAO_Chat_PostType::register_cpt(); } );
add_action( 'save_post_project', function($id,$post,$update){ if ( class_exists('ELXAO_Chat_PostType') ) ELXAO_Chat_PostType::maybe_create_chat_for_project($id,$post,$update); }, 20, 3 );
add_action( 'wp_enqueue_scripts', function(){ if ( class_exists('ELXAO_Chat_Render') ) ELXAO_Chat_Render::register_assets(); } );

add_shortcode( 'elxao_chat_window', function($atts){ return class_exists('ELXAO_Chat_Render') ? ELXAO_Chat_Render::shortcode_chat_window($atts) : ''; } );
add_shortcode( 'elxao_chat_list', function($atts){ return class_exists('ELXAO_Chat_List') ? ELXAO_Chat_List::shortcode_chat_list($atts) : ''; } );
add_shortcode( 'elxao_chat_inbox', function($atts){ return class_exists('ELXAO_Chat_Inbox') ? ELXAO_Chat_Inbox::shortcode_chat_inbox($atts) : ''; } );

add_action( 'wp_ajax_elxao_send_message', function(){ if ( class_exists('ELXAO_Chat_Ajax') ) ELXAO_Chat_Ajax::send_message(); } );
add_action( 'wp_ajax_elxao_fetch_messages', function(){ if ( class_exists('ELXAO_Chat_Ajax') ) ELXAO_Chat_Ajax::fetch_messages(); } );
add_action( 'wp_ajax_elxao_mark_read', function(){ if ( class_exists('ELXAO_Chat_Ajax') ) ELXAO_Chat_Ajax::mark_read(); } );

if ( ! function_exists('elxao_chat_user_can_access') ) {
function elxao_chat_user_can_access( $project_id, $user_id = 0 ){
    if ( ! $project_id ) return false;
    if ( ! $user_id ) $user_id = get_current_user_id();
    if ( ! $user_id ) return false;
    if ( user_can( $user_id, 'administrator' ) ) return true;
    $pm_id = (int) get_post_meta( $project_id, 'pm_user', true );
    $client_id = (int) get_post_meta( $project_id, 'client_user', true );
    return ( $user_id === $pm_id || $user_id === $client_id );
}}

if ( ! function_exists('elxao_chat_get_or_create_chat_for_project') ) {
function elxao_chat_get_or_create_chat_for_project( $project_id ){
    $chat_id = (int) get_post_meta( $project_id, '_elxao_chat_id', true );
    if ( $chat_id && get_post( $chat_id ) ) return $chat_id;
    $pm_id = (int) get_post_meta( $project_id, 'pm_user', true );
    $client_id = (int) get_post_meta( $project_id, 'client_user', true );
    $chat_post = [
        'post_type'   => 'elxao_chat',
        'post_status' => 'publish',
        'post_title'  => sprintf( 'Chat for Project %d', $project_id ),
    ];
    $chat_id = wp_insert_post( $chat_post );
    if ( $chat_id && ! is_wp_error( $chat_id ) ) {
        update_post_meta( $chat_id, 'project_id', $project_id );
        update_post_meta( $chat_id, 'pm_id', $pm_id );
        update_post_meta( $chat_id, 'client_id', $client_id );
        update_post_meta( $project_id, '_elxao_chat_id', $chat_id );
    }
    return $chat_id;
}}

/* =============================================================================
 * Send button icon (inline SVG) — server + client enforcement
 * - SVG color = currentColor (button already has color:#fff → blanc)
 * - Taille contrôlée partout à 36px (modifie ICON_SIZE pour 38/40)
 * ========================================================================== */
if ( ! defined('ELXAO_CHAT_ICON_SIZE') ) define('ELXAO_CHAT_ICON_SIZE', 36);

/** 1) PHP : injection inline dans la sortie des shortcodes (si rendu côté serveur) */
if ( ! function_exists('elxao_chat_inline_send_svg') ) {
function elxao_chat_inline_send_svg( $size = ELXAO_CHAT_ICON_SIZE ){
    $s = max(12, (int) $size);
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" aria-hidden="true" focusable="false" style="width:'.$s.'px;height:'.$s.'px;display:block">'
         . '<path fill="currentColor" d="M15.854.146a.5.5 0 0 1 .11.54l-5.819 14.547a.75.75 0 0 1-1.329.124l-3.178-4.995L.643 7.184a.75.75 0 0 1 .124-1.33L15.314.037a.5.5 0 0 1 .54.11ZM6.636 10.07l2.761 4.338L14.13 2.576L6.636 10.07Zm6.787-8.201L1.591 6.602l4.339 2.76l7.494-7.493Z"/>'
         . '</svg>';
}}
if ( ! function_exists('elxao_chat_replace_send_button_inner') ) {
function elxao_chat_replace_send_button_inner( $html, $size = ELXAO_CHAT_ICON_SIZE ){
    $svg = elxao_chat_inline_send_svg( $size );
    $pattern = '/(<button[^>]*class="[^"]*send-icon[^"]*"[^>]*>)(.*?)(<\/button>)/is';
    return preg_replace( $pattern, '$1' . $svg . '$3', $html, 1 );
}}
add_filter('do_shortcode_tag', function( $output, $tag, $attr ){
    if ( in_array( $tag, array( 'elxao_chat_window', 'elxao_chat_inbox' ), true ) ) {
        $output = elxao_chat_replace_send_button_inner( $output, ELXAO_CHAT_ICON_SIZE );
    }
    return $output;
}, 10, 3);

/** 2) JS : enforcement côté client (si le bouton est injecté après coup) */
add_action('wp_footer', function(){
    $size = (int) ELXAO_CHAT_ICON_SIZE;
    ?>
    <script>
    (function(){
      const ICON_SIZE = <?php echo (int) $size; ?>;
      const SVG_HTML =
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" aria-hidden="true" focusable="false"'
        + ' style="width:'+ICON_SIZE+'px;height:'+ICON_SIZE+'px;display:block">'
        + '<path fill="currentColor" d="M15.854.146a.5.5 0 0 1 .11.54l-5.819 14.547a.75.75 0 0 1-1.329.124l-3.178-4.995L.643 7.184a.75.75 0 0 1 .124-1.33L15.314.037a.5.5 0 0 1 .54.11ZM6.636 10.07l2.761 4.338L14.13 2.576L6.636 10.07Zm6.787-8.201L1.591 6.602l4.339 2.76l7.494-7.493Z"></path>'
        + '</svg>';

      function patch(el){
        if(!el) return;
        // Efface contenu actuel et injecte notre SVG inline (blanc, taille forcée)
        el.innerHTML = SVG_HTML;
        // S'assure que la couleur est blanche via currentColor
        el.style.color = '#fff';
        // Supprime tout éventuel background-image posé via CSS antérieur
        el.style.backgroundImage = 'none';
      }

      // Patch initial (DOM déjà présent)
      document.querySelectorAll('.send-icon').forEach(patch);

      // Observe toute nouvelle apparition / mise à jour (AJAX, frameworks)
      const obs = new MutationObserver((muts)=>{
        muts.forEach(m=>{
          m.addedNodes && m.addedNodes.forEach(node=>{
            if(!(node instanceof Element)) return;
            if(node.matches && node.matches('.send-icon')) patch(node);
            node.querySelectorAll && node.querySelectorAll('.send-icon').forEach(patch);
          });
        });
      });
      obs.observe(document.documentElement, {childList:true, subtree:true});
    })();
    </script>
    <?php
});


