<?php

/*
 * ==========================================================
 * AI APP
 * ==========================================================
 *
 * Artificial Intelligence App main file. © 2017-2024 board.support. All rights reserved.
 *
 */

define('SB_DIALOGFLOW', '1.4.3');

/*
 * -----------------------------------------------------------
 * SYNC
 * -----------------------------------------------------------
 *
 */

if (isset($_GET['code']) && file_exists('../../include/functions.php')) {
    require('../../include/functions.php');
    sb_cloud_load();
    $info = sb_google_key();
    $query = '{ code: "' . $_GET['code'] . '", grant_type: "authorization_code", client_id: "' . $info[0] . '", client_secret: "' . $info[1] . '", redirect_uri: "' . SB_URL . '/apps/dialogflow/functions.php" }';
    $response = sb_curl('https://accounts.google.com/o/oauth2/token', $query, ['Content-Type: application/json', 'Content-Length: ' . strlen($query)]);
    die($response && isset($response['refresh_token']) ? '<script>document.location = "' . (sb_is_cloud() ? str_replace('/script', '', SB_URL) : SB_URL . '/admin.php') . '?setting=dialogflow&refresh_token=' . $response['refresh_token'] . '";</script>' : 'Error while trying to get Dialogflow token. Dialogflow code: ' . $_GET['code'] . '. Response: ' . json_encode($response));
}

/*
 * -----------------------------------------------------------
 * OBJECTS
 * -----------------------------------------------------------
 *
 * Dialogflow objects
 *
 */

class SBDialogflowEntity {
    public $data;

    function __construct($id, $values, $prompts = []) {
        $this->data = ['displayName' => $id, 'entities' => $values, 'kind' => 'KIND_MAP', 'enableFuzzyExtraction' => true];
    }

    public function __toString() {
        return $this->json();
    }

    function json() {
        return json_encode($this->data);
    }

    function data() {
        return $this->data;
    }
}

class SBDialogflowIntent {
    public $data;

    function __construct($name, $training_phrases, $bot_responses, $entities = [], $entities_values = [], $payload = false, $input_contexts = [], $output_contexts = [], $prompts = [], $id = false) {
        $training_phrases_api = [];
        $parameters = [];
        $parameters_checks = [];
        $messages = [];
        $json = json_decode(file_get_contents(SB_PATH . '/apps/dialogflow/data.json'), true);
        $entities = array_merge($entities, $json['entities']);
        $entities_values = array_merge($entities_values, $json['entities-values']);
        $project_id = false;
        if (is_string($bot_responses)) {
            $bot_responses = [$bot_responses];
        }
        if (is_string($training_phrases)) {
            $training_phrases = [$training_phrases];
        }
        for ($i = 0; $i < count($training_phrases); $i++) {
            $parts_temp = explode('@', $training_phrases[$i]);
            $parts = [];
            $parts_after = false;
            for ($j = 0; $j < count($parts_temp); $j++) {
                $part = ['text' => ($j == 0 ? '' : '@') . $parts_temp[$j]];
                for ($y = 0; $y < count($entities); $y++) {
                    $entity = is_string($entities[$y]) ? $entities[$y] : $entities[$y]['displayName'];
                    $entity_type = '@' . $entity;
                    $entity_name = str_replace('.', '-', $entity);
                    $entity_value = empty($entities_values[$entity]) ? $entity_type : $entities_values[$entity][array_rand($entities_values[$entity])];
                    if (strpos($part['text'], $entity_type) !== false) {
                        $mandatory = true;
                        if (strpos($part['text'], $entity_type . '*') !== false) {
                            $mandatory = false;
                            $part['text'] = str_replace($entity_type . '*', $entity_type, $part['text']);
                        }
                        $parts_after = explode($entity_type, $part['text']);
                        $part = ['text' => $entity_value, 'entityType' => $entity_type, 'alias' => $entity_name, 'userDefined' => true];
                        if (count($parts_after) > 1) {
                            $parts_after = ['text' => $parts_after[1]];
                        } else {
                            $parts_after = false;
                        }
                        if (!in_array($entity, $parameters_checks)) {
                            array_push($parameters, ['displayName' => $entity_name, 'value' => '$' . $entity, 'mandatory' => $mandatory, 'entityTypeDisplayName' => '@' . $entity, 'prompts' => sb_isset($prompts, $entity_name, [])]);
                            array_push($parameters_checks, $entity);
                        }
                        break;
                    }
                }
                array_push($parts, $part);
                if ($parts_after)
                    array_push($parts, $parts_after);
            }
            array_push($training_phrases_api, ['type' => 'EXAMPLE', 'parts' => $parts]);
        }
        for ($i = 0; $i < count($bot_responses); $i++) {
            array_push($messages, ['text' => ['text' => $bot_responses[$i]]]);
        }
        if (!empty($payload)) {
            $std = new stdClass;
            $std->payload = $payload;
            array_push($messages, $std);
        }
        if (!empty($input_contexts) && is_array($input_contexts)) {
            $project_id = sb_get_multi_setting('google', 'google-project-id');
            for ($i = 0; $i < count($input_contexts); $i++) {
                $input_contexts[$i] = 'projects/' . $project_id . '/agent/sessions/-/contexts/' . $input_contexts[$i];
            }
        }
        if (!empty($output_contexts) && is_array($output_contexts)) {
            $project_id = $project_id ? $project_id : sb_get_multi_setting('google', 'google-project-id');
            for ($i = 0; $i < count($output_contexts); $i++) {
                $is_array = is_array($output_contexts[$i]);
                $output_contexts[$i] = ['name' => 'projects/' . $project_id . '/agent/sessions/-/contexts/' . ($is_array ? $output_contexts[$i][0] : $output_contexts[$i]), 'lifespanCount' => ($is_array ? $output_contexts[$i][1] : 3)];
            }
        }
        $t = ['displayName' => $name, 'trainingPhrases' => $training_phrases_api, 'parameters' => $parameters, 'messages' => $messages, 'inputContextNames' => $input_contexts, 'outputContexts' => $output_contexts];
        if ($id) {
            $t['name'] = $id;
        }
        $this->data = $t;
    }

    public function __toString() {
        return $this->json();
    }

    function json() {
        return json_encode($this->data);
    }

    function data() {
        return $this->data;
    }
}

/*
 * -----------------------------------------------------------
 * DIALOGFLOW MESSAGE
 * -----------------------------------------------------------
 *
 * Send the user message to the bot and return the reply
 *
 */

$sb_recursion_dialogflow = [true, true, true, true, true];
function sb_dialogflow_message($conversation_id = false, $message = '', $token = -1, $language = false, $attachments = [], $event = '', $parameters = false, $project_id = false, $session_id = false, $audio = false) {
    global $sb_recursion_dialogflow;
    if (sb_is_cloud()) {
        sb_cloud_membership_validation(true);
    }
    $smart_reply = $event == 'smart-reply';
    $user_id = $conversation_id && !$smart_reply && sb_is_agent() ? sb_db_get('SELECT user_id FROM sb_conversations WHERE id = ' . sb_db_escape($conversation_id, true))['user_id'] : sb_get_active_user_ID();
    if (!sb_cloud_membership_has_credits('google')) {
        return sb_error('no-credits', 'sb_dialogflow_message');
    }
    $cx = sb_get_multi_setting('google', 'dialogflow-edition', sb_get_setting('dialogflow-edition')) == 'cx'; // Deprecated: sb_get_setting('dialogflow-edition', 'es')
    $query = ['queryInput' => [], 'queryParams' => $cx ? ['parameters' => ['user_id' => $user_id, 'conversation_id' => $conversation_id]] : ['payload' => ['support_board' => ['conversation_id' => $conversation_id, 'user_id' => $user_id]]]];
    $bot_id = sb_get_bot_id();
    $human_takeover = sb_get_setting('dialogflow-human-takeover');
    $human_takeover = $human_takeover['dialogflow-human-takeover-active'] ? $human_takeover : false;
    $response_success = [];
    $multilingual = sb_get_setting('dialogflow-multilingual') || sb_get_multi_setting('google', 'google-multilingual'); // Deprecated: sb_get_setting('dialogflow-multilingual')
    $multilingual_translation = sb_get_setting('dialogflow-multilingual-translation') || sb_get_multi_setting('google', 'google-multilingual-translation'); // Deprecated: sb_get_setting('dialogflow-multilingual-translation')
    $user_language = $multilingual_translation ? sb_get_user_extra($user_id, 'language') : false;
    $unknow_language_message = false;
    $dialogflow_agent = false;
    $is_human_takeover = false;
    $message_id = false;
    $translations = false;
    if ($human_takeover && $conversation_id && !$smart_reply && sb_isset($human_takeover, 'dialogflow-human-takeover-disable-chatbot') && sb_dialogflow_is_human_takeover($conversation_id)) {
        return false;
    }
    if ($event == 'translations') {
        unset($GLOBALS['SB_LANGUAGE']);
        $translations = sb_get_current_translations();
    }
    if ($parameters) {
        $query['queryParams'][$cx ? 'parameters' : 'payload'] = array_merge($query['queryParams'][$cx ? 'parameters' : 'payload'], $parameters);
    }
    if (empty($bot_id)) {
        return new SBValidationError('bot-id-not-found');
    }
    if (!$language || empty($language[0])) {
        $language = $multilingual ? ($user_language ? $user_language : sb_get_user_language($user_id)) : false;
        $language = $language ? [$language] : ['en'];
    } else {
        $language[0] = sb_dialogflow_language_code($language[0]);
        if (count($language) > 1 && $language[1] == 'language-detection') {
            $response_success['language_detection'] = $language[0];
        }
    }
    $query['queryInput']['languageCode'] = $language[0];

    // Retrive token
    if ($token == -1 || $token === false) {
        $token = sb_dialogflow_get_token();
        if (sb_is_error($token)) {
            return $token;
        }
    }

    // Attachments
    $attachments = sb_json_array($attachments);
    for ($i = 0; $i < count($attachments); $i++) {
        $message .= ' [name:' . $attachments[$i][0] . ',url:' . $attachments[$i][1] . ',extension:' . pathinfo($attachments[$i][0], PATHINFO_EXTENSION) . ']';
    }

    if (!empty($audio)) {

        // Audio
        if (pathinfo($audio, PATHINFO_EXTENSION) == 'ogg' && sb_get_multi_setting('open-ai', 'open-ai-speech-recognition')) {
            $message .= sb_open_ai_audio_to_text($audio);
            $audio = false;
        } else {
            $audio = strpos($audio, 'http') === 0 ? sb_get($audio) : file_get_contents($audio);
            if ($cx) {
                $query['queryInput']['audio'] = ['config' => ['SampleRateHertz' => 16000, 'audioEncoding' => 'AUDIO_ENCODING_OGG_OPUS', 'languageCode' => $language[0]], 'audio' => base64_encode($audio)];
            } else {
                $query['queryInput']['audioConfig'] = ['audioEncoding' => 'AUDIO_ENCODING_UNSPECIFIED', 'languageCode' => $language[0]];
                $query['inputAudio'] = base64_encode($audio);
            }
        }
    }
    if (empty($audio) && !empty($message)) {

        // Message
        $query['queryInput']['text'] = ['text' => $message, 'languageCode' => $language[0]];
    } else if (!empty($event)) {

        // Events
        $query['queryInput']['event'] = $cx ? ['event' => $event] : ['name' => $event, 'languageCode' => $language[0]];
    }

    // Department linking
    if (!$project_id && $conversation_id && !$smart_reply) {
        $departments = sb_get_setting('dialogflow-departments');
        if ($departments && is_array($departments)) {
            $department = sb_db_get('SELECT department FROM sb_conversations WHERE id = ' . sb_db_escape($conversation_id, true))['department'];
            for ($i = 0; $i < count($departments); $i++) {
                if ($departments[$i]['dialogflow-departments-id'] == $department) {
                    $project_id = $departments[$i]['dialogflow-departments-agent'];
                    break;
                }
            }
        }
    }

    // Dialogflow response
    $session_id = $session_id ? $session_id : ($user_id ? $user_id : 'sb');
    $response_message = [];
    $response = sb_dialogflow_curl('/agent/sessions/' . $session_id . ':detectIntent', $query, false, 'POST', $token, $project_id);
    sb_cloud_membership_use_credits(($cx ? 'cx' : 'es') . (empty($audio) ? '' : '-audio'), 'google', strlen($audio));
    sb_webhooks('SBDialogflowMessage', ['response' => $response, 'message' => $message, 'conversation_id' => $conversation_id]);
    if (is_string($response)) {
        if (strpos($response, 'Error 404')) {
            return ['response' => ['error' => 'Error 404. Dialogflow Project ID or Agent Name not found.']];
        }
        $response = [];
    }
    if (sb_is_error($response)) {
        return $response;
    }
    if (isset($response['error']) && (sb_isset($response['error'], 'code') == 403 || in_array($response['error']['status'], ['PERMISSION_DENIED', 'UNAUTHENTICATED']))) {
        if ($sb_recursion_dialogflow[0]) {
            $sb_recursion_dialogflow[0] = false;
            $token = sb_dialogflow_get_token(false);
            return sb_dialogflow_message($conversation_id, $message, $token, $language, [], $event);
        } else {
            sb_error('dialogflow-access-token', 'sb_dialogflow_message', $response);
        }
    }
    $response_query = sb_isset($response, 'queryResult', []);
    $messages = sb_isset($response_query, 'fulfillmentMessages', sb_isset($response_query, 'responseMessages', []));
    $unknow_answer = sb_dialogflow_is_unknow($response);
    $results = [];
    $message_length = strlen($message);
    if (!$messages && isset($response_query['knowledgeAnswers'])) {
        $messages = sb_isset($response_query['knowledgeAnswers'], 'answers', []);
        for ($i = 0; $i < count($messages); $i++) {
            $messages[$i] = ['text' => ['text' => [$messages[$i]['answer']]]];
        }
    }
    if (isset($messages[0]) && isset($messages[0]['text']) && $messages[0]['text']['text'][0] == 'skip-intent') {
        $unknow_answer = true;
        $messages = [];
    }
    if (isset($response_query['webhookPayload'])) {
        array_push($messages, ['payload' => $response_query['webhookPayload']]);
    }

    // Parameters
    $parameters = isset($response_query['parameters']) && count($response_query['parameters']) ? $response_query['parameters'] : [];
    if (isset($response_query['outputContexts']) && count($response_query['outputContexts']) && isset($response_query['outputContexts'][0]['parameters'])) {
        for ($i = 0; $i < count($response_query['outputContexts']); $i++) {
            if (isset($response_query['outputContexts'][$i]['parameters'])) {
                $parameters = array_merge($response_query['outputContexts'][$i]['parameters'], $parameters);
            }
        }
    }

    // Google search, spelling correction
    if ($unknow_answer && !sb_is_agent()) {
        if ($message_length > 2) {
            if ($sb_recursion_dialogflow[1] && sb_get_multi_setting('open-ai', 'open-ai-spelling-correction-dialogflow') && !sb_get_shortcode($message)) {
                $spelling_correction = sb_open_ai_spelling_correction($message);
                $sb_recursion_dialogflow[1] = false;
                if ($spelling_correction != $message) {
                    return sb_dialogflow_message($conversation_id, $spelling_correction, $token, $language, $attachments, $event, $parameters);
                }
            }
            $google_search_settings = sb_get_setting('dialogflow-google-search');
            if ($google_search_settings) {
                $spelling_correction = $google_search_settings['dialogflow-google-search-spelling-active'];
                $continue = $google_search_settings['dialogflow-google-search-active'] && $message_length > 4;
                if ($continue) {
                    $entities = sb_isset($google_search_settings, 'dialogflow-google-search-entities');
                    if (!empty($entities) && is_array($entities)) {
                        $continue = false;
                        $entities_response = sb_isset(sb_google_analyze_entities($message, $language[0], $token), 'entities', []);
                        for ($i = 0; $i < count($entities_response); $i++) {
                            if (in_array($entities_response[$i]['type'], $entities)) {
                                $continue = true;
                                break;
                            }
                        }
                    }
                }
                if ($continue || $spelling_correction) {
                    $google_search_response = sb_get('https://www.googleapis.com/customsearch/v1?key=' . $google_search_settings['dialogflow-google-search-key'] . '&cx=' . $google_search_settings['dialogflow-google-search-id'] . '&q=' . urlencode($message), true);
                    if ($sb_recursion_dialogflow[2] && $spelling_correction && isset($google_search_response['spelling'])) {
                        $sb_recursion_dialogflow[2] = false;
                        return sb_dialogflow_message($conversation_id, $google_search_response['spelling']['correctedQuery'], $token, $language, $attachments, $event, $parameters);
                    }
                    if ($continue) {
                        $google_search_response = sb_isset($google_search_response, 'items');
                        if ($google_search_response && count($google_search_response)) {
                            $google_search_response = $google_search_response[0];
                            $google_search_message = $google_search_response['snippet'];
                            $pos = strrpos($google_search_message, '. ');
                            if (!$pos && substr($google_search_message, -3) !== '...' && substr($google_search_message, -1) === '.')
                                $pos = strlen($google_search_message);
                            if ($pos) {
                                $google_search_message = substr($google_search_message, 0, $pos);
                                $unknow_answer = false;
                                $messages = [['text' => ['text' => [$google_search_message]]]];
                                sb_dialogflow_set_active_context('google-search', ['link' => $google_search_response['link']], 2, $token, $user_id, $language[0]);
                            } else {
                                $google_search_message = false;
                            }
                        }
                    }
                }
            }
        }
    }
    if (!sb_is_agent() || $smart_reply) {
        $detected_language = false;
        $repeated_intent = false;

        // Language detection
        if ($sb_recursion_dialogflow[3] && (sb_get_multi_setting('dialogflow-language-detection', 'dialogflow-language-detection-active') || sb_get_multi_setting('google', 'google-language-detection')) && (($unknow_answer || !$user_language) && count(sb_db_get('SELECT id FROM sb_messages WHERE user_id = ' . $user_id . ' LIMIT 3', false)) < 3)) { // Deprecated: sb_get_multi_setting('dialogflow-language-detection', 'dialogflow-language-detection-active')
            $sb_recursion_dialogflow[3] = false;
            $detected_language = sb_google_language_detection($message, $token);
            if (!empty($detected_language) && ($detected_language != $language[0] || ($user_language && $detected_language != $user_language))) {
                $dialogflow_agent = sb_dialogflow_get_agent();
                sb_language_detection_db($user_id, $detected_language);
                $user_language = $detected_language;
                $response_message['queryResult']['action'] = 'sb-language-detection';
                $response_message['event'] = 'update-user';
                if ($detected_language != $language[0] && ($detected_language == sb_isset($dialogflow_agent, 'defaultLanguageCode') || in_array($detected_language, sb_isset($dialogflow_agent, 'supportedLanguageCodes', [])))) {
                    return sb_dialogflow_message($conversation_id, $message, $token, [$detected_language, 'language-detection'], $attachments, $event);
                } else if (!$multilingual_translation) {
                    $unknow_language_message = true;
                } else {
                    $event = 'translations';
                }
            }
        }

        // Repeated Intent
        if ($conversation_id && !$smart_reply && !$unknow_answer && sb_get_multi_setting('open-ai', 'open-ai-active') && isset($response['queryResult']) && isset($response['queryResult']['intent'])) {
            $previous_message_payload = json_decode(sb_isset(sb_get_last_message($conversation_id, false, $bot_id), 'payload'), true);
            $repeated_intent = $previous_message_payload && isset($previous_message_payload['queryResult']) && isset($previous_message_payload['queryResult']['intent']) && $previous_message_payload['queryResult']['intent']['name'] == $response['queryResult']['intent']['name'];
        }

        if ($unknow_answer || $repeated_intent) {

            // Multilingual and translations
            if ($sb_recursion_dialogflow[4] && $multilingual_translation && !$repeated_intent) {
                $sb_recursion_dialogflow[4] = false;
                if (empty($GLOBALS['dialogflow_languages'])) {
                    $dialogflow_agent = $dialogflow_agent ? $dialogflow_agent : sb_dialogflow_get_agent();
                    $lang = sb_isset($dialogflow_agent, 'defaultLanguageCode', $language[0]);
                } else {
                    $lang = $GLOBALS['dialogflow_languages'][0];
                }
                $message_translated = sb_google_translate([$message], $lang, $token);
                if (!empty($message_translated[0])) {
                    return sb_dialogflow_message($conversation_id, $message_translated[0][0], $token, [$language[0], 'language-translation'], $attachments, $event);
                }
            }

            // OpenAI
            if ($message_length > 4 && sb_get_multi_setting('open-ai', 'open-ai-active')) {
                if ($conversation_id && !$smart_reply) {
                    $is_human_takeover = sb_dialogflow_is_human_takeover($conversation_id);
                }
                if (!$is_human_takeover || !$conversation_id) {
                    $extra = [];
                    if ($multilingual && !$multilingual_translation) {
                        $extra['language'] = $user_language ? $user_language : sb_get_user_language($user_id);
                    }
                    if ($smart_reply) {
                        $extra['smart_reply'] = true;
                    }
                    $response_open_ai = sb_open_ai_message($message, false, false, $conversation_id, $extra);
                    if (!sb_is_error($response_open_ai) && $response_open_ai[0]) {
                        $unknow_answer = false;
                        $messages = [['text' => ['text' => [$response_open_ai[1]]]]];
                        $response = ['dialogflow' => $response, 'openai' => $response_open_ai];
                    }
                }
            }
            if ($unknow_answer && $unknow_language_message) {
                $language_detection_message = sb_get_multi_setting('google', 'google-language-detection-message', sb_get_multi_setting('dialogflow-language-detection', 'dialogflow-language-detection-message')); // Deprecated: sb_get_multi_setting('dialogflow-language-detection', 'dialogflow-language-detection-message')
                if (!empty($language_detection_message) && $conversation_id && $detected_language) {
                    $language_name = sb_google_get_language_name($detected_language);
                    $language_detection_message = str_replace('{language_name}', $language_name, sb_t($language_detection_message, $detected_language));
                    $message_id = sb_send_message($bot_id, $conversation_id, $language_detection_message)['id'];
                    return ['token' => $token, 'messages' => [['message' => $language_detection_message, 'attachments' => [], 'payload' => ['language_detection' => true], 'id' => $message_id]], 'response' => $response, 'language_detection_message' => $language_detection_message, 'message_id' => $message_id, 'user_language' => $user_language];
                }
            }
        }
    }

    $count = count($messages);
    $is_assistant = true;
    if (is_string($response)) {
        return ['response' => $response];
    }
    $response['outputAudio'] = '';
    for ($i = 0; $i < $count; $i++) {
        if (isset($messages[$i]['text']) && $messages[$i]['text']['text'][0]) {
            $is_assistant = false;
            break;
        }
    }
    for ($i = 0; $i < $count; $i++) {
        $bot_message = '';

        // Payload
        $payload = sb_isset($messages[$i], 'payload');
        if ($payload && $conversation_id && !$smart_reply) {
            if (isset($payload['redirect'])) {
                $payload['redirect'] = sb_dialogflow_merge_fields($payload['redirect'], $parameters, $language[0]);
            }
            if (isset($payload['archive-chat'])) {
                sb_update_conversation_status($conversation_id, 3);
                if (sb_get_multi_setting('close-message', 'close-active')) {
                    sb_close_message($conversation_id, $bot_id);
                }
                if (sb_get_multi_setting('close-message', 'close-transcript') && sb_isset(sb_get_active_user(), 'email')) {
                    $transcript = sb_transcript($conversation_id);
                    sb_email_create(sb_get_active_user_ID(), sb_get_user_name(), sb_isset(sb_get_active_user(), 'profile_image'), sb_get_multi_setting('transcript', 'transcript-message', ''), [[$transcript, $transcript]], true, $conversation_id);
                    $payload['force-message'] = true;
                }
            }
            if (isset($payload['update-user-details']) || isset($payload['update-user-language'])) {
                $payload_user_details = sb_isset($payload, 'update-user-details', []);
                $user = sb_get_user($user_id);
                if (!sb_is_agent($user)) {
                    if (isset($payload['update-user-language'])) {
                        $language_code = $payload['update-user-language'];
                        $language_codes = json_decode(file_get_contents(SB_PATH . '/resources/languages/language-codes.json'), true);
                        if (strlen($language_code) > 2) {
                            $language_code = ucfirst($language_code);
                            foreach ($language_codes as $key => $value) {
                                if ($language_code == $value) {
                                    $language_code = $key;
                                    break;
                                }
                            }
                            if (strlen($language_code) > 2) {
                                $language_code = sb_google_translate([$language_code], 'en', $token);
                                if (!empty($language_code[0])) {
                                    foreach ($language_codes as $key => $value) {
                                        if ($language_code[0][0] == $value) {
                                            $language_code = $key;
                                            break;
                                        }
                                    }
                                }
                            }
                        }
                        if (is_string($language_code) && strlen($language_code) == 2 && isset($language_codes[$language_code])) {
                            $payload_user_details['extra'] = ['language' => [$language_code, 'Language'], 'browser_language' => ['', 'Browser language']];
                            $user_language = $language_code;
                            if ($multilingual) {
                                $dialogflow_agent = sb_dialogflow_get_agent();
                                if ($language_code == sb_isset($dialogflow_agent, 'defaultLanguageCode') || in_array($language_code, sb_isset($dialogflow_agent, 'supportedLanguageCodes', []))) {
                                    $response_success['language_detection'] = $language_code;
                                }
                            }
                        } else {
                            return false;
                        }
                    }
                    $response_message['event'] = 'update-user';
                    $user['user_type'] = '';
                    sb_update_user($user_id, array_merge($user, $payload_user_details), sb_isset($payload_user_details, 'extra', []));
                }
            }
        }

        // Google Assistant
        if ($is_assistant) {
            if (isset($messages[$i]['platform']) && $messages[$i]['platform'] == 'ACTIONS_ON_GOOGLE') {
                if (isset($messages[$i]['simpleResponses']) && isset($messages[$i]['simpleResponses']['simpleResponses'])) {
                    $item = $messages[$i]['simpleResponses']['simpleResponses'];
                    if (isset($item[0]['textToSpeech'])) {
                        $bot_message = $item[0]['textToSpeech'];
                    } else if ($item[0]['displayText']) {
                        $bot_message = $item[0]['displayText'];
                    }
                }
            }
        } else if (isset($messages[$i]['text'])) {

            // Message
            $bot_message = $messages[$i]['text']['text'][0];
        }

        // Attachments
        $attachments = [];
        if ($payload) {
            if (isset($payload['attachments'])) {
                $attachments = $payload['attachments'];
                if (!$attachments && !is_array($attachments)) {
                    $attachments = [];
                }
            }
        }

        // WooCommerce
        if (defined('SB_WOOCOMMERCE')) {
            $woocommerce = sb_woocommerce_dialogflow_process_message($bot_message, $payload);
            $bot_message = $woocommerce[0];
            $payload = $woocommerce[1];
        }

        // Send message and human takeover
        if ($bot_message || $payload) {
            if ($conversation_id && !$smart_reply) {
                $is_human_takeover = sb_dialogflow_is_human_takeover($conversation_id);
                if ($human_takeover && $unknow_answer && strlen($message) > 3 && strpos($message, ' ') && !sb_dialogflow_is_human_takeover($conversation_id)) {
                    $human_takeover_response = sb_chatbot_human_takeover($conversation_id, $human_takeover);
                    if ($human_takeover_response[1]) {
                        $response_success['human_takeover'] = true;
                    }
                    $results = array_merge($results, $human_takeover_response[0]);
                } else {
                    $last_agent = sb_isset(sb_get_last_agent_in_conversation($conversation_id), 'id');
                    if ($is_human_takeover && (isset($payload['human-takeover']) || strpos($bot_message, 'sb-human-takeover'))) {
                        $bot_message = sb_isset($human_takeover, 'dialogflow-human-takeover-message-fallback');
                        $payload = false;
                    }
                    if (($bot_message || $payload) && (!$is_human_takeover || !empty($payload['force-message']) || ((!$last_agent || !sb_is_user_online($last_agent)) && !$unknow_answer))) {
                        if (!$bot_message && isset($payload['force-message']) && $i > 0 && isset($messages[$i - 1]['text'])) {
                            $bot_message = $messages[$i - 1]['text']['text'][0];
                        }
                        $bot_message = sb_dialogflow_merge_fields($bot_message, $parameters, $language[0]);
                        if ($multilingual_translation && $bot_message) {
                            $continue = isset($language[1]) && $language[1] == 'language-translation';
                            $user_language = $user_language ? $user_language : sb_get_user_language($user_id);
                            if (!$continue) {
                                $dialogflow_agent = $dialogflow_agent ? $dialogflow_agent : sb_dialogflow_get_agent();
                                $continue = $user_language != sb_isset($dialogflow_agent, 'defaultLanguageCode') && !in_array($user_language, sb_isset($dialogflow_agent, 'supportedLanguageCodes', []));
                            }
                            if ($continue) {
                                $message = sb_google_translate([$bot_message], $user_language, $token);
                                if (!empty($message[0])) {
                                    $bot_message = $message[0][0];
                                }
                            }
                        }
                        $bot_message = sb_open_ai_text_formatting($bot_message);
                        $message_id = sb_send_message($bot_id, $conversation_id, $bot_message, $attachments, -1, $response_message)['id'];
                        array_push($results, ['message' => sb_open_ai_text_formatting($bot_message), 'attachments' => $attachments, 'payload' => $payload, 'id' => $message_id]);
                    }
                }
            } else {
                array_push($results, ['message' => sb_dialogflow_merge_fields($bot_message, $parameters, $language[0]), 'attachments' => $attachments, 'payload' => $payload]);
            }
        }
    }
    if (count($results)) {
        $response_success['token'] = $token;
        $response_success['messages'] = $results;
        $response_success['response'] = $response;
        $response_success['user_language'] = $user_language;
        $response_success['message_language'] = $language[0];
        $response_success['translations'] = $translations;
        return $response_success;
    }
    if (isset($response['error']) && sb_isset($response['error'], 'code') != 400) {
        $admin_emails = sb_db_get('SELECT email FROM sb_users WHERE user_type = "admin"', false);
        $admin_emails_string = '';
        for ($i = 0; $i < count($admin_emails); $i++) {
            $admin_emails_string .= $admin_emails[$i]['email'] . ',';
        }
        $text = 'Dialogflow Error | ' . SB_URL . '/admin.php';
        sb_email_send(substr($admin_emails_string, 0, -1), $text, $text . '<br><br>' . json_encode($response));
    }
    return ['response' => $response];
}

/*
 * -----------------------------------------------------------
 * INTENTS
 * -----------------------------------------------------------
 *
 * 1. Create an Intent
 * 2. Update an existing Intent
 * 3. Create multiple Intents
 * 4. Delete multiple Intents
 * 5. Return all Intents
 *
 */

function sb_dialogflow_create_intent($training_phrases, $bot_responses, $language = '', $conversation_id = false, $services = false) {
    global $sb_entity_types;
    $training_phrases_api = [];
    $cx = sb_get_multi_setting('google', 'dialogflow-edition', sb_get_setting('dialogflow-edition')) == 'cx'; // Deprecated: sb_get_setting('dialogflow-edition')
    $sb_entity_types = $cx ? ($sb_entity_types ? $sb_entity_types : sb_isset(sb_dialogflow_curl('/entityTypes', '', false, 'GET'), 'entityTypes', [])) : false;
    $parameters = [];

    // Training phrases and parameters
    if (is_string($bot_responses)) {
        $bot_responses = [['text' => ['text' => $bot_responses]]];
    }
    for ($i = 0; $i < count($training_phrases); $i++) {
        if (is_string($training_phrases[$i])) {
            $parts = ['text' => $training_phrases[$i]];
        } else {
            $parts = $training_phrases[$i]['parts'];
            for ($j = 0; $j < count($parts); $j++) {
                if (empty($parts[$j]['text'])) {
                    array_splice($parts, $j, 1);
                } else if ($cx && isset($parts[$j]['entityType'])) {
                    for ($y = 0; $y < count($sb_entity_types); $y++) {
                        if ($sb_entity_types[$y]['displayName'] == $parts[$j]['alias']) {
                            $id = 'parameter_id_' . $y;
                            $parts[$j]['parameterId'] = $id;
                            $new = true;
                            for ($k = 0; $k < count($parameters); $k++) {
                                if ($parameters[$k]['id'] == $id) {
                                    $new = false;
                                    break;
                                }
                            }
                            if ($new) {
                                array_push($parameters, ['id' => $id, 'entityType' => $sb_entity_types[$y]['name']]);
                            }
                            break;
                        }
                    }
                }
            }
        }
        array_push($training_phrases_api, ['type' => 'TYPE_UNSPECIFIED', 'parts' => $parts, 'repeatCount' => 1]);
    }

    // Intent name
    $name = sb_isset($training_phrases_api[0]['parts'], 'text');
    if (!$name) {
        $parts = $training_phrases_api[0]['parts'];
        for ($i = 0; $i < count($parts); $i++) {
            $name .= $parts[$i]['text'];
        }
    }

    // Create the Intent
    $query = ['displayName' => ucfirst(str_replace('-', ' ', sb_string_slug(strlen($name) > 100 ? substr($name, 0, 99) : $name))), 'priority' => 500000, 'webhookState' => 'WEBHOOK_STATE_UNSPECIFIED', 'trainingPhrases' => $training_phrases_api, 'messages' => $bot_responses];
    if ($parameters) {
        $query['parameters'] = $parameters;
    }
    $response = sb_dialogflow_curl('/agent/intents', $query, $language);
    if ($cx) {
        $flow_name = '00000000-0000-0000-0000-000000000000';
        if ($conversation_id) {
            $messages = sb_db_get('SELECT payload FROM sb_messages WHERE conversation_id = ' . sb_db_escape($conversation_id, true) . ' AND payload <> "" ORDER BY id DESC');
            for ($i = 0; $i < count($messages); $i++) {
                $payload = json_decode($messages['payload'], true);
                if (isset($payload['queryResult']) && isset($payload['queryResult']['currentPage'])) {
                    $flow_name = $payload['queryResult']['currentPage'];
                    $flow_name = substr($flow_name, strpos($flow_name, '/flows/') + 7);
                    if (strpos($flow_name, '/'))
                        $flow_name = substr($flow_name, 0, strpos($flow_name, '/'));
                    break;
                }
            }
        }
        $flow = sb_dialogflow_curl('/flows/' . $flow_name, '', $language, 'GET');
        array_push($flow['transitionRoutes'], ['intent' => $response['name'], 'triggerFulfillment' => ['messages' => $bot_responses]]);
        $response = sb_dialogflow_curl('/flows/' . $flow_name . '?updateMask=transitionRoutes', $flow, $language, 'PATCH');
    }
    $response['response_open_ai'] = $services != 'dialogflow' && sb_chatbot_active(false, true) ? sb_embeddings_database([[$training_phrases[0], $bot_responses[0]['text']['text']]], $language) : true;
    if (isset($response['displayName']) && $response['response_open_ai']) {
        return true;
    }
    return $response;
}

function sb_dialogflow_update_intent($intent, $training_phrases, $language = '', $services = false) {
    $intent_name = is_string($intent) ? $intent : $intent['name'];
    $pos = strpos($intent_name, '/intents/');
    $intent_name = $pos ? substr($intent_name, $pos + 9) : $intent_name;
    if (is_string($intent)) {
        $intent = sb_dialogflow_get_intents($intent_name, $language);
    }
    if (!isset($intent['trainingPhrases'])) {
        $intent['trainingPhrases'] = [];
    }
    for ($i = 0; $i < count($training_phrases); $i++) {
        array_push($intent['trainingPhrases'], ['type' => 'TYPE_UNSPECIFIED', 'parts' => ['text' => $training_phrases[$i]], 'repeatCount' => 1]);
    }
    $response = sb_dialogflow_curl('/agent/intents/' . $intent_name . '?updateMask=trainingPhrases', $intent, $language, 'PATCH');
    if ($services != 'dialogflow' && sb_chatbot_active(false, true)) {
        $response['response_open_ai'] = sb_embeddings_database([[$training_phrases[0], $services]], $language);
    }
    return isset($response['name']) ? true : $response;
}

function sb_dialogflow_batch_intents($intents, $language = '') {
    if (sb_get_multi_setting('google', 'dialogflow-edition', sb_get_setting('dialogflow-edition')) == 'cx') { // Deprecated: sb_get_setting('dialogflow-edition', 'es')
        $response = [];
        for ($i = 0; $i < count($intents); $i++) {
            array_push($response, sb_dialogflow_create_intent($intents[$i]->data['trainingPhrases'], $intents[$i]->data['messages'], $language));
        }
        return $response;
    } else {
        $intents_array = [];
        for ($i = 0; $i < count($intents); $i++) {
            array_push($intents_array, $intents[$i]->data());
        }
        $query = ['intentBatchInline' => ['intents' => $intents_array], 'intentView' => 'INTENT_VIEW_UNSPECIFIED'];
        if (!empty($language))
            $query['languageCode'] = $language;
        return sb_dialogflow_curl('/agent/intents:batchUpdate', $query);
    }
}

function sb_dialogflow_batch_intents_delete($intents) {
    return sb_dialogflow_curl('/agent/intents:batchDelete', ['intents' => $intents]);
}

function sb_dialogflow_get_intents($intent_name = false, $language = '') {
    $next_page_token = true;
    $paginatad_items = [];
    $intents = [];
    while ($next_page_token) {
        $items = sb_dialogflow_curl($intent_name ? ('/agent/intents/' . $intent_name . '?intentView=INTENT_VIEW_FULL') : ('/agent/intents?pageSize=1000&intentView=INTENT_VIEW_FULL' . ($next_page_token !== true && $next_page_token !== false ? ('&pageToken=' . $next_page_token) : '')), '', $language, 'GET');
        if ($intent_name)
            return $items;
        $next_page_token = sb_isset($items, 'nextPageToken');
        if (sb_is_error($next_page_token))
            die($next_page_token);
        array_push($paginatad_items, sb_isset($items, 'intents'));
    }
    for ($i = 0; $i < count($paginatad_items); $i++) {
        $items = $paginatad_items[$i];
        if ($items) {
            for ($j = 0; $j < count($items); $j++) {
                if (!empty($items[$j]))
                    array_push($intents, $items[$j]);
            }
        }
    }
    return $intents;
}

/*
 * -----------------------------------------------------------
 * ENTITIES
 * -----------------------------------------------------------
 *
 * Create, get, update, delete a Dialogflow entities
 *
 */

function sb_dialogflow_create_entity($entity_name, $values, $language = '') {
    $response = sb_dialogflow_curl('/agent/entityTypes', is_a($values, 'SBDialogflowEntity') ? $values->data() : (new SBDialogflowEntity($entity_name, $values))->data(), $language);
    if (isset($response['displayName'])) {
        return true;
    } else if (isset($response['error']) && sb_isset($response['error'], 'status') == 'FAILED_PRECONDITION') {
        return new SBValidationError('duplicate-dialogflow-entity');
    }
    return $response;
}

function sb_dialogflow_update_entity($entity_id, $values, $entity_name = false, $language = '') {
    $response = sb_dialogflow_curl('/agent/entityTypes/' . $entity_id, is_a($values, 'SBDialogflowEntity') ? $values->data() : (new SBDialogflowEntity($entity_name, $values))->data(), $language, 'PATCH');
    if (isset($response['displayName'])) {
        return true;
    }
    return $response;
}

function sb_dialogflow_get_entity($entity_id = 'all', $language = '') {
    $entities = sb_dialogflow_curl('/agent/entityTypes', '', $language, 'GET');
    if (isset($entities['entityTypes'])) {
        $entities = $entities['entityTypes'];
        if ($entity_id == 'all') {
            return $entities;
        }
        for ($i = 0; $i < count($entities); $i++) {
            if ($entities[$i]['displayName'] == $entity_id) {
                return $entities[$i];
            }
        }
        return new SBValidationError('entity-not-found');
    } else
        return $entities;
}

/*
 * -----------------------------------------------------------
 * MISCELLANEOUS
 * -----------------------------------------------------------
 *
 * 1. Get a fresh Dialogflow access token
 * 2. Convert the Dialogflow merge fields to the final values
 * 3. Activate a context in the active conversation
 * 4. Return the details of a Dialogflow agent
 * 5. Chinese language sanatization
 * 6. Dialogflow curl
 * 7. Human takeover
 * 8. Check if human takeover is active
 * 9. Execute payloads
 * 10. Add Intents to saved replies
 * 11. Check if unknow answer
 * 12. PDF to text
 * 13. Support Board database embedding
 * 14. Check if manual or automatic sync mode
 *
 */

function sb_dialogflow_get_token($database_token = true) {
    if ($database_token) {
        global $dialogflow_token;
        if (!empty($dialogflow_token)) {
            return $dialogflow_token;
        }
        $dialogflow_token = sb_get_external_setting('dialogflow_token');
        if ($dialogflow_token && time() < $dialogflow_token[1]) {
            $dialogflow_token = $dialogflow_token[0];
            return $dialogflow_token;
        }
    }
    $token = sb_get_multi_setting('google', 'google-refresh-token'); // Deprecated: sb_get_multi_setting('dialogflow-sync', 'dialogflow-refresh-token')
    if (empty($token)) {
        return sb_error('dialogflow-refresh-token-not-found', 'sb_open_ai_message');
    }
    $info = sb_google_key();
    $query = '{ refresh_token: "' . $token . '", grant_type: "refresh_token", client_id: "' . $info[0] . '", client_secret: "' . $info[1] . '" }';
    $response = sb_curl('https://accounts.google.com/o/oauth2/token', $query, ['Content-Type: application/json', 'Content-Length: ' . strlen($query)]);
    $token = sb_isset($response, 'access_token');
    if ($token) {
        sb_save_external_setting('dialogflow_token', [$token, time() + $response['expires_in']]);
        $dialogflow_token = $token;
        return $token;
    }
    return json_encode($response);
}

function sb_dialogflow_merge_fields($message, $parameters, $language = '') {
    if (defined('SB_WOOCOMMERCE')) {
        $message = sb_woocommerce_merge_fields($message, $parameters, $language);
    }
    return $message;
}

function sb_dialogflow_set_active_context($context_name, $parameters = [], $life_span = 5, $token = false, $user_id = false, $language = false) {
    if (!sb_get_multi_setting('google', 'dialogflow-active')) {
        return false;
    }
    $language = $language === false ? (sb_get_multi_setting('google', 'google-multilingual') ? sb_get_user_language($user_id) : '') : $language;
    $session_id = $user_id === false ? sb_isset(sb_get_active_user(), 'id', 'sb') : $user_id;
    $parameters = empty($parameters) ? '' : ', "parameters": ' . (is_string($parameters) ? $parameters : json_encode($parameters));
    $query = '{ "queryInput": { "text": { "languageCode": "' . (empty($language) ? 'en' : $language) . '", "text": "sb-trigger-context" }}, "queryParams": { "contexts": [{ "name": "projects/' . sb_get_multi_setting('google', 'google-project-id') . '/agent/sessions/' . $session_id . '/contexts/' . $context_name . '", "lifespanCount": ' . $life_span . $parameters . ' }] }}';
    return sb_dialogflow_curl('/agent/sessions/' . $session_id . ':detectIntent', $query, false, 'POST', $token);
}

function sb_dialogflow_get_agent() {
    return sb_dialogflow_curl('/agent', '', '', 'GET');
}

function sb_dialogflow_language_code($language) {
    return $language == 'zh' ? 'zh-cn' : ($language == 'zt' ? 'zh-tw' : $language);
}

function sb_dialogflow_curl($url_part, $query = '', $language = false, $type = 'POST', $token = false, $project_id = false) {

    // Project ID
    if (!$project_id) {
        $project_id = sb_get_multi_setting('google', 'google-project-id');
        if (empty($project_id)) {
            return sb_error('project-id-not-found', 'sb_dialogflow_curl');
        }
    }

    // Retrive token
    $token = empty($token) || $token == -1 ? sb_dialogflow_get_token() : $token;
    if (sb_is_error($token)) {
        return sb_error('token-error', 'sb_dialogflow_curl');
    }

    // Language
    if (!empty($language)) {
        $language = (strpos($url_part, '?') ? '&' : '?') . 'languageCode=' . $language;
    }

    // Query
    if (!is_string($query)) {
        $query = json_encode($query);
    }

    // Edition and version
    $edition = sb_get_multi_setting('google', 'dialogflow-edition', sb_get_setting('dialogflow-edition', 'es')); // Deprecated: sb_get_setting('dialogflow-edition', 'es')
    $version = 'v2beta1/projects/';
    $cx = $edition == 'cx';
    if ($cx) {
        $version = 'v3beta1/';
        $url_part = str_replace('/agent/', '/', $url_part);
    }

    // Location
    $location = sb_get_multi_setting('google', 'dialogflow-location', sb_get_setting('dialogflow-location', '')); // Deprecated: sb_get_setting('dialogflow-location', '')
    $location_session = $location && !$cx ? '/locations/' . substr($location, 0, -1) : '';

    // Send
    $url = 'https://' . $location . 'dialogflow.googleapis.com/' . $version . $project_id . $location_session . $url_part . $language;
    $response = sb_curl($url, $query, ['Content-Type: application/json', 'Authorization: Bearer ' . $token, 'Content-Length: ' . strlen($query)], $type);
    return $type == 'GET' ? json_decode($response, true) : $response;
}

function sb_dialogflow_human_takeover($conversation_id, $auto_messages = false) {
    $human_takeover = sb_get_setting('dialogflow-human-takeover');
    $conversation_id = sb_db_escape($conversation_id, true);
    $bot_id = sb_get_bot_id();
    $data = sb_db_get('SELECT A.id AS `user_id`, A.email, A.first_name, A.last_name, A.profile_image, B.agent_id, B.department, B.status_code FROM sb_users A, sb_conversations B WHERE A.id = B.user_id AND B.id = ' . $conversation_id);
    $user_id = $data['user_id'];
    $messages = sb_db_get('SELECT A.user_id, A.message, A.attachments, A.creation_time, B.first_name, B.last_name, B.profile_image, B.user_type FROM sb_messages A, sb_users B WHERE A.conversation_id = ' . $conversation_id . ' AND A.user_id = B.id AND A.message <> "' . sb_t($human_takeover['dialogflow-human-takeover-confirm']) . '" AND A.message NOT LIKE "%sb-human-takeover%" AND A.payload NOT LIKE "%human-takeover%" ORDER BY A.id ASC', false);
    $count = count($messages);
    $last_message = $messages[$count - 1]['message'];
    $response = [];
    sb_send_message($bot_id, $conversation_id, '', [], false, ['human-takeover' => true]);
    $GLOBALS['human-takeover-' . $conversation_id] = true;

    // Human takeover message and status code
    $message = sb_t($human_takeover['dialogflow-human-takeover-message-confirmation']);
    if (!empty($message)) {
        $message_id = sb_send_message($bot_id, $conversation_id, $message, [], 2, ['human-takeover-message-confirmation' => true, 'preview' => $last_message])['id'];
        array_push($response, ['message' => $message, 'id' => $message_id]);
    } else if ($data['status_code'] != 2) {
        sb_update_conversation_status($conversation_id, 2);
    }

    // Auto messages
    if ($auto_messages) {
        $auto_messages = ['offline', 'follow_up', 'subscribe'];
        for ($i = 0; $i < count($auto_messages); $i++) {
            $auto_message = $i == 0 || empty($data['email']) ? sb_execute_bot_message($auto_messages[$i], $conversation_id, $last_message) : false;
            if ($auto_message) {
                array_push($response, $auto_message);
            }
        }
    }

    // Notifications
    sb_send_agents_notifications($last_message, str_replace('{T}', sb_get_setting('bot-name', 'Chatbot'), sb_('This message has been sent because {T} does not know the answer to the user\'s question.')), $conversation_id, false, $data, ['email' => sb_email_get_conversation_code($conversation_id, 20, true)]);

    // Slack
    if (defined('SB_SLACK') && sb_get_setting('slack-active')) {
        for ($i = 0; $i < count($messages); $i++) {
            sb_send_slack_message($user_id, sb_get_user_name($messages[$i]), $messages[$i]['profile_image'], $messages[$i]['message'], sb_isset($messages[$i], 'attachments'), $conversation_id);
        }
    }

    return $response;
}

function sb_chatbot_human_takeover($conversation_id, $human_takeover_settings) {
    if ($human_takeover_settings['dialogflow-human-takeover-auto']) {
        $human_takeover_messages = sb_dialogflow_human_takeover($conversation_id);
        $messages = [];
        for ($j = 0; $j < count($human_takeover_messages); $j++) {
            array_push($messages, ['message' => sb_t($human_takeover_messages[$j]['message']), 'attachments' => [], 'payload' => false, 'id' => $human_takeover_messages[$j]['id']]);
        }
        return [$messages, true];
    } else {
        $human_takeover_message = '[chips id="sb-human-takeover" options="' . str_replace(',', '\,', sb_rich_value($human_takeover_settings['dialogflow-human-takeover-confirm'], false)) . ',' . str_replace(',', '\,', sb_rich_value($human_takeover_settings['dialogflow-human-takeover-cancel'], false)) . '" message="' . sb_rich_value($human_takeover_settings['dialogflow-human-takeover-message']) . '"]';
        $message_id = sb_send_message(sb_get_bot_id(), $conversation_id, $human_takeover_message)['id'];
        return [[['message' => $human_takeover_message, 'attachments' => [], 'payload' => false, 'id' => $message_id]], false];
    }
}

function sb_dialogflow_is_human_takeover($conversation_id) {
    $name = 'human-takeover-' . $conversation_id;
    if (isset($GLOBALS[$name])) {
        return $GLOBALS[$name];
    }
    $response = sb_db_get('SELECT COUNT(*) AS `count` FROM sb_messages WHERE payload = "{\"human-takeover\":true}" AND conversation_id = ' . sb_db_escape($conversation_id, true) . ' AND creation_time > "' . gmdate('Y-m-d H:i:s', time() - 864000) . '" LIMIT 1')['count'] > 0;
    $GLOBALS[$name] = $response;
    return $response;
}

function sb_dialogflow_payload($payload, $conversation_id, $message = false, $extra = false) {
    if (isset($payload['agent'])) {
        sb_update_conversation_agent($conversation_id, $payload['agent'], $message);
    }
    if (isset($payload['department'])) {
        sb_update_conversation_department($conversation_id, $payload['department'], $message);
    }
    if (isset($payload['tags'])) {
        sb_tags_update($conversation_id, $payload['tags'], true);
    }
    if (isset($payload['human-takeover']) || isset($payload['disable-bot'])) {
        $messages = sb_dialogflow_human_takeover($conversation_id, $extra && isset($extra['source']));
        $source = sb_isset($extra, 'source');
        if ($source) {
            for ($i = 0; $i < count($messages); $i++) {
                $message = $messages[$i]['message'];
                $attachments = sb_isset($messages[$i], 'attachments', []);
                sb_messaging_platforms_send_message($message, $extra, $messages[$i]['id'], $attachments);
            }
        }
    }
    if (isset($payload['send-email'])) {
        $send_to_active_user = $payload['send-email']['recipient'] == 'active_user';
        sb_email_create($send_to_active_user ? sb_get_active_user_ID() : 'agents', $send_to_active_user ? sb_get_setting('bot-name') : sb_get_user_name(), $send_to_active_user ? sb_get_setting('bot-image') : sb_isset(sb_get_active_user(), 'profile_image'), $payload['send-email']['message'], sb_isset($payload['send-email'], 'attachments'), false, $conversation_id);
    }
    if (isset($payload['redirect']) && $extra) {
        $message_id = sb_send_message(sb_get_bot_id(), $conversation_id, $payload['redirect']);
        sb_messaging_platforms_send_message($payload['redirect'], $extra, $message_id);
    }
    if (isset($payload['transcript']) && $extra) {
        $transcript_url = sb_transcript($conversation_id);
        $attachments = [[$transcript_url, $transcript_url]];
        $message_id = sb_send_message(sb_get_bot_id(), $conversation_id, '', $attachments);
        sb_messaging_platforms_send_message($extra['source'] == 'ig' || $extra['source'] == 'fb' ? '' : $transcript_url, $attachments, $message_id);
    }
    if (isset($payload['rating'])) {
        sb_set_rating(['conversation_id' => $conversation_id, 'agent_id' => sb_isset(sb_get_last_agent_in_conversation($conversation_id), 'id', sb_get_bot_id()), 'user_id' => sb_get_active_user_ID(), 'message' => '', 'rating' => $payload['rating']]);
    }
}

function sb_dialogflow_saved_replies() {
    $settings = sb_get_settings();
    $saved_replies = sb_get_setting('saved-replies', []);
    $intents = sb_dialogflow_get_intents();
    $count = count($saved_replies);
    for ($i = 0; $i < count($intents); $i++) {
        if (isset($intents[$i]['messages'][0]) && isset($intents[$i]['messages'][0]['text']) && isset($intents[$i]['messages'][0]['text']) && isset($intents[$i]['messages'][0]['text']['text'])) {
            $slug = sb_string_slug($intents[$i]['displayName']);
            $existing = false;
            for ($j = 0; $j < $count; $j++) {
                if ($slug == $saved_replies[$j]['reply-name']) {
                    $existing = true;
                    break;
                }
            }
            if (!$existing) {
                array_push($saved_replies, ['reply-name' => $slug, 'reply-text' => $intents[$i]['messages'][0]['text']['text'][0]]);
            }
        }
    }
    $settings['saved-replies'][0] = $saved_replies;
    return sb_save_settings($settings);
}

function sb_dialogflow_is_unknow($dialogflow_response) {
    $dialogflow_response = sb_isset($dialogflow_response, 'response', $dialogflow_response);
    $query_result = sb_isset($dialogflow_response, 'queryResult', []);
    return (sb_isset($query_result, 'action') == 'input.unknown' || (isset($query_result['match']) && $query_result['match']['matchType'] == 'NO_MATCH')) || (sb_get_multi_setting('google', 'dialogflow-confidence') && sb_isset($query_result, 'intentDetectionConfidence') < floatval(sb_get_multi_setting('google', 'dialogflow-confidence'))) || isset($dialogflow_response['error']);
}

function sb_pdf_to_text($path) {
    if (file_exists($path)) {
        require('pdf/autoload.php');
        $parser = new \Smalot\PdfParser\Parser();
        $pdf = $parser->parseFile($path);
        return $pdf->getText();
    }
    return '';
}

function sb_get_sitemap_urls($sitemap_url) {
    $urls = [];
    $xml = sb_get($sitemap_url);
    $sitemap = new SimpleXmlElement($xml);
    foreach ($sitemap->url as $url) {
        $urls[] = strval($url->loc);
    }
    return $urls;
}

function sb_embeddings_database($questions_answers, $language = false, $reset = false) {
    $db_embeddings = $reset ? [] : sb_get_external_setting('embedding-texts', []);
    $db_embeddings_questions = $reset ? [] : array_column($db_embeddings, 0);
    $embeddings = [];

    // Update embeddings and questions
    for ($i = 0; $i < count($questions_answers); $i++) {
        if (!in_array($questions_answers[$i][0], $db_embeddings_questions)) {
            array_push($db_embeddings, [$questions_answers[$i][0], str_replace('..', '.', preg_replace('/\r|\n/', '', $questions_answers[$i][1]), $questions_answers[$i][1]), $language]);
        }
    }
    for ($i = 0; $i < count($db_embeddings); $i++) {
        array_push($embeddings, ['Question: ' . $db_embeddings[$i][0] . PHP_EOL . PHP_EOL . 'Answer: ' . $db_embeddings[$i][1], $db_embeddings[$i][2]]);
    }

    // Delete embeddings of deleted or updated questions
    $path = sb_open_ai_get_embeddings_path();
    $files_names = sb_isset(sb_get_external_setting('embedding-sources'), 'sb-database', []);
    for ($i = 0; $i < count($files_names); $i++) {
        $file_path = $path . 'embeddings-' . $files_names[$i] . '.json';
        if (file_exists($file_path)) {
            $embeddings_file = json_decode(file_get_contents($file_path), true);
            $embeddings_file_final = [];
            $is_updated = false;
            for ($j = 0; $j < count($embeddings_file); $j++) {
                $is_deleted = true;
                for ($y = 0; $y < count($embeddings); $y++) {
                    if ($embeddings_file[$j]['text'] == $embeddings[$y][0]) {
                        $is_deleted = false;
                        break;
                    }
                }
                if (!$is_deleted) {
                    array_push($embeddings_file_final, $embeddings_file[$j]);
                } else {
                    $is_updated = true;
                }
            }
            if ($is_updated) {
                if (count($embeddings_file_final)) {
                    sb_file($file_path, json_encode($embeddings_file_final, JSON_UNESCAPED_UNICODE));
                } else {
                    sb_file_delete($file_path);
                }
            }
        }
    }
    sb_save_external_setting('embedding-texts', $db_embeddings);
    $response = sb_open_ai_embeddings_get($embeddings, 'sb-database');
    return $response[0] ? true : $response;
}

function sb_ai_is_manual_sync($source) {
    switch ($source) {
        case 'google':
            return !sb_is_cloud() || !defined('GOOGLE_CLIENT_ID') || sb_get_multi_setting('google', 'google-sync-mode', 'manual') == 'manual'; // Deprecated: remove default , 'manual'
        case 'open-ai':
            return !sb_is_cloud() || !defined('OPEN_AI_KEY') || sb_get_multi_setting('open-ai', 'open-ai-sync-mode', 'manual') == 'manual'; // Deprecated: remove default , 'manual'
    }
    return false;
}

/*
 * -----------------------------------------------------------
 * SMART REPLY
 * -----------------------------------------------------------
 *
 * 1. Return the suggestions
 * 2. Update a smart reply conversation with a new message
 * 3. Generate the conversation transcript data for a dataset
 *
 */

function sb_dialogflow_smart_reply($message, $dialogflow_languages = false, $token = false, $conversation_id = false) {
    $suggestions = [];
    $smart_reply_response = false;
    if (!empty($dialogflow_languages)) {
        $GLOBALS['dialogflow_languages'] = $dialogflow_languages;
    }
    $token = empty($token) ? sb_dialogflow_get_token() : $token;
    $dialogflow_active = sb_chatbot_active(true, false);
    $messages = $dialogflow_active ? sb_dialogflow_message($conversation_id, $message, $token, false, [], 'smart-reply') : [];
    if (sb_is_error($messages)) {
        return sb_error('smart-reply-error', 'sb_dialogflow_smart_reply', $messages);
    }
    if (!empty($messages['messages']) && !sb_dialogflow_is_unknow($messages['response'])) {
        for ($i = 0; $i < count($messages['messages']); $i++) {
            $value = $messages['messages'][$i]['message'];
            if (!empty($value) && !strpos($value, 'sb-human-takeover')) {
                array_push($suggestions, $value);
            }
        }
        if ($messages['message_language'] != sb_get_user_language(sb_get_active_user_ID()) && sb_get_multi_setting('google', 'google-multilingual-translation')) {
            $translation = sb_google_translate($suggestions, sb_get_user_language(sb_get_active_user_ID()));
            if (!empty($translation[0])) {
                for ($i = 0; $i < count($suggestions); $i++) {
                    if (!empty($translation[0][$i])) {
                        $suggestions[$i] = $translation[0][$i];
                    }
                }
            }
        }
    }
    if (!count($suggestions) && !$dialogflow_active && (sb_get_multi_setting('open-ai', 'open-ai-active') || sb_get_multi_setting('open-ai', 'open-ai-smart-reply'))) {
        $suggestions = sb_isset(sb_open_ai_smart_reply($message, $conversation_id), 'suggestions', []);
    }
    return ['suggestions' => $suggestions, 'token' => sb_isset($messages, 'token'), 'dialogflow_languages' => $dialogflow_languages, 'smart_reply' => $smart_reply_response];
}

function sb_dialogflow_knowledge_articles($articles = false, $language = false) {
    $language = $language ? sb_dialogflow_language_code($language) : false;
    if (sb_isset(sb_dialogflow_get_agent(), 'defaultLanguageCode') != 'en') {
        return 'dialogflow-language-not-supported';
    }
    if (!$articles) {
        $articles = sb_get_articles(-1, false, true, false, 'all');
        $articles = $articles[0];
    }
    if ($articles) {

        // Create articles file
        $faq = [];
        for ($i = 0; $i < count($articles); $i++) {
            $content = strip_tags($articles[$i]['content']);
            if (mb_strlen($content) > 150) {
                $content = mb_substr($content, 0, 150);
                $content = mb_substr($content, 0, mb_strrpos($content, ' ') + 1) . '... [button link="#article-' . $articles[$i]['id'] . '" name="' . sb_('Read more') . '" style="link"]';
                $content = str_replace(', ...', '...', $content);
            }
            array_push($faq, [$articles[$i]['title'], $content]);
        }
        $file_path = sb_csv($faq, false, 'dialogflow-faq', false);
        $file = fopen($file_path, 'r');
        $file_bytes = fread($file, filesize($file_path));
        fclose($file);
        unlink($file_path);

        // Create new knowledge if not exist
        $knowledge_base_name = sb_get_external_setting('dialogflow-knowledge', []);
        if (!isset($knowledge_base_name[$language ? $language : 'default'])) {
            $query = ['displayName' => 'Support Board'];
            if ($language) {
                $query['languageCode'] = $language;
            }
            $name = sb_isset(sb_dialogflow_curl('/knowledgeBases', $query, false, 'POST'), 'name');
            $name = substr($name, strripos($name, '/') + 1);
            $knowledge_base_name[$language ? $language : 'default'] = $name;
            sb_save_external_setting('dialogflow-knowledge', $knowledge_base_name);
            $knowledge_base_name = $name;
        } else {
            $knowledge_base_name = $knowledge_base_name['default'];
        }

        // Save knowledge in Dialogflow
        $documents = sb_isset(sb_dialogflow_curl('/knowledgeBases/' . $knowledge_base_name . '/documents', '', false, 'GET'), 'documents', []);
        for ($i = 0; $i < count($documents); $i++) {
            $name = $documents[0]['name'];
            $response = sb_dialogflow_curl(substr($name, stripos($name, 'knowledgeBases/') - 1), '', false, 'DELETE');
        }
        $response = sb_dialogflow_curl('/knowledgeBases/' . $knowledge_base_name . '/documents', ['displayName' => 'Support Board', 'mimeType' => 'text/csv', 'knowledgeTypes' => ['FAQ'], 'rawContent' => base64_encode($file_bytes)], false, 'POST');
        if ($response && isset($response['error']) && sb_isset($response['error'], 'status') == 'NOT_FOUND') {
            sb_save_external_setting('dialogflow-knowledge', false);
            return false;
        }
    }
    return true;
}

/*
 * -----------------------------------------------------------
 * OPEN AI
 * -----------------------------------------------------------
 *
 * 1. OpenAI curl
 * 2. Send a message and returns the OpenAI reply
 * 3. Generate Dialogflow user expressions
 * 4. Generate user expressions for every Dialogflow Intent and update the Dialogflow agent
 * 5. Generate the smart replies
 * 6. Spelling correction
 * 7. Remove auto generated AI texts
 * 8. Check if the message returned by OpenAI is valid
 * 9. Upload a file to OpenAI
 * 10. Embedding functions
 * 11. PDF or TEXT file to paragraphs
 * 12. Get the default gpt model
 * 13. Support Board articles embedding
 * 14. Delete training files
 * 15. Get embeddings
 * 16. Send an audio file to OpenAI and return it's transcription
 * 17. Return the OpenAI key
 * 18. OpenAI Assistant
 * 19. AI data scraper
 *
 */

function sb_open_ai_curl($url_part, $post_fields = [], $type = 'POST') {
    return sb_curl('https://api.openai.com/v1/' . $url_part, json_encode($post_fields, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE), ['Content-Type: application/json', 'Authorization: Bearer ' . sb_open_ai_key()], $type, 30);
}

function sb_open_ai_message($message, $max_tokens = false, $model = false, $conversation_id = false, $extra = false, $audio = false) {
    $language = strtolower(sb_isset($extra, 'language'));
    if ($audio) {
        $message = sb_open_ai_audio_to_text($audio, $language, sb_isset($extra, 'user_id'), false, $conversation_id);
    }
    if (empty($message)) {
        return [true, false, false, false];
    }
    if (sb_is_cloud()) {
        sb_cloud_membership_validation(true);
        if (!sb_cloud_membership_has_credits('open-ai')) {
            return sb_error('no-credits', 'sb_open_ai_message');
        }
    }
    $settings = sb_get_setting('open-ai');
    $response = false;
    $dialogflow_active = sb_chatbot_active(true, false);
    $token = sb_isset($extra, 'token');
    $human_takeover = false;
    $human_takeover_settings = sb_get_setting('dialogflow-human-takeover');
    $human_takeover_active = $human_takeover_settings['dialogflow-human-takeover-active'];
    $payload = false;
    $is_embeddings = $extra == 'embeddings';
    $is_rewrite = $extra == 'rewrite';
    $is_smart_reply = sb_isset($extra, 'smart_reply');
    $unknow_answer = false;
    $open_ai_mode = sb_isset($settings, 'open-ai-mode');
    if ($token == 'false') {
        $token = false;
    }
    if (!$dialogflow_active) {
        $is_human_takeover = !$is_smart_reply && $conversation_id && sb_dialogflow_is_human_takeover($conversation_id);
        if ($is_human_takeover) {
            return [true, false, $token, false];
        }

        // Human takeover messaging apps
        if ($extra == 'messaging-app' && $human_takeover_active && !$is_smart_reply) {
            $button_confirm = sb_rich_value($human_takeover_settings['dialogflow-human-takeover-confirm'], false) == $message;
            if ($button_confirm || sb_rich_value($human_takeover_settings['dialogflow-human-takeover-cancel'], false) == $message) {
                $last_messages = sb_db_get('SELECT message, payload FROM sb_messages WHERE conversation_id = ' . sb_db_escape($conversation_id, true) . ' ORDER BY id DESC LIMIT 2', false);
                if ($last_messages && count($last_messages) > 1 && strpos($last_messages[1]['message'] . $last_messages[1]['payload'], 'sb-human-takeover')) {
                    return [true, $button_confirm ? $is_human_takeover : false, false, $button_confirm];
                }
            }
        }

        // Multilingual
        if (!$is_embeddings && !$is_rewrite) {
            $multilingual_translation = sb_get_setting('dialogflow-multilingual-translation') || sb_get_multi_setting('google', 'google-multilingual-translation'); // Depreacted: sb_get_setting('dialogflow-multilingual-translation')
            if (sb_get_setting('dialogflow-multilingual') || sb_get_multi_setting('google', 'google-multilingual') || sb_get_setting('google-translation') || sb_get_multi_setting('google', 'google-translation') || $multilingual_translation || !empty($settings['open-ai-multlilingual-sources'])) { // Depreacted: sb_get_setting('dialogflow-multilingual') + sb_get_setting('google-translation')
                $user_id = sb_isset($extra, 'user_id', sb_get_active_user_ID());
                if (!$language) {
                    if ((sb_get_multi_setting('dialogflow-language-detection', 'dialogflow-language-detection-active') || sb_get_multi_setting('google', 'google-language-detection')) && strlen($message) > 2) { // Deprecated: sb_get_multi_setting('dialogflow-language-detection', 'dialogflow-language-detection-active')
                        $language = sb_get_user_extra($user_id, 'language');
                        if (!$language) {
                            $language = sb_google_language_detection($message, $token);
                            if ($language) {
                                sb_language_detection_db($user_id, $language);
                                $payload = ['event' => 'update-user'];
                            }
                        }
                    } else {
                        $language = sb_get_user_language($user_id);
                    }
                }
            }
        }
    }

    // Assistant
    if ($open_ai_mode == 'assistant') {
        if ($conversation_id) {
            $response = sb_open_ai_assistant($message, $conversation_id, !$is_smart_reply && !$is_rewrite);
        } else {
            $open_ai_mode = '';
        }
    }

    // Embeddings
    if (!$is_embeddings && !$is_rewrite && !$response && in_array($open_ai_mode, ['sources', 'all'])) {
        if (!$dialogflow_active && $multilingual_translation) {
            $embedding_language = sb_open_ai_embeddings_language();
            if ($embedding_language && $embedding_language != $language) {
                $translation = sb_google_translate([$message], $embedding_language, $token);
                if (!empty($translation[0])) {
                    $message = $translation[0][0];
                }
                $response = sb_open_ai_embeddings_message($message);
                if ($response) {
                    $translation = sb_google_translate([$response], $language, $token);
                    if (!empty($translation[0])) {
                        $response = $translation[0][0];
                    }
                }
            } else {
                $response = sb_open_ai_embeddings_message($message, 0.7, $language, ['conversation_id' => $conversation_id]);
            }
        } else {
            $response = sb_open_ai_embeddings_message($message, 0.7, $language, ['conversation_id' => $conversation_id]);
        }
    }

    // General questions
    if (!$response && (in_array($open_ai_mode, ['', 'all']) || $is_embeddings || $is_rewrite)) {
        $model = $model ? $model : sb_isset($settings, 'open-ai-custom-model', sb_isset($settings, 'open-ai-model', 'gpt-3.5-turbo'));
        $max_tokens = intval($max_tokens ? $max_tokens : sb_isset($settings, 'open-ai-tokens', 150));
        $chat_model = $model != 'gpt-3.5-turbo-instruct';
        $query = ['model' => $model, 'temperature' => floatval(sb_isset($settings, 'open-ai-temperature', 1)), 'presence_penalty' => floatval(sb_isset($settings, 'open-ai-presence-penalty', 0)), 'frequency_penalty' => floatval(sb_isset($settings, 'open-ai-frequency-penalty', 0)), 'top_p' => 1];
        $messages = $conversation_id && !sb_isset($settings, 'open-ai-omit-previous-messages') ? sb_db_get('SELECT sb_messages.message, sb_users.user_type FROM sb_messages, sb_users, sb_conversations WHERE sb_messages.conversation_id = ' . sb_db_escape($conversation_id, true) . ' AND sb_messages.conversation_id = sb_conversations.id AND sb_users.id = sb_messages.user_id ORDER BY sb_messages.id ASC', false) : [['message' => $is_embeddings ? $message['user_prompt'] : $message, 'user_type' => 'user']];
        $count = count($messages);
        $prompt = sb_isset($settings, 'open-ai-prompt', $is_embeddings ? 'Provide extensive answers from the given user request. If the answer is not included, say exactly "I don\'t know." and stop after that. If you don\'t understand the request, say exactly "I don\'t know." Refuse to answer any question not about the info. Never break character.' : false);
        $first_message = false;
        $open_ai_length = 0;
        $max_tokens_list = ['gpt-3.5-turbo' => 4097, 'gpt-3.5-turbo-16k' => 16385, 'gpt-3.5-turbo-0125' => 16385, 'gpt-3.5-turbo-1106' => 16385, 'gpt-3.5-turbo-instruct' => 4097, 'gpt-4' => 8192, 'gpt-4-32k' => 32768];
        $open_ai_max_tokens = sb_isset($max_tokens_list, $model);
        if (!$open_ai_max_tokens) {
            foreach ($max_tokens_list as $key => $value) {
                if (strpos($model, $key) !== false) {
                    $open_ai_max_tokens = $value;
                    break;
                }
            }
        }
        if (!empty($settings['open-ai-logit-bias'])) {
            $query['logit_bias'] = json_decode($settings['open-ai-logit-bias'], true);
        }
        $query_messages = $chat_model ? [] : '';
        if ($max_tokens && ($max_tokens != 150 || !$chat_model)) {
            $query['max_tokens'] = $max_tokens;
        }
        if ($prompt) {
            $message_context = is_string($message) ? $message : $message['context'];
            if (strlen($message_context) > 9999) {
                $message_context = substr($message_context, 0, 9999);
            }
            if ($chat_model) {
                $first_message = ['role' => 'system', 'content' => $prompt . ($is_embeddings ? PHP_EOL . PHP_EOL . 'Context: """' . $message_context . '""""' : '')];
            } else {
                $first_message = 'prompt: """' . str_replace(['"', PHP_EOL], ['\'', ' '], $prompt) . '"""' . ($is_embeddings ? PHP_EOL . PHP_EOL . 'Context: """' . $message_context . '""""' : '') . PHP_EOL . PHP_EOL;
            }
            $open_ai_length += strlen($message_context);
        }
        for ($i = $count - 1; $i > -1; $i--) {
            $message_text = $messages[$i]['message'];
            if (intval(($open_ai_length + strlen($message_text)) / 4) < $open_ai_max_tokens && sb_open_ai_is_valid($message_text)) {
                $message_is_agent = sb_is_agent($messages[$i]['user_type']);
                if ($chat_model) {
                    array_unshift($query_messages, ['role' => $message_is_agent ? 'assistant' : 'user', 'content' => $message_text]);
                } else {
                    $query_messages = ($message_is_agent ? 'AI: ' : 'Human: ') . $message_text . PHP_EOL . $query_messages;
                }
                $open_ai_length += strlen($message_text);
            } else {
                break;
            }
        }
        if (empty($query_messages)) {
            return [false, false];
        }
        if ($chat_model) {
            if ($first_message) {
                array_unshift($query_messages, $first_message);
            }
            if (!$is_smart_reply && !$is_rewrite && $human_takeover_active) {
                $query['tools'] = [['type' => 'function', 'function' => ['name' => 'sb-human-takeover', 'description' => 'I want to contact a human support agent or team member. I want human support.', 'parameters' => ['type' => 'object', 'properties' => json_decode('{}'), 'required' => []]]]];
            }
            $query['messages'] = $query_messages;
        } else {
            $query['prompt'] = ($first_message ? $first_message : '') . $query_messages . 'AI: ' . PHP_EOL;
            $query['stop'] = ['Human:', 'AI:'];
        }
        if (isset($extra['query'])) {
            $query = array_merge($query, $extra['query']);
        }

        // OpenAI response
        $response = sb_open_ai_curl($chat_model ? 'chat/completions' : 'completions', $query);
        sb_cloud_membership_use_credits($model, 'open-ai', $max_tokens);
        if ($response && isset($response['choices']) && count($response['choices'])) {
            if (isset($query['n'])) {
                return $response['choices'];
            }
            $function_calling = sb_open_ai_function_calling($response);
            if ($function_calling && $function_calling[0] == 'sb-human-takeover') {
                $response = $function_calling[0];
            } else {
                $response = sb_open_ai_text_formatting($chat_model ? $response['choices'][0]['message']['content'] : $response['choices'][0]['text']);
            }
        } else {
            sb_error('open-ai-error', 'sb_open_ai_message', $response);
            if (isset($response['error'])) {
                return [false, $response];
            } else {
                $response = false;
            }
        }
    }

    // Human takeover
    if (!$is_rewrite) {
        $unknow_answer = !sb_open_ai_is_valid($response);
        if ($is_smart_reply) {
            return $response && !$unknow_answer ? [true, $response] : [false, false];
        }
        if ($dialogflow_active) {
            $is_human_takeover = $conversation_id && sb_dialogflow_is_human_takeover($conversation_id);
        }
        $human_takeover = !$is_embeddings && !$dialogflow_active && $human_takeover_active && $unknow_answer && strlen($message) > 3 && strpos($message, ' ');
        if ($human_takeover && $conversation_id) {
            if (!$is_human_takeover) {
                $human_takeover = sb_chatbot_human_takeover($conversation_id, $human_takeover_settings);
                return [true, $human_takeover[0], $token, $human_takeover[1]];
            }
            return [true, '', $token];
        } else if (!$response) {
            $response = $dialogflow_active || $is_human_takeover || $is_embeddings ? false : sb_t(sb_isset($settings, 'open-ai-fallback-message', 'Sorry, I didn\'t get that. Can you rephrase?'), $language);
        } else if (!$dialogflow_active && $is_human_takeover) {
            $last_agent = sb_isset(sb_get_last_agent_in_conversation($conversation_id), 'id');
            if ($last_agent && (sb_is_user_online($last_agent) || $unknow_answer)) {
                $response = false;
            }
        }
    }

    // Response
    if ($response) {
        if ($conversation_id && !$is_embeddings && !$is_rewrite && !empty($response) && !$dialogflow_active) {
            sb_send_message(sb_get_bot_id(), $conversation_id, $response, [], false, $payload);
            sb_webhooks('SBOpenAIMessage', ['response' => $response, 'message' => $message, 'conversation_id' => $conversation_id]);
        }
        return [true, $response, $token, false];
    }
    return [false, $response];
}

function sb_open_ai_user_expressions($message) {
    $settings = sb_get_setting('open-ai');
    $response = sb_open_ai_curl('chat/completions', ['messages' => [['role' => 'user', 'content' => 'Create a numbered list of minimum 10 variants of this sentence: """' . $message . '""""']], 'model' => sb_open_ai_get_gpt_model(), 'max_tokens' => 200, 'temperature' => floatval(sb_isset($settings, 'open-ai-temperature', 1)), 'presence_penalty' => floatval(sb_isset($settings, 'open-ai-presence-penalty', 0)), 'frequency_penalty' => floatval(sb_isset($settings, 'open-ai-frequency-penalty', 0))]);
    $error = sb_isset($response, 'error');
    $choices = sb_isset($response, 'choices');
    if ($choices) {
        $choices = explode("\n", trim($choices[0]['message']['content']));
        for ($i = 0; $i < count($choices); $i++) {
            $expression = trim($choices[$i]);
            if (in_array(substr($expression, 0, 2), [($i + 1) . '.', ($i + 1) . ')'])) {
                $expression = trim(substr($expression, 2));
            }
            if (substr($expression, 0, 1) === '.') {
                $expression = trim(substr($expression, 1));
            }
            $choices[$i] = $expression;
        }
        return $choices;
    } else if ($error) {
        return sb_error($error['type'], 'sb_open_ai_user_expressions', $error['message']);
    }
    return $response;
}

function sb_open_ai_user_expressions_intents() {
    $intents = sb_dialogflow_get_intents();
    $response = 0;
    $history = sb_get_external_setting('open-ai-intents-history', []);
    for ($i = 0; $i < count($intents); $i++) {
        $intent_name = substr($intents[$i]['name'], strripos($intents[$i]['name'], '/') + 1);
        if (in_array(sb_isset($intents[$i], 'action'), ['input.unknown', 'input.welcome']) || in_array($intent_name, $history)) {
            continue;
        }
        $messages = [];
        $training_phrases = $intents[$i]['trainingPhrases'];
        for ($j = 0; $j < count($training_phrases); $j++) {
            $parts = $training_phrases[$j]['parts'];
            $message = '';
            for ($y = 0; $y < count($parts); $y++) {
                $message .= $parts[$y]['text'];
            }
            array_push($messages, strtolower($message));
        }
        $count = count($messages) > 5 ? 5 : count($messages);
        $user_expressions_final = [];
        for ($j = 0; $j < $count; $j++) {
            if (strlen($messages[$j]) > 5) {
                $user_expressions = sb_open_ai_user_expressions($messages[$j]);
                for ($y = 0; $y < count($user_expressions); $y++) {
                    $expression = $user_expressions[$y];
                    if (!in_array(strtolower($expression), $messages) && strlen($expression) > 4)
                        array_push($user_expressions_final, $expression);
                }
            }
        }
        if (count($user_expressions_final)) {
            if (sb_dialogflow_update_intent($intents[$i], $user_expressions_final) === true) {
                array_push($history, $intent_name);
                sb_save_external_setting('open-ai-intents-history', $history);
            } else
                $response++;
        }
    }
    return $response === 0 ? true : $response;
}

function sb_open_ai_smart_reply($message, $conversation_id) {
    $response = sb_open_ai_message($message, false, sb_open_ai_get_gpt_model(), $conversation_id, ['smart_reply' => true, 'query' => ['n' => 3]]);
    $suggestions = [];
    if (sb_is_error($response) || isset($response[1]) && sb_isset($response[1], 'error')) {
        return sb_error('openai-error', 'sb_open_ai_smart_reply', $response, true);
    }
    for ($i = 0; $i < count($response); $i++) {
        if ($response[$i] && !is_bool($response[$i])) {
            array_push($suggestions, is_string($response[$i]) ? $response[$i] : $response[$i]['message']['content']);
        }
    }
    return ['suggestions' => $suggestions];
}

function sb_open_ai_spelling_correction($message) {
    if (strlen($message) < 2) {
        return $message;
    }
    $message_original = $message;
    $skip = [];
    $text_formatting = [];
    $regexes = [['/`[\S\s]*?`/', 0], ['/\b(?:(?:https?|ftp):\/\/|www\.)[-a-z0-9+&@#\/%?=~_|!:,.;]*[-a-z0-9+&@#\/%=~_|]/i', PREG_PATTERN_ORDER]];
    $index = 0;
    for ($i = 0; $i < count($regexes); $i++) {
        preg_match_all($regexes[$i][0], $message, $skip_sub, $regexes[$i][1]);
        $skip_sub = $skip_sub[0];
        for ($j = 0; $j < count($skip_sub); $j++) {
            $message = str_replace($skip_sub[$j], '{{' . $index . '}}', $message);
            array_push($skip, $skip_sub[$j]);
            $index++;
        }
    }
    if ($message == '{{0}}') {
        return $message_original;
    }
    $regexes = ['/\*(.*?)\*/', '/__(.*?)__/', '/~(.*?)~/', '/```(.*?)```/', '/`(.*?)`/'];
    for ($i = 0; $i < count($regexes); $i++) {
        $values = [];
        if (preg_match_all($regexes[$i], $message, $values)) {
            for ($j = 0; $j < count($values[0]); $j++) {
                $message = str_replace($values[0][$j], $values[1][$j], $message);
                array_push($text_formatting, [$values[0][$j], $values[1][$j]]);
            }
        }
    }
    $shortcode = sb_isset(sb_get_shortcode($message), 'shortcode');
    if ($shortcode) {
        $message = str_replace($shortcode, 'shortcode', $message);
    }
    if ($message && $message != 'shortcode') {
        $response = sb_open_ai_curl('chat/completions', ['model' => sb_open_ai_get_gpt_model(), 'messages' => [['role' => 'user', 'content' => 'Fix the spelling mistakes of the following text, return only the fixed text: """"' . $message . '"""']]]);
        $error = sb_isset($response, 'error');
        if ($response && isset($response['choices']) && count($response['choices'])) {
            $response = $response['choices'][0]['message']['content'];
            $response = sb_open_ai_is_valid($response) && strlen($response) > (strlen($message) * 0.5) ? sb_open_ai_text_formatting($response) : $message;
            if (count($skip) != substr_count($response, '{{')) {
                return $message_original;
            }
            for ($i = 0; $i < count($skip); $i++) {
                $response = str_replace('{{' . $i . '}}', $skip[$i], $response);
            }
            for ($i = 0; $i < count($text_formatting); $i++) {
                $response = str_replace($text_formatting[$i][1], $text_formatting[$i][0], $response);
            }
            return $shortcode ? str_replace('shortcode', $shortcode, $response) : $response;
        } else if ($error) {
            sb_error($error['type'], 'sb_open_ai_spelling_correction', $error['message'], true);
        }
    }
    return $message_original;
}

function sb_open_ai_text_formatting($message) {
    if (strpos($message, '- ')) {
        $rows = preg_split("/\r\n|\n|\r/", $message);
        $message = '';
        $is_list = false;
        for ($i = 0; $i < count($rows); $i++) {
            if ($rows[$i]) {
                if (strpos($rows[$i], '- ') === 0) {
                    $rows[$i] = str_replace(['- **', '**', ',', '"'], ['', '', '\,', '\''], substr($rows[$i], 2)) . ',';
                    if (!$is_list) {
                        $message .= '[list values="' . $rows[$i];
                        $is_list = true;
                    } else {
                        $message .= $rows[$i];
                    }
                } else if ($is_list) {
                    $message = substr($message, 0, -1) . '"]' . $rows[$i] . PHP_EOL;
                    $is_list = false;
                } else {
                    $message .= $rows[$i] . PHP_EOL;
                }
            }
        }
        if ($is_list) {
            $message = substr($message, 0, -1) . '"]';
        }
    }
    $message = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/', '$1 ($2)', $message);
    while (in_array(mb_substr($message, 0, 1), ["\n", "\r", '\\n', '\\', ',', ':', '?', '!', '"', '”', '\''])) {
        $message = mb_substr($message, 1);
    }
    while (in_array(mb_substr($message, -1), ["\n", "\r", '\\n', '\\', ',', ':', '"', '”', '\''])) {
        $message = mb_substr($message, 0, -1);
    }
    if (mb_substr($message, 0, 2) == 'n ') {
        $message = mb_substr($message, 2);
    }
    return trim(str_replace(['The fixed text is:', '(with correct punctuation)', 'Fix: ', 'Fixed: ', 'Corrected text:', 'A:', 'Answer: ', 'Question:', 'Fixed text:'], '', $message));
}

function sb_open_ai_is_valid($message) {
    return $message ? preg_match('/(I don\'t know|not included in the context|sb-human-takeover|provide more context|provide a valid text|What was that|I didn\'t get that|I don\'t understand|no text provided|provide the text|I cannot provide|I don\'t have access|I don\'t have any|As a language model|I do not have the capability|I do not have access|modelo de lenguaje de IA|no tengo acceso|no tinc accés|En tant qu\'IA|je n\'ai pas d\'accès|en tant qu\'intelligence artificielle|je n\'ai pas accès|programme d\'IA|স্মার্ট AI কম্পিউটার প্রোগ্রাম|আমি একটি AI|আমি জানি না|我無法回答未來的活動|AI 語言模型|我無法提供|作為AI|我無法得知|作為一名AI|我無法預測|作为AI|我没有未来预测的功能|作為一個AI|我無法預測未來|作为一个AI|我无法预测|我不具备预测|我作为一个人工智能|Как виртуальный помощник|я не могу предоставить|как AI-ассистента|Как ИИ|Как искусственный интеллект|я не имею доступа|я не могу ответить|я не могу предсказать|como um modelo de linguagem|eu não tenho informações|sou um assistente de linguagem|Não tenho acesso|modelo de idioma de AI|não é capaz de fornecer|não tenho a capacidade|como modelo de linguagem de IA|como uma AI|não tenho um|como modelo de linguagem de inteligência artificial|como modelo de linguagem AI|não sou capaz|poiché sono un modello linguistico|non posso fornire informazioni|in quanto intelligenza artificiale|non ho la capacità|non sono in grado|non ho la possibilità|non posso dare|non posso fare previsioni|non posso predire|in quanto sono un\'Intelligenza Artificiale|Come assistente digitale|come assistente virtuale|Si një AI|nuk mund të parashikoj|Si inteligjencë artificiale|nuk kam informacion|Nuk mund të jap parashikime|nuk mund të parashikoj|لا يمكنني توفير|نموذجًا لغة|لا يمكنني التنبؤ|AI भाषा मॉडल हूँ|मैं एक AI|मुझे इसकी जानकारी नहीं है|मैं आपको बता नहीं सकती|AI सहायक|मेरे पास भविष्य के बारे में कोई जानकारी नहीं है|का पता नहीं है|не мога да|Като AI|не разполагам с|нямам достъп|ne mogu pratiti|Nisam u mogućnosti|nisam sposoban|ne mogu prikazivati|ne mogu ti dati|ne mogu pružiti|nemam pristup|nemam sposobnosti|nemam trenutne informacije|nemam sposobnost|ne mogu s preciznošću|nemůžu předpovídat|nemohu s jistotou|Jako AI|nemohu předpovídat|nemohu s jistotou znát|Jako umělá inteligence|nemám informace|nemohu predikovat|Jako NLP AI|nemohu předvídat|nedokážu předvídat|nemám schopnost|som AI|som en AI|har jeg ikke adgang|Jeg kan desværre ikke besvare|jeg ikke har adgang|kan jeg ikke give|jeg har ikke|har jeg ikke mulighed|Jeg er en AI og har ikke|har jeg ikke evnen|Jeg kan desværre ikke hjælpe med|jeg kan ikke svare|Som sprog AI|jeg ikke i stand)/i', $message) !== 1 : false;
}

function sb_open_ai_upload($path, $post_fields = []) {
    return sb_curl('https://api.openai.com/v1/files', array_merge(['file' => new CurlFile($path, 'application/json')], $post_fields), ['Content-Type: multipart/form-data', 'Authorization: Bearer ' . sb_open_ai_key()], 'UPLOAD', 30);
}

function sb_open_ai_embeddings_get($paragraphs_or_string, $save_source = false) {
    if (is_string($paragraphs_or_string)) {
        if (mb_substr(trim($paragraphs_or_string), 0, 1) == '[') {
            $paragraphs_or_string = json_decode($paragraphs_or_string, true);
        } else {
            $paragraphs_or_string = [[$paragraphs_or_string, false]];
        }
    }
    if (!sb_cloud_membership_has_credits('open-ai')) {
        return sb_error('no-credits', 'sb_open_ai_embeddings_get');
    }
    $paragraphs_or_string_final = $paragraphs_or_string;
    $chars_limit = false;
    $chars_count = 0;
    if ($save_source) {
        $paragraphs_or_string_final = [];
        $path = sb_open_ai_get_embeddings_path();
        $embeddings = sb_open_ai_get_embeddings();
        $embedding_texts = [];
        if (sb_is_cloud()) {
            require_once(SB_CLOUD_PATH . '/account/functions.php');
            $chars_limit = cloud_embeddings_chars_limit();
        }
        for ($i = 0; $i < count($embeddings); $i++) {
            $embedding = $embeddings[$i];
            $texts = array_column(json_decode(file_get_contents($path . $embedding), true), 'text');
            for ($j = 0; $j < count($texts); $j++) {
                $texts[$j] = [$texts[$j], $embedding];
            }
            $embedding_texts = array_merge($embedding_texts, $texts);
        }

        // Remove duplicates and adjust paragraphs
        for ($i = 0; $i < count($paragraphs_or_string); $i++) {
            if (is_string($paragraphs_or_string[$i])) {
                $paragraphs_or_string[$i] = [$paragraphs_or_string[$i], false];
            } else if (isset($paragraphs_or_string[$i][2])) {
                $paragraphs_or_string[$i][0] .= ' More details at ' . $paragraphs_or_string[$i][2] . '.';
            }
            $paragraphs_or_string[$i][0] = trim($paragraphs_or_string[$i][0]);
            $text = $paragraphs_or_string[$i][0];
            $duplicate = false;
            for ($j = 0; $j < count($paragraphs_or_string); $j++) {
                if ($text == trim($paragraphs_or_string[$j][0]) && $j != $i) {
                    $duplicate = true;
                    break;
                }
            }
            if (!$duplicate) {
                for ($j = 0; $j < count($embedding_texts); $j++) {
                    if ($embedding_texts[$j][0] == $text) {
                        $duplicate = true;
                        break;
                    }
                }
                if (!$duplicate) {
                    array_push($paragraphs_or_string_final, $paragraphs_or_string[$i]);
                }
            }
        }
        if ($chars_limit) {
            for ($i = 0; $i < count($paragraphs_or_string_final); $i++) {
                $chars_count += strlen($paragraphs_or_string_final[$i][0]);
            }
            for ($i = 0; $i < count($embedding_texts); $i++) {
                $chars_count += strlen($embedding_texts[$i][0]);
            }
        }
    }
    if (empty($paragraphs_or_string_final)) {
        return [true, []];
    }
    if ($chars_limit && $chars_count > $chars_limit) {
        return [false, 'chars-limit-exceeded', $chars_limit, $chars_count];
    }
    $data_all = [];
    $paragraphs = sb_open_ai_embeddings_split_paragraphs($paragraphs_or_string_final, 0);
    $index = $paragraphs[1];
    $paragraphs = $paragraphs[0];
    $errors = [];

    // Generate embeddings
    while ($paragraphs) {
        $paragraphs_texts = [];
        $paragraphs_languages = [];
        for ($i = 0; $i < count($paragraphs); $i++) {
            array_push($paragraphs_texts, is_string($paragraphs[$i]) ? $paragraphs[$i] : $paragraphs[$i][0]);
            array_push($paragraphs_languages, is_string($paragraphs[$i]) ? '' : (is_string($paragraphs[$i][1]) ? $paragraphs[$i][1] : ''));
        }
        $response = sb_open_ai_curl('embeddings', ['model' => 'text-embedding-ada-002', 'input' => $paragraphs_texts]);
        sb_cloud_membership_use_credits('embedding-3-small', 'open-ai', strlen(implode($paragraphs_texts)) / 4);
        $data = sb_isset($response, 'data');
        if ($data) {
            for ($i = 0; $i < count($data); $i++) {
                $data[$i]['text'] = trim($paragraphs_texts[$i]);
                $data[$i]['language'] = $paragraphs_languages[$i];
                if (isset($paragraphs[$i][2])) {
                    $data[$i]['source'] = $paragraphs[$i][2];
                }
            }
            $data_all = array_merge($data_all, $data);
        } else {
            array_push($errors, $response);
        }
        $paragraphs = sb_open_ai_embeddings_split_paragraphs($paragraphs_or_string_final, $index);
        if (empty($paragraphs[0])) {
            $paragraphs = false;
        } else {
            $index = $paragraphs[1];
            $paragraphs = $paragraphs[0];
        }
    }

    // Save embedding files
    if ($save_source) {
        $len_total = 0;
        $embeddings_part = [];
        $count = count($data_all);
        $response = [];
        $embedding_sources = sb_get_external_setting('embedding-sources', []);
        for ($i = 0; $i < $count; $i++) {
            $len_total += strlen(json_encode($data_all[$i]));
            array_push($embeddings_part, $data_all[$i]);
            if ($len_total > 2000000 || $i == $count - 1) {
                $name = bin2hex(openssl_random_pseudo_bytes(10));
                array_push($response, sb_file($path . 'embeddings-' . $name . '.json', json_encode($embeddings_part, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE)));
                $embeddings_part = [];
                $len_total = 0;
                if (isset($embedding_sources[$save_source])) {
                    if (!in_array($name, $embedding_sources[$save_source])) {
                        array_push($embedding_sources[$save_source], $name);
                    }
                } else {
                    $embedding_sources[$save_source] = [$name];
                }
            }
        }

        // Delete old embedding file references
        $embedding_sources_final = [];
        foreach ($embedding_sources as $key => $file_names) {
            $embedding_source_file_names = [];
            for ($i = 0; $i < count($file_names); $i++) {
                if (file_exists($path . 'embeddings-' . $file_names[$i] . '.json')) {
                    array_push($embedding_source_file_names, $file_names[$i]);
                }
            }
            if (count($embedding_source_file_names)) {
                $embedding_sources_final[$key] = $embedding_source_file_names;
            }
        }
        sb_save_external_setting('embedding-sources', $embedding_sources_final);

        // Delete embeddings of deleted articles
        $count = count(sb_isset($embedding_sources, $save_source, []));
        if ($save_source == 'sb-articles' && $count) {
            for ($i = 0; $i < $count; $i++) {
                $file_name = $path . 'embeddings-' . $embedding_sources[$save_source][$i] . '.json';
                if (file_exists($file_name)) {
                    $article_embeddings = json_decode(file_get_contents($file_name), true);
                    $paragraphs_or_strings_text = array_column($paragraphs_or_string, 0);
                    $is_save = false;
                    for ($j = 0; $j < count($article_embeddings); $j++) {
                        if (!in_array($article_embeddings[$j]['text'], $paragraphs_or_strings_text)) {
                            array_splice($article_embeddings, $j, 1);
                            $j--;
                            $is_save = true;
                        }
                    }
                    if ($is_save && count($article_embeddings)) {
                        sb_file($file_name, json_encode($article_embeddings, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE));
                    }
                }
            }
        }

        //  Miscellaneous
        if (!empty($paragraphs_languages)) {
            sb_save_external_setting('embeddings-language', strtolower(substr($paragraphs_languages[0], 0, 2)));
        }
        if (!file_exists($path . 'index.html')) {
            sb_file($path . 'index.html', 'Forbidden');
        }
        return [$response, $errors];
    }
    return $data_all;
}

function sb_open_ai_embeddings_delete($sources) {
    $embedding_sources = sb_get_external_setting('embedding-sources', []);
    $path = sb_open_ai_get_embeddings_path();
    foreach ($embedding_sources as $key => $files_names) {
        if (!in_array($key, $sources) && $key != 'sb-articles' && $key != 'sb-database') {
            for ($i = 0; $i < count($files_names); $i++) {
                sb_file_delete($path . 'embeddings-' . $files_names[$i] . '.json');
            }
            unset($embedding_sources[$key]);
        }
    }
    sb_save_external_setting('embedding-sources', $embedding_sources);
}

function sb_open_ai_embeddings_split_paragraphs($paragraphs, $last_index) {
    $response = [];
    $len_total = 0;
    $paragraphs_2 = [];
    for ($i = 0; $i < count($paragraphs); $i++) {
        $len = strlen($paragraphs[$i][0]);
        if ($len > 8000) {
            $splits = mb_str_split($paragraphs[$i][0], 8000);
            for ($j = 0; $j < count($splits); $j++) {
                array_push($paragraphs_2, [$splits[$j], $paragraphs[$i][1]]);
            }
        } else {
            array_push($paragraphs_2, $paragraphs[$i]);
        }
    }
    for ($i = $last_index; $i < count($paragraphs_2); $i++) {
        $len = strlen($paragraphs_2[$i][0]);
        if ($len_total + $len < 100000 || !$len_total) {
            array_push($response, $paragraphs_2[$i]);
            $len_total += $len;
            $last_index = $i;
        } else {
            break;
        }
    }
    return [$response, $last_index + 1];
}

function sb_open_ai_embeddings_compare($a, $b) {
    $result = array_map(function ($x, $y) {
        return $x * $y;
    }, $a, $b);
    return array_sum($result);
}

function sb_open_ai_embeddings_message($user_prompt, $min_score = 0.7, $language = false, $extra = false) {
    $user_prompt_embeddings = sb_open_ai_embeddings_get($user_prompt);
    if (!empty($user_prompt_embeddings) && isset($user_prompt_embeddings[0]['embedding'])) {
        $scores = [];
        $user_prompt_embeddings = $user_prompt_embeddings[0]['embedding'];
        $embeddings = sb_open_ai_get_embeddings();
        $path = sb_open_ai_get_embeddings_path();
        $embedding_languages = [];
        if ($language) {
            $language = strtolower($language);
        }
        for ($i = 0; $i < count($embeddings); $i++) {
            $embeddings_content = json_decode(file_get_contents($path . $embeddings[$i]), true);
            for ($j = 0; $j < count($embeddings_content); $j++) {
                $embedding_language = sb_isset($embeddings_content[$j], 'language');
                if ($embedding_language && is_string($embedding_language)) {
                    $embedding_language = substr($embedding_language, 0, 2);
                    if (!in_array($embedding_language, $embedding_languages)) {
                        array_push($embedding_languages, $embedding_language);
                    }
                }
                if (!$language || !$embedding_language || $embedding_language == $language) {
                    $score = !empty($user_prompt_embeddings) && !empty($embeddings_content[$j]['embedding']) ? sb_open_ai_embeddings_compare($user_prompt_embeddings, $embeddings_content[$j]['embedding']) : 0;
                    if ($score > $min_score) {
                        array_push($scores, ['score' => $score, 'text' => $embeddings_content[$j]['text'], 'source' => sb_isset($embeddings_content[$j], 'source')]);
                    }
                }
            }
        }
        $count = count($scores);
        if ($count) {
            usort($scores, function ($a, $b) {
                return $a['score'] <=> $b['score'];
            });
            if ($count > 7) {
                $scores = array_slice($scores, -7);
            }
            $context = '';
            $count = count($scores);
            for ($i = $count - 1; $i > -1; $i--) {
                if (mb_strlen($context) < 4000) {
                    $context .= ($context ? '--------------------------------------------------------------------------------' : '') . str_replace('"', '\'', $scores[$i]['text']);
                }
            }
            $context = trim($context);
            if (mb_strlen($context) > 4000) {
                $context = mb_substr($context, 0, 4000);
            }
            $response = sb_open_ai_message(['context' => $context, 'user_prompt' => $user_prompt], false, false, sb_isset($extra, 'conversation_id'), 'embeddings');
            if ($response) {
                if (empty($response[0])) {
                    sb_error('open-ai-error', 'sb_open_ai_message', sb_isset($response[1], 'error', $response[1]));
                } else {
                    if (sb_open_ai_is_valid($response[1])) {
                        $response = explode("\r\n\r\n", $response[1]);
                        if (count($response) == 1) {
                            $response = explode("\r\n", $response[0]);
                        }
                        $response = sb_open_ai_text_formatting(isset($response[1]) ? $response[1] : $response[0]);
                        if ($extra == 'translation') {
                            $message = sb_google_translate([$response], $language);
                            if (!empty($message[0])) {
                                $response = $message[0][0];
                            }
                        }
                        if (sb_get_multi_setting('open-ai', 'open-ai-source-links')) {
                            $sources_string = '';
                            for ($i = 0; $i < $count; $i++) {
                                $source = sb_isset($scores[$i], 'source');
                                if ($source && strpos($response, $source) === false && strpos($sources_string, $source) === false) {
                                    $sources_string .= $scores[$i]['source'] . ', ';
                                }
                            }
                            if ($sources_string) {
                                $response .= ' ' . sb_('More details at') . ' ' . substr($sources_string, 0, -2) . '.';
                            }
                        }
                        return $response;
                    }
                }
            }
        } else if ($extra != 'translation' && $language && count($embedding_languages) && !in_array($language, $embedding_languages) && (sb_get_setting('dialogflow-multilingual-translation') || sb_get_multi_setting('google', 'google-multilingual-translation'))) { // Deprecated: sb_get_setting('dialogflow-multilingual-translation')
            $message = sb_google_translate([$user_prompt], $embedding_languages[0]);
            if (!empty($message[0])) {
                return sb_open_ai_embeddings_message($message[0][0], 0.7, $embedding_languages[0], 'translation');
            }
        }
    }
    return false;
}

function sb_open_ai_embeddings_language() {
    $embeddings_language = sb_get_external_setting('embeddings-language');
    if ($embeddings_language) {
        return $embeddings_language;
    }
    $embeddings = sb_open_ai_get_embeddings();
    if (count($embeddings)) {
        $embeddings = json_decode(file_get_contents(sb_open_ai_get_embeddings_path() . $embeddings[0]), true);
        $embeddings_language = sb_isset($embeddings[0], 'language');
        if ($embeddings_language && is_string($embeddings_language)) {
            $embeddings_language = strtolower(substr($embeddings_language, 0, 2));
            sb_save_external_setting('embeddings-language', $embeddings_language);
            return $embeddings_language;
        }
    }
    return false;
}

function sb_open_ai_embeddings_articles() {
    $paragraphs = [];
    $articles = sb_get_articles(false, false, true, false, 'all');
    for ($i = 0; $i < count($articles[0]); $i++) {
        array_push($paragraphs, [strip_tags($articles[0][$i]['title'] . ' ' . $articles[0][$i]['content']), false]);
    }
    if (!empty($articles[2])) {
        foreach ($articles[2] as $language_code => $articles_2) {
            for ($i = 0; $i < count($articles_2); $i++) {
                array_push($paragraphs, [strip_tags($articles_2[$i]['title'] . ' ' . $articles_2[$i]['content']), $language_code]);
            }
        }
    }
    return count($paragraphs) ? sb_open_ai_embeddings_get($paragraphs, 'sb-articles') : true;
}

function sb_open_ai_source_file_to_paragraphs($url) {
    $extension = substr($url, -4);
    $paragraphs = [];
    if (!in_array($extension, ['.pdf', '.txt'])) {
        sb_file_delete($url);
        return 'invalid-file-extension';
    }
    if ($extension == '.pdf') {
        $upload_url = sb_upload_path(true);
        $file = strpos($url, $upload_url) === 0 ? sb_upload_path() . str_replace($upload_url, '', $url) : sb_download_file($url, 'sb_open_ai_source_file' . $extension, false, [], 0, true);
        $text = sb_pdf_to_text($file);
    } else {
        $text = trim(sb_get($url));
    }
    if ($text) {
        $encoding = mb_detect_encoding($text);
        if (!$encoding || strpos($encoding, 'UTF-16') !== false) {
            $text = mb_convert_encoding($text, 'UTF-8', $encoding ? $encoding : 'UTF-16');
        }
        $separator = ['።', '。', '။', '.', '।'];
        for ($i = 0; $i < count($separator); $i++) {
            if (strpos($text, $separator[$i])) {
                $separator = $separator[$i];
                break;
            }
        }
        $parts = is_string($separator) ? explode($separator . ' ', $text) : [$text];
        $paragraph = '';
        for ($i = 0; $i < count($parts); $i++) {
            $part = trim($parts[$i]);
            $length_1 = mb_strlen($paragraph);
            $length_2 = mb_strlen($parts[$i]);
            if (($length_1 + $length_2 < 2000) || $length_1 < 100 || $length_2 < 100) {
                $paragraph .= $part;
            } else {
                array_push($paragraphs, $paragraph ? $paragraph . ' ' . $part : $part);
                $paragraph = '';
            }
        }
        if ($paragraph) {
            array_push($paragraphs, $paragraph);
        }
    }
    return $paragraphs;
}

function sb_open_ai_get_gpt_model() {
    $model = sb_get_multi_setting('open-ai', 'open-ai-model', 'gpt-3.5-turbo');
    return $model == 'gpt-3.5-turbo-instruct' ? 'gpt-3.5-turbo' : $model;
}

function sb_open_ai_delete_training() {
    $embeddings = sb_open_ai_get_embeddings();
    $path = sb_open_ai_get_embeddings_path();
    for ($i = 0; $i < count($embeddings); $i++) {
        unlink($path . $embeddings[$i]);
    }
    return true;
}

function sb_open_ai_get_embeddings() {
    $files = scandir(sb_open_ai_get_embeddings_path());
    $embeddings = [];
    for ($i = 0; $i < count($files); $i++) {
        $file = $files[$i];
        if (strpos($file, 'embeddings-') === 0) {
            array_push($embeddings, $files[$i]);
        }
    }
    return $embeddings;
}

function sb_open_ai_get_embeddings_path() {
    $path = sb_upload_path() . '/embeddings/';
    $cloud = sb_is_cloud() ? sb_cloud_account() : false;
    if (!file_exists($path)) {
        mkdir($path, 0755, true);
    }
    if ($cloud) {
        require_once(SB_CLOUD_PATH . '/account/functions.php');
        $path .= $cloud['user_id'] . '/';
        if (!file_exists($path)) {
            mkdir($path, 0755, true);
        }
    }
    return $path;
}

function sb_open_ai_audio_to_text($path_or_url, $audio_language = false, $user_id = false, $message_id = false, $conversation_id = false) {
    $is_delete = false;
    if (!sb_cloud_membership_has_credits('open-ai')) {
        return sb_error('no-credits', 'sb_open_ai_audio_to_text');
    }
    if (!$audio_language) {
        $audio_language = sb_get_user_language($user_id ? $user_id : sb_get_active_user_ID());
    }
    if (strpos($path_or_url, 'http') === 0) {
        $path_file = sb_upload_path(false, true) . '/' . basename($path_or_url);
        if (file_exists($path_file)) {
            $path_or_url = $path_file;
        } else {
            $is_delete = true;
            $path_or_url = sb_download_file($path_or_url, 'temp_open_ai_' . basename($path_or_url), false, [], 0, true);
        }
    }
    $response = sb_curl('https://api.openai.com/v1/audio/transcriptions', ['file' => new CURLFile($path_or_url), 'model' => 'whisper-1', 'language' => $audio_language ? $audio_language : sb_get_user_language()], ['Content-Type: multipart/form-data', 'Authorization: Bearer ' . sb_open_ai_key()], 'POST', 30);
    $message = sb_isset($response, 'text');
    sb_cloud_membership_use_credits('whisper', 'open-ai', $path_or_url);
    if ($message) {
        if ($conversation_id || $message_id) {
            if (!$message_id) {
                $message_id = sb_isset(sb_db_get('SELECT id FROM sb_messages WHERE conversation_id = ' . sb_db_escape($conversation_id, true) . ' ORDER BY id DESC LIMIT 1'), 'id');
            }
            if ($message_id) {
                if (sb_get_multi_setting('open-ai', 'open-ai-speech-recognition')) {
                    sb_update_message($message_id, $message);
                } else {
                    sb_db_query('UPDATE sb_messages SET message = "' . sb_db_escape($message) . '" WHERE id = ' . $message_id);
                }
            }
        }
    } else {
        sb_error('open-ai-error', 'sb_open_ai_audio_to_text', $response);
    }
    if ($is_delete) {
        sb_file_delete($path_or_url);
    }
    return $message;
}

function sb_open_ai_key() {
    return sb_ai_is_manual_sync('open-ai') ? trim(sb_get_multi_setting('open-ai', 'open-ai-key')) : OPEN_AI_KEY;
}

function sb_open_ai_assistant($message, $conversation_id, $human_takeover_check = true) {
    $assistant_id = sb_get_multi_setting('open-ai', 'open-ai-assistant-id');
    $conversation = sb_db_get('SELECT extra_2, department FROM sb_conversations WHERE id = ' . sb_db_escape($conversation_id, true));
    $thread_id = sb_isset($conversation, 'extra_2');
    $department_id = sb_isset($conversation, 'department');
    if ($department_id) {
        $assistants = sb_get_setting('open-ai-assistants');
        if ($assistants && is_array($assistants)) {
            for ($i = 0; $i < count($assistants); $i++) {
                if ($assistants[$i]['open-ai-assistants-department-id'] == $department_id) {
                    $assistant_id = $assistants[$i]['open-ai-assistants-id'];
                    break;
                }
            }
        }
    }
    if (!$assistant_id) {
        return sb_error('open-ai-error', 'sb_open_ai_assistant', 'No assistant ID', true);
    }
    $header = ['Content-Type: application/json', 'OpenAI-Beta: assistants=v1', 'Authorization: Bearer ' . trim(sb_get_multi_setting('open-ai', 'open-ai-key'))];
    $url_part = 'https://api.openai.com/v1/';
    if ($thread_id) {
        $response = sb_curl($url_part . 'threads/' . $thread_id . '/messages', json_encode(['role' => 'user', 'content' => $message], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE), $header);
    }
    $response = sb_curl($url_part . 'threads' . ($thread_id ? '/' . $thread_id : '') . '/runs', json_encode($thread_id ? ['assistant_id' => $assistant_id] : ['assistant_id' => $assistant_id, 'thread' => ['messages' => [['role' => 'user', 'content' => $message]]]], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE), $header);
    $run_id = sb_isset($response, 'id');
    if ($run_id) {
        if (!$thread_id) {
            $thread_id = sb_isset($response, 'thread_id');
            sb_db_query('UPDATE sb_conversations SET extra_2 = "' . sb_db_escape($thread_id) . '" WHERE id = ' . sb_db_escape($conversation_id, true));
        }
        for ($i = 0; $i < 30; $i++) {
            sleep(1);
            $response = json_decode(sb_curl($url_part . 'threads/' . $thread_id . '/runs/' . $run_id, '', $header, 'GET'), true);
            if (sb_isset($response, 'status') == 'completed') {
                $response = json_decode(sb_curl($url_part . 'threads/' . $thread_id . '/messages', '', $header, 'GET'), true);
                $message = isset($response['data']) ? $response['data'][0]['content'][0]['text']['value'] : '';
                if ($message) {
                    $message = preg_replace('/【[\s\S]+?】/', '', sb_open_ai_text_formatting($message));
                }
                return $message;
            } else if ($human_takeover_check) {
                $function_calling = sb_open_ai_function_calling($response);
                if ($function_calling) {
                    sb_curl($url_part . 'threads/' . $thread_id . '/runs/' . $run_id . '/submit_tool_outputs', json_encode(['tool_outputs' => [['tool_call_id' => $function_calling[1], 'output' => $function_calling[2]]]], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE), $header);
                    if ($function_calling[0] == 'sb-human-takeover') {
                        return $function_calling[0];
                    }
                }
            }
        }
    } else if (isset($response['error'])) {
        $error = sb_isset($response['error'], 'message');
        if (strpos($error, 'active run')) {
            $run_id = substr($error, strrpos($error, ' run_') + 1, -1);
            for ($i = 0; $i < 30; $i++) {
                sleep(2);
                if (sb_isset(json_decode(sb_curl($url_part . 'threads/' . $thread_id . '/runs/' . $run_id, '', $header, 'GET'), true), 'status') == 'completed') {
                    return sb_open_ai_assistant($message, $conversation_id, $human_takeover_check);
                }
            }
        }
    }
    sb_error('open-ai-error', 'sb_open_ai_assistant', $response);
    return '';
}

function sb_open_ai_data_scraping($conversation_id, $prompt_id) {
    if (!sb_cloud_membership_has_credits('open-ai')) {
        return sb_error('no-credits', 'sb_open_ai_audio_to_text');
    }
    $max_tokens = 4096;
    $max_tokens_prompt = $max_tokens - 250;
    $messages_temp = sb_db_get('SELECT message FROM sb_messages A, sb_users B WHERE A.conversation_id = ' . sb_db_escape($conversation_id, true) . ' AND A.user_id = B.id AND (B.user_type = "user" OR B.user_type = "lead") ORDER BY A.id DESC', false);
    $messages = [];
    $text = '';
    for ($i = 0; $i < count($messages_temp); $i++) {
        $message = $messages_temp[$i]['message'];
        if ($max_tokens_prompt > strlen($text . $message)) {
            array_push($messages, $message);
        }
    }
    for ($i = count($messages) - 1; $i > -1; $i--) {
        $text .= $messages[$i] . PHP_EOL . PHP_EOL . '.';
    }
    $prompts = sb_open_ai_data_scraping_get_prompts();
    $model = 'gpt-3.5-turbo-instruct';
    $query = ['prompt' => str_replace('"', '\'', $prompts[$prompt_id][0] . ' and nothing else from the following text, return only the scraped information separated by breaklines, do not add text: ') . PHP_EOL . '"""' . trim($text) . '""""', 'temperature' => 1, 'top_p' => 1, 'frequency_penalty' => 0, 'presence_penalty' => 0, 'max_tokens' => 250, 'model' => $model];
    $response = sb_open_ai_curl('completions', $query);
    $choices = sb_isset($response, 'choices');
    sb_cloud_membership_use_credits($model, 'open-ai', $max_tokens);
    if (!$choices) {
        sb_error('open-ai-error', 'sb_open_ai_data_scrape', $response);
    } else {
        $lines = preg_split("/\r\n|\n|\r/", $choices[0]['text']);
        $text = '';
        if (in_array('duplicate', $prompts[$prompt_id][1])) {
            $lines = array_unique($lines);
        }
        for ($i = 0; $i < count($lines); $i++) {
            $line = $lines[$i] . '';
            $lines[$i] = '';

            for ($j = 0; $j < count($prompts[$prompt_id][1]); $j++) {
                $check = $prompts[$prompt_id][1][$j];
                if (strpos($line, $check) !== false || ($check == 123 && is_numeric($line))) {
                    continue 2;
                }
            }
            $count = count($prompts[$prompt_id][2]);
            if ($count) {
                $valid = false;
                for ($j = 0; $j < count($prompts[$prompt_id][2]); $j++) {
                    $check = $prompts[$prompt_id][2][$j];
                    if (strpos($line, $check) !== false || ($check == 123 && !is_numeric($line))) {
                        $valid = true;
                        break;
                    }
                }
                if (!$valid) {
                    continue;
                }
            }
            $text .= trim($line) . PHP_EOL;
        }
        return $text;
    }
    return $response;
}

function sb_open_ai_data_scraping_get_prompts($type = false) {
    $prompts = ['login' => ['Scrape login information', [], [], 'Login information'], 'links' => ['Scrape all links and URLs', ['@', 123], ['http', 'www'], 'Links and URLs'], 'contacts' => ['Scrape addresses, phone numbers and emails', ['http'], [], 'Contact information']];
    if ($type == 'name') {
        foreach ($prompts as $key => $value) {
            $prompts[$key] = sb_($value[3]);
        }
    }
    return $prompts;
}

function sb_open_ai_function_calling($response) {
    $function = false;
    $function_name = false;
    $output = '';
    $id = false;
    if (sb_isset($response, 'status') == 'requires_action') {
        $response = sb_isset($response, 'required_action');
        if ($response) {
            $response = sb_isset(sb_isset($response, 'submit_tool_outputs'), 'tool_calls');
            if (!empty($response)) {
                $function = sb_isset($response[0], 'function');
                $id = $response[0]['id'];
            }
        }
    } else if (!empty($response['choices'])) {
        $response = sb_isset(sb_isset($response['choices'][0], 'message'), 'tool_calls');
        if (!empty($response)) {
            $function = sb_isset($response[0], 'function');
        }
    }
    if ($function) {
        $function_name = $function['name'];
        if ($function_name == 'sb-human-takeover') {
            return [$function_name, $id, $output];
        }
    }
    return false;
}

/*
 * -----------------------------------------------------------
 * GOOGLE
 * -----------------------------------------------------------
 *
 * 1. Detect the language of a string
 * 2. Retrieve the full language name in the desired language
 * 3. Text translation
 * 4. Analyze Entities
 * 5. Return the client ID and secret key
 *
 */

function sb_google_language_detection($string, $token = false) {
    $token = $token ? $token : sb_dialogflow_get_token();
    $query = json_encode(['q' => $string], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE);
    if (!sb_cloud_membership_has_credits('google')) {
        return sb_error('no-credits', 'sb_google_get_language_name');
    }
    $response = sb_curl('https://translation.googleapis.com/language/translate/v2/detect', $query, ['Content-Type: application/json', 'Authorization: Bearer ' . $token, 'Content-Length: ' . strlen($query)]);
    sb_cloud_membership_use_credits('translation', 'google', $string);
    if (isset($response['error']) && $response['error']['status'] == 'UNAUTHENTICATED') {
        global $sb_recursion_dialogflow;
        if ($sb_recursion_dialogflow[0]) {
            $sb_recursion_dialogflow[0] = false;
            $token = sb_dialogflow_get_token(false);
            return sb_google_language_detection($string, $token);
        }
    }
    return isset($response['data']) ? sb_language_code($response['data']['detections'][0][0]['language']) : false;
}

function sb_google_get_language_name($target_language_code, $token = false) {
    $token = $token ? $token : sb_dialogflow_get_token();
    $query = json_encode(['target' => $target_language_code], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE);
    $response = sb_curl('https://translation.googleapis.com/language/translate/v2/languages', $query, ['Content-Type: application/json', 'Authorization: Bearer ' . $token, 'Content-Length: ' . strlen($query)]);
    if (isset($response['data'])) {
        $languages = $response['data']['languages'];
        for ($i = 0; $i < count($languages); $i++) {
            if ($languages[$i]['language'] == $target_language_code) {
                return $languages[$i]['name'];
            }
        }
    }
    return $response;
}

function sb_google_translate($strings, $language_code, $token = false, $message_ids = false, $conversation_id = false) {
    $translations = [];
    $token = $token ? $token : sb_dialogflow_get_token();
    $chunks = array_chunk($strings, 125);
    $language_code = strtolower(substr($language_code, 0, 2));
    $language_code = sb_isset(['br' => 'pt'], $language_code, $language_code);
    $shortcode_replacements = [['[chips ', '[buttons ', '[select ', '[email ', '[articles ', '[rating ', '[list ', '[list-image ', '[table ', '[inputs ', '[card ', '[slider ', '[slider-images ', '[video ', '[image ', '[share ', '[registration]', '[timetable]', ' options="', ' title="', ' message="', ' success="', ' placeholder="', ' name="', ' phone="', ' phone-required="', ' link="', ' label="', '  label-positive="', ' label-negative="', ' success-negative="', ' values="', ' header="', ' button="', ' image="', ' target="', ' extra="', ' link-text="', ' type="', ' height="', ' id="', ' url="', ']'], ['[1 ', '[2 ', '[3 ', '[4 ', '[5 ', '[6 ', '[7 ', '[8 ', '[9 ', '[10 ', '[11 ', '[12 ', '[13 ', '[14 ', '[15 ', '[16 ', '[17', '[18', ' 19="', ' 20="', ' 21="', ' 22="', ' 23="', ' 24="', ' 25="', ' 26="', ' 27="', ' 28="', ' 29="', ' 30="', ' 31="', ' 32="', ' 33="', ' 34="', ' 35="', ' 36="', ' 37="', ' 38="', ' 39="', ' 40="', ' 41="', ' 42="', '43=']];
    $skipped_translations = [];
    $strings_original = $strings;
    $count = 0;
    if (!sb_cloud_membership_has_credits('google')) {
        return sb_error('no-credits', 'sb_dialogflow_message');
    }
    for ($j = 0; $j < count($chunks); $j++) {
        $strings = $chunks[$j];
        for ($i = 0; $i < count($strings); $i++) {
            $string = $strings[$i];
            if (strpos($string, '[') !== false || strpos($string, '="') !== false) {
                $string = str_replace($shortcode_replacements[0], $shortcode_replacements[1], $string);
            }
            preg_match_all('/`[\S\s]*?`/', $string, $matches);
            $matches = $matches[0];
            array_push($skipped_translations, $matches);
            for ($y = 0; $y < count($matches); $y++) {
                $string = str_replace($matches[$y], '"' . $y . '"', $string);
            }
            $strings[$i] = str_replace('"', '«»', str_replace(['\r\n', PHP_EOL, '\r', '\n'], '~~', $string));
        }
        $query = json_encode(['q' => $strings, 'target' => $language_code, 'format' => 'text'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE);
        $response = sb_curl('https://translation.googleapis.com/language/translate/v2', $query, ['Content-Type: application/json', 'Authorization: Bearer ' . $token, 'Content-Length: ' . strlen($query)]);
        if ($response && isset($response['data'])) {
            sb_cloud_membership_use_credits('translation', 'google', json_encode($strings, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE));
            $translations_partial = sb_isset($response['data'], 'translations', []);
            for ($i = 0; $i < count($translations_partial); $i++) {
                $string = $translations_partial[$i]['translatedText'];
                while (mb_substr($string, 0, 1) == '"') {
                    $string = mb_substr($string, 1);
                }
                $string = str_replace([PHP_EOL, '\r\n', '\r', '<br>', '~~', '”', '«»', '« »', '»»', '««', '_}', '“', '""'], ["\n", "\n", "\n", "\n", "\n", '"', '"', '"', '"', '"', '}', '', '"'], $string);
                for ($y = 0; $y < count($skipped_translations[$i]); $y++) {
                    $string = str_replace('"' . $y . '"', $skipped_translations[$i][$y], $string);
                }
                $string = str_replace($shortcode_replacements[1], $shortcode_replacements[0], $string);
                array_push($translations, $string);
            }
            $count = count($translations);
            if ($count && $message_ids && $conversation_id && $count == count($message_ids)) {
                $data = sb_db_get('SELECT id, payload FROM sb_messages WHERE id IN (' . implode(',', $message_ids) . ') AND conversation_id = ' . sb_db_escape($conversation_id, true), false);
                for ($i = 0; $i < $count; $i++) {
                    $payload = json_decode($data[$i]['payload'], true);
                    $payload['translation'] = $translations[$i];
                    $payload['translation-language'] = $language_code;
                    sb_db_query('UPDATE sb_messages SET payload = "' . sb_db_json_escape($payload) . '" WHERE id = ' . $data[$i]['id']);
                }
            }
        } else {
            $error = sb_isset($response, 'error');
            if ($error) {
                if (sb_isset($error, 'status') == 'UNAUTHENTICATED') {
                    global $sb_recursion_dialogflow;
                    if ($sb_recursion_dialogflow[0]) {
                        $sb_recursion_dialogflow[0] = false;
                        $token = sb_dialogflow_get_token(false);
                        return sb_google_translate($strings_original, $language_code, $token);
                    }
                }
                sb_error('error', 'sb_google_translate', $error, sb_is_agent());
                return [$strings_original, $token];
            }
        }
    }
    return [$count ? $translations : $response, $token];
}

function sb_google_translate_auto($string, $user_id) {
    if (is_numeric($user_id) && (sb_get_setting('google-translation') || sb_get_multi_setting('google', 'google-translation'))) { // Deprecated: sb_get_setting('google-translation')
        $recipient_language = sb_get_user_language($user_id);
        $active_user_language = sb_get_user_language(sb_get_active_user_ID());
        if ($recipient_language && $active_user_language && $recipient_language != $active_user_language) {
            $translation = sb_google_translate([$string], $recipient_language)[0];
            if (count($translation)) {
                $translation = trim($translation[0]);
                if (!empty($translation)) {
                    return $translation;
                }
            }
        }
    }
    return $string;
}

function sb_google_language_detection_update_user($string, $user_id = false, $token = false) {
    $user_id = $user_id ? $user_id : sb_get_active_user_ID();
    $detected_language = sb_google_language_detection($string, $token);
    $language = sb_get_user_language($user_id);
    if ($detected_language != $language[0] && !empty($detected_language)) {
        $response = sb_language_detection_db($user_id, $detected_language);
        if ($response) {
            unset($GLOBALS['SB_LANGUAGE']);
            return sb_get_current_translations();
        }
    }
    return false;
}

function sb_language_detection_db($user_id, $detected_language) {
    $response = sb_update_user_value($user_id, 'language', $detected_language);
    sb_db_query('DELETE FROM sb_users_data WHERE user_id = ' . sb_db_escape($user_id) . ' AND slug = "browser_language"');
    return $response;
}

function sb_google_language_detection_get_user_extra($message) {
    if ($message && (sb_get_multi_setting('google', 'google-language-detection') || sb_get_multi_setting('dialogflow-language-detection', 'dialogflow-language-detection-active'))) { // Deprecated: sb_get_multi_setting('dialogflow-language-detection', 'dialogflow-language-detection-active')
        return [sb_google_language_detection($message), 'Language'];
    }
    return '';
}

function sb_google_analyze_entities($string, $language = false, $token = false) {
    if (!strpos(trim($string), ' ')) {
        return false;
    }
    $token = $token ? $token : sb_dialogflow_get_token();
    $query = ['document' => ['type' => 'PLAIN_TEXT', 'content' => ucwords($string)]];
    if ($language) {
        $query['document']['language'] = $language;
    }
    $query = json_encode($query, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE);
    $response = sb_curl('https://language.googleapis.com/v1/documents:analyzeEntities', $query, ['Content-Type: application/json', 'Authorization: Bearer ' . $token, 'Content-Length: ' . strlen($query)]);
    if (isset($response['error'])) {
        trigger_error($response['error']['message']);
    }
    return $response;
}

function sb_google_key() {
    return sb_ai_is_manual_sync('google') ? [trim(sb_get_multi_setting('google', 'google-client-id')), trim(sb_get_multi_setting('google', 'google-client-secret'))] : [GOOGLE_CLIENT_ID, GOOGLE_CLIENT_SECRET];
}

/*
 * ----------------------------------------------------------
 * DIALOGFLOW INTENT BOX
 * ----------------------------------------------------------
 *
 * Display the form to create a new intent for Dialogflow
 *
 */

function sb_dialogflow_intent_box() {
    $is_dialogflow = sb_chatbot_active(true, false);
    ?>
    <div class="sb-lightbox sb-dialogflow-intent-box<?php echo $is_dialogflow ? '' : ' sb-dialogflow-disabled' ?>">
        <div class="sb-info"></div>
        <div class="sb-top-bar">
            <div>
                <?php sb_e('Chatbot Training') ?><a href="https://board.support/docs#chatbot-training-window" target="_blank">
                    <i class="sb-icon-help"></i>
                </a>
            </div>
            <div>
                <a class="sb-send sb-btn sb-icon">
                    <i class="sb-icon-check"></i>
                    <?php sb_e('Train chatbot') ?>
                </a>
                <a class="sb-close sb-btn-icon sb-btn-red">
                    <i class="sb-icon-close"></i>
                </a>
            </div>
        </div>
        <div class="sb-main sb-scroll-area">
            <div class="sb-title sb-intent-add">
                <?php
                if ($is_dialogflow) {
                    echo sb_('Add user expressions') . '<i data-value="add" data-sb-tooltip="' . sb_('Add expression') . '" class="sb-btn-icon sb-icon-plus"></i><i data-value="previous" class="sb-btn-icon sb-icon-arrow-up"></i><i data-value="next" class="sb-btn-icon sb-icon-arrow-down"></i>';
                } else {
                    sb_e('User message');
                }
                ?>
            </div>
            <div class="sb-input-setting sb-type-text sb-first">
                <input type="text" />
            </div>
            <div class="sb-title sb-bot-response">
                <?php
                sb_e('Chatbot response');
                if (defined('SB_DIALOGFLOW') && sb_get_multi_setting('open-ai', 'open-ai-rewrite')) {
                    echo '<i class="sb-btn-open-ai sb-btn-icon sb-icon-openai" data-sb-tooltip="' . sb_('Rewrite') . '"></i>';
                }
                ?>
            </div>
            <div class="sb-input-setting sb-type-textarea sb-bot-response">
                <textarea></textarea>
            </div>
            <div class="sb-title">
                <?php sb_e('Language') ?>
            </div>
            <?php
            echo sb_dialogflow_languages_list();
            if ($is_dialogflow) {
                echo '<div class="sb-title sb-title-search">' . sb_('Intent') . '<div class="sb-search-btn"><i class="sb-icon sb-icon-search"></i><input type="text" autocomplete="false" placeholder="' . sb_('Search for Intents...') . '" /></div><i id="sb-intent-preview" data-sb-tooltip="' . sb_('Preview') . '" class="sb-icon-help"></i></div><div class="sb-input-setting sb-type-select"><select id="sb-intents-select"></select></div>';
                if (sb_chatbot_active(false, true)) {
                    echo '<div class="sb-title">' . sb_('Services to update') . '</div><div class="sb-input-setting sb-type-select"><select id="sb-train-chatbots"><option value="">' . sb_('All') . '</option><option value="open-ai">OpenAI</option><option value="dialogflow">Dialogflow</option></select></div>';
                }
            }
            ?>
        </div>
    </div>
<?php } ?>