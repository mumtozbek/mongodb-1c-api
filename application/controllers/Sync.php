<?php
defined('BASEPATH') OR $this->output->set_output('No direct script access allowed');

error_reporting(E_ALL);
ini_set('display_errors', 'on');

class Sync extends CI_Controller {
  const VERSION = '3.0.0';

  private $mongodb;
  private $response = [];
  private $user = [];

  public function __construct() {
    parent::__construct();

    // Init MongoDB
    require_once BASEPATH . "/vendor/autoload.php";
    $this->mongodb = new MongoDB\Client('mongodb://admin:n;>>jz3C_cNA}Q]^@localhost');

    // Init binary model and parse gz request
    $this->load->model('binary', 'binary');
    $this->binary->parseRequest(true);

    // Current time
    $this->response['Время'] = date('d.m.Y H:i:s');

    // Set content-type=json
    header('Content-type: application/json; charset=utf8');

    // Auth
    if ($this->input->get_post('username', true) == '' || $this->input->get_post('password', true) == '') {
      $this->response['error_code'] = 'auth';
      $this->response['error_description'] = 'Неверные данные авторизации.';
    } else {
      $query = $this->db->query("SELECT * FROM `" . $this->db->dbprefix . "user` WHERE `status` = '1' AND `username` = '" . $this->input->get_post('username', true) . "' AND `password` = '" . $this->input->get_post('password', true) . "' AND `status` = '1'");
      if ($query->num_rows() > 0) {
        $row = $query->row_array(0);
        if ($row['is_mobile'] == 1) {
          $this->response['error_code'] = 'auth';
          $this->response['error_description'] = 'Для данного пользователя запрещено использование компьютерного клиента.';
        } else {
          $this->user= $row;
        }
      } else {
        $this->response['error_code'] = 'auth';
        $this->response['error_description'] = 'Неверные данные авторизации.';
      }
    }

    // Compare server and user time
    if ($this->input->get_post('time')) {
      $user_time = strtotime($this->input->get_post('time'));
      if ($user_time > (time() + 1800) || $user_time < (time() - 1800)) {
        $this->response['error_code'] = 'time';
        $this->response['error_description'] = 'Время устройства отличается от времени сервера.';
      }
    } else {
      $this->response['error_code'] = 'time';
      $this->response['error_description'] = 'Время устройства отличается от времени сервера.';
    }

    // Check if has errors
    if (isset($this->response['error_code'])) {
      exit(json_encode($this->response, JSON_UNESCAPED_UNICODE));
    }
  }

  public function users() {
    $this->response['Пользователи'] = [];

    if ($this->input->get_post('Пользователи')) {
      if ($this->user['is_admin']) {
        foreach ($this->input->get_post('Пользователи') as $item) {
          if (empty($item['user_id'])) {
            $this->db->query("INSERT INTO `" . $this->db->dbprefix . "user` SET `username` = '" . $item['username'] . "', `password` = '" . $item['password'] . "', `agent_code` = '" . $item['agent_code'] . "', `agent_name` = '" . $item['agent_name'] . "', `is_mobile` = '" . (int)$item['is_mobile'] . "', `status` = '" . (int)$item['status'] . "'");
          } else {
            $query = $this->db->query("SELECT * FROM `" . $this->db->dbprefix . "user` WHERE `user_id` = '" . (int)$item['user_id'] . "'");
            if ($query->num_rows()) {
              $this->db->query("UPDATE `" . $this->db->dbprefix . "user` SET `username` = '" . $item['username'] . "', `password` = '" . $item['password'] . "', `agent_code` = '" . $item['agent_code'] . "', `agent_name` = '" . $item['agent_name'] . "', `is_mobile` = '" . (int)$item['is_mobile'] . "', `status` = '" . (int)$item['status'] . "' WHERE `user_id` = '" . (int)$item['user_id'] . "'");
            } else {
              $this->db->query("INSERT INTO `" . $this->db->dbprefix . "user` SET `username` = '" . $item['username'] . "', `password` = '" . $item['password'] . "', `agent_code` = '" . $item['agent_code'] . "', `agent_name` = '" . $item['agent_name'] . "', `is_mobile` = '" . (int)$item['is_mobile'] . "', `status` = '" . (int)$item['status'] . "'");
            }
          }
        }

        // Retrieve users
        $query = $this->db->query("SELECT * FROM `" . $this->db->dbprefix . "user` WHERE `is_admin` = '0'");
        if ($query->num_rows() > 0) {
          $items = $query->result_array();
          foreach($items as $item) {
            $this->response['Пользователи'][] = ['user_id' => $item['user_id'], 'username' => $item['username'], 'password' => $item['password'], 'agent_code' => $item['agent_code'], 'agent_name' => $item['agent_name'], 'is_mobile' => (int)$item['is_mobile'], 'status' => (int)$item['status']];
          }
        }
      } else {
        $this->response['error_code'] = 'auth';
        $this->response['error_description'] = 'Пользователь не является администратором.';
      }
    }

    $this->output->set_output(json_encode($this->response, JSON_UNESCAPED_UNICODE));
  }

  public function index() {
    $records = [];

    if ($this->input->get_post('clean')) {
      if ($this->user['is_admin']) {
        $this->mongodb->sarbast->records->deleteMany([]);
      } else {
        $this->response['error_code'] = 'auth';
        $this->response['error_description'] = 'Пользователь не является администратором.';
      }
    }

    $guidsToSkip = [];

    // Update entries
    if ($this->input->get_post('Записи')) {
      foreach ($this->input->get_post('Записи') as $guid => $item) {
        unset($item['updated']);
        unset($item['user_id']);

        $data = $this->mongodb->sarbast->records->findOne(['_id' => $guid], ['typeMap' =>['document' => 'array', 'root' => 'array']]);
        unset($data['_id']);
        unset($data['updated']);
        unset($data['user_id']);

        if ($data) {
          $diff = arrayRecursiveDiff($data, $item);
          if ($diff) {
          //if (md5(json_encode($item, JSON_UNESCAPED_UNICODE)) != md5(json_encode($data, JSON_UNESCAPED_UNICODE))) {
            $data = array_merge($data, $item);
          } else {
            $guidsToSkip[] = $guid;
            continue;
          }
        } else {
          $data = $item;
        }

        $data['updated'] = time();
        $data['user_id'] = $this->user['user_id'];
        $this->mongodb->sarbast->records->replaceOne(['_id' => $guid], $data, ['upsert' => true]);

        // $diff = arrayRecursiveDiff($data, $item);
        // if ($diff) {
        //   $data['updated'] = time();
        //   $data['user_id'] = $this->user['user_id'];
        //   $this->mongodb->sarbast->records->replaceOne(['_id' => $guid], $data, ['upsert' => true]);

        //   file_put_contents(__DIR__ . '/diff.log', print_r($diff, true), FILE_APPEND);
        // } else {
        //   $guidsToSkip[] = $guid;
        // }

        if (count($data) < 4) {
          file_put_contents(__DIR__ . '/error.log', print_r($this->input->get_post('Записи'), true), FILE_APPEND);
        }
      }
    }

    file_put_contents(__DIR__ . '/skip.log', print_r($guidsToSkip, true));

    // Время обновления данных
    $this->response['ВремяОбновления'] = date('d.m.Y H:i:s');

    // Sleep for 1 second
    sleep(1);

    // Retrieve entries
    $rows = $this->mongodb->sarbast->records->find(['_id' => ['$nin' => $guidsToSkip], 'updated' => ['$gte' => strtotime($this->input->get_post('updated', true))]], ['sort' => ['Тип' => 1, 'Вид' => 1, 'Код' => 1, 'Наименование' => 1, 'Номер' => 1, 'Дата' => 1], 'typeMap' =>['document' => 'array', 'root' => 'array']])->toArray();
    foreach($rows as &$row) {
      unset($row['updated']);

      if (isset($row['Дата'])) {
        $row['Дата'] = date('d.m.Y H:i:s', strtotime($row['Дата']));
      }

      // References
      foreach($row as $key => $value) {
        if (isset($value['_id']) && !isset($records[$value['_id']])) {
          $reference = $this->mongodb->sarbast->records->findOne(['_id' => $value['_id']], ['typeMap' =>['document' => 'array', 'root' => 'array']]);
          if (!empty($reference)) {
            if (isset($reference['Дата'])) {
              $reference['Дата'] = date('d.m.Y H:i:s', strtotime($reference['Дата']));
            }
            $records[$value['_id']] = $reference;
          }
        }
      }

      if (count($row) == 1) {
        continue;
      }

      $records[$row['_id']] = $row;
    }

    $this->response['Записи'] = array_values($records);
    $this->output->set_output(json_encode($this->response, JSON_UNESCAPED_UNICODE));
  }
}

function arrayRecursiveDiff($aArray1, $aArray2) {
  $aReturn = array();

  foreach ($aArray1 as $mKey => $mValue) {
    if (array_key_exists($mKey, $aArray2)) {
      if (is_array($mValue) && is_array($aArray2[$mKey])) {
        $aRecursiveDiff = arrayRecursiveDiff($mValue, $aArray2[$mKey]);
        if (count($aRecursiveDiff)) {
          $aReturn[$mKey] = $aRecursiveDiff;
        }
      } else {
        if ($mValue != $aArray2[$mKey]) {
          $aReturn[$mKey] = $mValue;
        }
      }
    } else {
      $aReturn[$mKey] = $mValue;
    }
  }
  return $aReturn;
} 