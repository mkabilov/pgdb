<?php

namespace Ikitiki;

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
                "select '{}'::integer[] as t",
                []
            ],
            [
                "select null::integer[] as t",
                null
            ]
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
}
