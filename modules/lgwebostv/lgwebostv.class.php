<?php
/**
* Главный класс модуля LG webOS TV
* @author <skysilver.da@gmail.com>
* @copyright 2018 Agaphonov Dmitri aka skysilver <skysilver.da@gmail.com> (c)
* @version 0.1a
*/

include_once(DIR_MODULES . 'lgwebostv/lib/socket_jobs.class.php');

const WEBOS_PORT = 3000;
const CONTROL_SOCKET_PORT = 3005;

define ('AUTH_PAYLOAD', '{"forcePairing":false,"pairingType":"PROMPT","manifest":{"manifestVersion":1,"appVersion":"1.1","signed":{"created":"20140509","appId":"com.lge.test","vendorId":"com.lge","localizedAppNames":{"":"LG Remote App","ko-KR":"리모컨 앱","zxx-XX":"ЛГ Rэмotэ AПП"},"localizedVendorNames":{"":"LG Electronics"},"permissions":["TEST_SECURE","CONTROL_INPUT_TEXT","CONTROL_MOUSE_AND_KEYBOARD","READ_INSTALLED_APPS","READ_LGE_SDX","READ_NOTIFICATIONS","SEARCH","WRITE_SETTINGS","WRITE_NOTIFICATION_ALERT","CONTROL_POWER","READ_CURRENT_CHANNEL","READ_RUNNING_APPS","READ_UPDATE_INFO","UPDATE_FROM_REMOTE_APP","READ_LGE_TV_INPUT_EVENTS","READ_TV_CURRENT_TIME"],"serial":"2f930e2d2cfe083771f68e4fe7bb07"},"permissions":["LAUNCH","LAUNCH_WEBAPP","APP_TO_APP","CLOSE","TEST_OPEN","TEST_PROTECTED","CONTROL_AUDIO","CONTROL_DISPLAY","CONTROL_INPUT_JOYSTICK","CONTROL_INPUT_MEDIA_RECORDING","CONTROL_INPUT_MEDIA_PLAYBACK","CONTROL_INPUT_TV","CONTROL_POWER","READ_APP_STATUS","READ_CURRENT_CHANNEL","READ_INPUT_DEVICE_LIST","READ_NETWORK_STATE","READ_RUNNING_APPS","READ_TV_CHANNEL_LIST","WRITE_NOTIFICATION_TOAST","READ_POWER_STATE","READ_COUNTRY_INFO"],"signatures":[{"signatureVersion":1,"signature":"eyJhbGdvcml0aG0iOiJSU0EtU0hBMjU2Iiwia2V5SWQiOiJ0ZXN0LXNpZ25pbmctY2VydCIsInNpZ25hdHVyZVZlcnNpb24iOjF9.hrVRgjCwXVvE2OOSpDZ58hR+59aFNwYDyjQgKk3auukd7pcegmE2CzPCa0bJ0ZsRAcKkCTJrWo5iDzNhMBWRyaMOv5zWSrthlf7G128qvIlpMT0YNY+n/FaOHE73uLrS/g7swl3/qH/BGFG2Hu4RlL48eb3lLKqTt2xKHdCs6Cd4RMfJPYnzgvI4BNrFUKsjkcu+WD4OO2A27Pq1n50cMchmcaXadJhGrOqH5YmHdOCj5NSHzJYrsW0HPlpuAx/ECMeIZYDh6RMqaFM2DXzdKX9NmmyqzJ3o/0lkk/N97gfVRLW5hA29yeAwaCViZNCP8iC9aO0q9fQojoa7NQnAtw=="}]}}');

define('BACKGROUND_MESSAGE_PROCESS', 1);
define('CALL_METHOD_SAFE', 1);
define('EXTENDED_LOGGING', 0);

class lgwebostv extends module
{

   /**
   * lgwebostv
   *
   * Module class constructor
   *
   * @access private
   */
   function __construct()
   {
      $this->name = 'lgwebostv';
      $this->title = 'LG webOS TV';
      $this->module_category = '<#LANG_SECTION_DEVICES#>';
      $this->checkInstalled();
      $this->getConfig();
      $this->debug = ($this->config['API_LOG_DEBMES'] == 1) ? true : false;
   }

   /**
   * saveParams
   *
   * Saving module parameters
   *
   * @access public
   */
   function saveParams($data = 1)
   {
      $p = array();

      if (isset($this->id)) {
         $p['id'] = $this->id;
      }

      if (isset($this->view_mode)) {
         $p['view_mode'] = $this->view_mode;
      }

      if (isset($this->edit_mode)) {
         $p['edit_mode'] = $this->edit_mode;
      }

      if (isset($this->tab)) {
         $p['tab'] = $this->tab;
      }

      return parent::saveParams($p);
   }

   /**
   * getParams
   *
   * Getting module parameters from query string
   *
   * @access public
   */
   function getParams()
   {
      global $id;
      global $mode;
      global $view_mode;
      global $edit_mode;
      global $tab;

      if (isset($id)) {
         $this->id = $id;
      }

      if (isset($mode)) {
         $this->mode = $mode;
      }

      if (isset($view_mode)) {
         $this->view_mode = $view_mode;
      }

      if (isset($edit_mode)) {
         $this->edit_mode = $edit_mode;
      }

      if (isset($tab)) {
         $this->tab = $tab;
      }
   }

   /**
   * Run
   *
   * Description
   *
   * @access public
   */
   function run()
   {
      global $session;

      $out = array();

      if ($this->action == 'admin') {
         $this->admin($out);
      } else {
         $this->usual($out);
      }

      if (isset($this->owner->action)) {
         $out['PARENT_ACTION'] = $this->owner->action;
      }

      if (isset($this->owner->name)) {
         $out['PARENT_NAME'] = $this->owner->name;
      }

      $out['VIEW_MODE'] = $this->view_mode;
      $out['EDIT_MODE'] = $this->edit_mode;
      $out['ACTION'] = $this->action;
      $out['MODE'] = $this->mode;
      $out['TAB'] = $this->tab;

      $this->data = $out;

      $p = new parser(DIR_TEMPLATES . $this->name . '/' . $this->name . '.html', $this->data, $this);
      $this->result = $p->result;
   }

   /**
   * BackEnd
   *
   * Module backend
   *
   * @access public
   */
   function admin(&$out)
   {
      //$this->WriteLog("admin(): " . $_SERVER['REMOTE_ADDR'] . ' ==> ' . $_SERVER['REQUEST_URI']);

      $this->getConfig();

      $out['API_TCP_PING_PERIOD'] = $this->config['API_TCP_PING_PERIOD'];
      $out['API_WS_PING_PERIOD'] = $this->config['API_WS_PING_PERIOD'];
      $out['API_LOG_DEBMES'] = $this->config['API_LOG_DEBMES'];
      $out['API_LOG_CYCLE'] = $this->config['API_LOG_CYCLE'];

      if ((time() - (int)gg('cycle_' . $this->name . 'Run')) < 15) {
         $out['CYCLERUN'] = 1;
      } else {
         $out['CYCLERUN'] = 0;
      }

      if ($this->view_mode == 'update_settings') {
         $this->config['API_TCP_PING_PERIOD'] = gr('api_tcp_ping_period', 'int');
         $this->config['API_WS_PING_PERIOD'] = gr('api_ws_ping_period', 'int');
         $this->config['API_LOG_DEBMES'] = gr('api_log_debmes');
         $this->config['API_LOG_CYCLE'] = gr('api_log_cycle');

         $this->saveConfig();

         // После изменения настроек модуля перезапускаем цикл.
         setGlobal("cycle_{$this->name}Control", 'restart');

         $this->redirect('?');
      }

      if ($this->view_mode == '' || $this->view_mode == 'search_lgwebostv_devices') {
         $this->search_lgwebostv_devices($out);
      }

      if ($this->view_mode == 'addnew_lgwebostv_devices' && $this->mode == 'addnew') {
         $this->addnew_lgwebostv_devices($out);
      }

      if ($this->view_mode == 'edit_lgwebostv_devices') {
         $this->edit_lgwebostv_devices($out, $this->id);
      }

      if ($this->view_mode == 'delete_lgwebostv_devices') {
         $this->delete_lgwebostv_devices($this->id);
         $this->redirect('?');
      }
   }

   /**
   * FrontEnd
   *
   * Module frontend
   *
   * @access public
   */
   function usual(&$out)
   {
      if ($this->ajax) {

         $op = gr('op');

         if ($op == 'get_token') {
            $device_id = gr('id');

            header('HTTP/1.0: 200 OK\n');
            header('Content-Type: text/html; charset=utf-8');

            $token = SQLSelectOne("SELECT TOKEN FROM lgwebostv_devices WHERE ID='{$device_id}'")['TOKEN'];

            if ($token == '') {
               echo json_encode(array('error' => 'Token not found'));
            } else {
               echo json_encode(array('token' => $token));
               $this->WriteLog("Token successfully received: {$token}");
            }
            exit;
         } else if ($op == 'start_pairing') {
            $device_id = gr('id');

            header('HTTP/1.0: 200 OK\n');
            header('Content-Type: text/html; charset=utf-8');

            $hs = $this->GetHandshake();
            $this->SendCommand($device_id, 'register_', 'register', '', $hs);

            echo 'ok';
            exit;
         } else if ($op == 'send_command') {
            $device_id = gr('id');
            $command = gr('command');
            $value = gr('value');
            $this->MetricHandle($device_id, $command, $value);
         } else if ($op == 'process_message') {
            $message = gr('message');
            $device_id = gr('id');
            $this->ProcessMessage($message, $device_id);
         } else if ($op == 'ping') {
            $device_ip = gr('ip');
            $device_id = gr('id');
            if ($device_ip != '' && $device_id != '') {
               $this->Ping($device_ip, $device_id);
            }
         }

         echo 'OK';
         exit;
      }
   }

   /**
   * lgwebostv_devices search
   *
   * @access public
   */
   function search_lgwebostv_devices(&$out)
   {
      $res = SQLSelect("SELECT * FROM lgwebostv_devices ORDER BY 'TITLE'");

      if ($res[0]['ID']) {
         $total = count($res);
         for($i = 0; $i < $total; $i++) {
            $online = SQLSelectOne("SELECT VALUE FROM lgwebostv_commands WHERE DEVICE_ID='{$res[$i]['ID']}' AND TITLE='online'");
            $res[$i]['ONLINE'] = $online['VALUE'];
         }
         $out['RESULT'] = $res;
      }
   }

   /**
   * lgwebostv_devices add new
   *
   * @access public
   */
   function addnew_lgwebostv_devices(&$out)
   {
      $ok = 1;

      $rec['TITLE'] = gr('title');
      if ($rec['TITLE'] == '') {
         $out['ERR_TITLE'] = 1;
         $ok = 0;
      }

      $rec['IP'] = gr('ip');
      if ($rec['IP'] == '') {
         $out['ERR_IP'] = 1;
         $ok = 0;
      }

      $rec['TOKEN'] = gr('token');

      if ($ok) {
         $new_rec = 1;
         $rec['ID'] = SQLInsert('lgwebostv_devices', $rec);

         // Для только что добавленного устройства выставим статус оффлайн.
         $this->ProcessCommand($rec['ID'], 'online', 0);

         // И добавим метрики command, command_raw, notification.
         $this->ProcessCommand($rec['ID'], 'command', '');
         $this->ProcessCommand($rec['ID'], 'command_raw', '');
         $this->ProcessCommand($rec['ID'], 'notification', '');

         $out['OK'] = 1;
      } else {
         $out['ERR'] = 1;
      }

      if (is_array($rec)) {
         foreach($rec as $k=>$v) {
            if (!is_array($v)) {
               $rec[$k] = htmlspecialchars($v);
            }
         }
      }

      outHash($rec, $out);

      if ($ok) {
         //Передаем в цикл инфу о новом девайсе.
         $this->SendToCycle($rec['ID'], 'addnew', $rec['IP']);
         $this->SendToCycle($rec['ID'], 'ping');
         // И переходим на главную страницу модуля.
         $this->redirect('?');
      }
   }

   /**
   * lgwebostv_devices edit
   *
   * @access public
   */
   function edit_lgwebostv_devices(&$out, $id)
   {
      $rec = SQLSelectOne("SELECT ID, TITLE, IP, TOKEN, MODEL, MAC, WEBOS_VER, SUBSCRIBES FROM lgwebostv_devices WHERE ID='{$id}'");

      //ID, TITLE, IP, TOKEN, MODEL, MAC, WEBOS_VER, SUBSCRIBES, CHANNELS, APPS 

      $online = SQLSelectOne("SELECT VALUE FROM lgwebostv_commands WHERE DEVICE_ID='{$id}' AND TITLE='online'");
      $out['ONLINE'] = $online['VALUE'];

      // Настройки на вкладке "Общее".
      if ($this->tab == '') {
         if ($this->mode == 'update') {

            $ok = 1;

            if ($this->tab == '') {
               $rec['TITLE'] = gr('title');
               if ($rec['TITLE'] == '') {
                  $out['ERR_TITLE'] = 1;
                  $ok = 0;
               }

               $rec['IP'] = gr('ip');
               if ($rec['IP'] == '') {
                  $out['ERR_IP'] = 1;
                  $ok = 0;
               }

               $rec['TOKEN'] = gr('token');
            }

            if ($ok) {
               if ($rec['ID']) {
                  SQLUpdate('lgwebostv_devices', $rec);
                  // Добавим метрики command, command_raw, notification, если есть токен.
                  if ($rec['TOKEN'] != '') {
                     $this->ProcessCommand($rec['ID'], 'command', '');
                     $this->ProcessCommand($rec['ID'], 'command_raw', '');
                     $this->ProcessCommand($rec['ID'], 'notification', '');
                     $this->SendCommand($rec['ID'], 'list_chs_', 'request', 'ssap://tv/getChannelList');
                     usleep(500000);
                     $this->SendCommand($rec['ID'], 'list_apps_', 'request', 'ssap://com.webos.applicationManager/listApps');
                  } else if ($rec['TOKEN'] == '') {
                     // Если удалили токен, то нужно разорвать текущее соединение с ТВ,
                     // иначе будут дублироваться сообщения подписки после получения нового токена.
                     $this->SendToCycle($rec['ID'], 'disconnect');
                     $this->SendToCycle($rec['ID'], 'ping');
                  }
               }
               $out['OK'] = 1;
            } else {
               $out['ERR'] = 1;
            }
         }
      }

      // Вкладки "Данные", "Каналы".
      if ($this->tab == 'data' || $this->tab == 'channels') {

         $delete_id = gr('delete_id');

         if ($delete_id) {
            $prop = SQLSelectOne("SELECT LINKED_OBJECT,LINKED_PROPERTY FROM lgwebostv_commands WHERE ID='{$delete_id}'");
            removeLinkedProperty($prop['LINKED_OBJECT'], $prop['LINKED_PROPERTY'], $this->name);
            SQLExec("DELETE FROM lgwebostv_commands WHERE ID='{$delete_id}'");
         }

         if ($this->tab == 'data') {
            $properties = SQLSelect("SELECT * FROM lgwebostv_commands WHERE DEVICE_ID='{$id}' AND TITLE IN ('online','command','command_raw','notification','state','power','volume','muted','input','source','app','error') ORDER BY ID");
         } else if ($this->tab == 'channels') {
            $properties = SQLSelect("SELECT * FROM lgwebostv_commands WHERE DEVICE_ID='{$id}' AND TITLE IN ('channel_number','channel_name','channel_id','channels_count','channel_type') ORDER BY ID");
         }

         $total = count($properties);

         for($i = 0; $i < $total; $i++) {

            if ($this->mode == 'update') {

               $old_linked_object = $properties[$i]['LINKED_OBJECT'];
               $old_linked_property = $properties[$i]['LINKED_PROPERTY'];

               global ${'linked_object'.$properties[$i]['ID']};
               $properties[$i]['LINKED_OBJECT'] = trim(${'linked_object'.$properties[$i]['ID']});

               global ${'linked_property'.$properties[$i]['ID']};
               $properties[$i]['LINKED_PROPERTY'] = trim(${'linked_property'.$properties[$i]['ID']});

               global ${'linked_method'.$properties[$i]['ID']};
               $properties[$i]['LINKED_METHOD'] = trim(${'linked_method'.$properties[$i]['ID']});

               // Если юзер удалил привязанные свойство и метод, но забыл про объект, то очищаем его.
               if ($properties[$i]['LINKED_OBJECT'] != '' && ($properties[$i]['LINKED_PROPERTY'] == '' && $properties[$i]['LINKED_METHOD'] == '')) {
                   $properties[$i]['LINKED_OBJECT'] = '';
               }

               // Если юзер удалил только привязанный объект, то свойство и метод тоже очищаем.
               if ($properties[$i]['LINKED_OBJECT'] == '' && ($properties[$i]['LINKED_PROPERTY'] != '' || $properties[$i]['LINKED_METHOD'] != '')) {
                   $properties[$i]['LINKED_PROPERTY'] = '';
                   $properties[$i]['LINKED_METHOD'] = '';
               }

               if ($old_linked_object && $old_linked_property && ($old_linked_property != $properties[$i]['LINKED_PROPERTY'] || $old_linked_object != $properties[$i]['LINKED_OBJECT'])) {
                  removeLinkedProperty($old_linked_object, $old_linked_property, $this->name);
               }

               if ($properties[$i]['LINKED_OBJECT'] && $properties[$i]['LINKED_PROPERTY']) {
                  addLinkedProperty($properties[$i]['LINKED_OBJECT'], $properties[$i]['LINKED_PROPERTY'], $this->name);
               }

               SQLUpdate('lgwebostv_commands', $properties[$i]);
            }

            if (file_exists(DIR_MODULES . 'devices/devices.class.php')) {
               if ($properties[$i]['TITLE'] == 'power') {
                  $properties[$i]['SDEVICE_TYPE'] = 'relay';
               } else if ($properties[$i]['TITLE'] == 'volume') {
                  $properties[$i]['SDEVICE_TYPE'] = 'dimmer';
               } else {
                  $properties[$i]['SDEVICE_TYPE'] = 'sensor_general';
               }
            }
         }
         if (count($properties) != 0) {
            $out['PROPERTIES'] = $properties;
         } else {
            $out['PROPERTIES'] = '';
         }
      }

      // Вкладка "Приложения".
      if ($this->tab == 'apps') {
         $apps = SQLSelectOne("SELECT APPS FROM lgwebostv_devices WHERE ID='{$id}'")['APPS'];
         if (!empty($apps)) {
            $apps = json_decode($apps, true);
            if (is_array($apps)) {
               foreach ($apps as $app) {
                  if ($app['app_category'] == 'users') {
                     $apps_users[] = $app;
                  } else if ($app['app_category'] == 'inputs') {
                     $apps_inputs[] = $app;
                  } else {
                     $apps_others[] = $app;
                  }
               }
               $out['APPS_USERS'] = $apps_users;
               $out['APPS_INPUTS'] = $apps_inputs;
               $out['APPS_OTHERS'] = $apps_others;
            } else {
               $out['APPS_USERS'] = '';
               $out['APPS_INPUTS'] = '';
               $out['APPS_OTHERS'] = '';
            }
         }
      }

      // Вкладка "Справка".
      if ($this->tab == 'help') {
         $out['LANG'] = SETTINGS_SITE_LANGUAGE;
         $out['HELP_PATH'] = DIR_TEMPLATES . $this->name . '/help/help_' . SETTINGS_SITE_LANGUAGE . '.html';
         // Проверим наличие файла-справки для текущего языка МДМ
         if (!file_exists($out['HELP_PATH'])) {
            // если файла нет, то выводим файл-справку на русском языке
            $out['HELP_PATH'] = DIR_TEMPLATES . $this->name . '/help/help_ru.html';
         }
      }

      if (is_array($rec)) {
         foreach($rec as $k=>$v) {
            if (!is_array($v)) {
               $rec[$k] = htmlspecialchars($v);
            }
         }
      }

      outHash($rec, $out);
   }

   /**
   * lgwebostv_devices delete record
   *
   * @access public
   */
   function delete_lgwebostv_devices($id)
   {
      $this->SendToCycle($id, 'delete');

      $this->DeleteLinkedProperties($id);

      SQLExec("DELETE FROM lgwebostv_devices WHERE ID='{$id}'");
      SQLExec("DELETE FROM lgwebostv_commands WHERE DEVICE_ID='{$id}'");
   }

   function PropertySetHandle($object, $property, $value)
   {
      $properties = SQLSelect("SELECT lgwebostv_commands.*, lgwebostv_devices.TOKEN FROM lgwebostv_commands LEFT JOIN lgwebostv_devices ON lgwebostv_devices.ID=lgwebostv_commands.DEVICE_ID WHERE lgwebostv_commands.LINKED_OBJECT LIKE '".DBSafe($object)."' AND lgwebostv_commands.LINKED_PROPERTY LIKE '".DBSafe($property)."'");

      $total = count($properties);

      if ($total) {
         for($i = 0; $i < $total; $i++) {
            if ($properties[$i]['TOKEN'] != '') {

               $this->MetricHandle($properties[$i]['DEVICE_ID'], $properties[$i]['TITLE'], $value);

               // Не будем писать в базу, т.к. не получен ответ от ТВ. Только отправим команду.
               // Запись в БД, в связанное свойство и вызов связанного метода выполним при получении ответа от ТВ.
               // Исключение для command, command_raw, notification.
               if ($properties[$i]['TITLE'] == 'command' || $properties[$i]['TITLE'] == 'command_raw' || $properties[$i]['TITLE'] == 'notification') {
                  SQLExec("UPDATE lgwebostv_commands SET VALUE='" . DBSafe($value) . "', UPDATED='" . date('Y-m-d H:i:s') . "' WHERE ID=" . $properties[$i]['ID']);
               }
            } else {
               if ($properties[$i]['TITLE'] == 'command' && $value == 'ping') {
                  $this->SendToCycle($properties[$i]['DEVICE_ID'], 'ping');
               }
            }
         }
      }
   }

   function MetricHandle($device_id, $title, $value)
   {
      // Метрика command - отправка предопределенных команд на ТВ или для цикла.
      if ($title == 'command') {
         if ($value == 'ping') {
            $this->SendToCycle($device_id, 'ping');
         } else if ($value == 'connect') {
            $this->SendToCycle($device_id, 'connect');
         } else if ($value == 'disconnect') {
            $this->SendToCycle($device_id, 'disconnect');
         } else if ($value == 'volumeUp') {
            $this->SendCommand($device_id, '', 'request', 'ssap://audio/volumeUp');
         } else if ($value == 'volumeDown') {
            $this->SendCommand($device_id, '', 'request', 'ssap://audio/volumeDown');
         } else if ($value == 'channelUp') {
            $this->SendCommand($device_id, '', 'request', 'ssap://tv/channelUp');
         } else if ($value == 'channelDown') {
            $this->SendCommand($device_id, '', 'request', 'ssap://tv/channelDown');
         } else if ($value == 'powerOff') {
            $title = 'power';
            $value = 0;
         }
      } 

      if ($title == 'command_raw') {
         // Метрика command_raw - отправка "сырых" api-команд на ТВ (без payload).
         $this->SendCommand($device_id, '', 'request', $value);
         // TODO: для payload можно применять какой-либо разделитель (точка, запятая, собака и др.)
      } else if ($title == 'power') {
         // Метрика power - выключение ТВ.
         $value = (int)$value;
         if ($value == 0) {
            $this->SendCommand($device_id, '', 'request', 'ssap://system/turnOff');
         } else if ($value == 1) {
            // TODO: включение с помощью WOL.
         }
      } else if ($title == 'volume') {
         // Метрика volume - изменение громкости.
         $value = (int)$value;
         $this->SendCommand($device_id, '', 'request', 'ssap://audio/setVolume', '{"volume":' . $value . '}');
      } else if ($title == 'input') {
         // Метрика input - переключение входа.
         switch ($value) {
            case ('livetv');
            case ('hdmi1');
            case ('hdmi2');
            case ('hdmi3');
            case ('hdmi4');
            case ('miracast');
               $inputId = 'com.webos.app.' . $value;
               break;
            case ('av1');
            case ('component');
               $inputId = 'com.webos.app.externalinput.' . $value;
               break;
         }
         $this->SendCommand($device_id, '', 'request', 'ssap://system.launcher/launch', '{"id":"' . $inputId . '"}');
      } else if ($title == 'muted') {
         // Метрика muted - включение/выключение беззвучного режима.
         $value = (int)$value;
         $value = ($value == 1) ? 'true' : 'false';
         $this->SendCommand($device_id, '', 'request', 'ssap://audio/setMute', '{"mute":' . $value . '}');
      } else if ($title == 'notification') {
         // Метрика notification - отображение текстового уведомления на ТВ.
         $this->SendCommand($device_id, '', 'request', 'ssap://system.notifications/createToast', '{"message":"' . $value . '"}');
         // TODO: указание ссылки на иконку через разделитель '|'.
      } else if ($title == 'channel_name' || $title == 'channel_number') {
         // Метрика channel_name - переключение канала ТВ по названию.
         // Метрика channel_number - переключение канала ТВ по номеру.
         $channels = SQLSelectOne("SELECT CHANNELS FROM lgwebostv_devices WHERE ID='{$device_id}'")['CHANNELS'];
         $type = SQLSelectOne("SELECT VALUE FROM lgwebostv_commands WHERE DEVICE_ID='{$device_id}' AND TITLE='channel_type'")['VALUE'];
         if (!empty($channels)) {
            $channels = json_decode($channels, true);
            if (is_array($channels)) {
               if ($channels[$type]) {
                  $index = array();
                  $key = ($title == 'channel_name') ? 'channelName' : 'channelNumber';
                  foreach($channels[$type] as $k => $v) {
                     $index[$v[$key]] = $k;
                  }
                  $channelId = $channels[$type][$index[$value]]['channelId'];
                  if (isset($channelId) && $channelId != '') {
                     $this->SendCommand($device_id, '', 'request', 'ssap://tv/openChannel', '{"channelId":"' . $channelId . '"}');
                  } else {
                     $this->WriteLog("Channel $value for TV ID{$device_id} not found. Command not send.");
                  }
                  unset($index);
                  unset($channels); 
               } else {
                  // не верный тип вещания
                  $this->WriteLog("Wrong channel type for TV ID{$device_id}. Command not send.");
               }
            }
         } else {
            $this->WriteLog("No channels data in DB for TV ID{$device_id}. Command not send.");
         }
      } else if ($title == 'channel_id') {
         // Метрика channel_id - переключение канала ТВ по идентификатору.
         $this->SendCommand($device_id, '', 'request', 'ssap://tv/openChannel', '{"channelId":"' . $value . '"}');
      } else if ($title == 'app' || $title == 'state' || $title == 'source') {
         // Метрика app - запуск приложения (в т.ч. выбор источника)
         $this->SendCommand($device_id, '', 'request', 'ssap://system.launcher/launch', '{"id":"' . $value . '"}');
      }
   }

   function IncomingMessageProcessing($message, $device_id)
   {
      // Входящие сообщения можем отравить на дальнейшую обработку двумя способами:
      //    1. В неблокирующем режиме через вызов фонового процесса по URL-ссылке модуля.
      //    2. Прямой вызов функции модуля.
      // Первый способ не блокирует цикл модуля на время обработки сообщения,
      // вызов привязанного пользовательского метода и выполнение его кода,
      // а также защищает цикл от ошибок в коде привязанного метода.
      // Недостаток такого подхода - лишняя нагрузка на веб-сервер и невозможность отправки данных 
      // большой длины, т.к. есть ограничение на длину GET-запроса.
      // Второй способ может блокировать цикл модуля на долгое время, либо вообще крашить его
      // при ошибках в коде привязанного метода. Вызов привязанного метода через callMethodSafe
      // улучшает ситуацию, но блокировка цикла при этом составляет не менее 1 сек на каждое 
      // входящее сообщение. Преимущество - нет нагрузки на веб-сервер.
      if (defined('BACKGROUND_MESSAGE_PROCESS') && BACKGROUND_MESSAGE_PROCESS == 1 && strlen($message) <= 2048) {
         $data = array('message' => $message, 'id' => $device_id);
         $this->RunInBackground('process_message', $data);
      } else {
         $this->ProcessMessage($message, $device_id);
      }
      // Если сообщение длинное, то возникает ошибка Request-URI Too Large при передаче через RunInBackground.
      // Нужно такие большие сообщения отдавать на обработку через прямой вызов ProcessMessage().
   }

   function ProcessMessage($message, $device_id)
   {
      $data = json_decode($message, true);

      if ($data['type'] != 'ping') {
         // Очень длинные сообщения не будем писать в DebMes-лог.
         if (strlen($message) <= 8192) {
            $this->WriteLog("Incoming message from TV ID{$device_id}: {$message}");
         } else {
            $this->WriteLog("Incoming message from TV ID{$device_id} message too long. See the cycle log.");
         }
      }

      if ($data['type'] == 'registered') {
         if (isset($data['payload']['client-key'])) {
            $this->WriteLog('Success handshake');
            $device = SQLSelectOne("SELECT * FROM lgwebostv_devices WHERE ID=" . (int)$device_id);
            if ($device['TOKEN'] !== $data['payload']['client-key']) {
               $device['TOKEN'] = $data['payload']['client-key'];
               SQLUpdate('lgwebostv_devices', $device);
            }
            $this->GetInfoOnConnected($device_id);
         } else {
            $this->WriteLog('Failed handshake');
         }
      } else if ($data['type'] == 'response') {

         $this->ProcessCommand($device_id, 'online', 1);

         if (isset($data['payload']['pairingType']) && isset($data['payload']['returnValue'])) {
            $this->WriteLog('Handshake PROMT');
         }

         if (isset($data['payload']['channelListCount']) && isset($data['payload']['channelList'])) {
            $channelListCount = $data['payload']['channelListCount'];
            if ($channelListCount > 0) {
               $this->ProcessCommand($device_id, 'channels_count', $channelListCount);
               $chs_list = $data['payload']['channelList'];
               $total = count($chs_list);
               $channels = array();
               for($i = 0; $i < $total; $i++) {
                  $chl = array();
                  $chl['channelId'] = $chs_list[$i]['channelId'];
                  $chl['channelNumber'] = $chs_list[$i]['channelNumber'];
                  $chl['channelName'] = $chs_list[$i]['channelName'];
                  $channels[$chs_list[$i]['channelType']][] = $chl;
               }
               $device = SQLSelectOne("SELECT * FROM lgwebostv_devices WHERE ID='{$device_id}'");
               $device['CHANNELS'] = json_encode($channels);
               SQLUpdate('lgwebostv_devices', $device);
            }
         }

         if (isset($data['payload']['apps'])) {
            $device = SQLSelectOne("SELECT * FROM lgwebostv_devices WHERE ID='{$device_id}'");

            $total = count($data['payload']['apps']);
            $launchPoints = $data['payload']['apps'];
            $apps = array();

            $inputs = array('com.webos.app.livetv', 'com.webos.app.hdmi1', 'com.webos.app.hdmi2', 'com.webos.app.hdmi3', 'com.webos.app.hdmi4', 'com.webos.app.miracast', 'com.webos.app.externalinput.av1', 'com.webos.app.externalinput.av2', 'com.webos.app.externalinput.component', 'com.webos.app.externalinput.scart');

            for($i = 0; $i < $total; $i++) {
               $app = array();
               $app['app_id'] = $launchPoints[$i]['id'];
               $app['app_title'] = $launchPoints[$i]['title'];
               $app['app_icon'] = str_replace(parse_url($launchPoints[$i]['icon'])['host'], $device['IP'], $launchPoints[$i]['icon']);
               $app['app_miniicon'] = str_replace(parse_url($launchPoints[$i]['miniicon'])['host'], $device['IP'], $launchPoints[$i]['miniicon']);
               if (in_array($app['app_id'], $inputs)) {
                  $app['app_category'] = 'inputs';
               } else {
                  if ($launchPoints[$i]['visible'] == true) {
                     $app['app_category'] = 'users';
                  } else {
                     $app['app_category'] = 'others';
                  }
               }
               $apps[] = $app;
            }
            $device['APPS'] = json_encode($apps);
            SQLUpdate('lgwebostv_devices', $device);
         }
         
         if (strpos($data['id'], 'volume_') !== false) {
            // Подписка на громкость.
            $volume = $data['payload']['volume'];
            $mute = $data['payload']['muted'] ? 1 : 0;
            $this->ProcessCommand($device_id, 'volume', $volume);
            $this->ProcessCommand($device_id, 'muted', $mute);
         } else if (strpos($data['id'], 'channel_') !== false) {
            // Подписка на смену каналов.
            $channelId = $data['payload']['channelId'];
            $channelNumber = $data['payload']['channelNumber'];
            $channelName = $data['payload']['channelName'];
            $channelTypeName = $data['payload']['channelTypeName'];
            $this->ProcessCommand($device_id, 'channel_number', $channelNumber);
            $this->ProcessCommand($device_id, 'channel_name', $channelName);
            $this->ProcessCommand($device_id, 'channel_id', $channelId);
            $this->ProcessCommand($device_id, 'channel_type', $channelTypeName);
         } else if (strpos($data['id'], 'input_') !== false) {
            // Подписка на статусы входов/источников.
            /*
            if (!empty($data['payload']['devices'])) {
               foreach ($data['payload']['devices'] as $input) {
                  if ($input['connected']) {
                     if (strrpos($input['appId'], 'externalinput') !== false) {
                        $input = str_replace('com.webos.app.externalinput.', '', $input['appId']);
                     } else {
                        $input = str_replace('com.webos.app.', '', $input['appId']);
                     }
                     $this->ProcessCommand($device_id, 'input', $input);
                  }
               }
            }
            */
         } else if (strpos($data['id'], 'foreground_app_') !== false) {
            // Подписка на текущее запущенное приложение (в т. ч. источник).
            $appId = $data['payload']['appId'];
            if (strrpos($appId, 'com.webos.app') !== false) {
               if (strrpos($appId, 'externalinput') !== false) {
                  $app = str_replace('com.webos.app.externalinput.', '', $appId);
               } else {
                  $app = str_replace('com.webos.app.', '', $appId);
               }
            } else {
               $tmp = explode('.', $appId);
               if ($tmp[1]) {
                  $app = trim($tmp[0]);
               } else {
                  $app = $appId;
               }
            }
            $inputs = array('livetv', 'hdmi1', 'hdmi2', 'hdmi3', 'hdmi4', 'miracast', 'av1', 'av2', 'component', 'scart');

            if (in_array($app, $inputs)) {
               //$this->ProcessCommand($device_id, 'input', $app);
            } else {
               //$this->ProcessCommand($device_id, 'app', $app);
            }
            $this->ProcessCommand($device_id, 'state', $appId);
            // На каналы нужно подписываться, когда выбран вход livetv, иначе будет ошибка и подписка не оформится.
            if ($app == 'livetv') {
               // Если еще не подписаны на события смены каналов, то подписываемся.
               if (!$this->GetSubscribeStatus($device_id, 'channel_')) {
                  $this->SubscribeTo($device_id, 'channel_', 'ssap://tv/getCurrentChannel');
               }
            }
         } else if (strpos($data['id'], 'sw_info_') !== false) {
            $device = SQLSelectOne("SELECT * FROM lgwebostv_devices WHERE ID=" . (int)$device_id);
            $device['MAC'] = $data['payload']['device_id'];
            $device['WEBOS_VER'] = $data['payload']['product_name'];
            SQLUpdate('lgwebostv_devices', $device);
         } else if (strpos($data['id'], 'sys_info_') !== false) {
            $device = SQLSelectOne("SELECT * FROM lgwebostv_devices WHERE ID=" . (int)$device_id);
            $device['MODEL'] = $data['payload']['modelName'];
            SQLUpdate('lgwebostv_devices', $device);
         }
      } else if ($data['type'] == 'error') {
         if (isset($data['payload']['errorText'])) {
            $errorText = $data['payload']['errorText'];
         } else {
            $errorText = '';
         }
         $this->ProcessCommand($device_id, 'error', $data['error'] . " ({$errorText})");
      } else if ($data['type'] == 'ping') {
         if ($data['online'] == 1) {
            $this->ProcessCommand($device_id, 'online', 1);
         } else if ($data['online'] == 0) {
            $this->ProcessCommand($device_id, 'online', 0);
         }
      } else if ($data['type'] == 'ws_accept') {
         $device = SQLSelectOne("SELECT * FROM lgwebostv_devices WHERE ID=" . (int)$device_id);
         if ($device['TOKEN'] != '') {
            $hs = $this->GetHandshake($device['TOKEN']);
            $this->SendCommand($device_id, 'register_', 'register', '', $hs);
         }
         $this->ProcessCommand($device_id, 'power', 1);
      } else if ($data['type'] == 'ws_close') {
         $this->ProcessCommand($device_id, 'online', 0);
         $this->ProcessCommand($device_id, 'power', 0);
         $this->UnsubscribeFrom($device_id, 'channel_');
      }
   }

   function ProcessCommand($device_id, $command, $value, $params = 0)
   {
      $cmd_rec = SQLSelectOne("SELECT * FROM lgwebostv_commands WHERE DEVICE_ID=".(int)$device_id." AND TITLE LIKE '".DBSafe($command)."'");

      if (!$cmd_rec['ID']) {
         $cmd_rec = array();
         $cmd_rec['TITLE'] = $command;
         $cmd_rec['DEVICE_ID'] = $device_id;
         $cmd_rec['ID'] = SQLInsert('lgwebostv_commands', $cmd_rec);
      }

      $old_value = $cmd_rec['VALUE'];

      $cmd_rec['VALUE'] = $value;
      $cmd_rec['UPDATED'] = date('Y-m-d H:i:s');

      // Обновляем значение метрики в таблице модуля.
      SQLUpdate('lgwebostv_commands', $cmd_rec);

      // Если значение метрики не изменилось, то выходим.
      if ($old_value == $value) return;

      // Иначе обновляем привязанное свойство.
      if ($cmd_rec['LINKED_OBJECT'] && $cmd_rec['LINKED_PROPERTY']
                                    && (getGlobal($cmd_rec['LINKED_OBJECT'] . '.' . $cmd_rec['LINKED_PROPERTY']) != $value)) {
         setGlobal($cmd_rec['LINKED_OBJECT'] . '.' . $cmd_rec['LINKED_PROPERTY'], $value, array($this->name => '0'));
      }

      // И вызываем привязанный метод.
      if ($cmd_rec['LINKED_OBJECT'] && $cmd_rec['LINKED_METHOD']) {
         if (!is_array($params)) {
            $params = array();
         }

         $params['PROPERTY'] = $command;
         $params['NEW_VALUE'] = $value;
         $params['OLD_VALUE'] = $old_value;

         if (defined('CALL_METHOD_SAFE') && CALL_METHOD_SAFE == 1) {
            callMethodSafe($cmd_rec['LINKED_OBJECT'] . '.' . $cmd_rec['LINKED_METHOD'], $params);
         } else {
            callMethod($cmd_rec['LINKED_OBJECT'] . '.' . $cmd_rec['LINKED_METHOD'], $params);
         }
      }
   }

   function ProcessControlCommand($message, $csocket, &$tvList, $cycle_debug = true)
   {
      $control_cmd = json_decode($message);

      // Команда
      $cmd = $control_cmd->command;

      // ID телевизора
      $dev_id = $control_cmd->device_id;

      switch ($cmd) {
         case ('send'):
            $tvList[$dev_id]['SOCKET']->WriteData($control_cmd->data, true);
            break;
         case ('connect'):
            $tvList[$dev_id]['SOCKET']->SetStatus('DO_CONNECT');
            break;
         case ('disconnect'):
            $tvList[$dev_id]['SOCKET']->Disconnect();
            break;
         case ('addnew'):
            $dev_ip = $control_cmd->data;
            $tvObj = array();
            $tvObj['ID'] = $dev_id;
            $tvObj['IP'] = $dev_ip;
            $tvObj['SOCKET'] = new SocketJobs($dev_ip, WEBOS_PORT, $cycle_debug);
            $tvList[$dev_id] = $tvObj;
            break;
         case ('delete'):
            if ($tvList[$dev_id]['SOCKET']->IsOnline()) {
               $tvList[$dev_id]['SOCKET']->Disconnect();
            }
            unset($tvList[$dev_id]);
            break;
         case ('ping'):
            if ($tvList[$dev_id]['SOCKET']->Ping()) {
               $this->ProcessMessage('{"type":"ping","online":"1"}', $dev_id, 'ping');
            } else {
               $this->ProcessMessage('{"type":"ping","online":"0"}', $dev_id, 'ping');
            }
            break;
         case ('ping_status'):
            $status = $control_cmd->data;
            if ($status == true) {
               if ($tvList[$dev_id]['SOCKET']->GetStatus() == 'DO_PING') {
                  $tvList[$dev_id]['SOCKET']->SetStatus('DO_CONNECT');
                  $tvList[$dev_id]['SOCKET']->lastSendMsgTime = time();
                  $tvList[$dev_id]['SOCKET']->lastRcvMsgTime = time();
               }
               $this->ProcessMessage('{"type":"ping","online":"1"}', $dev_id, 'ping');
            } else if ($status == false) {
               $this->ProcessMessage('{"type":"ping","online":"0"}', $dev_id, 'ping');
            }
            break;
      }
   }

   function SendCommand($device_id, $prefix, $msgtype, $uri = '', $payload = null)
   {
      $commandCount = 1;

      $msg = array();
      if ($msgtype != 'subscribe') {
         $msg['id'] = $prefix . $commandCount;
      } else {
         $msg['id'] = $prefix;
      }
      $msg['type'] = $msgtype;

      if ($uri !== '') {
         $msg['uri'] = $uri;
      }

      if ($payload) {
         $msg['payload'] = $payload;
      }

      $cmd = json_encode($msg, JSON_UNESCAPED_SLASHES);

      $this->WriteLog("Outgoing message to TV ID{$device_id}: {$cmd}");

      $this->SendToCycle($device_id, 'send', $cmd);
   }

   function SendToCycle($device_id, $command, $data = '')
   {
      $msg = array();
      $msg['command'] = $command;
      $msg['device_id'] = $device_id;
      $msg['data'] = $data;

      $cmd = json_encode($msg, JSON_UNESCAPED_SLASHES);

      $client = @stream_socket_client('tcp://127.0.0.1:' . CONTROL_SOCKET_PORT, $errno, $errstr, 30);

      if (!$client) {
         $this->WriteLog("Failed sending to cycle control socket: $errstr ($errno)");
      } else {
         fwrite($client, $cmd);
         stream_socket_shutdown($client, STREAM_SHUT_RDWR);
         fclose($client);
      }
   }

   function GetHandshake($token = '')
   {
      $payload = json_decode(AUTH_PAYLOAD, true);

      if ($token !== '') {
         $payload['client-key'] = $token;
         return $payload;
      } else {
         return $payload;
      }
   }

   function Ping($device_ip, $device_id)
   {
      $this->WriteLog("Checking TV {$device_ip} [ID{$device_id}] (tcp ping).");

      $isOnline = false;
      $connection = @fsockopen($device_ip, WEBOS_PORT, $errno, $errstr, 5);

      if (is_resource($connection) && !empty($connection)) {
         fclose($connection);
         $this->WriteLog(" TV {$device_ip} [ID{$device_id}] is online.");
         $isOnline = true;
      } else {
         $this->WriteLog(" TV {$device_ip} [ID{$device_id}] is offline. Connect error: {$errno}.");
      }
      // Передаем сведения в цикл.
      $this->SendToCycle($device_id, 'ping_status', $isOnline);
   }

   function GetInfoOnConnected($device_id)
   {
      $this->SendCommand($device_id, 'volume_', 'subscribe', 'ssap://audio/getVolume');
      $this->SendCommand($device_id, "foreground_app_", "subscribe", "ssap://com.webos.applicationManager/getForegroundAppInfo");
      //$this->SendCommand($device_id, "input_", "subscribe", "ssap://tv/getExternalInputList");
      $this->SendCommand($device_id, 'sw_info_', 'request', 'ssap://com.webos.service.update/getCurrentSWInformation');
      $this->SendCommand($device_id, 'sys_info_', 'request', 'ssap://system/getSystemInfo');
      
      $channels = SQLSelectOne("SELECT CHANNELS FROM lgwebostv_devices WHERE ID='{$device_id}'")['CHANNELS'];
      if (empty($channels) && strlen($channels) == 0) {
         $this->SendCommand($device_id, 'list_chs_', 'request', 'ssap://tv/getChannelList');
      }
      usleep(500000);
      $apps = SQLSelectOne("SELECT APPS FROM lgwebostv_devices WHERE ID='{$device_id}'")['APPS'];
      if (empty($apps) && strlen($apps) == 0) {
         $this->SendCommand($device_id, 'list_apps_', 'request', 'ssap://com.webos.applicationManager/listApps');
      }
   }

   function SubscribeTo($device_id, $prefix, $ssap_url)
   {
      $this->SendCommand($device_id, $prefix, 'subscribe', $ssap_url);

      $device = SQLSelectOne("SELECT * FROM lgwebostv_devices WHERE ID='{$device_id}'");

      if (!empty($device['SUBSCRIBES'])) {
         $subscribes = json_decode($device['SUBSCRIBES'], true);
         if (is_array($subscribes)) {
            $subscribes[$prefix] = 1;
         }
      } else {
         $subscribes = array();
         $subscribes[$prefix] = 1;
      }
      $device['SUBSCRIBES'] = json_encode($subscribes);
      SQLUpdate('lgwebostv_devices', $device);
   }

   function UnsubscribeFrom($device_id, $prefix)
   {
      $device = SQLSelectOne("SELECT * FROM lgwebostv_devices WHERE ID='{$device_id}'");

      if (!empty($device['SUBSCRIBES'])) {
         $subscribes = json_decode($device['SUBSCRIBES'], true);
         if (is_array($subscribes)) {
            if (isset($subscribes[$prefix]) && $subscribes[$prefix] == 1) {
               $subscribes[$prefix] = 0;
               $device['SUBSCRIBES'] = json_encode($subscribes);
               SQLUpdate('lgwebostv_devices', $device);
            }
         }
      }
   }

   function GetSubscribeStatus($device_id, $prefix)
   {
      $subscribes = SQLSelectOne("SELECT SUBSCRIBES FROM lgwebostv_devices WHERE ID='{$device_id}'")['SUBSCRIBES'];

      if (!empty($subscribes)) {
         $subscribes = json_decode($subscribes, true);
         if (is_array($subscribes)) {
            if (isset($subscribes[$prefix]) && $subscribes[$prefix] == 1) {
               return true;
            } else {
               return false;
            }
         }
      }  else {
         return false;
      }
   }

   function RunInBackground($command, $params = false)
   {
      $args['op'] = $command;

      if (is_array($params)) {
         $args += $params;
      }

      $url = BASE_URL . "/ajax/{$this->name}.html?" . http_build_query($args);

      try {
         $ch = curl_init();
         curl_setopt($ch, CURLOPT_URL, $url);
         curl_setopt($ch, CURLOPT_NOSIGNAL, 1);
         curl_setopt($ch, CURLOPT_TIMEOUT_MS, 50);
         curl_exec($ch);
         curl_close($ch);
      }
      catch (Exception $e) {
         $this->WriteLog('Exception in RunInBackground(): ' . $url . '. ' . get_class($e) . ', ' . $e->getMessage());
      }
   }

   function DeleteLinkedProperties($id)
   {
      $properties = SQLSelect("SELECT * FROM lgwebostv_commands WHERE DEVICE_ID='{$id}' AND LINKED_OBJECT != '' AND LINKED_PROPERTY != ''");

      if (!empty($properties)) {
         foreach ($properties as $prop) {
            removeLinkedProperty($prop['LINKED_OBJECT'], $prop['LINKED_PROPERTY'], $this->name);
         }
      }
   }

   function DeleteCycleProperties()
   {
      $cycle_name = 'cycle_' . $this->name;
      $cycle_props = array("{$cycle_name}Run", "{$cycle_name}Control", "{$cycle_name}Disabled", "{$cycle_name}AutoRestart");

      $object = getObject('ThisComputer');

      foreach ($cycle_props as $property) {
         $property_id = $object->getPropertyByName($property, $object->class_id, $object->id);
         if ($property_id) {
            $value_id = getValueIdByName($object->object_title, $property);
            if ($value_id) {
               SQLExec("DELETE FROM phistory WHERE VALUE_ID={$value_id}");
               SQLExec("DELETE FROM pvalues WHERE ID={$value_id}");
            }
            if ($object->class_id != 0) {
               SQLExec("DELETE FROM properties WHERE ID={$property_id}");
            }
          }
      }

      SQLExec("DELETE FROM cached_values WHERE KEYWORD LIKE '%{$cycle_name}%'");
      SQLExec("DELETE FROM cached_ws WHERE PROPERTY LIKE '%{$cycle_name}%'");
   }

   function WriteLog($msg)
   {
      if ($this->debug) {
         DebMes($msg, $this->name);
      }
   }

   /**
   * Install
   *
   * Module installation routine
   *
   * @access private
   */
   function install($data = '')
   {
      parent::install();
   }

   /**
   * Uninstall
   *
   * Module uninstall routine
   *
   * @access public
   */
   function uninstall()
   {
      echo '<br>' . date('H:i:s') . " Uninstall module {$this->name}.<br>";

      // Остановим цикл модуля.
      echo date('H:i:s') . " Stopping cycle cycle_{$this->name}.php.<br>";
      setGlobal("cycle_{$this->name}Control", 'stop');
      // Нужна пауза, чтобы главный цикл обработал запрос.
      $i = 0;
      while ($i < 6) {
         echo '.';
         $i++; 
         sleep(1);
      }

      // Удалим слинкованные свойства объектов у метрик каждого ТВ.
      echo '<br>' . date('H:i:s') . ' Delete linked properties.<br>';
      $tvs = SQLSelect("SELECT * FROM lgwebostv_devices");
      if (!empty($tvs)) {
         foreach ($tvs as $tv) {
            $this->DeleteLinkedProperties($tv['ID']);
         }
      }

      // Удаляем таблицы модуля из БД.
      echo date('H:i:s') . ' Delete DB tables.<br>';
      SQLExec('DROP TABLE IF EXISTS lgwebostv_devices');
      SQLExec('DROP TABLE IF EXISTS lgwebostv_commands');

      // Удаляем служебные свойства контроля состояния цикла у объекта ThisComputer.
      echo date('H:i:s') . ' Delete cycles properties.<br>';
      $this->DeleteCycleProperties();

      // Удаляем модуль с помощью "родительской" функции ядра.
      echo date('H:i:s') . ' Delete files and remove frome system.<br>';
      parent::uninstall();
   }

   /**
   * dbInstall
   *
   * Database installation routine
   *
   * @access private
   */
   function dbInstall($data = '')
   {
      $data = <<<EOD
         lgwebostv_devices: ID int(10) unsigned NOT NULL auto_increment
         lgwebostv_devices: TITLE varchar(255) NOT NULL DEFAULT ''
         lgwebostv_devices: IP varchar(100) NOT NULL DEFAULT ''
         lgwebostv_devices: TOKEN varchar(100) NOT NULL DEFAULT ''
         lgwebostv_devices: MODEL varchar(100) NOT NULL DEFAULT ''
         lgwebostv_devices: MAC varchar(100) NOT NULL DEFAULT ''
         lgwebostv_devices: WEBOS_VER varchar(100) NOT NULL DEFAULT ''
         lgwebostv_devices: SUBSCRIBES varchar(255) NOT NULL DEFAULT ''
         lgwebostv_devices: CHANNELS text
         lgwebostv_devices: APPS text

         lgwebostv_commands: ID int(10) unsigned NOT NULL auto_increment
         lgwebostv_commands: TITLE varchar(100) NOT NULL DEFAULT ''
         lgwebostv_commands: VALUE text
         lgwebostv_commands: DEVICE_ID int(10) NOT NULL DEFAULT '0'
         lgwebostv_commands: LINKED_OBJECT varchar(100) NOT NULL DEFAULT ''
         lgwebostv_commands: LINKED_PROPERTY varchar(100) NOT NULL DEFAULT ''
         lgwebostv_commands: LINKED_METHOD varchar(100) NOT NULL DEFAULT ''
         lgwebostv_commands: UPDATED datetime
EOD;
      parent::dbInstall($data);
   }

}
