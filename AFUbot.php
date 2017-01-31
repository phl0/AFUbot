<?php

require_once('config.inc.php');

function apiRequestWebhook($method, $parameters) {
  if (!is_string($method)) {
    error_log("Method name must be a string\n");
    return false;
  }

  if (!$parameters) {
    $parameters = array();
  } else if (!is_array($parameters)) {
    error_log("Parameters must be an array\n");
    return false;
  }

  $parameters["method"] = $method;

  header("Content-Type: application/json");
  echo json_encode($parameters);
  return true;
}

function exec_curl_request($handle) {
  $response = curl_exec($handle);

  if ($response === false) {
    $errno = curl_errno($handle);
    $error = curl_error($handle);
    error_log("Curl returned error $errno: $error\n");
    curl_close($handle);
    return false;
  }

  $http_code = intval(curl_getinfo($handle, CURLINFO_HTTP_CODE));
  curl_close($handle);

  if ($http_code >= 500) {
    // do not wat to DDOS server if something goes wrong
    sleep(10);
    return false;
  } else if ($http_code != 200) {
    $response = json_decode($response, true);
    error_log("Request has failed with error {$response['error_code']}: {$response['description']}\n");
    if ($http_code == 401) {
      throw new Exception('Invalid access token provided');
    }
    return false;
  } else {
    $response = json_decode($response, true);
    if (isset($response['description'])) {
      error_log("Request was successfull: {$response['description']}\n");
    }
    $response = $response['result'];
  }

  return $response;
}

function apiRequest($method, $parameters) {
  if (!is_string($method)) {
    error_log("Method name must be a string\n");
    return false;
  }

  if (!$parameters) {
    $parameters = array();
  } else if (!is_array($parameters)) {
    error_log("Parameters must be an array\n");
    return false;
  }

  foreach ($parameters as $key => &$val) {
    // encoding to JSON array parameters, for example reply_markup
    if (!is_numeric($val) && !is_string($val)) {
      $val = json_encode($val);
    }
  }
  $url = API_URL.$method.'?'.http_build_query($parameters);

  $handle = curl_init($url);
  curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($handle, CURLOPT_CONNECTTIMEOUT, 5);
  curl_setopt($handle, CURLOPT_TIMEOUT, 60);

  return exec_curl_request($handle);
}

function apiRequestJson($method, $parameters) {
  if (!is_string($method)) {
    error_log("Method name must be a string\n");
    return false;
  }

  if (!$parameters) {
    $parameters = array();
  } else if (!is_array($parameters)) {
    error_log("Parameters must be an array\n");
    return false;
  }

  $parameters["method"] = $method;

  $handle = curl_init(API_URL);
  curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($handle, CURLOPT_CONNECTTIMEOUT, 5);
  curl_setopt($handle, CURLOPT_TIMEOUT, 60);
  curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($parameters));
  curl_setopt($handle, CURLOPT_HTTPHEADER, array("Content-Type: application/json"));

  return exec_curl_request($handle);
}

function processMessage($message) {
  // process incoming message
  $message_id = $message['message_id'];
  $id = $message['from']['id'];
  $username = $message['from']['username'];
  $chat_id = $message['chat']['id'];
  if (isset($message['text'])) {
    // incoming text message
    $text = $message['text'];

    if (preg_match('/^\/dstarlh/', $text)) {
       if (!preg_match('/^\/dstarlh /', $text)) {
          apiRequestWebhook("sendMessage", array('chat_id' => $chat_id, "text" => 'Usage: /dstarlh callsign'));
       }
       preg_match("/^\/dstarlh ([\w-]+)/", $text, $results);
       $callsign = strtoupper($results[1]);
       $url = "http://status.ircddb.net/cgi-bin/ircddb-user?callsign=".$callsign;
       $ch = curl_init();
       $timeout = 5;
       curl_setopt($ch, CURLOPT_URL, $url);
       curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
       curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
       $html = curl_exec($ch);
       curl_close($ch);

       $dom = new DOMDocument();

       @$dom->loadHTML($html);

       $tables = $dom->getElementsByTagName('table');
       $rows = $tables->item(0)->getElementsByTagName('tr');
       if (!empty($rows->item(1))) {
          $callsign = $rows->item(1)->getElementsByTagName('td')->item(2)->nodeValue;
          $callsign = str_replace(array('"',' ','_'), '', $callsign);
          $timestamp = $rows->item(1)->getElementsByTagName('td')->item(1)->nodeValue;
          $timestamp = preg_replace('/(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2}):(\d{2})/', '\4:\5:\6 UTC \3.\2.\1', $timestamp);

          apiRequest("sendMessage", array('chat_id' => $chat_id, "text" => "Last heard $callsign on ircDDB: ".$timestamp));
       } else {
          apiRequest("sendMessage", array('chat_id' => $chat_id, "text" => "$callsign not found"));
       }
    }
    if (preg_match('/^\/qrz/', $text)) {
       if (!preg_match('/^\/qrz /', $text)) {
          apiRequestWebhook("sendMessage", array('chat_id' => $chat_id, "text" => 'Usage: /qrz callsign'));
       }
       preg_match("/^\/qrz ([\w-]+)/", $text, $results);
       $callsign = strtoupper($results[1]);
       $url = "https://www.qrz.com/db/".$callsign;
       $ch = curl_init();
       $timeout = 5;
       curl_setopt($ch, CURLOPT_URL, $url);
       curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
       curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
       $html = curl_exec($ch);
       curl_close($ch);

       $dom = new DOMDocument();
       @$dom->loadHTML($html);
       $content = $dom->getElementsByTagName('table')->item(0)->getElementsByTagName('tr')->item(0)->getElementsByTagName('td')->item(1)->nodeValue;
       if(preg_match('/produced no results/', $content)) {
          apiRequest("sendMessage", array('chat_id' => $chat_id, 'text' => $callsign." not found."));
       } else {
          $image = $dom->getElementById('mypic')->getAttribute('src');
          apiRequest("sendMessage", array('chat_id' => $chat_id, 'text' => $url));
          apiRequest("sendPhoto", array('chat_id' => $chat_id, 'photo' => $image));
       }
    }
    if (preg_match('/^\/aprs/', $text)) {
       if (!preg_match('/^\/aprs /', $text)) {
          apiRequestWebhook("sendMessage", array('chat_id' => $chat_id, "text" => 'Usage: /aprs callsign'));
       }
       preg_match("/^\/aprs ([\w-]+)/", $text, $results);
       $callsign = strtoupper($results[1]);
       ini_set( "user_agent", "AFUbot (+https://github.com/phl0/AFUbot/)" );
       $url = "http://api.aprs.fi/api/get?name=".$callsign."&what=loc&apikey=".APRSFI_APIKEY."&format=json";
       $json = file_get_contents( $url, 0, null, null );
       $obj = json_decode($json, true);
       $entries = $obj['entries'];
       if ($obj['found'] != "0") {
          foreach ($entries as $entry) {
             apiRequest("sendMessage", array('chat_id' => $chat_id, "text" => "Last known position of ".$entry['name']));
             apiRequest("sendMessage", array('chat_id' => $chat_id, "text" => "Latitude: ".$entry['lat'].", Longitude: ".$entry['lng']));
             $time = gmdate("H:i:s d.m.Y", $entry['lasttime']);
             apiRequest("sendMessage", array('chat_id' => $chat_id, "text" => "Time (UTC): ".$time));
             apiRequest("sendLocation", array('chat_id' => $chat_id, 'latitude' => $entry['lat'], 'longitude' => $entry['lng']));
          }
       } else {
          apiRequest("sendMessage", array('chat_id' => $chat_id, "text" => $callsign." not found."));
       }
    }
    if (preg_match('/^\/page/', $text)) {
       if (!preg_match('/^\/page [\w-]+ .*/', $text)) {
          apiRequestWebhook("sendMessage", array('chat_id' => $chat_id, "text" => 'Usage: /page callsign text'));
       }
       preg_match("/^\/page ([\w-]+) (.*)/", $text, $results);
       $callsign = strtoupper($results[1]);
       $text = $username.": ".$results[2];
       ini_set( "user_agent", "AFUbot (+https://github.com/phl0/AFUbot/)" );
       $data = array(
          'text' => $text,
          'callSignNames' => array(
             $callsign
          ),
          'transmitterGroupNames' => array(
             'All'
          ),
          'emergency' => 'false'
       );
       $url = DAPNET_URL;

       $content = json_encode($data);

       $curl = curl_init($url);
       curl_setopt($curl, CURLOPT_HEADER, false);
       curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
       curl_setopt($curl, CURLOPT_HTTPHEADER,
                     array("Content-type: application/json"));
       curl_setopt($curl, CURLOPT_POST, true);
       curl_setopt($curl, CURLOPT_USERAGENT, "AFUbot/0.1");
       curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
       curl_setopt($curl, CURLOPT_USERPWD, DAPNET_USERNAME.":".DAPNET_PASSWORD);
       curl_setopt($curl, CURLOPT_POSTFIELDS, $content);

       $json_response = curl_exec($curl);

       $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);

       curl_close($curl);
       if ($status == 201) {
             apiRequest("sendMessage", array('chat_id' => $chat_id, "text" => "Success!"));
       } else {
             apiRequest("sendMessage", array('chat_id' => $chat_id, "text" => "Error: ".$status));
       }
    }
  } else {
    apiRequest("sendMessage", array('chat_id' => $chat_id, "text" => 'I understand only text messages'));
  }
}

if (php_sapi_name() == 'cli') {
  // if run from console, set or delete webhook
  apiRequest('setWebhook', array('url' => isset($argv[1]) && $argv[1] == 'delete' ? '' : WEBHOOK_URL));
  exit;
}


$content = file_get_contents("php://input");
$update = json_decode($content, true);

if (!$update) {
  // receive wrong update, must not happen
  exit;
}

if (isset($update["message"])) {
  processMessage($update["message"]);
}
