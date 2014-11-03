<?php
/**
 * Auto-conflict detector
 *
 * Runs as a single webpage that receives POSTs from GitHub
 * once a branch is pushed, and queues push data for another
 * script that detects conflicts with all other branches in 
 * repository.
 */

include 'src/Git.php';
include 'src/Hipchat.php';

// log the request
file_put_contents('.logs/git.log', "REQUEST PAYLOAD: \n".$_REQUEST['payload']."\n", FILE_APPEND);

// parse JSON
try {
	$payload = json_decode($_REQUEST['payload']);
}
catch (Exception $e)
{
	die('Invalid request payload');
}

// Write raw JSON payload to a file to be processed async by detect_conflicts_process.php
$queue_filename = ".queue/".$payload->before."-".$payload->after;
file_put_contents($queue_filename, $_REQUEST['payload']);

// respond with success
echo("Request queued");