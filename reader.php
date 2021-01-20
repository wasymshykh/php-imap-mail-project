<?php

/*
    - Remaining work:
        - Code cleaning
*/

require 'vendor/autoload.php';

use Ddeboer\Imap\Server;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// Database configuration
define('DB_HOST', 'localhost');
define('DB_NAME', '');
define('DB_USER', 'root');
define('DB_PASS', '');

// Timezone setting
define('TIMEZONE', 'Asia/Karachi');
date_default_timezone_set(TIMEZONE);

// Email configuration
define("SERVER", '');
define("PORT", 993);
define("MAIL_USER", '');
define("MAIL_PASSWORD", '');
$port = 993;

$mail = new PHPMailer(true);
// SMTP configuration
$mail->isSMTP();
$mail->Host = '';
$mail->SMTPAuth = true;
$mail->Username = '';
$mail->Password = '';
$mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
$mail->Port = 587;
$mail->setFrom('test@test.com', 'John?');
$mail->addAddress("test@testing.com");

// DB initialization
$db = new DB();
$db->connect();

$ep = new EmailParser($db);

$server = new Server(SERVER, PORT);
$connection = $server->authenticate(MAIL_USER, MAIL_PASSWORD);
$mailbox = $connection->getMailbox('INBOX');
$messages = $mailbox->getMessages();

foreach ($messages as $message) {
    if (!$message->isSeen()) {
        $is_valid = false;

        // validating the message
        $from = trim($message->getFrom()->getAddress());
        $type = $ep->get_type($from);
        if ($type) {
            if ($type === 1) {
                $content_body = $message->getBodyText();
            } else
            if ($type === 2) {
                $content_body = $message->getBodyHtml();
            } else
            if ($type === 3) {
                $content_body = $message->getBodyHtml();
            }
            if ($ep->validate_type_by_body($content_body, $type)) {
                $is_valid = true;
            }
        }

        if ($is_valid) {
            if ($type === 1) {
                $body = trim($content_body);
                $data = $ep->two_talk_data($body);
                if ($data && !empty($data)) {
                    $check = $db->insert_email($data['name'], $data['description'], $data['dateadded'], $data['email'], true);
                    if ($check && is_array($check)) {
                        $attachments = $message->getAttachments();
                        foreach ($attachments as $attachment) {
                            file_put_contents(__DIR__.'/attachments/'. $attachment->getFilename(), $attachment->getDecodedContent());
                            $file_name = $attachment->getFilename();
                            $file_check = $db->insert_file($check[1], $file_name, $data['dateadded']);
                            if ($file_check) {
                                $ep->send_notification($mail);
                            } else {
                                $db->store_log('Failed to save file data in database: '.trim($message->getSubject()));
                            }
                        }
                    } else {
                        $db->store_log('Failed to store in database: '.trim($message->getSubject()));
                    }
                }
            } else if ($type === 2) {
                $subject = trim($message->getSubject());
                $body = trim($content_body);
                $data = $ep->trade_me_data($subject, $body);
                if ($data && !empty($data)) {
                    $check = $db->insert_email($data['name'], $data['description'], $data['dateadded'], $data['email']);
                    if ($check) {
                        $ep->send_notification($mail);
                    } else {
                        $db->store_log('Failed to store in database: '.trim($message->getSubject()));
                    }
                }
            } else if ($type === 3) {
                $subject = trim($message->getSubject());
                $body = trim($content_body);
                $data = $ep->car_buyer_data($subject, $body);
                if ($data && !empty($data)) {
                    $check = $db->insert_email($data['name'], $data['description'], $data['dateadded'], $data['email']);
                    if ($check) {
                        $ep->send_notification($mail);
                    } else {
                        $db->store_log('Failed to store in database: '.trim($message->getSubject()));
                    }
                }
            }
        }
        $message->delete();
    }
}
// closing connection 
$connection->expunge();

// oh God 

class EmailParser {
    private $Twotalk;
    private $Trademe;
    private $CarBuyer;
    private $db;
    
    public function __construct($db) {
        $this->db = $db;
        $this->Twotalk = "2talk.co.nz";
        $this->Trademe = "trademe.co.nz";
        $this->CarBuyer = "carbuyers.co.nz";
    }

    public function get_type($from)
    {
        // template 1
        if (strpos($from, $this->Twotalk)) {
            return 1;
        }
        // template 2
        if (strpos($from, $this->Trademe)) {
            return 2;
        }
        // template 3
        if (strpos($from, $this->CarBuyer)) {
            return 3;
        }
        return false;
    }

    public function validate_type_by_body ($body, $type)
    {
        if ($type === 1) {
            if (strpos($body, "A voice recording was generated for a call from:")) {
                return true;
            }
        } else
        if ($type === 2) {
            if (strpos($body, "This is an automated email regarding listing #")) {
                return true;
            }
        } else if ($type === 3) {
            if (strpos($body, "Congratulations on selecting this lead!")) {
                return true;
            }
        }
        return false;
    }

    public function trade_me_data ($subject, $body)
    {
        $extraced_data['name'] = trim(preg_replace('/\([\s*\d*\s*]+\)/', '', $subject));
        $extraced_data['description'] = trim($body);
        $extraced_data['dateadded'] = $this->current_date();

        preg_match_all('/Listing\s+#\s+(\d+)/', $extraced_data['name'], $matches);
        if (!isset($matches[1]) || !isset($matches[1][0])) {
            return false;
        }
        $listing_no = $matches[1][0];
        $extraced_data['email'] = $listing_no . '@' . $this->Trademe;

        return $extraced_data;
    }

    public function two_talk_data ($body)
    {
        preg_match_all('/Calling\s+Party:\s+(\d+)/', $body, $matches);
        if (!isset($matches[1]) || !isset($matches[1][0])) {
            return false;
        }

        $extraced_data = [];
        $extraced_data['name'] = $matches[1][0];
        $extraced_data['description'] = trim($body);
        $extraced_data['dateadded'] = $this->current_date();
        $extraced_data['email'] = $extraced_data['name'] . '@' . $this->Twotalk;

        return $extraced_data;
    }

    public function car_buyer_data($subject, $htmlbody)
    {
        $extraced_data['name'] = trim($subject);
        $extraced_data['description'] = trim($htmlbody);
        $extraced_data['dateadded'] = $this->current_date();

        preg_match_all('/(\w.+@\w+.+\w+)/', $htmlbody, $matches);
        
        if (!isset($matches[1]) || !isset($matches[1][0])) {
            return false;
        }
        $email = trim($matches[1][0]);
        $extraced_data['email'] = $email;

        return $extraced_data;
    }

    public function send_notification(PHPMailer $mail)
    {
        try {
            // Content
            $mail->isHTML(true);
            $mail->Subject = 'A new record has been added!';
            $mail->Body = 'You have receive a new recording in DB. Please check – Thank you.';
            $mail->send();
            
            return true;
        } catch (Exception $e) {
            $error = "Email could not be sent. Mailer Error.";
            if (PROJECT_MODE === 'development') {
                $error .= " {$mail->ErrorInfo}";
            }
            $this->db->store_log($error);
        }
    }

    public function current_date($format = 'Y-m-d H:i:s')
    {
        return date($format);
    }

}

// v. nice another class wow

class DB
{
    /*
        Setting values from system constants
    */
    private $host = DB_HOST;
    private $database = DB_NAME;
    private $username = DB_USER;
    private $password = DB_PASS;
    private PDO $pdo;

    public function credentials ($host, $database, $username, $password)
    {
        $this->host = $host;
        $this->database = $database;
        $this->username = $username;
        $this->password = $password;
    }

    public function connect ()
    {
        $dsn = 'mysql:host=' . $this->host . '; dbname=' . $this->database;
        try {
            $pdo = new PDO($dsn, $this->username, $this->password);
        } catch(Exception $e) {
            die('Database Connection Failure');
            return false;
        }
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $this->pdo = $pdo;
    }

    public function insert_email ($name, $description, $dateadded, $email, $return_id = false)
    {
        $q = "INSERT INTO `emails` (`email_name`, `email_description`, `email_dateadded`, `email_email`) VALUE (:n, :d, :dt, :e)";
        $s = $this->pdo->prepare($q);
        $s->bindParam(':n', $name);
        $s->bindParam(':d', $description);
        $s->bindParam(':dt', $dateadded);
        $s->bindParam(':e', $email);
        if ($s->execute()) {
            if ($return_id) {
                return [true, $this->pdo->lastInsertId()];
            }
            return true;
        }
        return false;
    }

    public function insert_file ($email_id, $file_name, $dateadded, $status = 1, $return_id = false)
    {
        $q = "INSERT INTO `files` (`file_email_id`, `file_name`, `file_dateadded`, `file_status`) VALUE (:i, :n, :dt, :st)";
        $s = $this->pdo->prepare($q);
        $s->bindParam(':i', $email_id);
        $s->bindParam(':n', $file_name);
        $s->bindParam(':dt', $dateadded);
        $s->bindParam(':st', $status);
        if ($s->execute()) {
            if ($return_id) {
                return [true, $this->pdo->lastInsertId()];
            }
            return true;
        }
        return false;
    }
    
    public function store_log ($body)
    {
        $f = fopen(__DIR__.'/logs/failure.log', "a") or die("hey dummy. Sorry, can't even log failure :(");
        $d = $body."\n";
        fwrite($f, $d);
        fclose($f);
    }
}
