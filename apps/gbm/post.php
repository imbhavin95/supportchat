<?php

/*
 * ==========================================================
 * POST.PHP
 * ==========================================================
 *
 * Messenger response listener. This file receive the Facebook Messenger messages of the agents forwarded by board.support. This file requires the Messenger App.
 * © 2017-2022 board.support. All rights reserved.
 *
 */


$raw = file_get_contents('php://input');

if ($raw) {
    require('../../include/functions.php');
    $response = json_decode($raw, true);
    $token = sb_get_multi_setting('gbm', 'gbm-client-token');
    if (isset($response['secret']) && sb_isset($response, 'clientToken') == $token) die($response['secret']);
    $signature = base64_encode(hex2bin(hash_hmac('sha512', $raw, $token)));
    if ($_SERVER['HTTP_X_GOOG_SIGNATURE'] == $signature) {
        flush();
        if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
        $message = sb_isset($response, 'message');
        if (!$message) die();
        $message_id = $message['messageId'];
        $message = $message['text'];
        $GLOBALS['SB_FORCE_ADMIN'] = true;
        $user_id = false;
        $conversation_id = false;
        $user = sb_get_user_by('gbm-id', $response['conversationId']);
        $payload = json_encode(['bmid' => $message_id]);
        $new_conversation = false;

        // Attachments
        $attachments = [];
        if (strpos($message, 'storage.googleapis')) {
            $attachments = [[$message_id, sb_download_file($message, $message_id, true)]];
            $message = '';
        }

        // User and conversation
        if (!$user) {
            $name = $response['context']['userInfo']['displayName'];
            $space_in_name = strpos($name, ' ');
            $first_name = $space_in_name ? trim(substr($name, 0, $space_in_name)) : $name . $space_in_name;
            $last_name = $space_in_name ? trim(substr($name, $space_in_name)) : '';
            $language = sb_isset($response['context']['userInfo'], 'userDeviceLocale');
            $extra = ['gbm-id' => [$response['conversationId'], 'Business Messages ID'], 'language' => [$language ? substr($language, 0, 2) : (defined('SB_DIALOGFLOW') ? sb_google_language_detection_get_user_extra($message) : false), 'Language']];
            $user_id = sb_add_user(['first_name' => $first_name, 'last_name' => $last_name, 'user_type' => 'user'], $extra);
            $user = sb_get_user($user_id);
        } else {
            $user_id = $user['id'];
            $conversation_id = sb_gbm_get_conversation_id($user_id);
        }
        $GLOBALS['SB_LOGIN'] = $user;
        if (!$conversation_id) {
            $conversation_id = sb_isset(sb_new_conversation($user_id, 2, '', sb_get_setting('gbm-department'), -1, 'bm'), 'details', [])['id'];
            $new_conversation = true;
        }

        // Send message
        $response = sb_send_message($user_id, $conversation_id, $message, $attachments, 2, $payload);

        // Dialogflow, Notifications, Bot messages
        $response_extarnal = sb_messaging_platforms_functions($conversation_id, $message, $attachments, $user, ['source' => 'bm', 'new_conversation' => $new_conversation]);

        // Queue
        if (sb_get_multi_setting('queue', 'queue-active')) {
            sb_queue($conversation_id, $department, true);
        }

        // Online status
        sb_update_users_last_activity($user_id);

        $GLOBALS['SB_FORCE_ADMIN'] = false;

    } else die('invalid-signature');
}
die();

?>