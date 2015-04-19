<?php
include 'DB.php';

$db = new DB();

$db->setDatabase('test');
$db->setUsername('postgres');
$db->setHost('127.0.0.1');

$res = $db->exec('select now() as n')->current();
var_dump($res);
