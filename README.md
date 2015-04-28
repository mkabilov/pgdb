# Pgdb

[![Travis](https://travis-ci.org/ikitiki/pgdb.svg?branch=master)](https://travis-ci.org/ikitiki/pgdb)

Class for working with PostgreSQL database

##Usage

Create db instance:

```php
$db = new Ikitiki\DB();

$db->setDbName('test');
$db->setUsername('postgres');
$db->setHost('127.0.0.1');
```

###Make queries:

Single row query:

```php
$res = $db->execOne(
	"select id, name from users where email = '%s' and status_id = %d limit 1", 
	Ikitiki\DB::quote('john_doe@company.com')
	1
);
// Executes "select id, email from users where email = 'john_doe@company.com' and status_id = 1"
// $res = [
//   'id' => 1,
//   'name' => 'John Doe'
// ];
```

Key-value queries:

```php
$res = $db->exec("select id, name from users")->fetchArray('id', 'name');
// $res = [
//   1 => 'John Doe',
//   2 => 'Richard Roe',
//   3 => 'Mark Moe',
//   ...
// ]
```
or

```php
$res = $db->exec("select id, name, department_id from users")->fetchArray('id');
// $res = [
//   1 => ['name' => 'John Doe', 'department_id' => 1],
//   2 => ['name' => 'Richard Roe', 'department_id' => 1],
//   3 => ['name' => 'Mark Moe', 'department_id' => 2]
//   ...
// ];
```
