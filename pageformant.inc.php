<?php
/*
    PageFormant api binding for PHP
    rev: 7
    (C) 2012-2013 by PageFormant.de
*/

/*
 * Helper functions
 */

function random_string($length) {
    $alphabet = "2346789bcdfghjkmnpqrtvwxyzBCDFGHJKLMNPQRTVWXYZ";
    $a_len = strlen($alphabet);

    $str = "";
    for($i = 0; $i < $length; $i++)
        $str .= $alphabet[mt_rand(0, $a_len-1)];

    return $str;
}


function unchunk($result) {
    return preg_replace_callback(
        '/(?:(?:\r\n|\n)|^)([0-9A-F]+)(?:\r\n|\n){1,2}(.*?)'.
        '((?:\r\n|\n)(?:[0-9A-F]+(?:\r\n|\n))|$)/si',
        create_function(
            '$matches',
            'return hexdec($matches[1]) == strlen($matches[2]) ? $matches[2] : $matches[0];'
        ),
        $result
    );
}

function array_assoc_implode($glue, $separator, $array) {
    if(!is_array($array)) return $array;
    $string = array();
    foreach ( $array as $key => $val ) {
        if ( is_array( $val ) )
            $val = implode( ',', $val );
        $string[] = "{$key}{$glue}{$val}";

    }
    return implode( $separator, $string );

}

/*
 * API Class
 */

class PageformantAPI {
    protected $userKey;
    protected $userPassword;

    public function __construct($key, $password)
    {
        $this->userKey = $key;
        $this->userPassword = $password;
    }


    public function sendMessage($svcId, $message, $link)
    {
        return pageformant_send(
            array(
                "SERVICEID" => (int)$svcId,
                "MESSAGE"   => $message,
                "LINK"      => $link,
                "METHOD"    => "PostMessage"
            )
        );
    }

    public function sendRequest($sendData)
    {

        /**
         *  Pageformant Connection Data
         */
        $pf_message_host             = "pageformant.de";
        $pf_message_ssl              = true;
        $pf_message_path             = "/api/betreiber/nvp/";

        /*
        **/


        //update data map to identify our side
        $sendData["APIKEY"]     = $this->userKey;
        $sendData["APIPASSWD"]  = $this->userPassword;
        $sendData["APIVERSION"] = "1.0";

        //encode data
        $stringSendData         = http_build_query($sendData);

        $pf_connect_host = $pf_message_host;
        if($pf_message_ssl === true && in_array("https", stream_get_wrappers(), true)) {
            $pf_connect_host = "ssl://".$pf_connect_host;
            $pf_message_port = 443;
        } else
            $pf_message_port = 80;

        //connect to server and send data
        $fp = fsockopen($pf_connect_host, $pf_message_port, $errno, $errstr, 30);
        if(!$fp) {
            return false;
        }

        fputs($fp, "POST ". $pf_message_path ." HTTP/1.1\r\n");
        fputs($fp, "Host: ". $pf_message_host ."\r\n");
        fputs($fp, "Referer: ". $_SERVER['SERVER_ADDR'] ."\r\n");
        fputs($fp, "Content-type: application/x-www-form-urlencoded\r\n");
        fputs($fp, "Content-length: ". strlen($stringSendData) ."\r\n");
        fputs($fp, "Connection: close\r\n\r\n");
        fputs($fp, $stringSendData);
        $res = '';
        while(!feof($fp))
            $res .= fgets($fp);
        fclose($fp);

        //split header and body
        $idx_sep = strpos($res, "\r\n\r\n");
        if($idx_sep === false) {
            return false;
        }

        $data = substr($res, $idx_sep+4);
        $header = substr($res, 0, $idx_sep);

        if (strpos(strtolower($header), "transfer-encoding: chunked") !== false) {
            $data = unchunk($data);
        }

        //parse body post data
        mb_parse_str($data, $serverVals);
        foreach($serverVals as $key => $val) {
            $serverVals[$key] = str_replace("\r\n", "", $val);
        }

        if(isset($serverVals['ACK']) && $serverVals['ACK'] === "SUCCESS") {
            return true;
        } else {
            pageformant_save_error(
                isset($serverVals["ERRCODE"]) ? $serverVals["ERRCODE"] : -1,
                isset($serverVals["ERRSTR"]) ? $serverVals["ERRSTR"] : "Ungültige Daten: ".array_assoc_implode(": ", ", ", $serverVals)
            );
        }

        return false;
    }

}

?>