<?php
class Binary extends CI_Model {
    public function parseRequest($use_json = true) {
        $content = file_get_contents('php://input');
        //file_put_contents(__DIR__ . '/gzip.txt', $content);

        $hex = $this->binToHex($content);
        //file_put_contents(__DIR__ . '/hex.txt', $hex);
        if (preg_match('#02 01 53 4B 6F F4 88 8D C1 4E A0 D5 EB B6 BD A0 A7 0D#', $hex)) {
            $hex = str_replace('02 01 53 4B 6F F4 88 8D C1 4E A0 D5 EB B6 BD A0 A7 0D', '', $hex);
            if ($hex) {
              $bin = $this->hexToBin($hex);
              //file_put_contents(__DIR__ . '/bin.txt', $bin);

              $data = gzinflate($bin);
              //file_put_contents(__DIR__ . '/data.txt', $data);
              if ($data) {
                $data = preg_replace('#.\xA5?\n?.*"{#', '{', $data);
                $data = preg_replace('#\}\"\}#', '}', $data);
                $data = preg_replace('#\"\"#', '"', $data);
                //file_put_contents(__DIR__ . '/data.txt', $data);

                if ($use_json) {
                    $json = json_decode($data, true);
                    if ($json) {
                        $_POST = array_merge($_POST, $json);
                    }
                } else {
                    $_POST['data'] = $data;
                }
              }
            }
        } else {
            $json = json_decode($content, true);
            if ($json) {
                $_POST = array_merge($_POST, $json);
            }
        }
    }

    public function binToHex($string) {
        $characters = str_split($string);

        $binary = [];
        foreach ($characters as $character) {
            $data = unpack('H*', $character);
            $binary[] = strtoupper($data[1]);
        }

        return implode(' ', $binary);
    }

    public function hexToBin($binary) {
        $binaries = explode(' ', $binary);

        $string = null;
        foreach ($binaries as $binary) {
            $string .= pack('H*', $binary);
        }

        return $string;
    }
}
?>
