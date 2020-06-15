# imap

IMAP class for reading IMAP emails with PHP

## Install

Using composer include the repository by typing the following into a terminal

```
composer require dcblogdev/imap
```

Example usage:

```php
//set search criteria
$date = date('d-M-y', strtotime('1 day ago'));
$term = 'ALL UNDELETED SINCE "'.$date.'"';

//ignore array of emails
$exclude = [];

$email    = 'someone@domain.com'
$password = 'emailpassword';
$host     = 'outlook.office365.com'//your email host
$port     = '993'//port number
$savePath = "emails";//folder to save attachments
$delete   = false;//set to true to delete email

//initialise email
$imap = new Imap($email, $password, $host, $port, 'Inbox', $savePath, $delete);

//get emails pass in the search term and exclude array
$emails = $imap->emails($term, $exclude);
```
