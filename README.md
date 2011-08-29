SilverStripe email bounce handling
==================================

Handles incoming emails that have bounced as a result of past sent emails.

Emails sent out must be marked with the appropriate header so that bounces come to the right place.

## Requirements

 * SilverStripe 2.4

## Installation

 * Add folder to your SilverStripe installation directory.
 * add "define('BOUNCE_EMAIL','bounceaddress@mysite.com');" to your _config.php file.
 	- This is then set by Mailer.php when sending emails
 * Reading emails
 	- Pipe incoming emails addressed to 'bounceaddress@mysite.com' to | silverstripe/sapphire/sake /BounceEmailTask
 	
 
## External Resources:

 * http://en.wikipedia.org/wiki/Bounce_address
 * http://en.wikipedia.org/wiki/Non_delivery_report
 
Other solutions:
 
 * http://www.umailcampaign.com/Article/16.aspx
 * http://www.boogietools.com/Products/Linux/

 ## History

SilverStripe's core functionality for bounce handling is limited / incomplete (as of v2.4).
Bounce handling appears to have been started with the Newsletter module. This is evident by
the newsletter-specific code visible in Email.php