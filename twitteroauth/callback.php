<?php
/**
 * @file
 * Take the user when they return from Twitter. Get access tokens.
 * Verify credentials and redirect to based on response from Twitter.
 */

/* Start session and load lib */
session_start();
require_once('twitteroauth/twitteroauth.php');
require_once('config.php');

/* If the oauth_token is old redirect to the connect page. */
if (isset($_REQUEST['oauth_token']) && $_SESSION['oauth_token'] !== $_REQUEST['oauth_token']) {
  $_SESSION['oauth_status'] = 'oldtoken';
  header('Location: ./clearsessions.php');
}

/* Create TwitteroAuth object with app key/secret and token key/secret from default phase */
$connection = new TwitterOAuth(CONSUMER_KEY, CONSUMER_SECRET, $_SESSION['oauth_token'], $_SESSION['oauth_token_secret']);

/* Request access tokens from twitter */
$access_token = $connection->getAccessToken($_REQUEST['oauth_verifier']);
$account = $connection->get('account/verify_credentials');

/* Save temporary credentials to session. */
$_SESSION['oauth_token'] = $token = $request_token['oauth_token'];
$_SESSION['oauth_token_secret'] = $request_token['oauth_token_secret'];


$dblink = mysql_connect(DB_HOST, DB_USER, DB_PASS);
mysql_select_db(DB_NAME, $dblink);

$res = mysql_query('SELECT id FROM users WHERE twitter_id =' . $account->id);

$id = null;
if(!mysql_num_rows($res)){
	if( FALSE === mysql_query(
		'INSERT INTO users (twitter_name,twitter_id,oauth_token,oauth_secret) VALUES ('. implode(',',
			array($account->screen_name, $account->id, $access_token['oauth_token'], $access_token['oauth_token_secret'])
		).')')
	){
		error_log("Could not create twitter record in database");
		die('Problem saving your twitter information for processing');
	}
	$id = mysql_insert_id();
}else{
	$row = mysql_fetch_object($res);
	$id = $row->id;
}

mysql_close($dblink);

/* Save the access tokens. Normally these would be saved in a database for future use. */
$_SESSION['access_token'] = $access_token;

/* Remove no longer needed request tokens */
unset($_SESSION['oauth_token']);
unset($_SESSION['oauth_token_secret']);

/* If HTTP response is 200 continue otherwise send to connect page to retry */
if (200 == $connection->http_code) {
  /* The user has been verified and the access tokens can be saved for future use */
  	$_SESSION['status'] = 'verified';
  	if(isset($_SESSION['state'])){
  		switch ($_SESSION['state']) {
  			case 'makelist':
		  		$twitter = new TwitterOAuth(CONSUMER_KEY, CONSUMER_SECRET, $access_token['oauth_token'], $access_token['oauth_token_secret']);
				if (!is_object($twitter)) {
				    error_log('Error creating TwitterOAuth object');
			    	exit (-1);
				}
				$twitter->host = 'https://api.twitter.com/1.1/';

				$cursor = -1; // first page
				$follower_total = 0;
				$followerIds = array();
				while ($cursor != 0) {
					$params = array(
				        'stringify_ids' => true,
				        'count' => 100,
				        'cursor' => $cursor,
				    );

				    $followers = $twitter->get('followers/ids', $params);
				    if (!is_object($followers) || isset($followers->errors)) {
				        error_log ("Error retrieving followers");
				        print_r($followers);
				        exit (-1);
				    }

				    $sql = "INSERT INTO lists (owner_id,to_follow) VALUES ";
				    foreach ($followers->id as $id) {
				    	$sql .= '(' . $account->id . ',' . $id . ')';
				    }

				    if(count($followers->id) != 0){
				    	mysql_query($sql);
				    }
				}
				$_SESSION['twitter_id'] = $account->id;
				unset($_SESSION['state']);
		  		header('Location: /list.php');
		  		break;
		  	case 'follow':
		  		/* We have been asked to follow someone, create a job */
		  		if(!isset($_SESSION['owner_id'])){
		  			die('Session Issue');
		  		}
		  		$jobid = uniqid($_SESSION['owner_id']);
		  		$sqlValues = array(
		  			'"'.$jobid.'"'.,
		  			$account->id,
		  			$_SESSION['owner_id'],
		  			"\"Job waiting to run\"",
		  			"\"CREATED\""
		  		);
		  		$sql = "INSERT INTO jobs (owner_id, follower_id, job_id, message, status) VALUES (" .implode(',',$sqlValues). ")";
		  		mysql_query($sql);
		  		header('Location: /jobs?job_id=' . $jobid);
		  		break;
		  	default:
  				header('Location: /');		
  				break;
	}else{
		header('Location: /');		
	}
} else {
  /* Save HTTP status for error dialog on connnect page.*/
  header('Location: ./clearsessions.php');
}
