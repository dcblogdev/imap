[![Latest Version on Packagist](https://img.shields.io/packagist/v/dcblogdev/imap.svg?style=flat-square)](https://packagist.org/packages/dcblogdev/imap)
[![Total Downloads](https://img.shields.io/packagist/dt/dcblogdev/imap.svg?style=flat-square)](https://packagist.org/packages/dcblogdev/imap)

![Logo](https://repository-images.githubusercontent.com/74881390/3b747080-49bf-11eb-9d44-c941e96ba0e7)

IMAP class for reading IMAP emails with PHP

# Example usage:

```php
use Dcblogdev\Imap\Imap;

//set search criteria
$date = date('d-M-y', strtotime('1 week ago'));
$term = 'ALL UNDELETED SINCE "'.$date.'"';

//ignore array of emails
$exclude = [];

$email    = 'someone@domain.com';
$password = 'emailpassword';
$host     = 'outlook.office365.com';//your email host
$port     = '993';//port number
$savePath = "emails";//folder to save attachments
$markAsSeen = true;//when true mark email as been read
$delete   = false;//set to true to delete email

//initialise email
$imap = new Imap($email, $password, $host, $port, 'Inbox', $savePath, $markAsSeen, $delete);

//get emails pass in the search term and exclude array
$emails = $imap->emails($term, $exclude);

//loop over emails and display
foreach($emails as $email) {
	
	echo "Account {$email['account']}<br>";
	echo "Subject {$email['subject']}<br>";
	echo "From {$email['fromName']} ({$email['fromAddress']})<br>";
	echo "To {$email['toAddress']}<br>";
	echo "CC {$email['ccAddress']}<br>";
	echo "Date {$email['emailDate']}<br>";
	echo count($email['attachments'])." Attachments<br>";

	foreach($email['attachments'] as $attachment) {
		echo "<a href='{$attachment['file']}'>{$attachment['fileName']}</a>";
	}

	echo "<br><br>";
	if ($email['htmlBody'] !='') {
		echo $email['htmlBody'];
	} else {
		echo nl2br($email['plainBody']);
	}
	echo "<hr>";
}
```
