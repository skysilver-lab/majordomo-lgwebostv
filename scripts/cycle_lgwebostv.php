<?php
/**
* Цикл модуля LG webOS TV
* @author <skysilver.da@gmail.com>
* @copyright 2018 Agaphonov Dmitri aka skysilver <skysilver.da@gmail.com> (c)
* @version 0.1a
*/

chdir(dirname(__FILE__) . '/../');

include_once('./config.php');
include_once('./lib/loader.php');
include_once('./lib/threads.php');

set_time_limit(0);

$db = new mysql(DB_HOST, '', DB_USER, DB_PASSWORD, DB_NAME);

include_once('./load_settings.php');
include_once(DIR_MODULES . 'control_modules/control_modules.class.php');

$ctl = new control_modules();

include_once(DIR_MODULES . 'lgwebostv/lgwebostv.class.php');
include_once(DIR_MODULES . 'lgwebostv/lib/socket_jobs.class.php');

$lgwebostv_module = new lgwebostv();

echo date('H:i:s') . ' Running ' . basename(__FILE__) . PHP_EOL;

$latest_ping  = 0;
$ping_period  = 60;     // Период tcp ping по умолчанию.
$ws_check_period = 20;  // Период websocket ping по умолчанию.
$latest_cycle_check = 0;
$cycle_check_period = 5;

$cycle_debug = false;    // По умолчанию логи цикла выключены.
$debmes_debug = false;   // По умолчанию логи debmes выключены.

if ($lgwebostv_module->config['API_TCP_PING_PERIOD'] != '') $ping_period = (int)$lgwebostv_module->config['API_TCP_PING_PERIOD'];
if ($lgwebostv_module->config['API_WS_PING_PERIOD'] != '') $ws_check_period = (int)$lgwebostv_module->config['API_WS_PING_PERIOD'];
if ($lgwebostv_module->config['API_LOG_CYCLE']) $cycle_debug = true;
if ($lgwebostv_module->config['API_LOG_DEBMES']) $debmes_debug = true;

echo date('H:i:s') . ' Init LG webOS TV cycle' . PHP_EOL;
echo date('H:i:s') . ' Cycle debug - ' . ($cycle_debug ? 'yes' : 'no') . PHP_EOL;
echo date('H:i:s') . ' DebMes debug - ' . ($debmes_debug ? 'yes' : 'no') . PHP_EOL;
echo date('H:i:s') . ' Extended debug - ' . (EXTENDED_LOGGING ? 'yes' : 'no') . PHP_EOL;
echo date('H:i:s') . " Ping period - $ping_period seconds" . PHP_EOL;
echo date('H:i:s') . " Websocket check period - $ws_check_period seconds" . PHP_EOL;
echo date('H:i:s') . ' Incoming message processing mode - ' . (BACKGROUND_MESSAGE_PROCESS ? 'background' : 'direct') . PHP_EOL;
echo date('H:i:s') . ' Call method mode - ' . (CALL_METHOD_SAFE ? 'safe' : 'default') . PHP_EOL;

// Создаем управляющий сокет, который будет принимать команды от МДМ.
$controlSocket = stream_socket_server('tcp://127.0.0.1:' . CONTROL_SOCKET_PORT, $errno, $errstr);
if ($controlSocket === false) {
   echo date('H:i:s') . ' Control socket - FAILED' . PHP_EOL;
   echo date('H:i:s') . " Connect error: $errno $errstr" . PHP_EOL;
} else {
   echo date('H:i:s') . ' Control socket - OK' . PHP_EOL;
}

// Получаем список ТВ из базы.
$tvs = SQLSelect("SELECT * FROM lgwebostv_devices ORDER BY 'ID'");

$tvList = array();
$tvObj = array();

// Формируем массив заданий.
if ($tvs[0]['ID']) {
   $total = count($tvs);
   echo date('H:i:s') . " $total TVs found" . PHP_EOL;
   for ($i = 0; $i < $total; $i++) {
      $tvObj['ID'] = $tvs[$i]['ID'];
      $tvObj['IP'] = $tvs[$i]['IP'];
      $tvObj['SOCKET'] = new SocketJobs($tvs[$i]['IP'], WEBOS_PORT, $cycle_debug);
      $tvList[$tvs[$i]['ID']] = $tvObj;
      $lgwebostv_module->UnsubscribeFrom($tvs[$i]['ID'], 'channel_');
   }
} else {
   echo date('H:i:s') . ' No TV' . PHP_EOL;
}

while (1) {

   if (defined('EXTENDED_LOGGING') && EXTENDED_LOGGING == 1) {
      if ($cycle_debug) echo date('H:i:s') . ' Cycle start' . PHP_EOL;
   }

   if ((time() - $latest_cycle_check) >= $cycle_check_period) {
      // Сообщаем МДМ, что цикл живой.
      $latest_cycle_check = time();
      setGlobal((str_replace('.php', '', basename(__FILE__))) . 'Run', time(), 1);
   }

   if ((time() - $latest_ping) >= $ping_period) {
      // Периодическая проверка доступности ТВ (tcp ping на порт 3000).
      if (!empty($tvList)) {
         if ($cycle_debug) echo date('H:i:s') . ' Periodic TV availability check in background process.' . PHP_EOL;
         foreach ($tvList as $tv) {
            if ($cycle_debug) echo date('H:i:s') . " Checking TV {$tv['IP']} [ID{$tv['ID']}] (tcp ping)." . PHP_EOL;
            // На Ping() каждого IP уходит 3 секунды, поэтому запускаем в фоновом процессе.
            $data = array('ip' => $tv['IP'], 'id' => $tv['ID']);
            $lgwebostv_module->RunInBackground('ping', $data);
         }
      }
      $latest_ping = time();
   }

   $ar_read = null;
   $ar_write = null;
   $ar_ex = null;

   // Управляющий сокет слушаем всегда (даже если нет сокетов заданий).
   $ar_read[] = $controlSocket;

   if (!empty($tvList)) {
      foreach ($tvList as $tv) {

         if ($tv['SOCKET']->IsOffline()) {
            if (defined('EXTENDED_LOGGING') && EXTENDED_LOGGING == 1) {
               if ($cycle_debug) echo date('H:i:s') . ' TV ' . $tv['SOCKET']->GetIP() . ' socket status = ' . $tv['SOCKET']->GetStatus() . PHP_EOL;
            }
            continue;
         }

         if ($tv['SOCKET']->IsOnline()) {
            $sendTimeout = time() - $tv['SOCKET']->lastSendMsgTime;
            $rcvTimeout  = time() - $tv['SOCKET']->lastRcvMsgTime;
            if (($sendTimeout >= $ws_check_period) && ($rcvTimeout >= $ws_check_period)) {
               if ($cycle_debug) echo date('H:i:s') . ' Send websocket ping to ' . $tv['SOCKET']->GetIP() . PHP_EOL;
               $tv['SOCKET']->WriteData('ping', true, 'ping');
            } else if (($sendTimeout >= 5 && $sendTimeout < $ws_check_period) && ($rcvTimeout > $ws_check_period)) {
               if ($cycle_debug) echo date('H:i:s') . ' Close connection on timeout ' . $tv['SOCKET']->GetIP() . PHP_EOL;
               $tv['SOCKET']->Disconnect();
               $lgwebostv_module->IncomingMessageProcessing('{"type":"ws_close"}', $tv['ID']);
            }
         }

         if ($tv['SOCKET']->GetStatus() == 'DO_CONNECT') {
            // Заданию нужно инициировать соединение.
            // Коннект на 3000 порт ТВ и авторизация по протоколу websocket.
            $socket = $tv['SOCKET']->Connect();
            if ($socket) {
               // После соединения будем пытаться авторизоваться в WS (писать в сокет).
               $ar_write[] = $socket;
            }
         } else if ($tv['SOCKET']->GetStatus() == 'READ_ANSWER') {
            // Задание хочет прочитать ответ из сокета.
            // Получаем ответ на отправленную команду или просто слушаем сообщения от ТВ.
            $socket = $tv['SOCKET']->GetSocket();
            if ($socket) {
               $ar_read[] = $socket;
            }
         } else if ($tv['SOCKET']->GetStatus() == 'WRITE_REQUEST') {
            // Заданию нужно записать запрос в сокет.
            // Отправляем команду на ТВ.
            $socket = $tv['SOCKET']->GetSocket();
            if ($socket) {
               $ar_write[] = $socket;
            }
         }
         if (defined('EXTENDED_LOGGING') && EXTENDED_LOGGING == 1) {
            if ($cycle_debug) echo date('H:i:s') . ' TV ' . $tv['SOCKET']->GetIP() . ' socket status = |SS:' . $tv['SOCKET']->GetStatus() . '|ST:' . $sendTimeout . '|RT:' . $rcvTimeout . '|' . PHP_EOL;
         }
      }
   }

   if (defined('EXTENDED_LOGGING') && EXTENDED_LOGGING == 1) {
      if ($cycle_debug) echo date('H:i:s') . ' Start stream_select()' . PHP_EOL;
   }
   // Магия сокетов! :) Ждем, когда ядро ОС нас уведомит о событии, или делаем дежурную итерацию раз в 5 сек.
   if (($num_changed_streams = stream_select($ar_read, $ar_write, $ar_ex, 5)) === false) {
      echo date('H:i:s') . ' Error stream_select()' . PHP_EOL;
   }

   if (defined('EXTENDED_LOGGING') && EXTENDED_LOGGING == 1) {
      if ($cycle_debug) echo date('H:i:s') . ' Stop stream_select()' . PHP_EOL;
   }

   if (is_array($ar_ex)) {
      echo date('H:i:s') . ' Exeption in one of the sockets' . PHP_EOL;
   }

   // Есть сокеты на запись.
   if (is_array($ar_write)) {
      foreach ($ar_write as $write_ready_socket) {
         // Выясняем, в какой сокет надо писать.
         foreach ($tvList as $tv) {
            if ($write_ready_socket == $tv['SOCKET']->GetSocket()) {
               // Пишем.
               $tv['SOCKET']->WriteHandle();
            }
         }
      }
   }

   // Есть сокеты на чтение.
   if (is_array($ar_read)) {
      foreach ($ar_read as $read_ready_socket) {
         // Пришло соединение на управляющий сокет.
         if ($read_ready_socket == $controlSocket) {
            // Принимаем соединение.
            $csocket = stream_socket_accept($controlSocket);
            // Получаем данные.
            // Тут упрощение - верим локальному клиенту, что он закроет соединение. Иначе надо ставить таймаут.
            if ($csocket) {
               $req = '';
               while (($data = fread($csocket, 8192)) !== '' ) {
                  $req .= $data;
               }
               // Получили данные.
               if ($cycle_debug) echo date('H:i:s') . ' Command from MDM module: ' . trim($req) . PHP_EOL;
               // Передаем полученные данные на обработку и исполнение.
               $lgwebostv_module->ProcessControlCommand(trim($req), $csocket, $tvList, $cycle_debug);
               // Закрываем надежно соединение с управляющим сокетом.
               stream_socket_shutdown($csocket, STREAM_SHUT_RDWR);
               fclose($csocket);
            }
            continue;
         } else {
            // Выясняем, из какого сокета надо читать.
            foreach ($tvList as $tv) {
               if ($read_ready_socket == $tv['SOCKET']->getSocket()) {
                  // Читаем.
                  $msgs = $tv['SOCKET']->ReadHandle($tv['ID']);
                  // Отправляем на обработку в модуль.
                  if (is_array($msgs)) {
                     foreach ($msgs as $msg) {
                        if (defined('EXTENDED_LOGGING') && EXTENDED_LOGGING == 1) {
                           $time_start = microtime(true);
                           $lgwebostv_module->IncomingMessageProcessing($msg, $tv['ID']);
                           $time = microtime(true) - $time_start;
                           if ($cycle_debug) echo date('H:i:s') . " ProcessMessage() runtime: {$time} s." . PHP_EOL;
                        } else {
                           $lgwebostv_module->IncomingMessageProcessing($msg, $tv['ID']);
                        }
                     }
                  }
                  $msgs = null;
               }
            }
         }
      }
   }

   if (defined('EXTENDED_LOGGING') && EXTENDED_LOGGING == 1) {
      if ($cycle_debug) echo date('H:i:s') . ' Cycle end' . PHP_EOL;
   }

   if (file_exists('./reboot') || isset($_GET['onetime'])) {
      $db->Disconnect();
      // Закрываем все открытые соединения.
      if (!empty($tvList)) {
         foreach ($tvList as $tv) {
            if ($tv['SOCKET']->IsOnline()) {
               $tv['SOCKET']->Disconnect();
            }
         }
      }
      // Закрываем служебный сокет.
      if (is_resource($controlSocket)) {
         stream_socket_shutdown($controlSocket, STREAM_SHUT_RDWR);
         fclose($controlSocket);
      }
      echo date('H:i:s') . ' Stopping by command REBOOT or ONETIME ' . basename(__FILE__) . PHP_EOL;
      exit;
   }
}

echo date('H:i:s') . ' Unexpected close of cycle' . PHP_EOL;

DebMes('Unexpected close of cycle: ' . basename(__FILE__));
