<?php

namespace Tests\Unit;

use App\Support\Mask;
use PHPUnit\Framework\TestCase;

class MaskEmailTest extends TestCase
{
    public function test_it_hides_the_middle_of_the_address_and_keeps_the_domain(): void
    {
        $this->assertSame('kf*****a@amsipr.com', Mask::email('kfigueroa@amsipr.com'));
        $this->assertSame('lc*****r@gmail.com', Mask::email('lcasadopr@gmail.com'));
        $this->assertSame('ab*****d@x.org', Mask::email('abcd@x.org'));   // el largo no se deduce de las estrellas
    }

    public function test_short_and_invalid_addresses_do_not_leak_or_break(): void
    {
        $this->assertSame('a*c@x.com', Mask::email('abc@x.com'));
        $this->assertSame('a*@x.com', Mask::email('ab@x.com'));
        $this->assertSame('*@x.com', Mask::email('a@x.com'));
        $this->assertSame('', Mask::email('sin-arroba'));
        $this->assertSame('', Mask::email(null));
    }
}
