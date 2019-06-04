<?php
function addUserToNewUsers($chatId, $userId) {
  $users = json_decode(file_get_contents('users.json'), true);
  if (empty($users[$chatId][$userId])) {
    $users[$chatId][$userId]['time'] = time();
    $users[$chatId][$userId]['posted'] = false;
    $users[$chatId][$userId]['clicked_button'] = false;

    file_put_contents('users.json', json_encode($users));
  }
}

function makeApiRequest($method, $data) {
  global $config, $client;
  if (!($client instanceof \GuzzleHttp\Client)) {
    $client = new \GuzzleHttp\Client(['base_uri' => $config['url']]);
  }
  try {
    $response = $client->request('POST', $method, array('json' => $data));
  } catch (\GuzzleHttp\Exception\BadResponseException $e) {
    $body = $e->getResponse()->getBody();
    //mail($config['mail'], 'Error', print_r($body->getContents(), true) . "\n" . print_r($data, true) . "\n" . __FILE__);
    file_put_contents('log.txt', print_r($body->getContents(), true) . "\n" . print_r($data, true) . "\n\n", FILE_APPEND);
  }
  return json_decode($response->getBody(), true)['result'];
}

function returnResponse(){
  ignore_user_abort(true);
  ob_start();
  // do initial processing here
  header('Connection: close');
  header('Content-Length: '.ob_get_length());
  header("Content-Encoding: none");
  header("Status: 200");
  ob_end_flush();
  ob_flush();
  flush();
}

function isUserUnknown($chatId, $userId) {
  global $config;
  $dbConnection = buildDatabaseConnection($config);

  try {
    $sql = "SELECT user_id FROM telegram_users WHERE chat_id = $chatId AND user_id = $userId";
    $stmt = $dbConnection->prepare('SELECT user_id FROM telegram_users WHERE chat_id = :chatId AND user_id = :userId');
    $stmt->bindParam(':chatId', $chatId);
    $stmt->bindParam(':userId', $userId);
    $stmt->execute();
    $stmt->fetch();
  } catch (PDOException $e) {
    notifyOnException('Database Select', $config, $sql, $e);
  }
  if ($stmt->rowCount() == 0) {
    return true;
  }
  return false;
}

function userIsKnown($chatId, $userId){
  global $config;
  $dbConnection = buildDatabaseConnection($config);

  try {
    $sql = "INSERT INTO telegram_users VALUES($chatId, $userId)";
    $stmt = $dbConnection->prepare('INSERT INTO telegram_users VALUES(:chatId, :userId)');
    $stmt->bindParam(':chatId', $chatId);
    $stmt->bindParam(':userId', $userId);
    $stmt->execute();
  } catch (PDOException $e) {
    notifyOnException('Database Select', $config, $sql, $e);
  }
}

function notifyOnException($subject, $config, $sql = '', $e = '', $fail = false) {
  $to = $config['mail'];
  $txt = __FILE__ . ' ' . $sql . ' Error: ' . $e;
  $headers = 'From: ' . $config['mail'];
  mail($to, $subject, $txt, $headers);
  http_response_code(200);
  if ($fail) {
    die();
  }
}

function buildDatabaseConnection($config) {
  //Connect to DB only here to save response time on other commands
  try {
    $dbConnection = new PDO('mysql:dbname=' . $config['dbname'] . ';host=' . $config['dbserver'] . ';port=' . $config['dbport'] . ';charset=utf8mb4', $config['dbuser'], $config['dbpassword'], array(PDO::ATTR_TIMEOUT => 25));
    $dbConnection->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    $dbConnection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  } catch (PDOException $e) {
    notifyOnException('Database Connection', $config, '', $e);
    return false;
  }
  return $dbConnection;
}

function addMessageToHistory($chatId, $userId, $messageId, $time = 0) {
  global $config;
  $dbConnection = buildDatabaseConnection($config);

  try {
    $sql = "SELECT id FROM telegram_messages WHERE chat_id = '$chatId' AND user_id = '$userId' AND message_id = '$messageId'";
    $stmt = $dbConnection->prepare("SELECT id FROM telegram_messages WHERE chat_id = :chatId AND user_id = :userId AND message_id = :messageId");
    $stmt->bindParam(':chatId', $chatId);
    $stmt->bindParam(':userId', $userId);
    $stmt->bindParam(':messageId', $messageId);
    $stmt->execute();
    $row = $stmt->fetch();
  } catch (PDOException $e) {
    notifyOnException('Database Select', $config, $sql, $e);
  }
  if (!$row) {
    try {
      $sql = "INSERT INTO telegram_messages(chat_id, user_id, message_id, time) VALUES ($chatId, $userId, $messageId, $time)";
      $stmt = $dbConnection->prepare("INSERT INTO telegram_messages(chat_id, user_id, message_id, time) VALUES (:chatId, :userId,:messageId, :time)");
      $stmt->bindParam(':chatId', $chatId);
      $stmt->bindParam(':userId', $userId);
      $stmt->bindParam(':messageId', $messageId);
      $stmt->bindParam(':time', $time);
      $stmt->execute();
    } catch (PDOException $e) {
      notifyOnException('Database Insert', $config, $sql, $e);
    }
  }
}

function sendMessage($chatId, $text, $replyMarkup = '') {
  $data = array(
    'disable_web_page_preview' => true,
    'parse_mode' => 'html',
    'chat_id' => $chatId,
    'text' => $text,
    'reply_markup' => $replyMarkup
  );
  return makeApiRequest('sendMessage', $data);
}

function kickUser($chatId, $userId, $length = 40) {
  $until = time() + $length;
  $data = array('chat_id'=>$chatId, 'user_id'=>$userId,'until_date'=>$until);
  return makeApiRequest('kickChatMember', $data);
}

function deleteMessages($chatId, $userId){
  global $config;
  $dbConnection = buildDatabaseConnection($config);

  try{
    $sql = "SELECT message_id FROM telegram_messages WHERE chat_id = '$chatId' AND user_id = '$userId'";
    $stmt = $dbConnection->prepare("SELECT message_id FROM telegram_messages WHERE chat_id = :chatId AND user_id = :userId");
    $stmt->bindParam(':chatId', $chatId);
    $stmt->bindParam(':userId', $userId);
    $stmt->execute();
    $rows = $stmt->fetchAll();
  } catch (PDOException $e) {
    notifyOnException('Database Select', $config, $sql, $e);
  }

  foreach($rows as $row){
    $messageId = $row['message_id'];
    deleteMessage($chatId, $messageId);
    try{
      $sql = "DELETE FROM telegram_messages WHERE chat_id = '$chatId' AND user_id = '$userId' AND message_id = '$messageId'";
      $stmt = $dbConnection->prepare("DELETE FROM telegram_messages WHERE chat_id = :chatId AND user_id = :userId AND message_id = :messageId");
      $stmt->bindParam(':chatId', $chatId);
      $stmt->bindParam(':userId', $userId);
      $stmt->bindParam(':messageId', $messageId);
      $stmt->execute();
    } catch (PDOException $e) {
      notifyOnException('Database Delete', $config, $sql, $e);
    }
  }
}

function isNewUser($chatId, $userId) {
  $users = json_decode(file_get_contents('users.json'), true);
  if (!empty($users[$chatId][$userId]['time']) && $users[$chatId][$userId]['time'] + 3600 > time()) {
    return true;
  }
  return false;
}

function hasUserClickedButton($chatId, $userId) {
  $users = json_decode(file_get_contents('users.json'), true);
  if (!empty($users[$chatId][$userId]['clicked_button']) && $users[$chatId][$userId]['clicked_button'] == true) {
    return true;
  }
  return false;
}

function deleteMessage($chatId, $messageId){
  $data = array('chat_id'=>$chatId, 'message_id'=>$messageId);
  return makeApiRequest('deleteMessage', $data);
}

function isNewUsersFirstMessage($chatId, $userId) {
  $users = json_decode(file_get_contents('users.json'), true);
  if ($users[$chatId][$userId]['posted'] == false) {
    $users[$chatId][$userId]['posted'] = true;
    file_put_contents('users.json', json_encode($users));

    return true;
  }
  return false;
}

function removeMessageHistory($chatId, $userId) {
  global $config;

  $dbConnection = buildDatabaseConnection($config);

  try{
    $sql = "DELETE FROM telegram_messages WHERE chat_id = $chatId AND user_id = $userId";
    $stmt = $dbConnection->prepare('DELETE FROM telegram_messages WHERE chat_id = :chatId AND user_id = :userId');
    $stmt->bindParam(':chatId', $chatId);
    $stmt->bindParam(':userId', $userId);
    $stmt->execute();
  }catch (PDOException $e){
    notifyOnException('Database Insert', $config, $sql, $e);
  }
}

function isAdmin($chatId, $userId) {
  $data = array(
    'chat_id' => $chatId,
    'user_id' => $userId
  );
  $user = makeApiRequest('getChatMember', $data);
  if ($user['status'] === 'administrator' || $user['status'] === 'creator') {
    return true;
  } else {
    return false;
  }
}

function restrictChatMember($chatId, $userId, $until = 0, $sendMessages = false, $sendMedia = false, $sendOther = false, $sendWebPreview = false) {
  $untilTimestamp = time() + $until;
  $data = array(
    'chat_id' => $chatId,
    'user_id' => $userId,
    'until_date' => $untilTimestamp,
    'can_send_messages' => $sendMessages,
    'can_send_media_messages' => $sendMedia,
    'can_send_other_messages' => $sendOther,
    'can_add_web_page_previews' => $sendWebPreview
  );
  return makeApiRequest('restrictChatMember', $data);
}

function unrestrictUser($chatId, $userId, $welcomeMsgId, $welcomeMsgText){
  removeMessageHistory($chatId, $userId);
  restrictChatMember($chatId, $userId, 0, true, true, true, true);
  editMessageText($chatId, $welcomeMsgId, $welcomeMsgText);
}

function editMessageText($chatId, $messageId, $text, $replyMarkup = '', $inlineMessageId = '') {
  if (empty($inlineMessageId)) {
    $data = array(
      'chat_id' => $chatId,
      'message_id' => $messageId,
      'text' => $text,
      'parse_mode' => 'html',
      'disable_web_page_preview' => true,
      'reply_markup' => $replyMarkup
    );
  } else {
    $data = array(
      'inline_message_id' => $inlineMessageId,
      'text' => $text,
      'parse_mode' => 'html',
      'disable_web_page_preview' => true,
      'reply_markup' => $replyMarkup
    );
  }
  return makeApiRequest('editMessageText', $data);
}

function userClickedButton($chatId, $userId) {
  $users = json_decode(file_get_contents('users.json'), true);
  if ($users[$chatId][$userId]['clicked_button'] == false) {
    $users[$chatId][$userId]['clicked_button'] = true;
    file_put_contents('users.json', json_encode($users));

    return true;
  }
  return false;
}

function answerCallbackQuery($queryId, $text = '') {
  $data = array('callback_query_id'=>$queryId, 'text'=>$text);
  return makeApiRequest('answerCallbackQuery', $data);
}