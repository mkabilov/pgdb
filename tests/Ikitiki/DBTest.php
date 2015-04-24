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

}
