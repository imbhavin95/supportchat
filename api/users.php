<?php

require('../include/functions.php');

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
                'phone' => $_POST['phone']
            ];

            $userId = sb_add_user($settings, $settings_extra);

            if($userId == 'duplicate-email'){
                $response['data'][] = 'Duplicate email address, Please use another one';
            }else{
                $response = ['status' => 200, 'message' => 'success', 'user_id' => $userId];
            }

            echo json_encode($response);
        }catch (Exception $exception){
            echo json_encode(['status' => 400, 'message' => $exception->getMessage()]);
        }
    } else{
        echo json_encode(['status' => 400, 'message' => 'action is reqiuired']);
    }
}