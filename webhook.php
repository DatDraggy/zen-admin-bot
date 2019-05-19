<?php
require_once(__DIR__ . '/config.php');
header('Cache-Control: max-age=0');
require_once(__DIR__ . '/funcs.php');

$response = file_get_contents('php://input');
$data = json_decode($response, true);
$dump = print_r($data, true);

if (isset($data['callback_query'])) {
  $chatId = $data['callback_query']['message']['chat']['id'];
  $queryId = $data['callback_query']['id'];
  $chatType = $data['callback_query']['message']['chat']['type'];
  $callbackData = $data['callback_query']['data'];
  $senderUserId = $data['callback_query']['from']['id'];
  if ($chatId == $config['chat_id']) {
    list($targetUserId, $status) = explode('|', $callbackData);

    if ($targetUserId == $senderUserId) {
      unrestrictUser($chatId, $senderUserId, $data['callback_query']['message']['message_id'], $data['callback_query']['message']['text']);
      userClickedButton((string)$chatId, $senderUserId);
      answerCallbackQuery($queryId, 'Accepted.');
      die();
    }
    answerCallbackQuery($queryId);
  }
}
if (!isset($data['message'])) {
  die();
}
$chatId = $data['message']['chat']['id'];
$chatType = $data['message']['chat']['type'];
$messageId = $data['message']['message_id'];
$senderUserId = preg_replace("/[^0-9]/", "", $data['message']['from']['id']);
$senderUsername = NULL;
if (isset($data['message']['from']['username'])) {
  $senderUsername = $data['message']['from']['username'];
}
$senderName = $data['message']['from']['first_name'];
if (isset($data['message']['from']['last_name'])) {
  $senderName .= ' ' . $data['message']['from']['last_name'];
}
if (isset($data['message']['text'])) {
  $text = $data['message']['text'];
} else if (isset($data['message']['caption'])) {
  $text = $data['message']['caption'];
}

if ($chatId == $config['chat_id']) {
  if (isset($data['message']['new_chat_participant']) && $data['message']['new_chat_participant']['is_bot'] != 1) {
    $userId = $data['message']['new_chat_participant']['id'];
    //restrictChatMember($chatId, $userId, 3600);
    //Instead of restricting via api, we instantly return for a faster restrict

    ignore_user_abort(true);
    set_time_limit(0);

    ob_start();
    $untilTimestamp = time() + 3600;
    $returndata = array(
      'method' => 'restrictChatMember',
      'chat_id' => $chatId,
      'user_id' => $userId,
      'until_date' => $untilTimestamp,
      'can_send_messages' => false,
      'can_send_media_messages' => false,
      'can_send_other_messages' => false,
      'can_add_web_page_previews' => false
    );
    echo json_encode($returndata);
    header('Content-type: application/json');
    header('Connection: close');
    header('Content-Length: '.ob_get_length());
    ob_end_flush();
    ob_flush();
    flush();

    addUserToNewUsers((string)$chatId, $userId);
    $name = $data['message']['new_chat_participant']['first_name'];
    if (isset($data['message']['new_chat_participant']['last_name'])) {
      $name .= ' ' . $data['message']['new_chat_participant']['last_name'];
    }
    $rules = "Welcome to the Summerbo.at Group, <a href=\"tg://user?id=$userId\">$name</a>!
Follow the /rules and enjoy your stay~";
    $replyMarkup = array(
      'inline_keyboard' => array(
        array(
          array(
            "text" => "Press if you're not a bot!",
            "callback_data" => $userId . '|bot'
          )
        )
      )
    );
    $message = sendMessage($chatId, $rules, json_encode($replyMarkup));
    addMessageToHistory($chatId, $data['message']['new_chat_participant']['id'], $messageId, time());
    addMessageToHistory($chatId, $data['message']['new_chat_participant']['id'], $message['message_id']);
    if ($name == 'Bot Notification' || $name == 'Information Agent') {
      kickUser($chatId, $userId, '0');
      deleteMessages($chatId, $userId);
    }
    die();
  } else {
    if (isset($text)) {
      if (substr($text, '0', '1') == '/') {
        $messageArr = explode(' ', $text);
        $command = explode('@', $messageArr[0])[0];
        $command = strtolower($command);
        switch (true) {
          case ($command === '/rules'):
            sendMessage($chatId, '1. Apply common sense
2. Don\'t spam. Neither stickers nor GIFs nor memes nor pictures.
3. Keep it English, other languages are not allowed
4. Keep it PG-13
5. No hate-speech, harassment, illegal stuff or insults.
6. No talk about Piracy (Pirates in general are allowed)
7. Keep your swim vest near you at all times
8. Thank the captain and listen to the boat crew');
            break;
        }
      } else {
        //addUserToNewUsers((string)$chatId, $senderUserId);
        //if (json_decode(file_get_contents('users.json'), true)[$chatId][$senderUserId] < time() + 1800){
        if (isNewUser((string)$chatId, $senderUserId)) {
          mail($config['mail'], 'Summerboat Dump', $dump);
          if (!hasUserClickedButton((string)$chatId, $senderUserId)) {
            deleteMessage($chatId, $messageId);
            kickUser($chatId, $senderUserId, 0);
          } else if (!empty($data['message']['entities'])) {
            foreach ($data['message']['entities'] as $entity) {
              if ($entity['type'] == 'url') {
                deleteMessage($chatId, $messageId);
                if (isNewUsersFirstMessage((string)$chatId, $senderUserId)) {
                  kickUser($chatId, $senderUserId, 0);
                }
                break;
              }
            }
          } else if (!empty($data['message']['caption_entities'])) {
            foreach ($data['message']['caption_entities'] as $entity) {
              if ($entity['type'] == 'url') {
                deleteMessage($chatId, $messageId);
                if (isNewUsersFirstMessage((string)$chatId, $senderUserId)) {
                  kickUser($chatId, $senderUserId, 0);
                }
                break;
              }
            }
          } else if (stripos($text, 'http') !== FALSE || stripos($text, 'https') !== FALSE) {
            deleteMessage($chatId, $messageId);
            if (isNewUsersFirstMessage((string)$chatId, $senderUserId)) {
              kickUser($chatId, $senderUserId, 0);
            }
          }
          isNewUsersFirstMessage((string)$chatId, $senderUserId);
        }
      }
      die();
    }

  }
}