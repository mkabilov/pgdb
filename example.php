<?php
require 'vendor/autoload.php';


$db = new Ikitiki\DB();

$db->setDatabase('test');
$db->setUsername('postgres');
$db->setHost('127.0.0.1');


$res = $db->exec(<<<SQL
  select
    i,
    now(),
    '"col1"=>"val1", "col2"=>"val2", "col3"=>"val3"'::hstore,
    '{"a":"1", "b":"3"}'::json
  from
    generate_series(1, 2) i;
SQL
)->fetchArray('i');

var_dump($res);

/* outputs:
array(2) {
  [1] =>
  array(3) {
    'now' =>
    int(1429720419)
    'hstore' =>
    array(3) {
      'col1' =>
      string(4) "val1"
      'col2' =>
      string(4) "val2"
      'col3' =>
      string(4) "val3"
    }
    'json' =>
    array(2) {
      'a' =>
      string(1) "1"
      'b' =>
      string(1) "3"
    }
  }
  [2] =>
  array(3) {
    'now' =>
    int(1429720419)
    'hstore' =>
    array(3) {
      'col1' =>
      string(4) "val1"
      'col2' =>
      string(4) "val2"
      'col3' =>
      string(4) "val3"
    }
    'json' =>
    array(2) {
      'a' =>
      string(1) "1"
      'b' =>
      string(1) "3"
    }
  }
}
*/