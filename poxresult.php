<?php
require_once "../config.php";

use \Tsugi\OAuth\OAuthUtil;
use \Tsugi\Util\U;
use \Tsugi\Util\LTI;
use \Tsugi\Core\LTIX;

// For my application, We only allow application/xml
$request_headers = OAuthUtil::get_headers();
$hct = $request_headers['Content-Type'];
if ( ! isset($hct) ) $hct = $request_headers['Content-type'];
if ( ! isset($hct) ) $hct = $_SERVER['CONTENT_TYPE'];
if (strpos($hct,'application/xml') === false ) {
   header('Content-Type: text/plain');

   echo("Data dump:");
   print_r($request_headers);
   print_r($_SERVER);
   die("Must be content type xml, found ".$hct);
}

header('Content-Type: application/xml; charset=utf-8'); 

// Grab POX Body
$postdata = file_get_contents('php://input');

try {
    $xml = new SimpleXMLElement($postdata);
    $imsx_header = $xml->imsx_POXHeader->children();
    $imsx_body = $xml->imsx_POXBody->children();
    $operation = $imsx_body->getName();
    $parms = $imsx_body->children();
    $sourcedid = (string) $parms->resultRecord->sourcedGUID->sourcedId;
} catch (Exception $e) {
    Net::send400('Could not find sourcedid in XML body');
    return;
}

// Parse the sourcedid
$pieces = explode('::', $sourcedid);
if ( count($pieces) != 5 ) {
    Net::send400('Invalid sourcedid format');
    return;
}
if ( is_numeric($pieces[0]) && is_numeric($pieces[1]) && 
    is_numeric($pieces[2]) && is_numeric($pieces[3]) ) {
    $key_id = $pieces[0];
    $context_id = $pieces[1];
    $link_id = $pieces[2];
    $result_id = $pieces[3];
    $incoming_sig = $pieces[4];
} else {
    Net::send400('sourcedid requires 4 numeric parameters');
    return;
}
$sql = "SELECT K.secret, K.key_key, C.context_key, L.link_key, R.placementsecret
    FROM {$CFG->dbprefix}lti_key AS K
    JOIN {$CFG->dbprefix}lti_context AS C ON K.key_id = C.key_id
    JOIN {$CFG->dbprefix}lti_link AS L ON C.context_id = L.context_id
    JOIN {$CFG->dbprefix}lti_RESULT AS R ON L.link_id = R.link_id
    WHERE K.key_id = :KID AND C.context_id = :CID AND
        L.link_id = :LID AND R.result_id = :RID
    LIMIT 1";

$PDOX = LTIX::getConnection();

$row = $PDOX->rowDie($sql, array(
    ":KID" => $key_id,
    ":CID" => $context_id,
    ":LID" => $link_id,
    ":RID" => $result_id)
    );

if ( ! $row ) {
    Net::send403('Could not locate sourcedid row');
    return;
}

$oauth_consumer_key = $row['key_key'];
$oauth_consumer_secret = $row['secret'];
$placementsecret = $row['placementsecret'];

$sourcebase = $key_id . '::' . $context_id . '::' . $link_id . '::' . $result_id . '::';
$plain = $sourcebase . $placementsecret;
$sig = U::lti_sha256($plain);

if ( $incoming_sig != $sig ) {
    Net::send403('Invalid sourcedid signature');
    return;
}

// Get skeleton response
$response = LTI::getPOXResponse();

if ( strlen($oauth_consumer_key) < 1 || strlen($oauth_consumer_secret) < 1 ) {
   echo(sprintf($response,uniqid(),'failure', "Missing key/secret key=$oauth_consumer_key",$message_ref,"",""));
   return;
}

$header_key = LTI::getOAuthKeyFromHeaders();
if ( $header_key != $oauth_consumer_key ) {
   echo(sprintf($response,uniqid(),'failure', "key=$oauth_consumer_key HDR=$header_key",$message_ref,"",""));
   exit();
}

try {
    $body = LTI::handleOAuthBodyPOST($oauth_consumer_key, $oauth_consumer_secret, $postdata);
    $xml = new SimpleXMLElement($body);
    $imsx_header = $xml->imsx_POXHeader->children();
    $parms = $imsx_header->children();
    $message_ref = (string) $parms->imsx_messageIdentifier;

    $imsx_body = $xml->imsx_POXBody->children();
    $operation = $imsx_body->getName();
    $parms = $imsx_body->children();
} catch (Exception $e) {
    global $LastOAuthBodyBaseString;
    global $LastOAuthBodyHashInfo;

    $retval = sprintf($response,uniqid(),'failure', $e->getMessage().
        " key=$oauth_consumer_key HDRkey=$header_key",uniqid(),"","") .
        "<!--\n".
        "Base String:\n".$LastOAuthBodyBaseString."\n".
	"Hash Info:\n".$LastOAuthBodyHashInfo."\n-->\n";
    echo($retval);
    return;
}

die('Past validation');
$top_tag = str_replace("Request","Response",$operation);
$body_tag = "\n<".$top_tag."/>";
if ( $operation == "replaceResultRequest" ) {
    $score =  (string) $parms->resultRecord->result->resultScore->textString;
    $fscore = (float) $score;
    if ( ! is_numeric($score) ) {
        echo(sprintf($response,uniqid(),'failure', "Score must be numeric",$message_ref,$operation,$body_tag));
        exit();
    }
    $fscore = (float) $score;
    if ( $fscore < 0.0 || $fscore > 1.0 ) {
        echo(sprintf($response,uniqid(),'failure', "Score not between 0.0 and 1.0",$message_ref,$operation,$body_tag));
        exit();
    }
    echo(sprintf($response,uniqid(),'success', "Score for ".htmlspec_utf8($sourcedid)." is now $score",$message_ref,$operation,$body_tag));
    $gradebook[$sourcedid] = $score;
} else if ( $operation == "readResultRequest" ) {
    $score =  $gradebook[$sourcedid];
    $body = '
    <readResultResponse>
      <result>
        <resultScore>
          <language>en</language>
          <textString>%s</textString>
        </resultScore>
      </result>
    </readResultResponse>';
    $body = sprintf($body,$score);
    echo(sprintf($response,uniqid(),'success', "Score read successfully",$message_ref,$operation,$body));
} else if ( $operation == "deleteResultRequest" ) {
    unset( $gradebook[$sourcedid]);
    echo(sprintf($response,uniqid(),'success', "Score deleted",$message_ref,$operation,$body_tag));
} else {
    echo(sprintf($response,uniqid(),'unsupported', "Operation not supported - $operation",$message_ref,$operation,""));
}
$_SESSION['cert_gradebook'] = $gradebook;
// print_r($gradebook);
?>
