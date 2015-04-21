<?php
require 'vendor/autoload.php';


$db = new Ikitiki\DB();

$db->setDatabase('test');
$db->setUsername('postgres');
$db->setHost('127.0.0.1');


$res = $db->exec(<<<SQL
select
  i, now(), '"col1"=>"val1", "col2"=>"val2", "col3"=>"val3"'::hstore, '{"a":"1", "b":"3"}'::json
from
  generate_series(1, 5) i;
SQL
)->fetchArray('i');
var_dump($res);
