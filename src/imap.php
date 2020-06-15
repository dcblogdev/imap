<?php
namespace Dcblogdev\Imap;

class Imap {

    private $imap;
    private $email;
    private $folder;
    private $host;
    private $port;
    private $secure;
    private $connection;
    private $saveFolder;
    private $delete;

    public function __construct($email, $password, $host, $port, $folder, $saveFolder, $delete = false)
    {
        $this->email      = $email;
        $this->host       = $host;
        $this->port       = $port;
        $this->folder     = $folder;
        $this->saveFolder = $saveFolder;
        $this->delete     = $delete;

        // Connect to a remote imap server
        if ($port != '143') {
            $this->secure = 'ssl/';
        }

        $this->connection = "{{$host}:{$port}/imap/{$this->secure}novalidate-cert}$folder";
        $this->imap       = imap_open($this->connection, $email, $password);

        $accountPath = $this->saveFolder.'/'.$email;

        //create folder for email account
        if (!file_exists($accountPath)) {
            mkdir($accountPath);
        }
    }

    public function getFolders()
    {
        if ($this->imap) {

            $folderslist = imap_list($this->imap, $this->connection, "*");
            $folders     = [];

            if (is_array($folderslist)) {
                foreach ($folderslist as $folder) {
                    $folder = str_replace($this->connection, '', $folder);
                    $folders[] = $folder;
                }
            }

            return $folders;
        }
    }

    public function decode_utf8($string)
    {
        if (preg_match("@=\?.{0,}\?[Bb]\?@",$string)) {
            $string = preg_split("@=\?.{0,}\?[Bb]\?@",$string);

            while (list($key, $value) = each($string)) {

                if (preg_match("@\?=@", $value)) {
                    $arrTemp    = preg_split("@\?=@", $value);
                    $arrTemp[0] = base64_decode($arrTemp[0]);
                    $string[$key]  = join("", $arrTemp);
                }
            }

            $string = join("", $string);
        }

        if (preg_match("@=\?.{0,}\?Q\?@", $string)) {
            $string = quoted_printable_decode($string);
            $string = preg_replace("/=\?.{0,}\?[Qq]\?/", "", $string);
            $string = preg_replace("/\?=/", "", $string);
        }

        return trim($string);
    }

    private function is_in_array($needle, $haystack)
    {
        foreach ($needle as $stack) {
            if (in_array(strtolower($stack), $haystack)) {
                 return true;
            }
        }

        return false;
    }

    public function emails($searchterms, $exclude)
    {
        $search = imap_search($this->imap, $searchterms);
        if (is_array($search)) {

            /* put the newest emails on top */
            rsort($search);

            $data = [];

            foreach ($search as $msg_id) {
                $check = array();
                $header = imap_header($this->imap, $msg_id);

                $subject = $header->subject;
                $msgno   = $header->Msgno;
                $from    = $header->from;
                $date    = $header->udate;
                $to      = $header->to;

                if(array_key_exists('cc', $header)) {
                    $cc  = $header->cc;
                } else {
                    $cc = null;
                }

                $fromaddress = null;
                $toaddress = null;
                $ccaddress = null;

                if (is_array($from)) {
                    foreach ($from as $id => $object) {
                        $fromname = $object->personal;
                        $fromaddress.= $object->mailbox . "@" . $object->host;
                        $check[] = strtolower($object->mailbox . "@" . $object->host);
                    }
                }

                if (is_array($to)) {
                    foreach ($to as $id => $object) {
                        $toaddress.= $object->mailbox . "@" . $object->host.' ';
                        $check[] = strtolower($object->mailbox . "@" . $object->host);
                    }
                }

                if (is_array($cc)) {
                    foreach ($cc as $id => $object) {
                        $ccaddress.= $object->mailbox . "@" . $object->host.' ';
                        $check[] = strtolower($object->mailbox . "@" . $object->host);
                    }
                }

                $target = array(
                    strtolower($this->email),
                    strtolower($fromaddress),
                    strtolower($toaddress),
                    strtolower($ccaddress)
                );

                $path = $this->saveFolder.'/'.$this->email.'/'.$msgno.'/';

                //if not in the exclude array insert
                if($this->is_in_array($exclude, $target) == false && $this->is_in_array($exclude, $check) == false){

                    $body = $this->getBody($msg_id);

                    $data[] = [
                        'account'     => $this->email,
                        'folder'      => $this->folder,
                        'subject'     => $this->decode_utf8($subject),
                        'msgno'       => $msgno,
                        'emailDate'   => date('Y-m-d H:i:s', $date),
                        'fromName'    => $fromname,
                        'fromAddress' => $fromaddress,
                        'toAddress'   => $toaddress,
                        'ccAddress'   => $ccaddress,
                        'body'        => $this->getBody($msg_id),
                        'attachments' => $this->getAttachments($msg_id)
                    ];

                    if ($this->delete == true) {
                        imap_delete($this->imap, $msg_id); //mark for deletion
                        imap_expunge($this->imap); //delete all marked for deletion
                    }

                    //if($header->Recent == 'Y'){
                       //mark email as unread
                        //imap_clearflag_full($this->imap, $msg_id, "\\Seen");
                    //}

                }

            }

            return $data;
        }
    }


    public function getBody($uid) {
        $body = $this->get_part($uid, "TEXT/HTML");

        // if HTML body is empty, try getting text body
        if ($body == "") {
            $body = $this->get_part($uid, "TEXT/PLAIN");
        }

        //store email account to local variable
        $email = $this->email;

        //replace cid with full path to image
        $body = preg_replace_callback(
            '/src="cid:(.*)">/Uims',
            function($m) use($email, $uid){
                //split on @
                $parts = explode('@', $m[1]);

                //replace local path with absolute path
                $img = str_replace($parts[0], resource_url($email.'/'.$uid.'/'.$parts[0], 'Emails'), $parts[0]);
                return "src='$img'>";
           },
        $body);

        return trim(utf8_encode(quoted_printable_decode($body)));
    }

    public function getAttachments($uid) {

        $attachments = [];

        $info = imap_fetchstructure($this->imap, $uid);
        if (isset($info->parts)) {
            $i = 0;
            foreach ($info->parts as $part) {

                $path = $this->saveFolder.'/'.$this->email.'/'.$uid.'/';

                if (array_key_exists('disposition', $part)) {

                    //create folder for email account
                    if (!file_exists($path)) {
                        mkdir($path);
                    }

                    if (strtolower($part->disposition) == "inline") {
                        $this->save_inline_image($info, $i, $uid, $path);
                    }

                    if (strtolower($part->disposition) == "attachment") {
                        $attachments[] = $this->save_attachment($info, $i, $uid, $path);
                    }
                } elseif(isset($part->parts)) {

                    foreach ($part->parts as $linepart) {
                        if (array_key_exists('disposition', $part)) {

                            //create folder for email account
                            if (!file_exists($path)) {
                                mkdir($path);
                            }

                            if (strtolower($part->disposition) == "inline") {
                                $this->save_inline_image($info, $i, $uid, $path);
                            }

                            if (strtolower($part->disposition) == "attachment") {
                                $attachments[] = $this->save_attachment($info, $i, $uid, $path);
                            }
                        }
                    }

                }

            $i++;}
        }

        return $attachments;
    }

    private function save_inline_image($structure, $k, $mid, $path)
    {
        if (isset($structure->parts[$k]->dparameters[0])) {

            //extract file name from headers
            $fileName = strtolower($structure->parts[$k]->dparameters[0]->value);

            //extract attachment from email body
            $fileSource = base64_decode(imap_fetchbody($this->imap, $mid, $k+1, FT_PEEK));

            if (!is_dir($path)) {
              // dir doesn't exist, make it
              mkdir($path);
            }

            $file = $path.$fileName;

            //only save if file does not exist
            if(!file_exists($file) && $fileName !=''){
                file_put_contents($file, $fileSource);
            }
        }
    }

    private function save_attachment($structure, $k, $mid, $path)
    {
        //extract file name from headers
        $fileName = strtolower($structure->parts[$k]->dparameters[0]->value);

        $file = $path.$fileName;
        $mege = imap_fetchbody($this->imap, $mid, $k+1, FT_PEEK);
        $fp   = fopen($file, 'w');
        $data = $this->getdecodevalue($mege,$structure->parts[$k]->type);

        fwrite($fp, $data);
        fclose($fp);

        return [
            'account'  => $this->email,
            'msgno'    => $mid,
            'file'     => $file,
            'fileName' => $fileName
        ];
    }

    private function getdecodevalue($message,$coding) {
      switch($coding) {
          case 0:
          case 1:
              //$message = imap_8bit($message);
              $message = imap_base64($message);
              break;
          case 2:
              $message = imap_binary($message);
              break;
          case 3:
          case 5:
              $message = imap_base64($message);
              break;
          case 4:
              $message = imap_qprint($message);
              break;
      }
      return $message;
    }

    private function get_part($uid, $mimetype, $structure = false, $partNumber = false) {
        if (!$structure) {
               $structure = imap_fetchstructure($this->imap, $uid);
        }
        if ($structure) {

            if ($mimetype == $this->get_mime_type($structure)) {
                if (!$partNumber) {
                    $partNumber = 1;
                }
                $text = imap_fetchbody($this->imap, $uid, $partNumber, FT_PEEK);
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
                        $prefix = $partNumber . ".";
                    }
                    $data = $this->get_part($uid, $mimetype, $subStruct, $prefix . ($index + 1));
                    if ($data) {
                        return $data;
                    }
                }
            }
        }
        return false;
    }

    private function get_mime_type($structure) {
        $primaryMimetype = array("TEXT", "MULTIPART", "MESSAGE", "APPLICATION", "AUDIO", "IMAGE", "VIDEO", "OTHER");

        if ($structure->subtype) {
           return $primaryMimetype[(int)$structure->type] . "/" . $structure->subtype;
        }
        return "TEXT/PLAIN";
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
}
