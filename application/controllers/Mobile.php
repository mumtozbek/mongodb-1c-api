<?php
defined('BASEPATH') OR $this->output->set_output('No direct script access allowed');

error_reporting(E_ALL);
ini_set('display_errors', 'on');

class Mobile extends CI_Controller {
  const VERSION = '3.0.7';

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
        if ($row['is_mobile'] != 1) {
          $this->response['error_code'] = 'auth';
          $this->response['error_description'] = 'Для пользователя запрещено использование мобильного клиента.';
        } else {
          $user = $this->mongodb->sarbast->records->findOne(['Тип' => 'Справочники', 'Вид' => 'Сотрудники', 'Код' => $row['agent_code']], ['projection' => ['_id' => 1, 'Код' => 1, 'Наименование' => 1],'typeMap' => ['document' => 'array', 'root' => 'array']]);
          if (empty($user)) {
            $this->response['error_code'] = 'auth';
            $this->response['error_description'] = 'Не найден агент, прикрепленный к данному пользователю.';
          } else {
            $this->user = $user;
            $this->user['user_id'] = $row['user_id'];
          }
        }
      } else {
        $this->response['error_code'] = 'auth';
        $this->response['error_description'] = 'Неверные данные авторизации.';
      }
    }

    // // Compare server and user time
    // if ($this->input->get_post('time')) {
    //   $user_time = strtotime($this->input->get_post('time'));
    //   if ($user_time > (time() + 1800) || $user_time < (time() - 1800)) {
    //     $this->response['error_code'] = 'time';
    //     $this->response['error_description'] = 'Время устройства отличается от времени сервера.';
    //   }
    // } else {
    //   $this->response['error_code'] = 'time';
    //   $this->response['error_description'] = 'Время устройства отличается от времени сервера.';
    // }

    // // Check mobile config version
    // if ($this->input->get_post('version')) {
    //   if (version_compare($this->input->get_post('version'), self::VERSION, '<')) {
    //     $this->response['error_code'] = 'version';
    //     $this->response['error_description'] = 'Мобильное приложение обновлено. Пожалуйста, перезапустите приложение.';
    //   }
    // } else {
    //   $this->response['error_code'] = 'version';
    //   $this->response['error_description'] = 'Мобильное приложение обновлено. Пожалуйста, перезапустите приложение.';
    // }

    // Check if has errors
    if (isset($this->response['error_code'])) {
      exit(json_encode($this->response, JSON_UNESCAPED_UNICODE));
    } else {
      $this->response['Агент'] = $this->user;
    }
  }

  public function index() {
    $records = [];

    // Update entries
    if ($this->input->get_post('Записи')) {
      foreach ($this->input->get_post('Записи') as $guid => $item) {
        if ($item['Тип'] == "Документы" && $item['Вид'] == "Касса") {
          continue;
        }

        $data = $this->mongodb->sarbast->records->findOne(['_id' => $guid], ['typeMap' => ['document' => 'array', 'root' => 'array']]);
        if ($data) {
          if ($data['user_id'] = $this->user['user_id']) {
            if (md5(json_encode($item, JSON_UNESCAPED_UNICODE)) != md5(json_encode($data, JSON_UNESCAPED_UNICODE))) {
              $data = array_merge($data, $item);
            } else {
              continue;
            }
          } else {
            continue;
          }
        } else {
          $data = $item;
          if ($item['Тип'] == 'Справочники') {
            if ($item['Вид'] == 'Контрагенты') {
                $data['ВидКонтрагента'] = "Перечисления.ВидыКонтрагентов.Покупатель";
            }
          }
          $data['Агент'] = ['_id' => $this->user['_id']];
          $data['user_id'] = $this->user['user_id'];
        }

        $data['updated'] = time();
        unset($data['Синхронизирован']);

        $this->mongodb->sarbast->records->replaceOne(['_id' => $guid], $data, ['upsert' => true]);
      }
    }

    // Retrieve "ВидыРеализации"
    $rows = $this->mongodb->sarbast->records->find(['updated' => ['$gte' => ($this->input->get_post('full', true) == 1 ? 0 : strtotime($this->input->get_post('updated', true)))], 'Тип' => 'Справочники', 'Вид' => 'ВидыРеализации', 'Активный' => 1], ['typeMap' => ['document' => 'array', 'root' => 'array']])->toArray();
    foreach($rows as &$row) {
      unset($row['updated']);
      unset($row['user_id']);

      foreach($row as $key => &$value) {
        if (isset($value['_id']) && !isset($records[$value['_id']])) {
          $item = $this->mongodb->sarbast->records->findOne(['_id' => $value['_id'], 'user_id' => $this->user['user_id']], ['typeMap' => ['document' => 'array', 'root' => 'array']]);
          if ($item) {
            $records[$value['_id']] = $item;
          }
        }
      }

      $records[$row['_id']] = $row;
    }

    // Retrieve "Номенклатура"
    // print_r(json_encode([
    //   ['$match' => ['Тип' => 'Документы', 'Проведен' => 1]],
    //   ['$unwind' => '$Движения'],
    //   ['$match' => ['Движения.ТипРегистра' => 'РегистрыНакопления', 'Движения.ИмяРегистра' => 'Остатки', 'Движения.Пустые' => 0, 'Движения.Склад._id' => '1a3164d3-73d8-11e9-9e50-309c23294d61', 'Движения.Период' => ['$lte' => date('Y-m-d H:i:s')]]],
    //   ['$group' => ['_id' => '$Движения.Номенклатура._id', 'Остаток' => ['$sum' => '$Движения.Количество']]],
    //   ['$match' => ['Остаток' => ['$gt' => 0]]],

    //   ['$lookup' => ['from' => 'records', 'localField' => '_id', 'foreignField' => '_id', 'as' => 'productData']],
    //   ['$replaceRoot' => ['newRoot' => ['$mergeObjects' => [['$arrayElemAt' => ['$productData', 0]], '$$ROOT']]]],

    //   ['$match' => ['Цена' => ['$gt' => 0], 'updated' => ['$gte' => ($this->input->get_post('full', true) == 1 ? 0 : strtotime($this->input->get_post('updated', true)))]]],
    //   ['$project' => ['productData' => 0]]]));die;
    $rows = $this->mongodb->sarbast->records->aggregate([
      ['$match' => ['Тип' => 'Документы', 'Проведен' => 1]],
      ['$unwind' => '$Движения'],
      ['$match' => ['Движения.ТипРегистра' => 'РегистрыНакопления', 'Движения.ИмяРегистра' => 'Остатки', 'Движения.Пустые' => 0, 'Движения.Склад._id' => '1a3164d3-73d8-11e9-9e50-309c23294d61', 'Движения.Период' => ['$lte' => date('Y-m-d H:i:s')]]],
      ['$group' => ['_id' => '$Движения.Номенклатура._id', 'Остаток' => ['$sum' => '$Движения.Количество']]],
      ['$match' => ['Остаток' => ['$gt' => 0]]],

      ['$lookup' => ['from' => 'records', 'localField' => '_id', 'foreignField' => '_id', 'as' => 'productData']],
      ['$replaceRoot' => ['newRoot' => ['$mergeObjects' => [['$arrayElemAt' => ['$productData', 0]], '$$ROOT']]]],

      ['$match' => ['Цена' => ['$gt' => 0], 'updated' => ['$gte' => ($this->input->get_post('full', true) == 1 ? 0 : strtotime($this->input->get_post('updated', true)))]]],
      ['$project' => ['productData' => 0]],
    ], ['typeMap' => ['document' => 'array', 'root' => 'array']])->toArray();

    foreach($rows as &$row) {
      unset($row['updated']);
      unset($row['user_id']);

      foreach($row as $key => &$value) {
        if (isset($value['_id']) && !isset($records[$value['_id']])) {
          $item = $this->mongodb->sarbast->records->findOne(['_id' => $value['_id'], 'user_id' => $this->user['user_id']], ['typeMap' => ['document' => 'array', 'root' => 'array']]);
          if ($item) {
            $records[$value['_id']] = $item;
          }
        }
      }

      $records[$row['_id']] = $row;
    }

    // Retrieve "Контрагенты"
    $rows = $this->mongodb->sarbast->records->find(['updated' => ['$gte' => ($this->input->get_post('full', true) == 1 ? 0 : strtotime($this->input->get_post('updated', true)))], 'Тип' => 'Справочники', 'Вид' => 'Контрагенты', 'ЭтоГруппа' => 0, 'Агент._id' => $this->user['_id']], ['typeMap' => ['document' => 'array', 'root' => 'array']])->toArray();
    foreach($rows as &$row) {
      unset($row['updated']);
      unset($row['user_id']);

      foreach($row as $key => &$value) {
        if (isset($value['_id']) && !isset($records[$value['_id']])) {
          $item = $this->mongodb->sarbast->records->findOne(['_id' => $value['_id'], 'user_id' => $this->user['user_id']], ['typeMap' => ['document' => 'array', 'root' => 'array']]);
          if ($item) {
            $records[$value['_id']] = $item;
          }
        }
      }

      unset($row['Синхронизирован']);
      $records[$row['_id']] = $row;
    }

    // Retrieve "Заказ"
    $rows = $this->mongodb->sarbast->records->find(['updated' => ['$gte' => ($this->input->get_post('full', true) == 1 ? 0 : strtotime($this->input->get_post('updated', true)))], 'Тип' => 'Документы', 'Вид' => 'Заказ', 'Агент._id' => $this->user['_id']], ['typeMap' => ['document' => 'array', 'root' => 'array']])->toArray();
    foreach($rows as &$row) {
      unset($row['updated']);
      unset($row['user_id']);

      foreach($row as $key => &$value) {
        if (isset($value['_id']) && !isset($records[$value['_id']])) {
          $item = $this->mongodb->sarbast->records->findOne(['_id' => $value['_id'], 'user_id' => $this->user['user_id']], ['typeMap' => ['document' => 'array', 'root' => 'array']]);
          if ($item) {
            $records[$value['_id']] = $item;
          }
        }
      }

      if (isset($row['Дата'])) {
        $row['Дата'] = date('d.m.Y H:i:s', strtotime($row['Дата']));
      }

      unset($row['Синхронизирован']);
      $records[$row['_id']] = $row;
    }

    // Retrieve "Касса"
    $rows = $this->mongodb->sarbast->records->find(['updated' => ['$gte' => ($this->input->get_post('full', true) == 1 ? 0 : strtotime($this->input->get_post('updated', true)))], 'Тип' => 'Документы', 'Вид' => 'Касса', 'user_id' => $this->user['user_id']], ['typeMap' => ['document' => 'array', 'root' => 'array']])->toArray();
    foreach($rows as &$row) {
      unset($row['updated']);
      unset($row['user_id']);

      foreach($row as $key => &$value) {
        if (isset($value['_id']) && !isset($records[$value['_id']])) {
          $item = $this->mongodb->sarbast->records->findOne(['_id' => $value['_id'], 'user_id' => $this->user['user_id']], ['typeMap' => ['document' => 'array', 'root' => 'array']]);
          if ($item) {
            $records[$value['_id']] = $item;
          }
        }
      }

      if (isset($row['Дата'])) {
        $row['Дата'] = date('d.m.Y H:i:s', strtotime($row['Дата']));
      }

      unset($row['Синхронизирован']);
      $records[$row['_id']] = $row;
    }

    $this->response['Записи'] = array_values($records);
    $this->output->set_output(json_encode($this->response, JSON_UNESCAPED_UNICODE));
  }

  public function sales() {
    $records = [];

    $rows = $this->mongodb->sarbast->records->aggregate([
      ['$match' => ['Тип' => 'Документы', 'Проведен' => 1]],
      ['$unwind' => '$Движения'],
      ['$match' => ['Движения.ТипРегистра' => 'РегистрыНакопления', 'Движения.ИмяРегистра' => 'Продажи', 'Движения.ВидРеализации._id' => $this->input->get_post('sale_type_id'), 'Движения.Агент._id' => $this->user['_id'], 'Движения.Период' => ['$gte' => $this->input->get_post('date_start'), '$lte' => $this->input->get_post('date_end')]]],
      ['$group' => ['_id' => '$Движения.Номенклатура._id', 'Количество' => ['$sum' => '$Движения.Количество'], 'Объем' => ['$sum' => '$Движения.Объем'], 'Сумма' => ['$sum' => '$Движения.Сумма']]],

      ['$lookup' => ['from' => 'records', 'localField' => '_id', 'foreignField' => '_id', 'as' => 'productInfo']],

      ['$replaceRoot' => ['newRoot' => ['$mergeObjects' => [['$arrayElemAt' => ['$productInfo', 0]], '$$ROOT']]]],
      ['$project' => ['productInfo' => 0, 'updated' => 0, 'user_id' => 0]],

      ['$match' => ['Количество' => ['$gt' => 0], 'Сумма' => ['$gt' => 0]]],
    ], ['typeMap' => ['document' => 'array', 'root' => 'array']])->toArray();

    foreach($rows as $row) {
      $records[$row['_id']] = array('Представление' => $row['Наименование'], 'Количество' => $row['Количество'], 'Объем' => $row['Объем']);
    }

    $this->response['Записи'] = array_values($records);
    $this->output->set_output(json_encode($this->response, JSON_UNESCAPED_UNICODE));
  }

  public function balance() {
    $records = [];

    // Сальдо на начало
    $rows = $this->mongodb->sarbast->records->aggregate([
        ['$match' => ['Тип' => 'Документы', 'Проведен' => 1]],
        ['$unwind' => '$Движения'],
        ['$match' => ['Движения.ТипРегистра' => 'РегистрыНакопления', 'Движения.ИмяРегистра' => 'Сальдо', 'Движения.Контрагент._id' => $this->input->get_post('customer_id'), 'Движения.ВидВзаиморасчета' => 'Перечисления.ВидыВзаиморасчетов.' . $this->input->get_post('payment_type'), 'Движения.Период' => ['$lt' => $this->input->get_post('date_start')]]],
        ['$group' => ['_id' => null, 'Сумма' => ['$sum' => '$Движения.Сумма']]],
    ], ['typeMap' => ['document' => 'array', 'root' => 'array']])->toArray();

    foreach($rows as $row) {
      $records[] = array('Представление' => "Сальдо на начало:", 'Сумма' => $row['Сумма'], 'Итог' => 1);
    }

    // Обороты за период
    $rows = $this->mongodb->sarbast->records->aggregate([
        ['$match' => ['Тип' => 'Документы', 'Проведен' => 1]],
        ['$unwind' => '$Движения'],
        ['$match' => ['Движения.ТипРегистра' => 'РегистрыНакопления', 'Движения.ИмяРегистра' => 'Сальдо', 'Движения.Контрагент._id' => $this->input->get_post('customer_id'), 'Движения.ВидВзаиморасчета' => 'Перечисления.ВидыВзаиморасчетов.' . $this->input->get_post('payment_type'), 'Движения.Период' => ['$gte' => $this->input->get_post('date_start'), '$lte' => $this->input->get_post('date_end')]]],
        ['$group' => ['_id' => '$_id', 'Сумма' => ['$sum' => '$Движения.Сумма']]],
        ['$lookup' => ['from' => 'records', 'localField' => '_id', 'foreignField' => '_id', 'as' => 'documentInfo']],
        ['$replaceRoot' => ['newRoot' => ['$mergeObjects' => [['$arrayElemAt' => ['$documentInfo', 0]], '$$ROOT']]]],
        ['$project' => ['_id' => 1, 'Сумма' => 1, 'Вид' => 1, 'Номер' => 1, 'Дата' => 1]],
        ['$match' => ['Сумма' => ['$ne' => 0]]],
        ['$sort' => ['Дата' => 1]],
    ], ['typeMap' => ['document' => 'array', 'root' => 'array']])->toArray();

    foreach($rows as $row) {
      $records[] = array('Представление' => $row['Вид'] . " " . $row['Номер'] . " от " . $row['Дата'], 'Сумма' => $row['Сумма'], 'Итог' => 0);
    }

    // Сальдо на конец
    $rows = $this->mongodb->sarbast->records->aggregate([
        ['$match' => ['Тип' => 'Документы', 'Проведен' => 1]],
        ['$unwind' => '$Движения'],
        ['$match' => ['Движения.ТипРегистра' => 'РегистрыНакопления', 'Движения.ИмяРегистра' => 'Сальдо', 'Движения.Контрагент._id' => $this->input->get_post('customer_id'), 'Движения.ВидВзаиморасчета' => 'Перечисления.ВидыВзаиморасчетов.' . $this->input->get_post('payment_type'), 'Движения.Период' => ['$lte' => $this->input->get_post('date_end')]]],
        ['$group' => ['_id' => null, 'Сумма' => ['$sum' => '$Движения.Сумма']]],
    ], ['typeMap' => ['document' => 'array', 'root' => 'array']])->toArray();

    foreach($rows as $row) {
      $records[] = array('Представление' => "Сальдо на конец:", 'Сумма' => $row['Сумма'], 'Итог' => 1);
    }

    $this->response['Записи'] = array_values($records);
    $this->output->set_output(json_encode($this->response, JSON_UNESCAPED_UNICODE));
  }

  public function dishes() {
    $records = [];

    // Сальдо на начало
    $rows = $this->mongodb->sarbast->records->aggregate([
      ['$match' => ['Тип' => 'Документы', 'Проведен' => 1]],
      ['$unwind' => '$Движения'],
      ['$match' => ['Движения.ТипРегистра' => 'РегистрыНакопления', 'Движения.ИмяРегистра' => 'СальдоПоТаре', 'Движения.Контрагент._id' => $this->input->get_post('customer_id'), 'Движения.Период' => ['$lt' => $this->input->get_post('date_start')]]],
      ['$group' => ['_id' => '$Движения.Контрагент._id', 'Количество' => ['$sum' => '$Движения.Количество']]],

      ['$lookup' => ['from' => 'records', 'localField' => '_id', 'foreignField' => '_id', 'as' => 'productInfo']],

      ['$replaceRoot' => ['newRoot' => ['$mergeObjects' => [['$arrayElemAt' => ['$productInfo', 0]], '$$ROOT']]]],
      ['$project' => ['productInfo' => 0, 'updated' => 0, 'user_id' => 0]],

      ['$match' => ['Количество' => ['$ne' => 0]]],
    ], ['typeMap' => ['document' => 'array', 'root' => 'array']])->toArray();

    foreach($rows as $row) {
      $records[] = ['Представление' => 'Сальдо на начало:', 'Количество' => $row['Количество'], 'Группа' => 1];
    }

    // Обороты за период
    $rows = $this->mongodb->sarbast->records->aggregate([
      ['$match' => ['Тип' => 'Документы', 'Проведен' => 1]],
      ['$unwind' => '$Движения'],
      ['$match' => ['Движения.ТипРегистра' => 'РегистрыНакопления', 'Движения.ИмяРегистра' => 'СальдоПоТаре', 'Движения.Контрагент._id' => $this->input->get_post('customer_id'), 'Движения.Период' => ['$gte' => $this->input->get_post('date_start'), '$lte' => $this->input->get_post('date_end')]]],
      ['$group' => ['_id' => '$Движения.Номенклатура._id', 'Количество' => ['$sum' => '$Движения.Количество']]],

      ['$lookup' => ['from' => 'records', 'localField' => '_id', 'foreignField' => '_id', 'as' => 'productInfo']],

      ['$replaceRoot' => ['newRoot' => ['$mergeObjects' => [['$arrayElemAt' => ['$productInfo', 0]], '$$ROOT']]]],
      ['$project' => ['productInfo' => 0, 'updated' => 0, 'user_id' => 0]],

      ['$match' => ['Количество' => ['$ne' => 0]]],
    ], ['typeMap' => ['document' => 'array', 'root' => 'array']])->toArray();

    foreach($rows as $row) {
      $records[] = ['Представление' => $row['Наименование'], 'Количество' => $row['Количество'], 'Группа' => 0];
    }

    // Сальдо на конец
    $rows = $this->mongodb->sarbast->records->aggregate([
      ['$match' => ['Тип' => 'Документы', 'Проведен' => 1]],
      ['$unwind' => '$Движения'],
      ['$match' => ['Движения.ТипРегистра' => 'РегистрыНакопления', 'Движения.ИмяРегистра' => 'СальдоПоТаре', 'Движения.Контрагент._id' => $this->input->get_post('customer_id'), 'Движения.Период' => ['$lte' => $this->input->get_post('date_end')]]],
      ['$group' => ['_id' => '$Движения.Контрагент._id', 'Количество' => ['$sum' => '$Движения.Количество']]],

      ['$lookup' => ['from' => 'records', 'localField' => '_id', 'foreignField' => '_id', 'as' => 'productInfo']],

      ['$replaceRoot' => ['newRoot' => ['$mergeObjects' => [['$arrayElemAt' => ['$productInfo', 0]], '$$ROOT']]]],
      ['$project' => ['productInfo' => 0, 'updated' => 0, 'user_id' => 0]],

      ['$match' => ['Количество' => ['$ne' => 0]]],
    ], ['typeMap' => ['document' => 'array', 'root' => 'array']])->toArray();

    foreach($rows as $row) {
      $records[] = ['Представление' => 'Сальдо на конец:', 'Количество' => $row['Количество'], 'Группа' => 1];
    }

    $this->response['Записи'] = array_values($records);
    $this->output->set_output(json_encode($this->response, JSON_UNESCAPED_UNICODE));
  }
}
