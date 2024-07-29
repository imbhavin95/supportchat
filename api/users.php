<?php

require('../include/functions.php');
// error_reporting(0);

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

            $existingPhone = sb_db_get('SELECT value FROM sb_users_data WHERE value = "' . $_POST['phone'] . '" LIMIT 1');
            if ($existingPhone) {
                $response['data'][] = 'Duplicate phone number, Please use another one';
                echo json_encode($response);
                die;
            }

            $userId = sb_add_user($settings);

            sb_add_new_user_extra($userId, $settings_extra);

            if($userId == 'duplicate-email'){
                $response['data'][] = 'Duplicate email address, Please use another one';
            }else{
                $existingConversation = sb_db_get('SELECT id FROM sb_conversations WHERE user_id = "' . $userId . '" LIMIT 1');
                if($existingConversation){
                    $response = ['status' => 200, 'message' => 'success', 'user_id' => $userId, 'conversation_url' => 'https://supportboard.test/admin.php?conversation='.$existingConversation ];
                }else{
                    $conversationId = sb_new_conversation($userId);
                    $response = ['status' => 200, 'message' => 'success', 'user_id' => $userId, 'conversation_url' => 'https://supportboard.test/admin.php?conversation='.$conversationId['details']['id'], 'conversation_details' => $conversationId['details'] ];
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