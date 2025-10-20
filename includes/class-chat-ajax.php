<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! class_exists('ELXAO_Chat_Ajax') ) {
class ELXAO_Chat_Ajax {
    private static function sync_participants( $chat_id, $user_id = 0 ) {
        if ( function_exists( 'elxao_chat_sync_participants' ) ) {
            elxao_chat_sync_participants( $chat_id );
        }
        if ( $user_id && function_exists( 'elxao_chat_ensure_participant' ) ) {
            elxao_chat_ensure_participant( $chat_id, $user_id );
        }
    }

    private static function get_participants_state( $chat_id ) {
        return function_exists( 'elxao_chat_get_participants_state' )
            ? elxao_chat_get_participants_state( $chat_id )
            : [];
    }

    private static function formatted_participants_state( $chat_id, $participants = null ) {
        return function_exists( 'elxao_chat_format_participants_state' )
            ? elxao_chat_format_participants_state( $chat_id, $participants )
            : [];
    }

    private static function resolve_role( $chat_id, $user_id, $participants, $pm_id = 0, $client_id = 0 ) {
        if ( isset( $participants[ $user_id ]['role'] ) ) {
            return $participants[ $user_id ]['role'];
        }

        if ( $pm_id && $user_id === $pm_id ) {
            return 'pm';
        }

        if ( $client_id && $user_id === $client_id ) {
            return 'client';
        }

        if ( function_exists( 'elxao_chat_get_user_role_in_chat' ) ) {
            $role = elxao_chat_get_user_role_in_chat( $chat_id, $user_id );
            if ( $role ) {
                return $role;
            }
        }

        if ( function_exists( 'user_can' ) && user_can( $user_id, 'administrator' ) ) {
            return 'admin';
        }

        return ( $user_id !== $pm_id && $user_id !== $client_id ) ? 'admin' : null;
    }

    private static function status_payload( $message_id, $sender_role, $participants, $pm_id, $client_id, $is_mine ) {
        $client_state = $client_id ? ( $participants[ $client_id ] ?? [ 'last_delivered' => 0, 'last_read' => 0 ] ) : null;
        $pm_state     = $pm_id ? ( $participants[ $pm_id ] ?? [ 'last_delivered' => 0, 'last_read' => 0 ] ) : null;

        $client_delivered = $client_state ? ( $client_state['last_delivered'] >= $message_id ) : false;
        $client_read      = $client_state ? ( $client_state['last_read'] >= $message_id ) : false;
        $pm_delivered     = $pm_state ? ( $pm_state['last_delivered'] >= $message_id ) : false;
        $pm_read          = $pm_state ? ( $pm_state['last_read'] >= $message_id ) : false;

        $recipient_breakdown = [];

        if ( $client_id ) {
            $recipient_breakdown['client'] = [
                'delivered' => $client_delivered,
                'read'      => $client_read,
            ];
        }

        if ( $pm_id ) {
            $recipient_breakdown['pm'] = [
                'delivered' => $pm_delivered,
                'read'      => $pm_read,
            ];
        }

        $include_breakdown = ( ! $sender_role || 'admin' === $sender_role );

        $result = [
            'status'              => $is_mine ? 'sent' : '',
            'delivered'           => false,
            'read'                => false,
            'recipient_breakdown' => $include_breakdown ? $recipient_breakdown : [],
        ];

        if ( ! $is_mine ) {
            return $result;
        }

        if ( 'pm' === $sender_role ) {
            $dest = $client_state;
        } elseif ( 'client' === $sender_role ) {
            $dest = $pm_state;
        } elseif ( 'admin' === $sender_role ) {
            $result['recipient_breakdown']['client'] = [
                'delivered' => $client_state ? ( $client_state['last_delivered'] >= $message_id ) : false,
                'read'      => $client_state ? ( $client_state['last_read'] >= $message_id ) : false,
            ];
            $result['recipient_breakdown']['pm'] = [
                'delivered' => $pm_state ? ( $pm_state['last_delivered'] >= $message_id ) : false,
                'read'      => $pm_state ? ( $pm_state['last_read'] >= $message_id ) : false,
            ];

            $targets       = [];
            $has_state     = false;
            $any_delivered = false;
            $any_read      = false;

            if ( $client_id && $client_state ) {
                $targets[] = $client_state;
            }
            if ( $pm_id && $pm_state ) {
                $targets[] = $pm_state;
            }

            foreach ( $targets as $target ) {
                if ( ! $target ) {
                    continue;
                }

                $has_state = true;

                if ( $target['last_delivered'] >= $message_id ) {
                    $any_delivered = true;
                }
                if ( $target['last_read'] >= $message_id ) {
                    $any_read      = true;
                    $any_delivered = true;
                }
            }

            if ( $has_state && $any_read ) {
                $result['status']    = 'read';
                $result['delivered'] = true;
                $result['read']      = true;
            } elseif ( $has_state && $any_delivered ) {
                $result['status']    = 'delivered';
                $result['delivered'] = true;
            } else {
                $result['status']    = 'sent';
                $result['delivered'] = false;
                $result['read']      = false;
            }

            return $result;
        }

        if ( 'client' === $sender_role ) {
            if ( $pm_read ) {
                $result['status']    = 'read';
                $result['delivered'] = true;
                $result['read']      = true;
            } elseif ( $pm_delivered ) {
                $result['status']    = 'delivered';
                $result['delivered'] = true;
            }

            return $result;
        }

        $any_delivered = ( $client_delivered || $pm_delivered );
        $any_read      = ( $client_read || $pm_read );

        if ( $any_read ) {
            $result['status']    = 'read';
            $result['delivered'] = true;
            $result['read']      = true;
        } elseif ( $any_delivered ) {
            $result['status']    = 'delivered';
            $result['delivered'] = true;
        }

        return $result;
    }

    public static function send_message(){
        check_ajax_referer( 'elxao_chat_nonce', 'nonce' );
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => 'Not logged in' ], 401 );
        }
        $chat_id = isset( $_POST['chat_id'] ) ? intval( $_POST['chat_id'] ) : 0;
        $text    = isset( $_POST['text'] ) ? wp_unslash( $_POST['text'] ) : '';
        if ( ! $chat_id || '' === trim( $text ) ) {
            wp_send_json_error( [ 'message' => 'Missing data' ], 400 );
        }
        $project_id = (int) get_post_meta( $chat_id, 'project_id', true );
        if ( ! $project_id ) {
            wp_send_json_error( [ 'message' => 'Invalid chat' ], 400 );
        }
        $user_id = get_current_user_id();
        if ( ! elxao_chat_user_can_access( $project_id, $user_id ) ) {
            wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
        }

        global $wpdb;
        $table = $wpdb->prefix . ELXAO_CHAT_TABLE;
        $now   = current_time( 'mysql' );
        $ins   = $wpdb->insert(
            $table,
            [
                'chat_id'    => $chat_id,
                'sender_id'  => $user_id,
                'message'    => wp_kses_post( $text ),
                'created_at' => $now,
            ],
            [ '%d', '%d', '%s', '%s' ]
        );
        if ( ! $ins ) {
            wp_send_json_error( [ 'message' => 'DB insert failed' ], 500 );
        }

        $message_id = (int) $wpdb->insert_id;
        self::sync_participants( $chat_id, $user_id );

        update_post_meta( $chat_id, 'last_message_at', $now );
        update_post_meta( $chat_id, 'last_message_id', $message_id );

        wp_send_json_success( [ 'message' => 'ok', 'id' => $message_id ] );
    }

    public static function mark_read(){
        check_ajax_referer( 'elxao_chat_nonce', 'nonce' );
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => 'Not logged in' ], 401 );
        }
        $chat_id = isset( $_POST['chat_id'] ) ? intval( $_POST['chat_id'] ) : 0;
        $last_id = isset( $_POST['last_id'] ) ? intval( $_POST['last_id'] ) : 0;
        if ( ! $chat_id || ! $last_id ) {
            wp_send_json_error( [ 'message' => 'Missing data' ], 400 );
        }
        $project_id = (int) get_post_meta( $chat_id, 'project_id', true );
        if ( ! $project_id ) {
            wp_send_json_error( [ 'message' => 'Invalid chat' ], 400 );
        }
        $user_id = get_current_user_id();
        if ( ! elxao_chat_user_can_access( $project_id, $user_id ) ) {
            wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
        }

        self::sync_participants( $chat_id, $user_id );
        if ( function_exists( 'elxao_chat_update_participant_progress' ) ) {
            elxao_chat_update_participant_progress( $chat_id, $user_id, 'last_read_message_id', $last_id );
            elxao_chat_update_participant_progress( $chat_id, $user_id, 'last_delivered_message_id', $last_id );
        }

        $participants = self::formatted_participants_state( $chat_id );
        wp_send_json_success( [ 'ok' => true, 'participants' => $participants ] );
    }

    public static function fetch_messages(){
        check_ajax_referer( 'elxao_chat_nonce', 'nonce' );
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => 'Not logged in' ], 401 );
        }
        $chat_id  = isset( $_POST['chat_id'] ) ? intval( $_POST['chat_id'] ) : 0;
        $after_id = isset( $_POST['after_id'] ) ? intval( $_POST['after_id'] ) : 0;
        if ( ! $chat_id ) {
            wp_send_json_error( [ 'message' => 'Missing chat_id' ], 400 );
        }
        $project_id = (int) get_post_meta( $chat_id, 'project_id', true );
        if ( ! $project_id ) {
            wp_send_json_error( [ 'message' => 'Invalid chat' ], 400 );
        }
        $user_id = get_current_user_id();
        if ( ! elxao_chat_user_can_access( $project_id, $user_id ) ) {
            wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
        }

        global $wpdb;
        $table = $wpdb->prefix . ELXAO_CHAT_TABLE;
        if ( $after_id > 0 ) {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, sender_id, message, created_at FROM {$table} WHERE chat_id=%d AND id>%d ORDER BY id ASC LIMIT 20",
                    $chat_id,
                    $after_id
                ),
                ARRAY_A
            );
        } else {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, sender_id, message, created_at FROM {$table} WHERE chat_id=%d ORDER BY id ASC LIMIT 200",
                    $chat_id
                ),
                ARRAY_A
            );
        }

        self::sync_participants( $chat_id, $user_id );
        $participants = self::get_participants_state( $chat_id );

        $max_id = 0;
        foreach ( $rows as $row ) {
            $mid = (int) $row['id'];
            if ( $mid > $max_id ) {
                $max_id = $mid;
            }
        }
        if ( $max_id && function_exists( 'elxao_chat_update_participant_progress' ) ) {
            elxao_chat_update_participant_progress( $chat_id, $user_id, 'last_delivered_message_id', $max_id );
        }

        $participants = self::get_participants_state( $chat_id );
        $pm_id        = (int) get_post_meta( $chat_id, 'pm_id', true );
        $client_id    = (int) get_post_meta( $chat_id, 'client_id', true );

        $messages = [];
        foreach ( $rows as $row ) {
            $id        = (int) $row['id'];
            $sender_id = (int) $row['sender_id'];
            $mine      = ( $sender_id === $user_id );
            $role      = self::resolve_role( $chat_id, $sender_id, $participants, $pm_id, $client_id );
            if ( $role ) {
                if ( function_exists( 'elxao_chat_ensure_participant' ) ) {
                    elxao_chat_ensure_participant( $chat_id, $sender_id, $role );
                }
                if ( ! isset( $participants[ $sender_id ] ) ) {
                    $participants[ $sender_id ] = [
                        'role'           => $role,
                        'last_delivered' => 0,
                        'last_read'      => 0,
                    ];
                }
            }

            $status = self::status_payload( $id, $role, $participants, $pm_id, $client_id, $mine );
            $message = [
                'id'           => $id,
                'sender_id'    => $sender_id,
                'sender'       => self::fmt( $sender_id ),
                'message'      => wpautop( $row['message'] ),
                'time'         => mysql2date( 'Y-m-d H:i', $row['created_at'], true ),
                'mine'         => $mine,
                'incoming'     => $mine ? 0 : 1,
                'status'       => $mine ? $status['status'] : '',
                'delivered'    => $mine ? $status['delivered'] : false,
                'read'         => $mine ? $status['read'] : false,
                'delivered_at' => null,
                'read_at'      => null,
            ];
            if ( $role ) {
                $message['sender_role'] = $role;
            }
            if ( ! empty( $status['recipient_breakdown'] ) ) {
                $message['recipients'] = $status['recipient_breakdown'];
            }
            $messages[] = $message;
        }

        $participants_payload = self::formatted_participants_state( $chat_id, $participants );

        wp_send_json_success(
            [
                'messages'     => $messages,
                'participants' => $participants_payload,
            ]
        );
    }

    public static function inbox_load_chat(){
        check_ajax_referer( 'elxao_chat_nonce', 'nonce' );
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => 'Not logged in' ], 401 );
        }

        $chat_id    = isset( $_POST['chat_id'] ) ? intval( $_POST['chat_id'] ) : 0;
        $project_id = isset( $_POST['project_id'] ) ? intval( $_POST['project_id'] ) : 0;

        if ( ! $chat_id ) {
            wp_send_json_error( [ 'message' => 'Invalid chat.' ], 400 );
        }

        if ( ! $project_id ) {
            $project_id = (int) get_post_meta( $chat_id, 'project_id', true );
        }

        if ( ! $project_id ) {
            wp_send_json_error( [ 'message' => 'Chat is not linked to a project.' ], 400 );
        }

        $user_id = get_current_user_id();
        if ( ! elxao_chat_user_can_access( $project_id, $user_id ) ) {
            wp_send_json_error( [ 'message' => 'You do not have access to this chat.' ], 403 );
        }

        if ( ! class_exists( 'ELXAO_Chat_Render' ) ) {
            wp_send_json_error( [ 'message' => 'Chat renderer is unavailable.' ], 500 );
        }

        $html = ELXAO_Chat_Render::shortcode_chat_window( [ 'post_id' => $project_id ] );

        if ( '' === trim( $html ) ) {
            wp_send_json_error( [ 'message' => 'Unable to render chat window.' ], 500 );
        }

        wp_send_json_success(
            [
                'html'        => $html,
                'chat_id'     => (int) $chat_id,
                'project_id'  => (int) $project_id,
            ]
        );
    }

    private static function fmt( $uid ){
        $u = get_userdata( $uid );
        if ( ! $u ) {
            return 'User ' . $uid;
        }
        $n = $u->display_name ? $u->display_name : $u->user_login;
        if ( user_can( $uid, 'administrator' ) ) {
            return $n . ' (Admin)';
        }
        return $n;
    }
}
}
