<?php

namespace Tests\Unit;

use App\Support\MacVendor;
use PHPUnit\Framework\TestCase;

class MacVendorTest extends TestCase
{
    public function test_the_vendor_comes_from_the_first_bytes_in_any_notation(): void
    {
        $this->assertStringContainsString('Raspberry Pi', (string) MacVendor::lookup('b8:27:eb:12:34:56'));
        $this->assertSame(MacVendor::lookup('B8:27:EB:12:34:56'), MacVendor::lookup('b8-27-eb-12-34-56'));
        $this->assertSame(MacVendor::lookup('B8:27:EB:12:34:56'), MacVendor::lookup('B827EB123456'));
        $this->assertStringContainsString('VMware', (string) MacVendor::lookup('00:50:56:AA:BB:CC'));
    }

    public function test_made_up_and_invalid_addresses_have_no_vendor(): void
    {
        // The locally administered bit: a phone's private address names no vendor.
        $this->assertTrue(MacVendor::isLocal('02:20:E1:EF:09:DF'));
        $this->assertNull(MacVendor::lookup('02:20:E1:EF:09:DF'));
        $this->assertNull(MacVendor::lookup('not a mac'));
        $this->assertNull(MacVendor::lookup(null));
        $this->assertNull(MacVendor::lookup('FC:FF:FF:00:00:00'));
    }
}
