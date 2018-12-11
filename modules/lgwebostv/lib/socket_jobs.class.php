<?php
/**
* Класс для работы с сокетами в неблокирующем режиме и протоколом websocket.
* @author <skysilver.da@gmail.com>
* @copyright 2018 Agaphonov Dmitri aka skysilver <skysilver.da@gmail.com> (c)
* @version 0.1a
*/

class SocketJobs
{
   private  $ip;
   private  $port;
   private  $debug;
   private  $socket;
   private  $status;
   public   $dataRecived;
   public   $dataToWrite;
   public   $handshaked;
   public   $lastSendMsgTime;
   public   $lastRcvMsgTime;

   public function __construct($ip = '127.0.0.1', $port = 3000, $debug = false)
   {
      $this->ip = $ip;
      $this->port = $port;
      $this->debug = $debug;

      $this->socket = null;
      $this->dataRecived = '';
      $this->dataToWrite = '';
      $this->handshaked = false;
      $this->lastSendMsgTime = time();
      $this->lastRcvMsgTime = time();

      $this->status = 'DO_PING';
   }

   public function GetSocket()
   {
      return $this->socket;
   }

   public function GetStatus()
   {
      return $this->status;
   }

   public function GetIP()
   {
      return $this->ip;
   }

   public function SetStatus($status)
   {
      $this->status = $status;
   }

   public function IsOnline()
   {
      if (($this->status == 'READ_ANSWER' || $this->status == 'WRITE_REQUEST') && is_resource($this->socket)) {
         return true;
      } else {
         return false;
      }
   }

   public function IsOffline()
   {
      if ($this->status == 'DO_PING' && !is_resource($this->socket)) {
         return true;
      } else {
         return false;
      }
   }

   public function ReadyDataWriteEvent()
   {
      if (!$this->dataToWrite) {
         // Каждый коннект начинаем с авторизации WS.
         $this->WriteLog(date('H:i:s') . ' Do auth in websocket.');
         $this->dataToWrite = $this->CreateHeader();
         return $this->dataToWrite;
      } else {
         return $this->dataToWrite;
      }
   }

   public function DataWrittenEvent($count)
   {
      if ($count === false || $count == 0) {
         // Во время записи произошла ошибка или не удалось ничего записать (сокет закрылся, ТВ выключился).
         $this->Disconnect();
      } else {
         // Если не все данные записали в сокет, то получаем остаток и переходим в режим записи.
         $dataTotalSize = strlen($this->dataToWrite);
         if ($count < $dataTotalSize) {
            $this->dataToWrite = substr($this->dataToWrite, $count);
            $this->SetStatus('WRITE_REQUEST');
         } else {
            // Когда успешно записали запрос в сокет, переходим в режим чтения ответа.
            $this->SetStatus('READ_ANSWER');
            $this->dataToWrite = '';
         }
      }
   }

   public function Ping()
   {
      if ($this->GetStatus() == 'DO_PING') {
         $this->WriteLog(date('H:i:s') . " Checking TV {$this->ip}.", false);

         $connection = @fsockopen($this->ip, $this->port, $errno, $errstr, 5);

         if (is_resource($connection) && !empty($connection)) {
            fclose($connection);
            // ТВ онлайн. Будем пытаться соединиться с ним.
            $this->WriteLog(" TV is online.");
            $this->SetStatus('DO_CONNECT');
            $this->lastSendMsgTime = time();
            $this->lastRcvMsgTime = time();
            return true;
         } else {
            $this->WriteLog(" TV is offline. Connect error: {$errno}.");
            return false;
         }
      } else {
         return true;
      }
   }

   public function Connect()
   {
      $socket = stream_socket_client('tcp://' . $this->ip . ':' . $this->port, $errno, $errstr, 30, STREAM_CLIENT_ASYNC_CONNECT | STREAM_CLIENT_CONNECT);

      if ($socket === false) {
         $this->WriteLog(date('Y-m-d H:i:s') . " Stream socket create error: $errno $errstr");
         $this->socket = null;
         return false;
      } else {
         stream_set_blocking($socket, false);
         $this->socket = $socket;
         $this->lastSendMsgTime = time();
         $this->lastRcvMsgTime = time();
         return $socket;
      }
   }

   public function Disconnect()
   {
      $this->WriteLog(date('H:i:s') . " Disconnect from {$this->ip}.");
      if (is_resource($this->getSocket())) {
         stream_socket_shutdown($this->getSocket(), STREAM_SHUT_RDWR);
         fclose($this->getSocket());
         $this->socket = null;
         $this->dataToWrite = '';
         $this->dataRecived = '';
         $this->handshaked = false;
         $this->SetStatus('DO_PING');
         return true;
      } else {
         return false;
      }
   }

   public function ReadHandle($tv_id)
   {
      $msg = array();
      if (feof($this->socket) || !is_resource($this->socket)) {
         $this->WriteLog(date('H:i:s') . ' Error: unsuccessful reading from socket or socket resource does not exist.');
         $this->Disconnect();
         $msg[] = '{"type":"ws_close"}';
      } else {
         // Читаем из готового к чтению сокета без блокировки.
         $data = fread($this->socket, 8192);
         if ('' === $data || false === $data) {
            $this->WriteLog(date('H:i:s') . ' Error: data false or empty.');
            $this->Disconnect();
            $msg[] = '{"type":"ws_close"}';
         } else {
            $length = strlen($data);
            $this->WriteLog(date('H:i:s') . " Successful get data from TV {$this->ip} [{$length} bytes].");
            $this->lastRcvMsgTime = time();

            // Т.к. работаем в режиме неблокирующих сокетов и ограничены размером буфера,
            // то накапливаем данные в переменную и обрабатываем поступившие данные с учетом,
            // что они могут не полные.
            $this->dataRecived .= $data;

            if ($this->handshaked === false) {
               $headers = $this->dataRecived;
               $resp = $this->ParseHeader($headers);
               if (isset($resp['Sec-Websocket-Accept']) && empty($resp['content'])) {
                  $this->WriteLog(date('H:i:s') . " TV {$this->ip}: websocket accept is true.");
                  $this->handshaked = true;
                  $this->dataRecived = '';
                  $msg[] = '{"type":"ws_accept"}';
               }
            } else {
               while ($frame = $this->Decode($this->dataRecived)) {
                  switch ($frame['type']) {
                     case 'text':
                        // Сообщение от ТВ.
                        $this->WriteLog(date('H:i:s') . " Get TEXT type message: {$frame['payload']}");
                        $msg[] = trim($frame['payload']);
                     break;
                     case 'ping':
                        // На ping от ТВ отвечаем pong.
                        $this->WriteLog(date('H:i:s') . " Get PING type message.");
                        $this->WriteData($response->data, true, 'pong');
                     break;
                     case 'pong':
                        $this->WriteLog(date('H:i:s') . " Get PONG type message.");
                     break;
                     case 'close':
                        // Штатное выключение ТВ. Если получили, то надо закрывать соединение.
                        $this->WriteLog(date('H:i:s') . " Get CLOSE type message.");
                        $this->Disconnect();
                        $msg[] = '{"type":"ws_close"}';
                     break;
                  }
               }
            }
         }
      }
      return $msg;
   }

   public function WriteHandle()
   {
      if (!$this->IsOffline()) {
         // Получаем данные, которые нужно записать.
         $dataToWrite = $this->readyDataWriteEvent();
         $length = strlen($dataToWrite);
         $this->WriteLog(date('H:i:s') . " Send {$length} bytes data to TV {$this->ip}. ", false);
         $count = fwrite($this->socket, $dataToWrite, strlen($dataToWrite));
         $this->WriteLog($count . ' bytes written to socket.');
         // Сообщаем объекту сколько байт удалось записать в сокет.
         // Т.к. работаем в режиме неблокирующих сокетов, то не всегда удается записать все данные за раз.
         // Поэтому необходимо проверять и отправлять оставшиеся, если они имеются.
         $this->dataWrittenEvent($count);
         if ($count != 0) {
            $this->lastSendMsgTime = time();
         }
      } else {
         $this->WriteLog(date('H:i:s') . " TV {$this->ip} offline. Data not send.");
         $this->dataToWrite = '';
         $this->SetStatus('READ_ANSWER');
      }
   }

   public function WriteData($data, $encode = true, $type = null)
   {
      if (!$this->IsOffline()) {
         if ($encode) {
            if (isset($type) && ($type == 'pong' || $type == 'ping')) {
               $this->dataToWrite = $this->Encode($type, $type, false);
            } else {
               $this->dataToWrite = $this->Encode($data);
            }
         } else {
            $this->dataToWrite = $data;
         }
         $this->SetStatus('WRITE_REQUEST');
      } else {
         $this->WriteLog(date('H:i:s') . " TV {$this->ip} offline. Data not send.");
      }
   }

   public function CreateHeader()
   {
      $key = base64_encode(uniqid());

      return
         "GET / HTTP/1.1\r\n" .
         "Host: {$this->ip}:{$this->port}\r\n" .
         "pragma: no-cache\r\n" .
         "cache-control: no-cache\r\n" .
         "Upgrade: WebSocket\r\n" .
         "Connection: Upgrade\r\n" .
         "Sec-WebSocket-Key: {$key}\r\n" .
         "Sec-WebSocket-Version: 13\r\n" .
         "User-Agent: Mozilla/5.0 (Windows NT 6.1; Win64; x64; rv:60.0) Gecko/20100101 Firefox/60.0\r\n" . 
         "\r\n";
   }

   public function ParseHeader($header)
   {
      $retval = array();
      $content = '';
      $fields = explode("\r\n", preg_replace('/\x0D\x0A[\x09\x20]+/', ' ', $header));

      foreach ($fields as $field) {
         if (preg_match('/([^:]+): (.+)/m', $field, $match)) {
            $match[1] = preg_replace_callback('/(?<=^|[\x09\x20\x2D])./', function ($matches) {
               return strtoupper($matches[0]);
            }, strtolower(trim($match[1])));
            
            if (isset($retval[$match[1]])) {
               $retval[$match[1]] = array($retval[$match[1]], $match[2]);
            } else {
               $retval[$match[1]] = trim($match[2]);
            }
         } else if (preg_match('!HTTP/1\.\d (\d)* .!', $field)) {
            $retval["status"] = $field;
         } else {
            $content = $field;
         }
      }
      $retval['content'] = $content;

      return $retval;
   }

   public function Encode($payload, $type = 'text', $masked = true)
   {
      $frameHead = array();
      $frame = '';
      $payloadLength = strlen($payload);

      switch ($type) {
         case 'text':
            $frameHead[0] = 129;
         break;
         case 'close':
            $frameHead[0] = 136;
         break;
         case 'ping':
            $frameHead[0] = 137;
         break;
         case 'pong':
            $frameHead[0] = 138;
         break;
      }

      if ($payloadLength > 65535) {
         $payloadLengthBin = str_split(sprintf('%064b', $payloadLength), 8);
         $frameHead[1] = ($masked === true) ? 255 : 127;
         for ($i = 0; $i < 8; $i++) {
            $frameHead[$i + 2] = bindec($payloadLengthBin[$i]);
         }
         if ($frameHead[2] > 127) {
            return false;
         }
      } else if ($payloadLength > 125) {
            $payloadLengthBin = str_split(sprintf('%016b', $payloadLength), 8);
            $frameHead[1] = ($masked === true) ? 254 : 126;
            $frameHead[2] = bindec($payloadLengthBin[0]);
            $frameHead[3] = bindec($payloadLengthBin[1]);
      } else {
         $frameHead[1] = ($masked === true) ? $payloadLength + 128 : $payloadLength;
      }

      foreach (array_keys($frameHead) as $i) {
         $frameHead[$i] = chr($frameHead[$i]);
      }

      if ($masked === true) {
         $mask = array();
         for ($i = 0; $i < 4; $i++) {
            $mask[$i] = chr(rand(0, 255));
         }
         $frameHead = array_merge($frameHead, $mask);
      }
      
      $frame = implode('', $frameHead);
      
      for ($i = 0; $i < $payloadLength; $i++) {
         $frame .= ($masked === true) ? $payload[$i] ^ $mask[$i % 4] : $payload[$i];
      }

      return $frame;
   }

   function Decode(&$buffer)
   {
      // Слишком мало данных. Выходим и ждем еще.
      if (strlen($buffer) < 2) {
         return false;
      }

      $firstByte = ord(substr($buffer, 0, 1));
      $secondByte = ord(substr($buffer, 1, 1));
      $raw = substr($buffer, 2);
      $opcode = $firstByte & 0x0F;
      $len = $secondByte & ~128;

      switch ($opcode) {
         case 1:
            $type = 'text';
         break;
         case 2:
            $type = 'binary';
         break;
         case 8:
            $type = 'close';
         break;
         case 9:
            $type = 'ping';
         break;
         case 10:
            $type = 'pong';
         break;
         default:
            return false;
         break;
      }

      if ($len <= 125){
         $payloadLength = $len;
      } else if (($len == 126) && strlen($raw) >= 2) {
         $arr = unpack('nfirst', $raw);
         $payloadLength = array_pop($arr);
         $raw = substr($raw, 2);
      } else if (($len == 127) && strlen($raw) >= 8) {
         list(, $h, $l) = unpack('N2', $raw);
         $payloadLength = ($l + ($h * 0x0100000000));
         $raw = substr($raw, 8);
      } else {
         return false;
      }

      // Данных меньше, чем должно быть согласно заголовку пакета. Выходим и ждем еще данных.
      if (strlen($raw) < $payloadLength) {
         return false;
      }

      $payload = substr($raw, 0, $payloadLength);
      $buffer = substr($raw, $payloadLength);

      return array('type' => $type, 'payload' => $payload);
   }

   public function WriteLog($msg, $is_eol = true)
   {
      if ($this->debug) {
         if ($is_eol) {
            echo $msg . PHP_EOL;
         } else {
            echo $msg;
         }
      }
   }

}
