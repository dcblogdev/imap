<?php
namespace Dcblogdev\Imap;

class Imap
{
    protected $imap;
    protected $email;
    protected $folder;
    protected $connection;
    protected $saveFolder;
    protected $markAsSeen;
    protected $delete;
    protected $accountPath;

    public function __construct($email, $password, $host, $port, $folder, $saveFolder = 'attachments', $markAsSeen = false, $delete = false)
    {
        $this->email      = $email;
        $this->folder     = $folder;
        $this->saveFolder = $saveFolder;
        $this->markAsSeen = $markAsSeen;
        $this->delete     = $delete;
        $secure           = '';

        // Connect to a remote imap server
        if ($port != '143') {
            $secure = 'ssl/';
        }

        $this->connection  = "{{$host}:{$port}/imap/{$secure}novalidate-cert}$folder";
        $this->imap        = imap_open($this->connection, $email, $password);
        $this->accountPath = $this->saveFolder.'/'.$this->email;

        //create folder for email account
        if (!file_exists($this->accountPath)) {
            mkdir($this->accountPath, 0777, true);
        }
    }

    public function getFolders()
    {
        if ($this->imap) {
            $foldersList = imap_list($this->imap, $this->connection, "*");
            $folders     = [];

            if (is_array($foldersList)) {
                foreach ($foldersList as $folder) {
                    $folder    = str_replace($this->connection, '', $folder);
                    $folders[] = $folder;
                }
            }

            return $folders;
        }
    }

    public function emails($searchTerms, $exclude)
    {
        $search = imap_search($this->imap, $searchTerms);

        if (is_array($search)) {
            /* put the newest emails on top */
            rsort($search);

            $data = [];

            foreach ($search as $mid) {
                $check  = [];
                $header = imap_header($this->imap, $mid);

                $subject = $header->subject;
                $msgno   = $header->Msgno;
                $from    = $header->from;
                $date    = $header->udate;
                $to      = $header->to;

                if (isset($header->cc)) {
                    $cc = $header->cc;
                } else {
                    $cc = null;
                }

                $fromAddress = null;
                $toAddress   = null;
                $ccAddress   = null;

                if (is_array($from)) {
                    foreach ($from as $id => $object) {
                        $fromName    = $object->personal;
                        $fromAddress .= $object->mailbox."@".$object->host;
                        $check[]     = strtolower($object->mailbox."@".$object->host);
                    }
                }

                if (is_array($to)) {
                    foreach ($to as $id => $object) {
                        $toAddress .= $object->mailbox."@".$object->host.' ';
                        $check[]   = strtolower($object->mailbox."@".$object->host);
                    }
                }

                if (is_array($cc)) {
                    foreach ($cc as $id => $object) {
                        $ccAddress .= $object->mailbox."@".$object->host.' ';
                        $check[]   = strtolower($object->mailbox."@".$object->host);
                    }
                }

                $target = [
                    strtolower($this->email),
                    strtolower($fromAddress),
                    strtolower($toAddress),
                    strtolower($ccAddress)
                ];

                //if not in the excluded array add to array
                if ($this->is_in_array($exclude, $target) == false && $this->is_in_array($exclude, $check) == false) {

                    $data[] = [
                        'account'     => $this->email,
                        'folder'      => $this->folder,
                        'subject'     => $this->decode_utf8($subject),
                        'msgno'       => $msgno,
                        'emailDate'   => date('Y-m-d H:i:s', $date),
                        'fromName'    => $fromName,
                        'fromAddress' => $fromAddress,
                        'toAddress'   => $toAddress,
                        'ccAddress'   => $ccAddress,
                        'htmlBody'    => $this->getBody($mid, $type = 'html'),
                        'plainBody'   => $this->getBody($mid, $type = 'plain'),
                        'attachments' => $this->getAttachments($mid),
                    ];

                    if ($this->delete === true) {
                        imap_delete($this->imap, $mid); //mark for deletion
                        imap_expunge($this->imap); //delete all marked for deletion
                    } else {
                        //only applies if the email is not deleted
                        if ($this->markAsSeen === false) {
                            imap_clearflag_full($this->imap, $mid, "\\Seen"); //remove seen
                            imap_expunge($this->imap);//confirm request
                        }
                    }
                }
            }

            return $data;
        }
    }

    public function getBody($mid, $type = 'html')
    {
        if ($type == 'html') {
            $body = $this->get_part($mid, "TEXT/HTML");
        } else {
            $body = $this->get_part($mid, "TEXT/PLAIN");
        }

        preg_match_all('/src="cid:(.*)"/Uims', $body, $matches);

        if (count($matches)) {
            $search  = [];
            $replace = [];

            foreach ($matches[1] as $match) {
                [$name] = explode('@', $match);
                $path      = $this->accountPath."/$mid/$name";
                $search[]  = 'src="cid:'.$match.'"';
                $replace[] = "src='$path'";
            }

            $body = str_replace($search, $replace, $body);
        }

        if (!is_dir("{$this->accountPath}/$mid")) {
            mkdir("{$this->accountPath}/$mid");
        }

        //save complete email as .eml
        $path = "{$this->accountPath}/$mid/{$mid}.eml";

        imap_savebody($this->imap, $path, $mid, null);

        return $body;
    }

    public function getAttachments($mid)
    {
        $structure   = imap_fetchstructure($this->imap, $mid);
        $attachments = [];

        if(isset($structure->parts)) {
            $flattenedParts = $this->flattenParts($structure->parts);

            foreach ($flattenedParts as $key => $row) {
                if ($row->ifdparameters) {
                    foreach ($row->dparameters as $object) {
                        if (strtolower($object->attribute) == 'filename') {
                            $filename    = $object->value;
                            $disposition = $row->disposition;
                            $attachment  = imap_fetchbody($this->imap, $mid, $key);

                            if ($row->encoding == 3) {
                                $attachment = base64_decode($attachment);
                            } elseif ($row->encoding == 4) {
                                $attachment = quoted_printable_decode($attachment);
                            }

                            $destination = "$this->accountPath/$mid/$filename";

                            //only save attachments, save inline but don't register as an attachment
                            if ($disposition == 'attachment') {
                                $attachments[] = [
                                    'account'  => $this->email,
                                    'msgno'    => $mid,
                                    'file'     => $destination,
                                    'fileName' => $filename
                                ];
                            }

                            file_put_contents($destination, $attachment);
                        }
                    }
                }
            }
        }

        return $attachments;
    }

    public function numEmails()
    {
        if ($this->imap) {
            return imap_num_msg($this->imap);
        }
    }

    public function __destruct()
    {
        if ($this->imap) {
            imap_errors();
            imap_alerts();
            imap_close($this->imap);
        }
    }

    protected function decode_utf8($string)
    {
        return imap_utf8($string);
    }

    protected function is_in_array($needle, $haystack)
    {
        foreach ($needle as $stack) {
            if (in_array(strtolower($stack), $haystack)) {
                return true;
            }
        }

        return false;
    }

    protected function get_part($mid, $mimetype, $structure = false, $partNumber = false)
    {
        if (!$structure) {
            $structure = imap_fetchstructure($this->imap, $mid);
        }
        if ($structure) {
            if ($mimetype == $this->get_mime_type($structure)) {
                if (!$partNumber) {
                    $partNumber = 1;
                }
                $text = imap_fetchbody($this->imap, $mid, $partNumber, FT_PEEK);
                switch ($structure->encoding) {
                    # 7BIT
                    case 0:
                        return imap_qprint($text);
                    # 8BIT
                    case 1:
                        return imap_8bit($text);
                    # BINARY
                    case 2:
                        return imap_binary($text);
                    # BASE64
                    case 3:
                        return imap_base64($text);
                    # QUOTED-PRINTABLE
                    case 4:
                        return quoted_printable_decode($text);
                    # OTHER
                    case 5:
                        return $text;
                    # UNKNOWN
                    default:
                        return $text;
                }
            }

            // multipart
            if ($structure->type == 1) {
                foreach ($structure->parts as $index => $subStruct) {
                    $prefix = "";
                    if ($partNumber) {
                        $prefix = $partNumber.".";
                    }
                    $data = $this->get_part($mid, $mimetype, $subStruct, $prefix.($index + 1));
                    if ($data) {
                        return $data;
                    }
                }
            }
        }
        return false;
    }

    protected function get_mime_type($structure)
    {
        $primaryMimetype = ["TEXT", "MULTIPART", "MESSAGE", "APPLICATION", "AUDIO", "IMAGE", "VIDEO", "OTHER"];

        if ($structure->subtype) {
            return $primaryMimetype[(int) $structure->type]."/".$structure->subtype;
        }
        return "TEXT/PLAIN";
    }

    //function from https://electrictoolbox.com/php-imap-message-parts
    protected function flattenParts($messageParts, $flattenedParts = [], $prefix = '', $index = 1, $fullPrefix = true)
    {
        foreach ($messageParts as $part) {
            $flattenedParts[$prefix.$index] = $part;
            if (isset($part->parts)) {
                if ($part->type == 2) {
                    $flattenedParts = $this->flattenParts($part->parts, $flattenedParts, $prefix.$index.'.', 0, false);
                } elseif ($fullPrefix) {
                    $flattenedParts = $this->flattenParts($part->parts, $flattenedParts, $prefix.$index.'.');
                } else {
                    $flattenedParts = $this->flattenParts($part->parts, $flattenedParts, $prefix);
                }
                unset($flattenedParts[$prefix.$index]->parts);
            }
            $index++;
        }

        return $flattenedParts;
    }
}
