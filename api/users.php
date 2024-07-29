<?php
header('Access-Control-Allow-Origin: *');

require('../include/functions.php');

error_reporting(0);

if(!empty($_POST))
{
    if(!empty($_POST['action']) && $_POST['action'] == 'create'){
        try {

            $response = ['status' => 400, 'message' => 'error', 'data' => []];
            if(!isset($_POST['name']) || empty($_POST['name'])){
                $response['data'][] = 'Name is required';
            }
            if(!isset($_POST['email']) || empty($_POST['email'])){
                $response['data'][] = 'Email address is required';
            }
            if(!isset($_POST['phone']) || empty($_POST['phone'])){
                $response['data'][] = 'Phone number is required';
            }

            $name = explode(' ', $_POST['name']);
            $last_name = substr(strstr($_POST['name']," "), 1);

            $settings = [
                'first_name' => $name[0],
                'last_name' => $last_name,
                'email' =>  $_POST['email'],
            ];

            $settings_extra = [
                'phone' => [$_POST['phone'], 'Phone']
            ];

            $existingUser = sb_db_get('SELECT user_id FROM sb_users_data WHERE value = "' . $_POST['phone'] . '" LIMIT 1');
            $existingUserEmail = sb_db_get('SELECT id FROM sb_users WHERE email = "' . $_POST['email'] . '" LIMIT 1');
            if($existingUserEmail){
                $existingConversation = sb_db_get('SELECT id FROM sb_conversations WHERE user_id = "' . $existingUserEmail['id'] . '" LIMIT 1');
                if($existingConversation) {
                    $response = ['status' => 200, 'message' => 'success', 'user_id' => $existingUserEmail['id'], 'conversation_url' => 'https://successinsurance.ae/support/admin.php?conversation='.$existingConversation['id'] ];
                }else{
                    $conversationId = sb_new_conversation($existingUserEmail['id'], 3);
                    $response = ['status' => 200, 'message' => 'success', 'user_id' => $existingUserEmail['id'], 'conversation_url' => 'https://successinsurance.ae/support/admin.php?conversation='.$conversationId['details']['id']];
                }
            } else if ($existingUser) {
                $existingConversation = sb_db_get('SELECT id FROM sb_conversations WHERE user_id = "' . $existingUser['user_id'] . '" LIMIT 1');
                if($existingConversation) {
                    $response = ['status' => 200, 'message' => 'success', 'user_id' => $existingUser['user_id'], 'conversation_url' => 'https://successinsurance.ae/support/admin.php?conversation='.$existingConversation['id'] ];
                }else{
                    $conversationId = sb_new_conversation($existingUser['user_id'], 3);
                    $response = ['status' => 200, 'message' => 'success', 'user_id' => $existingUser['user_id'], 'conversation_url' => 'https://successinsurance.ae/support/admin.php?conversation='.$conversationId['details']['id']];
                }
            } else{
                $userId = sb_add_user($settings);
                sb_add_new_user_extra($userId, $settings_extra);
                $existingConversation = sb_db_get('SELECT id FROM sb_conversations WHERE user_id = "' . $userId . '" LIMIT 1');
                if($existingConversation){
                    $response = ['status' => 200, 'message' => 'success', 'user_id' => $userId, 'conversation_url' => 'https://successinsurance.ae/support/admin.php?conversation='.$existingConversation ];
                }else{
                    $conversationId = sb_new_conversation($userId, 3);
                    $response = ['status' => 200, 'message' => 'success', 'user_id' => $userId, 'conversation_url' => 'https://successinsurance.ae/support/admin.php?conversation='.$conversationId['details']['id']];
                }
            }

            echo json_encode($response);
        }catch (Exception $exception){
            echo json_encode(['status' => 400, 'message' => json_encode($exception)]);
        }
    } else{
        echo json_encode(['status' => 400, 'message' => 'action is reqiuired']);
    }
}