<?php

namespace Kirjuri\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** kirjuri_user_can_access_case() is the single rule every page, action and the API use. */
final class CaseAccessTest extends TestCase
{
    public static function cases(): array
    {
        $bob = array('username' => 'bob', 'access' => '1');
        return array(
            'no access group' => array($bob, '', true),
            'null access group' => array($bob, null, true),
            'member' => array($bob, 'alice;bob', true),
            'not a member' => array($bob, 'alice;carol', false),
            // Twig's "in" on the raw string treated these as members.
            'name is a prefix of a member' => array($bob, 'bobby;alice', false),
            'name is inside a member' => array($bob, 'jimbob', false),
            'admin only group' => array($bob, 'admin', false),
            'admins open everything' => array(array('username' => 'root', 'access' => '0'), 'alice', true),
            'integer admin access level' => array(array('username' => 'root', 'access' => 0), 'alice', true),
            'stray separators and spaces' => array($bob, ';alice; bob ;', true),
            'only separators means no group' => array($bob, ';;', true),
            'usernames are case sensitive' => array($bob, 'Bob', false),
            'logged out user' => array(array(), 'alice', false),
            'view only user who is a member' => array(array('username' => 'bob', 'access' => '2'), 'bob', true),
        );
    }

    #[DataProvider('cases')]
    public function testAccessRule(array $user, ?string $caseOwner, bool $expected): void
    {
        $this->assertSame($expected, kirjuri_user_can_access_case($user, $caseOwner));
    }

    public function testAccessGroupParsing(): void
    {
        $this->assertSame(array('alice', 'bob'), kirjuri_access_group(' alice ;;bob;'));
        $this->assertSame(array(), kirjuri_access_group(null));
    }
}
