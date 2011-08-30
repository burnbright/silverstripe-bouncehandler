#!/usr/bin/php5 -q
<?

//require_once("common/files.php"); //not found

chdir(dirname(__FILE__));

if($_SERVER[USER] == "bounce" && $emailData = file_get_contents("php://stdin")) {
	//writeToFile("lastReceivedEmail", $emailData); //not found, probably in common/files.php
} else if($_SERVER[argv][1]) {
	$emailData = file_get_contents($_SERVER['argv'][1]);
} else {
	$emailData = file_get_contents("lastReceivedEmail");
}

decodeMultipart($emailData, $parts, array("text/plain","message/rfc822"));

if($parts) foreach($parts as $part) {
	// We split the message into candidate quoted-header-segment chunks
	// We then see if both headers appear in any of these chunks together
	// This should hopefully allow for erroneous e-mail structure robustly
	$blocks = split("(\n\r?){2}", $part[body]);

	foreach($blocks as $blockNum => $block) {
		unset($messageID);
		unset($ssURL);
		unset($info);

		if(ereg("X-SilverStripeMessageID: *([^ \n\r\t]+)[ \n\r\t]", $block, $parts))
			$messageID = $parts[1];
		if(ereg("X-SilverStripeSite: *([^ \n\r\t]+)[ \n\r\t]", $block, $parts))
			$ssURL = $parts[1];
        if(ereg("X-SilverStripeBounceURL: *([^ \n\r\t]+)[ \n\r\t]", $block, $parts)) {
			$ssBounceURL = $parts[1];
            $info['BounceURL'] = $ssBounceURL;
        }

		if(ereg("To: *([^\n\r\t]+)[\n\r\t]", $block, $parts)) {
			$info[FullEmail] = $parts[1];
			if(ereg('^([^ ]+) +([^<]+) *<([^>]+)>', trim($parts[1]), $parts2)) {
				$info[FirstName] = $parts2[1];
				$info[Surname] = $parts2[2];
				$info[Email] = $parts2[3];
			} else {
				$info[Email] = $parts[1];
			}
		}

		if($messageID && $ssURL) {
			// Get the text message up until now
			$textMessage = implode("\n", array_slice($blocks, 0, $blockNum - 1));
			$mungedMessage = ereg_replace("[\t\r\n ]+", " ", $textMessage);

			$regEmail = str_replace(".",'\.', $info[Email]);
			$regFullEmail = str_replace(".",'\.', $info[FullEmail]);


  			// Look for the full address followed by "malformed address"
			if(ereg("$regFullEmail.*malformed +address", $mungedMessage, $parts)) {
				$info[Text] = "Invalid e-mail address.  Check for stray commas and dots";

  			// Look for the full address preceededby "incorrectly constructed"
			} else if(ereg("incorrectly +constructed.*$regFullEmail", $mungedMessage, $parts)) {
				$info[Text] = "Invalid e-mail address.  Check for stray commas and dots";

  			// Look for unrouta
			} else if(ereg("$regEmail.*unrouteable +mail +domain +([^ ]+)", $mungedMessage, $parts)) {
				$info[Text] = "Cannot find the domain name $parts[1]";

			//	Otherwise, default to the entire message
			} else {
				$info[Text] = $mungedMessage;
			}

			list($info[SiteName], $emailID) = explode(".",$messageID,2);
			$bounces[$ssURL][$emailID] = $info;
		}
	}
}

if($bounces) foreach($bounces as $site => $siteBounces) {
	foreach($siteBounces as $messageID => $data) {
		if(!$data[SiteName]) $data[SiteName] = "unknown";
		$fn = "/fileshares/project/kristovScripts/bouncelogs/$data[SiteName].csv";

		if(file_exists($fn)) $fH = fopen($fn,'a');
		else $fH = fopen($fn,'w');

		if($fH) {
			fwrite($fH, "$data[Email]," . date('Y-m-d') . "," . date('h:i') . "," . addslashes($data[Text]) . "\",BounceURL=" . $data['BounceURL'] . "\n");
			fclose($fH);
			chmod($fn, 0777);
		}

        // notify the domain
        if( $data['BounceURL'] ) {

            $pathIndex = stripos( $data['BounceURL'], '/' );
            $host = substr( $data['BounceURL'], 0, $pathIndex );
            $path = substr( $data['BounceURL'], $pathIndex );

            $fp = fsockopen( $host, 80, $errno, $error );

           // $statusFile = "/fileshares/project/kristovScripts/bouncelogs/$data[SiteName].log";

           // if( file_exists( $statusFile ) ) $status = fopen( $statusFile, 'a' );
          //  else $status = fopen( $statusFile, 'w' );

           // if( $errno )
                //fwrite( $status, $error."\n" );

            if( !$fp ) {
                continue;
                //fclose( $status );
            }

            $sendData =
"Email=".urlencode($data['Email'])."&Date=".urlencode(date('Y-m-d'))."&Time=".urlencode(date('H:i:s'))."&Message=".urlencode($data['Text'])."&MessageID=".$messageID."&Key=1aaaf8fb60ea253dbf6efa71baaacbb3";
            $length = strlen( $sendData );

            $send =  "POST $path HTTP/1.1\r\n";
            $send .= "Host: $host\r\n";
            $send .= "User-Agent: SilverStripe Bounce Handler\r\n";
            $send .= "Connection: Close\r\n";
            $send .= "Content-Type: application/x-www-form-urlencoded\r\n";
            $send .= "Content-Length: $length\r\n\r\n";

            $send .= "$sendData\r\n";

            $response = '';

            fwrite($fp, $send);

            while (!feof($fp)) {
                 $response .= fgets($fp, 128);
            }

         //  fwrite($status, $send);
         //   fwrite($status, 'Response: '. $response ."\r\n\r\n" );
         //  fclose( $status );
            fclose($fp);
        }
	}
}

/*
 * Breaks a nested multipart e-mail down into each component
 * Some components will be sub-components of other ones
 * It will ignore the effects of rfc822 email files
 */
function decodeMultipart($emailData, &$emailParts, $limitToContentType = null, $indent = "") {
	// Get the outermost e-mail
	$email = readEmail($emailData);

	// If we have a .eml attachment just get the contents of the attachment
	if(substr($email['content-type'],0,14) == "message/rfc822") {
		$email = readEmail($email[body]);
	}

	if(!is_array($limitToContentType)) $limitToContentType = array($limitToContentType);

	if(!$limitToContentType || !$email['content-type']) {
		$emailParts[] = $email;
	} else {
		foreach($limitToContentType as $ct) {
			if(substr(strtolower($email['content-type']),0,strlen($ct)) == $ct) {
				$emailParts[] = $email;
				break;
			}
		}
	}


//	unset($parts[sizeof($parts)-1][body]);

	// Do we have multiparts?
	if(substr($email['content-type'],0,10) == "multipart/" && ereg('boundary="([^"]+)"', $email['content-type'], $parts)) {
		$boundary = $parts[1];

		$multiparts = explode($boundary, $email[body]);
		// Remove the first part - it's junk anyway
		array_shift($multiparts);

		// Recursively add to $emailParts
		foreach($multiparts as $multipart) {
			// -- signals the end of the parts
			if(trim($multipart) == "--") break;

			// $multipart is another valid email; they can be nested indefinitely
			decodeMultipart($multipart, $emailParts, $limitToContentType, "$indent  ");

		}
	}

	// Do we have nested emails?
	if(preg_match("/[\r\n]Content-Type:/i", $email['body'])) {
		decodeMultipart($email['body'], $emailParts, $limitToContentType);
	}
}

/*
 * Splits an email into headers and body.  Returns a map, keyed by lowercase header names
 *  - if there are more than one of the same header, each value is given in an array
 *  - multiline headers a handled okay, output header is a single line however
 *  - the body is given as the 'body' element of the map
 *  - content-transfer-encoding is recognised, and content is decoded
 */
function readEmail($emailData) {
	// Remove leading whitespace
	$emailData = ereg_replace("^[\t\r\n ]+", "", $emailData);

	list($headers, $email[body]) = split("(\n\r?){2}", $emailData, 2);
	$headers = split("(\n\r?)", trim($headers));

	foreach($headers as $header) {
		// Normal header
		if(ereg('^([^ ]+ *):(.*)', $header, $parts)) {
			$headerName = strtolower(trim($parts[1]));
			$headerValue = trim($parts[2]);

			// There are more than one of these headers already; append to the array
			if(is_array($email[$headerName])) {
				$email[$headerName][] = $headerValue;
				// Keep a track of the most recent header so multiline headers can be appended
				$mostRecentHeader = &$email[$headerName][sizeof($email[$headerName])-1];

			// Second header added with this name; we must turn a string header into an array
			} else if($email[$headerName]) {
				$email[$headerName] = array(
					$email[$headerName],
					$headerValue
				);
				$mostRecentHeader = &$email[$headerName][1];

			// Normal Case
			} else {
				$email[$headerName] = $headerValue;
				$mostRecentHeader = &$email[$headerName];
			}


		// Second line of a multiline header
		} else if($mostRecentHeader && substr($header,0,1)=="\t") {
			$mostRecentHeader .= ' ' . trim($header);
		}
	}

	// Handle content transfer encoding
	switch(strtolower($email['content-transfer-encoding'])) {
		case 'quoted-printable':
			$email[body] = preg_replace('/=([A-Za-z0-9]{2})/e', 'chr(hexdec("$1"))', $email[body]);
			break;
	}

	return $email;
}

?>
