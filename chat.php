<?php
/*
Plugin Name: Chat
Description: Private per-project chat (client, PM, admin) with read receipts and WhatsApp-style inbox sorting.
Version: 1.4.3
Author: ELXAO
*/

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! defined('ELXAO_CHAT_VERSION') ) define( 'ELXAO_CHAT_VERSION', '1.4.2' );
if ( ! defined('ELXAO_CHAT_DIR') ) define( 'ELXAO_CHAT_DIR', plugin_dir_path( __FILE__ ) );
if ( ! defined('ELXAO_CHAT_URL') ) define( 'ELXAO_CHAT_URL', plugin_dir_url( __FILE__ ) );
if ( ! defined('ELXAO_CHAT_TABLE') ) define( 'ELXAO_CHAT_TABLE', 'elxao_chat_messages' );
if ( ! defined('ELXAO_CHAT_PARTICIPANTS_TABLE') ) define( 'ELXAO_CHAT_PARTICIPANTS_TABLE', 'elxao_chat_participants' );
if ( ! defined('ELXAO_CHAT_RECEIPTS_TABLE') ) define( 'ELXAO_CHAT_RECEIPTS_TABLE', 'elxao_chat_receipts' );

require_once ELXAO_CHAT_DIR . 'includes/class-chat-posttype.php';
require_once ELXAO_CHAT_DIR . 'includes/class-chat-render.php';
require_once ELXAO_CHAT_DIR . 'includes/class-chat-ajax.php';
require_once ELXAO_CHAT_DIR . 'includes/class-chat-list.php';
require_once ELXAO_CHAT_DIR . 'includes/class-chat-inbox.php';

/* ===== Activation ===== */
if ( ! function_exists('elxao_chat_activate') ) {
function elxao_chat_activate() {
    global $wpdb;
    $charset_collate    = $wpdb->get_charset_collate();
    $messages_table     = $wpdb->prefix . ELXAO_CHAT_TABLE;
    $participants_table = $wpdb->prefix . ELXAO_CHAT_PARTICIPANTS_TABLE;
    $receipts_table     = $wpdb->prefix . ELXAO_CHAT_RECEIPTS_TABLE;

    $sql_messages = "CREATE TABLE IF NOT EXISTS {$messages_table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        chat_id BIGINT UNSIGNED NOT NULL,
        sender_id BIGINT UNSIGNED NOT NULL,
        message LONGTEXT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY chat_id_idx (chat_id),
        KEY sender_id_idx (sender_id)
    ) {$charset_collate};";

    $sql_participants = "CREATE TABLE IF NOT EXISTS {$participants_table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        chat_id BIGINT UNSIGNED NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        role ENUM('client','pm','admin') NOT NULL,
        last_delivered_message_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        last_read_message_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        last_active_at DATETIME NULL DEFAULT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY chat_user (chat_id, user_id),
        KEY chat_idx (chat_id),
        KEY user_idx (user_id),
        KEY role_idx (role)
    ) {$charset_collate};";

    $sql_receipts = "CREATE TABLE IF NOT EXISTS {$receipts_table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        message_id BIGINT UNSIGNED NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        status ENUM('sent','delivered','read') NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        UNIQUE KEY message_user_status (message_id, user_id, status),
        KEY message_idx (message_id),
        KEY user_idx (user_id)
    ) {$charset_collate};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql_messages );
    dbDelta( $sql_participants );
    dbDelta( $sql_receipts );
    if ( class_exists('ELXAO_Chat_PostType') ) { ELXAO_Chat_PostType::register_cpt(); }
    flush_rewrite_rules();
}}
register_activation_hook( __FILE__, 'elxao_chat_activate' );

/* ===== Init / assets ===== */
add_action( 'init', function(){
    if ( class_exists('ELXAO_Chat_PostType') )
        ELXAO_Chat_PostType::register_cpt();
});
add_action( 'save_post_project', function($id,$post,$update){
    if ( class_exists('ELXAO_Chat_PostType') )
        ELXAO_Chat_PostType::maybe_create_chat_for_project($id,$post,$update);
}, 20, 3 );
add_action( 'wp_enqueue_scripts', function(){
    if ( class_exists('ELXAO_Chat_Render') )
        ELXAO_Chat_Render::register_assets();
});

/* ===== Shortcodes ===== */
add_shortcode( 'elxao_chat_window', function($atts){
    return class_exists('ELXAO_Chat_Render') ? ELXAO_Chat_Render::shortcode_chat_window($atts) : '';
});
add_shortcode( 'elxao_chat_list', function($atts){
    return class_exists('ELXAO_Chat_List') ? ELXAO_Chat_List::shortcode_chat_list($atts) : '';
});
add_shortcode( 'elxao_chat_inbox', function($atts){
    return class_exists('ELXAO_Chat_Inbox') ? ELXAO_Chat_Inbox::shortcode_chat_inbox($atts) : '';
});

/* ===== AJAX ===== */
add_action( 'wp_ajax_elxao_send_message', function(){
    if ( class_exists('ELXAO_Chat_Ajax') ) ELXAO_Chat_Ajax::send_message();
});
add_action( 'wp_ajax_elxao_fetch_messages', function(){
    if ( class_exists('ELXAO_Chat_Ajax') ) ELXAO_Chat_Ajax::fetch_messages();
});
add_action( 'wp_ajax_elxao_mark_read', function(){
    if ( class_exists('ELXAO_Chat_Ajax') ) ELXAO_Chat_Ajax::mark_read();
});

/* ===== Helpers ===== */
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
        elxao_chat_sync_participants( $chat_id );
    }
    return $chat_id;
}}

if ( ! function_exists( 'elxao_chat_get_user_role_in_chat' ) ) {
function elxao_chat_get_user_role_in_chat( $chat_id, $user_id ) {
    if ( ! $chat_id || ! $user_id ) {
        return null;
    }
    $pm_id     = (int) get_post_meta( $chat_id, 'pm_id', true );
    $client_id = (int) get_post_meta( $chat_id, 'client_id', true );
    if ( $user_id === $pm_id ) {
        return 'pm';
    }
    if ( $user_id === $client_id ) {
        return 'client';
    }
    if ( user_can( $user_id, 'administrator' ) ) {
        return 'admin';
    }
    return null;
}}

if ( ! function_exists( 'elxao_chat_ensure_participant' ) ) {
function elxao_chat_ensure_participant( $chat_id, $user_id, $role = null ) {
    if ( ! $chat_id || ! $user_id ) {
        return null;
    }
    if ( null === $role ) {
        $role = elxao_chat_get_user_role_in_chat( $chat_id, $user_id );
    }
    if ( ! $role ) {
        return null;
    }

    global $wpdb;
    $table = $wpdb->prefix . ELXAO_CHAT_PARTICIPANTS_TABLE;
    $wpdb->query(
        $wpdb->prepare(
            "INSERT INTO {$table} (chat_id, user_id, role, last_active_at)
             VALUES (%d, %d, %s, NOW())
             ON DUPLICATE KEY UPDATE role = VALUES(role), last_active_at = NOW()",
            $chat_id,
            $user_id,
            $role
        )
    );

    return $role;
}}

if ( ! function_exists( 'elxao_chat_update_participant_progress' ) ) {
function elxao_chat_update_participant_progress( $chat_id, $user_id, $field, $value ) {
    if ( ! in_array( $field, [ 'last_delivered_message_id', 'last_read_message_id' ], true ) ) {
        return false;
    }
    if ( ! $chat_id || ! $user_id ) {
        return false;
    }

    $value = (int) $value;
    elxao_chat_ensure_participant( $chat_id, $user_id );

    global $wpdb;
    $table = $wpdb->prefix . ELXAO_CHAT_PARTICIPANTS_TABLE;
    $wpdb->query(
        $wpdb->prepare(
            "UPDATE {$table}
             SET {$field} = GREATEST({$field}, %d), last_active_at = NOW()
             WHERE chat_id = %d AND user_id = %d",
            $value,
            $chat_id,
            $user_id
        )
    );

    return true;
}}

if ( ! function_exists( 'elxao_chat_get_participants_state' ) ) {
function elxao_chat_get_participants_state( $chat_id ) {
    if ( ! $chat_id ) {
        return [];
    }
    global $wpdb;
    $table = $wpdb->prefix . ELXAO_CHAT_PARTICIPANTS_TABLE;
    $rows  = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT user_id, role, last_delivered_message_id, last_read_message_id
             FROM {$table}
             WHERE chat_id = %d",
            $chat_id
        ),
        ARRAY_A
    );

    $out = [];
    foreach ( (array) $rows as $row ) {
        $uid = (int) $row['user_id'];
        $out[ $uid ] = [
            'role'           => $row['role'],
            'last_delivered' => (int) $row['last_delivered_message_id'],
            'last_read'      => (int) $row['last_read_message_id'],
        ];
    }
    return $out;
}}

if ( ! function_exists( 'elxao_chat_format_participants_state' ) ) {
function elxao_chat_format_participants_state( $chat_id, $participants = null ) {
    if ( null === $participants ) {
        $participants = elxao_chat_get_participants_state( $chat_id );
    }
    $pm_id     = (int) get_post_meta( $chat_id, 'pm_id', true );
    $client_id = (int) get_post_meta( $chat_id, 'client_id', true );

    $formatted = [];
    if ( $client_id ) {
        $state = $participants[ $client_id ] ?? [ 'last_delivered' => 0, 'last_read' => 0 ];
        $formatted['client'] = [
            'role'          => 'client',
            'user_id'       => $client_id,
            'last_delivered'=> $state['last_delivered'] ?? 0,
            'last_read'     => $state['last_read'] ?? 0,
        ];
    }
    if ( $pm_id ) {
        $state = $participants[ $pm_id ] ?? [ 'last_delivered' => 0, 'last_read' => 0 ];
        $formatted['pm'] = [
            'role'          => 'pm',
            'user_id'       => $pm_id,
            'last_delivered'=> $state['last_delivered'] ?? 0,
            'last_read'     => $state['last_read'] ?? 0,
        ];
    }

    $admins = [];
    foreach ( $participants as $uid => $state ) {
        if ( isset( $state['role'] ) && 'admin' === $state['role'] ) {
            $admins[] = [
                'role'          => 'admin',
                'user_id'       => $uid,
                'last_delivered'=> $state['last_delivered'],
                'last_read'     => $state['last_read'],
            ];
        }
    }
    if ( ! empty( $admins ) ) {
        $formatted['admins'] = $admins;
    }

    return $formatted;
}}

if ( ! function_exists( 'elxao_chat_sync_participants' ) ) {
function elxao_chat_sync_participants( $chat_id ) {
    if ( ! $chat_id ) {
        return;
    }
    $pm_id     = (int) get_post_meta( $chat_id, 'pm_id', true );
    $client_id = (int) get_post_meta( $chat_id, 'client_id', true );
    if ( $pm_id ) {
        elxao_chat_ensure_participant( $chat_id, $pm_id, 'pm' );
    }
    if ( $client_id ) {
        elxao_chat_ensure_participant( $chat_id, $client_id, 'client' );
    }
}}

/* ===== Force unified send icon (static file) on server render ===== */
if ( ! function_exists('elxao_chat_replace_send_button_inner') ) {
function elxao_chat_replace_send_button_inner( $html ){
    $svg_url = ELXAO_CHAT_URL . 'assets/icons/send.svg';
    $img = '<img src="' . esc_url( $svg_url ) . '" alt="" class="send-icon-img" />';
    $pattern = '/(<button[^>]*class="[^"]*send-icon[^"]*"[^>]*>)(.*?)(<\/button>)/is';
    return preg_replace( $pattern, '$1' . $img . '$3', $html, 1 );
}}
add_filter('do_shortcode_tag', function( $output, $tag ){
    if ( in_array( $tag, array( 'elxao_chat_window', 'elxao_chat_inbox' ), true ) ) {
        $output = elxao_chat_replace_send_button_inner( $output );
    }
    return $output;
}, 10, 2);

/* ===== Reapply icon after AJAX / room switch & remove tooltips ===== */
add_action('wp_footer', function () {
  $icon_url = esc_url( ELXAO_CHAT_URL . 'assets/icons/send.svg' );
  ?>
  <script>
  (function(){
    const ICON_URL = "<?php echo $icon_url; ?>";
    function patchSendIcons(root){
      (root || document).querySelectorAll('.send-icon').forEach(btn=>{
        if (!btn.querySelector('img.send-icon-img')) {
          btn.innerHTML = '<img src="'+ICON_URL+'" alt="" class="send-icon-img">';
        }
        btn.removeAttribute('title');
      });
    }
    patchSendIcons(document);
    const mo = new MutationObserver(()=>patchSendIcons(document));
    mo.observe(document.body, {subtree:true, childList:true, attributes:true, attributeFilter:['class']});
    if (window.jQuery) jQuery(document).ajaxComplete(()=>patchSendIcons(document));
  })();
  </script>
  <?php
});


/* ===== NEW: Message status assets (sent/delivered/read) ===== */
add_action('wp_enqueue_scripts', function () {
    $css_handle = 'elxao-chat-status';
    $js_handle  = 'elxao-chat-status';

    // Enqueue CSS
    wp_enqueue_style(
      $css_handle,
      ELXAO_CHAT_URL . 'assets/css/status.css',
      [],
      ELXAO_CHAT_VERSION
    );

    // Définition des variables CSS globales (icônes + couleurs)
    $tick       = ELXAO_CHAT_URL . 'assets/icons/tick.svg';
    $tickDouble = ELXAO_CHAT_URL . 'assets/icons/tick-double.svg';
    $inline_css = "
    :root{
      --elxao-tick-url: url('{$tick}');
      --elxao-tick-double-url: url('{$tickDouble}');
      --elxao-status-grey: #9aa0a6;
      --elxao-status-blue: #34B7F1;
    }";
    wp_add_inline_style($css_handle, $inline_css);

    // Enqueue JS
    wp_enqueue_script(
      $js_handle,
      ELXAO_CHAT_URL . 'assets/js/status.js',
      [],
      ELXAO_CHAT_VERSION,
      true
    );

    wp_localize_script($js_handle, 'ELXAO_STATUS', [
      'restUrl' => esc_url_raw( rest_url('elxao/v1/messages/read') ),
      'nonce'   => wp_create_nonce('wp_rest'),
    ]);
});

/* ===== Include REST endpoint for read receipts ===== */
require_once ELXAO_CHAT_DIR . 'includes/rest-status.php';

require_once ELXAO_CHAT_DIR . 'includes/class-chat-status-loader.php';
