<?php
declare(strict_types=1);

namespace IwacSeo\Test\Service;

use IwacSeo\Service\SettingsGate;
use Omeka\Settings\Settings;
use PHPUnit\Framework\TestCase;

/**
 * "What counts as on" used to be defined in a trait, in three inline variants,
 * and once more inside a factory. Omeka stores settings as JSON, so a checkbox
 * comes back as '1' or 1 depending on how it was written — all the accepted
 * forms are pinned here.
 */
final class SettingsGateTest extends TestCase
{
    private function gate(array $values): SettingsGate
    {
        return new SettingsGate(new Settings($values));
    }

    public function testTruthinessAcceptsEveryStoredForm(): void
    {
        $gate = $this->gate(['s' => '1', 'i' => 1, 'b' => true]);
        $this->assertTrue($gate->isOn('s'));
        $this->assertTrue($gate->isOn('i'));
        $this->assertTrue($gate->isOn('b'));
    }

    public function testFalsinessRejectsEverythingElse(): void
    {
        $gate = $this->gate(['zero' => '0', 'int' => 0, 'no' => false, 'empty' => '', 'text' => 'yes']);
        foreach (['zero', 'int', 'no', 'empty', 'text'] as $key) {
            $this->assertFalse($gate->isOn($key), $key);
        }
    }

    public function testDefaultAppliesOnlyWhenUnset(): void
    {
        $this->assertTrue($this->gate([])->isOn('missing', true));
        $this->assertFalse($this->gate([])->isOn('missing'));
        // An explicit '0' must beat a true default — this is the kill switch.
        $this->assertFalse($this->gate(['k' => '0'])->isOn('k', true));
    }

    public function testTextTrimsAndRawDoesNot(): void
    {
        $gate = $this->gate(['k' => "  token \n"]);
        $this->assertSame('token', $gate->text('k'));
        $this->assertSame("  token \n", $gate->raw('k'));
        $this->assertSame('', $gate->text('missing'));
    }

    public function testIntFallsBackForUnsetAndEmpty(): void
    {
        $gate = $this->gate(['ttl' => '3600', 'blank' => '']);
        $this->assertSame(3600, $gate->int('ttl'));
        $this->assertSame(86400, $gate->int('missing', 86400));
        $this->assertSame(86400, $gate->int('blank', 86400));
        // An explicit zero is a real value, not "unset" — a 0 TTL disables caching.
        $this->assertSame(0, $this->gate(['ttl' => 0])->int('ttl', 86400));
    }

    public function testListIgnoresNonListValues(): void
    {
        $this->assertSame(['a', 'b'], $this->gate(['q' => ['a', 'b']])->list('q'));
        $this->assertSame([], $this->gate(['q' => 'not-a-list'])->list('q'));
        $this->assertSame([], $this->gate([])->list('q'));
    }
}
