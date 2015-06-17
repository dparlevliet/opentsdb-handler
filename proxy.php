<?php
/**
 * Reluctant author @dparlevliet ( https://github.com/dparlevliet/opentsdb-query-handler )
 *
 * Background:
 *
 * OpenTSDB has a really annoying issue which will cause a 500 error if a single one
 * of your metric queries has a tag value requested that does not exist. This causes
 * all of your other legitimate requests to be lost.
 *
 * This pisses me off, because for my use-cases the value will eventually exist and I know it will
 * so I would be more than happy with an empty list rather than a 500 error that breaks
 * everything!
 *
 * This severly limits your ability to safely perform batch queries. I hope they fix
 * this error so I can throw this script in the bin where it belongs.
 *
 * I do not want to patch OpenTSDB because I don't want the headache of
 * maintaining a patched version right now so this will do in the interrum and if it
 * is not fixed in a few months then I may seek a better alternative.
 */

error_reporting(E_ALL);
ini_set('display_errors', 'On');

define('OPENTSDB_HOST', '127.0.0.1');
define('OPENTSDB_PORT', 4242);


$json = file_get_contents('php://input');
if (sizeof($json) == 0)
{
  // check if this is an OPTIONS request
  if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    // Forward OPTIONS headers to Host and relay responses
  } else {
    // really?
    http_response_code(412);
  }
  exit;
}

$json = json_decode($json, true);
$results = Array();

// make sure we're talking the same language
if (isset($json['queries']) && is_array($json['queries']))
{
  $headers = getallheaders();

  foreach ($json['queries'] as $offset=>$query)
  {
    $new_json = $json;
    $new_json['queries'] = Array($query);
    $new_json = json_encode($new_json);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
    curl_setopt($ch, CURLOPT_URL, 'http://'.OPENTSDB_HOST.':'.OPENTSDB_PORT.'/api/query');
    curl_setopt($ch, CURLOPT_POSTFIELDS, $new_json);
    curl_setopt($ch, CURLOPT_HEADER, 1);

    foreach ($headers as $key=>$header)
    {
      if (stristr('content-length', $header) !== false)
      {
        $headers[$key] = 'Content-Length: ' . strlen($new_json);
      }
    }

    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $response       = curl_exec($ch);
    $header_size    = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $header         = substr($response, 0, $header_size);
    $body           = substr($response, $header_size);
    $response_code  = curl_getinfo($ch,CURLINFO_HTTP_CODE);

    if ($response_code == 200) {
      $returned_json = json_decode(trim($body, '"'), true);
      $results[$offset] = $returned_json[0];
    } else {
      $results[$offset] = Array(
        "metric" => $query['metric'],
        "tags" => $query['tags'],
        "aggregatedTags" => Array(),
        "dps" => Array(),
      );
    }

    curl_close($ch);
  }
}

header('Content-Type: application/json; charset=UTF-8');
echo json_encode($results);