<?php
/**
 * This task should be piped-to from an email forwarder.
 * Eg: all mails sent to bounces@mysite.com should get piped to this script with | ./sake /BounceEmailTask
 *
 */
class BounceEmailTask extends Controller{

	static $allowed_actions = array(
		'*' => 'ADMIN'
	);

    function index() {
        $this->process();
    }

	function process(){
		echo "\n";
		echo "checking bounced email...\n";

		$emailData = file_get_contents("php://stdin"); //read in email via php://stdin
		$parts = $this->decodeMultipart($emailData, array("text/plain","message/rfc822"));

		$bounces = array();

		if($parts) foreach($parts as $part) {
			// We split the message into candidate quoted-header-segment chunks
			// We then see if both headers appear in any of these chunks together
			// This should hopefully allow for erroneous e-mail structure robustly
			$blocks = split("(\n\r?){2}", $part[body]);

			foreach($blocks as $blockNum => $block) {

				if(ereg("X-SilverStripeMessageID: *([^ \n\r\t]+)[ \n\r\t]", $block, $parts))
					$messageID = $parts[1];
				if(ereg("X-SilverStripeSite: *([^ \n\r\t]+)[ \n\r\t]", $block, $parts))
					$ssURL = $parts[1];
		        if(ereg("X-SilverStripeBounceURL: *([^ \n\r\t]+)[ \n\r\t]", $block, $parts)) {
					$ssBounceURL = $parts[1];
		            $info['BounceURL'] = $ssBounceURL;
		        }

				if(ereg("To: *([^\n\r\t]+)[\n\r\t]", $block, $parts)) {
					$info['FullEmail'] = $parts[1];
					if(ereg('^([^ ]+) +([^<]+) *<([^>]+)>', trim($parts[1]), $parts2)) {
						$info['FirstName'] = $parts2[1];
						$info['Surname'] = $parts2[2];
						$info['Email'] = $parts2[3];
					} else {
						$info['Email'] = $parts[1];
					}
				}

				if($messageID && $ssURL) {
					// Get the text message up until now
					$textMessage = implode("\n", array_slice($blocks, 0, $blockNum - 1));
					$mungedMessage = ereg_replace("[\t\r\n ]+", " ", $textMessage);

					$regEmail = str_replace(".",'\.', $info['Email']);
					$regFullEmail = str_replace(".",'\.', $info['FullEmail']);


		  			// Look for the full address followed by "malformed address"
					if(ereg("$regFullEmail.*malformed +address", $mungedMessage, $parts)) {
						$info['Text'] = "Invalid e-mail address.  Check for stray commas and dots";

		  			// Look for the full address preceededby "incorrectly constructed"
					} else if(ereg("incorrectly +constructed.*$regFullEmail", $mungedMessage, $parts)) {
						$info['Text'] = "Invalid e-mail address.  Check for stray commas and dots";

		  			// Look for unrouta
					} else if(ereg("$regEmail.*unrouteable +mail +domain +([^ ]+)", $mungedMessage, $parts)) {
						$info['Text'] = "Cannot find the domain name $parts[1]";

					//	Otherwise, default to the entire message
					} else {
						$info['Text'] = $mungedMessage;
					}

					list($info['SiteName'], $emailID) = explode(".",$messageID,2);
					$bounces[$ssURL][$emailID] = $info;

				}

				unset($messageID);
				unset($ssURL);
				unset($info);
			}
		}

		if(count($bounces) > 0){
			foreach($bounces as $ssURL){
				foreach($ssURL as $bounce){
					//todo:
					// if($ssURL = $project){//if site name matches global $project

					//}else{//if not match, then vist bounce handler url to submit the bounce to the other site

					$text = (isset($bounce['Text'])) ? $bounce['Text'] : null;
					$this->recordBounce( $bounce['Email'], null,null, $text); // recordBounce is a private method, so I copied below
				}
			}
		}else{
			echo "No bounce detected\n";
		}


		echo "\n";
	}


	/**
	 * Breaks a nested multipart e-mail down into each component
	 * Some components will be sub-components of other ones
	 * It will ignore the effects of rfc822 email files
	 */
	function decodeMultipart($emailData, $limitToContentType = null, $indent = "") {
		$emailParts = array();

		// Get the outermost e-mail
		$email = $this->readEmail($emailData);

		// If we have a .eml attachment just get the contents of the attachment
		if(substr($email['content-type'],0,14) == "message/rfc822") {
			$email = $this->readEmail($email['body']);
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

			$multiparts = explode($boundary, $email['body']);
			// Remove the first part - it's junk anyway
			array_shift($multiparts);

			// Recursively add to $emailParts
			foreach($multiparts as $multipart) {
				// -- signals the end of the parts
				if(trim($multipart) == "--") break;

				// $multipart is another valid email; they can be nested indefinitely
				array_merge($this->decodeMultipart($multipart, $emailParts, $limitToContentType, "$indent  "),$emailParts);

			}
		}

		// Do we have nested emails?
		if(preg_match("/[\r\n]Content-Type:/i", $email['body'])) {
			array_merge($this->decodeMultipart($email['body'], $emailParts, $limitToContentType),$emailParts);
		}

		return $emailParts;
	}


	/**
	 * Splits an email into headers and body.  Returns a map, keyed by lowercase header names
	 *  - if there are more than one of the same header, each value is given in an array
	 *  - multiline headers a handled okay, output header is a single line however
	 *  - the body is given as the 'body' element of the map
	 *  - content-transfer-encoding is recognised, and content is decoded
	 */
	function readEmail($emailData) {
		// Remove leading whitespace
		$emailData = ereg_replace("^[\t\r\n ]+", "", $emailData);

		list($headers, $email['body']) = split("(\n\r?){2}", $emailData, 2);
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
			} else if(substr($header,0,1)=="\t") {
				$mostRecentHeader .= ' ' . trim($header);
			}
		}

		// Handle content transfer encoding
		switch(strtolower($email['content-transfer-encoding'])) {
			case 'quoted-printable':
				$email['body'] = preg_replace('/=([A-Za-z0-9]{2})/e', 'chr(hexdec("$1"))', $email['body']);
				break;
		}

		return $email;
	}

    private function recordBounce( $email, $date = null, $time = null, $error = null ) {
	  	if(ereg('<(.*)>', $email, $parts)) $email = $parts[1];

	  	$SQL_email = Convert::raw2sql($email);
	  	$SQL_bounceTime = Convert::raw2sql("$date $time");

    	$duplicateBounce = DataObject::get_one("Email_BounceRecord", "BounceEmail = '$SQL_email' AND (BounceTime+INTERVAL 1 MINUTE) > '$SQL_bounceTime'");

    	if(!$duplicateBounce) {
	        $record = new Email_BounceRecord();

	        $member = DataObject::get_one( 'Member', "`Email`='$SQL_email'" );

	        if( $member ) {
	            $record->MemberID = $member->ID;
	            $member->Bounced = true;
	            $member->write();

				// If the SilverStripeMessageID (taken from the X-SilverStripeMessageID header embedded in the email) is sent,
				// then log this bounce in a Newsletter_SentRecipient record so it will show up on the 'Sent Status Report' tab of the Newsletter
				if( isset($_REQUEST['SilverStripeMessageID'])) {
					// Note: was sent out with: $project . '.' . $messageID;
					$message_id_parts = explode('.', $_REQUEST['SilverStripeMessageID']);
					// Note: was encoded with: base64_encode( $newsletter->ID . '_' . date( 'd-m-Y H:i:s' ) );
					$newsletter_id_date_parts = explode ('_', base64_decode($message_id_parts[1]) );

					// Escape just in case
					$SQL_memberID = Convert::raw2sql($member->ID);
					$SQL_newsletterID = Convert::raw2sql($newsletter_id_date_parts[0]);
					// Log the bounce
					$oldNewsletterSentRecipient = DataObject::get_one("Newsletter_SentRecipient", "MemberID = '$SQL_memberID' AND ParentID = '$SQL_newsletterID' AND Email = '$SQL_email'");
		    			// Update the Newsletter_SentRecipient record if it exists
		    			if($oldNewsletterSentRecipient) {
						$oldNewsletterSentRecipient->Result = 'Bounced';
						$oldNewsletterSentRecipient->write();
					} else {
						// For some reason it didn't exist, create a new record
						$newNewsletterSentRecipient = new Newsletter_SentRecipient();
						$newNewsletterSentRecipient->Email = $SQL_email;
						$newNewsletterSentRecipient->MemberID = $member->ID;
						$newNewsletterSentRecipient->Result = 'Bounced';
						$newNewsletterSentRecipient->ParentID = $newsletter_id_date_parts[0];
						$newNewsletterSentRecipient->write();
					}

					// Now we are going to Blacklist this member so that email will not be sent to them in the future.
					// Note: Sending can be re-enabled by going to 'Mailing List' 'Bounced' tab and unchecking the box under 'Blacklisted'
					//$member->setBlacklistedEmail(TRUE);
					echo '<p><b>Member: '.$member->FirstName.' '.$member->Surname.' <'.$member->Email.'> was added to the Email Blacklist!</b></p>';
				}

			}

	        if( !$date )
	            $date = date( 'd-m-Y' );

	        if( !$time )
	            $time = date( 'H:i:s' );

	        $record->BounceEmail = $email;
	        $record->BounceTime = $date . ' ' . $time;
	        $record->BounceMessage = $error;
	        $record->write();

	        echo "Handled bounced email to address: $email";
		} else {
			echo 'Sorry, this bounce report has already been logged, not logging this duplicate bounce.';
		}
    }

}
?>