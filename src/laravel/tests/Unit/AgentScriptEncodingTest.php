<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Windows PowerShell 5.1 reads a script without a byte order mark in the ANSI code page: one
 * character outside ASCII (a "█" in a regex) made the whole agent fail to parse there.
 */
class AgentScriptEncodingTest extends TestCase
{
    public function test_the_agent_is_ascii_only(): void
    {
        $path = dirname(__DIR__, 3).'/powershell/app.ps1';
        $this->assertFileExists($path);
        foreach (file($path) as $number => $line) {
            $this->assertMatchesRegularExpression('/^[\x00-\x7F]*$/', $line, 'app.ps1 line '.($number + 1).' has a character outside ASCII: '.trim($line));
        }
    }
}
