<?php

use Ikitiki\DB;

class DBTest extends \PHPUnit_Framework_TestCase {

    /**
     * @var DB
     */
    protected $db;

    protected function setUp()
    {
        $this->db = new DB();
        $this->db->setHost('127.0.0.1');
        $this->db->setDbName('test');
        $this->db->setUsername('postgres');
    }

    /**
     * @return array
     */
    public function providerStrings()
    {
        return [
            ['', ''],
            ['ololo', 'ololo'],
            ["lol2'ololo", "lol2''ololo"],
            ['te"s"t', 'te"s"t'],
        ];
    }

    /**
     * @return array
     */
    public function providerArrays()
    {
        return [
            [['a', 'b', 'c'], '\'{"a","b","c"}\''],
            [['a', 'olo lo', 'c'], '\'{"a","olo lo","c"}\''],
            [[], "'{}'"],
            [["'quoted'", "'"], '\'{"\'\'quoted\'\'","\'\'"}\''],
            [['"double quoted"', ''], '\'{"\\\"double quoted\\\"",""}\''],
            [['"double "quoted"', ''], '\'{"\\\"double \\\"quoted\\\"",""}\''],
            [['{}', '"{}"'], '\'{"{}","\\\"{}\\\""}\''],
        ];
    }

    /**
     * @return array
     */
    public function providerTypeCasts()
    {
        return [
            [
                'select \'"a"=>"123","b"=>"32\'\'1", "c\'\'"=>"another string"\'::hstore as t',
                ['a'=>'123', 'b'=>'32\'1', 'c\''=>'another string']
            ],
            [
                "select '{1,2,3,4,5,6}'::integer[] as t",
                [1,2,3,4,5,6]
            ],
            [
                "select '{null,null,3,4,null,6}'::integer[] as t",
                [null,null,3,4,null,6]
            ],
            [
                "select '{}'::integer[] as t",
                []
            ],
            [
                "select null::integer[] as t",
                null
            ],
            [
                'select \'{"Accept-Language": "en-US,en;q=0.8", "Host": "headers.jsontest.com", ' .
                '"Accept-Charset": "ISO-8859-1,utf-8;q=0.7,*;q=0.3", ' .
                '"Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8"}\'::json as t',
                [
                    'Accept-Language' => 'en-US,en;q=0.8',
                    'Host' => 'headers.jsontest.com',
                    'Accept-Charset' => 'ISO-8859-1,utf-8;q=0.7,*;q=0.3',
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'
                ]
            ],
        ];
    }

    /**
     * @group fast
     *
     * @dataProvider providerStrings
     *
     * @param $string
     * @param $quotedString
     */
    public function testQuote($string, $quotedString)
    {
        $this->assertEquals(
            $quotedString,
            DB::quote($string)
        );
    }

    /**
     * @group fast
     *
     * @dataProvider providerArrays
     *
     * @param $array
     * @param $quoted
     */
    public function testArrayQuote($array, $quoted)
    {
        $this->assertEquals(
            $quoted,
            DB::toArray($array)
        );
    }

    /**
     * @dataProvider providerTypeCasts
     */
    public function testTypeCasts($sql, $expected)
    {
        $res = $this->db->exec($sql)->fetchField('t');
        $this->assertEquals($expected, $res);
    }

    /**
     * Test exec one
     * @expectedException Exception
     */
    public function testExecOneException()
    {
        $this->db->execOne('select i from generate_series(1, 10) i');
    }

    /**
     * ExecOne test
     */
    public function testExecOne()
    {
        $res = $this->db->execOne("select 1 as i, '{1,1,1,1,1,2}'::integer[] as a");
        $expected = [
            'i' => 1,
            'a' => [1,1,1,1,1,2]
        ];

        $this->assertEquals($expected, $res);
    }

    /**
     * Exec test
     */
    public function testExec()
    {
        $res = $this->db->exec('select i, i * 2 as i2 from generate_series(0, 10) i');
        $this->assertTrue($res instanceof \Iterator);
        $this->assertTrue($res instanceof DB\Result);
        foreach ($res as $rowId => $row) {
            $this->assertEquals($rowId, $row['i']);
            $this->assertEquals($rowId * 2, $row['i2']);
        }
    }
}
