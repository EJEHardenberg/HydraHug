<?php

include dirname(__FILE__) . '/twitteroauth/config.php';
include dirname(__FILE__) . '/twitteroauth/twitteroauth/twitteroauth.php';

$dblink = mysql_connect(DB_HOST, DB_USER, DB_PASS);
mysql_select_db(DB_NAME, $dblink);

/* Get unfinished jobs */
/* Retrieve credentials for users */
$jobSql = "SELECT jobs.id as jobid, last_id, owner_id as listOwnerToBeFollowedOwner,oauth_token,oauth_secret" 
         ." FROM jobs JOIN users ON users.twitter_id = jobs.follower_id WHERE status != 'FINISHED'";
$jobRes = mysql_query($jobSql, $dblink);

if($jobRes === FALSE){
    echo 'jobRes query failed' . '\n';
    exit;
}

/* Iterate through "to follows" from database */
echo ("Beginning Job Processing " . mysql_num_rows($jobRes)) . '\n';
while (($jobInfo = mysql_fetch_object($jobRes)) != FALSE) {
    $jobid = $jobInfo->jobid;
    $jobStatus = "RUNNING";
    if ( mysql_query("UPDATE jobs SET status = \"$jobStatus\" WHERE id = $jobid", $dblink) === FALSE ){
        echo 'Could not update job ' . $jobid . ' to running status' . '\n';
        continue;
    }

    $lastid = 0;
    if(!is_null($jobInfo->last_id)){
        $lastid = intval($jobInfo->last_id);
    }

    $followListSQL = "SELECT id,to_follow FROM lists WHERE owner_id = " . $jobInfo->listOwnerToBeFollowedOwner . " AND id >= $lastid ORDER BY id ASC";
    $followRes = mysql_query($followListSQL, $dblink);
    if($followRes === FALSE){
        echo 'Failed to select list owner' . '\n';
        continue;
    }

    $processed = 0;
    $lastid = 0;
    while(($followRow = mysql_fetch_object($followRes)) != FALSE ){

        $twitter = new TwitterOAuth(CONSUMER_KEY, CONSUMER_SECRET, $jobInfo->oauth_token, $jobInfo->oauth_secret);
        if (!is_object($twitter)) {
            echo('Error creating TwitterOAuth object') . '\n';
            exit (-1);
        }       
        $twitter->host = 'https://api.twitter.com/1.1/';

        $params = array(
            'user_id' => $followRow->to_follow,
            'follow' => true
        );

        $followed = $twitter->post('friendships/create', $params);
        if (!is_object($followed) || isset($followed->errors)) {

            if ($followed->errors[0]->code == 160) {
                /* Already followed... */
            } elseif ($followed->errors[0]->code == 161 ) {
                $ref = uniqid();
                mysql_query("UPDATE jobs SET status = \"NO_MORE_FOLLOW\", message = \"You've hit the twitter daily follow limit! See: http://support.twitter.com/articles/66885-i-can-t-follow-people-follow-limits Ref: $ref\", last_id = {$followRow->id} WHERE id = $jobid", $dblink);
                echo $ref . ' ' . print_r($followed,1) . '\n';
                goto next;
            } elseif ($followed->errors[0]->code == 162) {
                //user has been blocked from following, so just let it go.
            } else {
                $ref = uniqid();
                mysql_query("UPDATE jobs SET status = \"ERROR\", message = \"Problem while processing, Ref: $ref\", last_id = {$followRow->id} WHERE id = $jobid", $dblink);
                echo $ref . ' ' . print_r($followed,1) . '\n';
                goto next;
            }
        } else {
            if($processed % 10){
                mysql_query("UPDATE jobs SET status = \"RUNNING\", message = \"Last processed id: {$followRow->to_follow}\", last_id = {$followRow->id} WHERE id = $jobid", $dblink);    
            }
        }
        $processed++;
        $lastid = $followRow->id;
    }
    mysql_query("UPDATE jobs SET status = \"FINISHED\", message = \"Done processing. Processed: $processed followers\", last_id = $lastid WHERE id = $jobid", $dblink);    
    next:
}
echo ("Done Running Jobs") . '\n';
mysql_close($dblink);
?>