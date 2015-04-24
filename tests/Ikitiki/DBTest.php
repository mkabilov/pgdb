<?php

namespace Ikitiki;

class DBTest extends \PHPUnit_Framework_TestCase {

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
}
