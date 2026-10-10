<?php

namespace Tests\Unit;

use App\Support\SuspiciousInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The pattern set observes probes and leaves real people alone.
 *
 * The second half of this file matters more than the first. A pattern gate on a
 * public registration form would refuse a participant named O'Brien, an event
 * called "Drop Zone" and a rule line carrying a < character — and those refusals
 * land on somebody trying to sign up for a sports event. Nothing here decides
 * whether a request is served (see ObserveSuspiciousInput), but the patterns are
 * still pinned, so a later edit that starts matching ordinary text fails here.
 */
class SuspiciousInputTest extends TestCase
{
    /** @return array<string, array{0: string}> */
    public static function probes(): array
    {
        return [
            'sql tautology' => ["' OR 1=1--"],
            'quoted tautology' => ["admin' or '1'='1"],
            'union select' => ['1 UNION SELECT username, password FROM users'],
            'union all select' => ['x union all select null,null'],
            'statement break' => ['1; DROP TABLE users'],
            'delete from' => ["x'; delete from event_participants where 1"],
            'timing probe' => ['1 AND sleep(5)'],
            'script tag' => ['<script>alert(1)</script>'],
            'spaced script tag' => ['< script src=x>'],
            'inline handler' => ['<img src=x onerror=alert(1)>'],
            'javascript uri' => ['<a href="javascript:alert(1)">x</a>'],
            'path traversal' => ['../../.env'],
            'windows traversal' => ['..\\..\\web.config'],
            'stream wrapper' => ['php://filter/convert.base64-encode/resource=index'],
        ];
    }

    #[DataProvider('probes')]
    public function test_an_obvious_probe_is_noticed(string $value): void
    {
        $this->assertNotNull(SuspiciousInput::match($value), sprintf('Expected "%s" to be noticed.', $value));
    }

    /** @return array<string, array{0: string}> */
    public static function realPeople(): array
    {
        return [
            'an apostrophe in a surname' => ["Siobhan O'Brien"],
            'an apostrophe and a name particle' => ["Sean O'Connor-D'Souza"],
            'an event called Drop Zone' => ['Drop Zone Championship 2026'],
            'a note about deleting later' => ['Please delete this entry later, thanks'],
            'a dropped table in plain words' => ['We had to drop table seven from the hall plan'],
            'a bare less-than in a rule line' => ['Juniors: age < 12 compete in the morning heat'],
            'a bare greater-than' => ['Score > 90 qualifies'],
            'arithmetic that is not a tautology' => ['2 and 2=4 is the answer'],
            'a javascript enquiry' => ['Do you build in JavaScript: the good parts?'],
            'a quoted team name' => ['The "Union" Select XI football club'],
            'an ellipsis before a path word' => ['Waiting... then home'],
            'a semicolon in prose' => ['Register now; places are limited'],
            'a union in prose' => ['Our staff union selects a representative yearly'],
            'a malaysian address' => ['No. 12, Jalan SS2/24, Petaling Jaya'],
            'a phone number' => ['+60 12-345 6789'],
            'an email' => ['siti.nurhaliza@example.com.my'],
        ];
    }

    #[DataProvider('realPeople')]
    public function test_ordinary_text_is_left_alone(string $value): void
    {
        $this->assertNull(
            SuspiciousInput::match($value),
            sprintf('"%s" is real text and must not be flagged as an attack.', $value),
        );
    }

    public function test_a_password_field_is_never_scanned(): void
    {
        // A password containing a quote is nobody's business, and a wrong password
        // is not intelligence. The key is skipped before the value is read.
        $this->assertNull(SuspiciousInput::firstMatch([
            'password' => "' OR 1=1--",
            'password_confirmation' => '<script>alert(1)</script>',
            '_token' => '../../etc/passwd',
        ]));

        $this->assertTrue(SuspiciousInput::isSkipped('PASSWORD'));
    }

    public function test_it_reports_the_field_and_pattern_it_matched(): void
    {
        $found = SuspiciousInput::firstMatch([
            'name' => "O'Brien",
            'message' => "anything' OR 1=1-- else",
        ]);

        $this->assertSame('message', $found['key']);
        $this->assertSame('sql-tautology', $found['pattern']);
        $this->assertStringContainsString('OR 1=1', $found['sample']);
    }

    public function test_it_walks_a_nested_payload(): void
    {
        $found = SuspiciousInput::firstMatch([
            'participants' => [
                ['full_name' => "O'Brien"],
                ['full_name' => '<script>alert(1)</script>'],
            ],
        ]);

        $this->assertSame('full_name', $found['key']);
        $this->assertSame('script-tag', $found['pattern']);
    }

    public function test_it_stops_at_the_first_match(): void
    {
        // One row per request is intelligence; ten rows naming the same probe is
        // noise, so firstMatch returns one answer however many fields trip.
        $found = SuspiciousInput::firstMatch([
            'a' => '<script>alert(1)</script>',
            'b' => '../../.env',
        ]);

        $this->assertSame('a', $found['key']);
    }
}
