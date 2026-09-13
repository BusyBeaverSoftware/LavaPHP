<?php

declare(strict_types=1);

namespace App\Tests;

use App\Auth\PasswordHasher;
use App\Http\Redirects;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Two sign-in defences held to what they claim, both found wanting by the review
 * of an outside build (Lava Notes) that copied the same ideas.
 *
 * `safeNext()` let `/<TAB>/evil.example` through: a URL parser strips the tab,
 * and the browser went to another host. The decoy hash was bcrypt at cost 12,
 * which on PHP 8.3 (default cost 10) made an unknown address the SLOW answer —
 * the timing oracle the decoy exists to close, reversed.
 */
final class SignInHardeningTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function foreignDestinations(): array
    {
        return [
            'protocol-relative' => ['//evil.example/login'],
            'backslash after the slash' => ['/\\evil.example'],
            'a tab after the slash' => ["/\t/evil.example"],
            'a tab later on' => ["/posts\t//evil.example"],
            'a line feed' => ["/posts\nLocation: https://evil.example"],
            'a carriage return' => ["/posts\r"],
            'a NUL byte' => ["/posts\0"],
            'a backslash later on' => ['/posts\\..\\evil'],
            'an absolute URL' => ['https://evil.example/'],
            'no leading slash' => ['evil.example'],
        ];
    }

    #[DataProvider('foreignDestinations')]
    public function testANextThatCouldLeaveThisSiteFallsBack(string $next): void
    {
        self::assertSame('/', Redirects::safeNext($next));
    }

    public function testALocalPathWithAQueryIsKept(): void
    {
        self::assertSame('/posts/new?draft=1&tag=php%20tips', Redirects::safeNext('/posts/new?draft=1&tag=php%20tips'));
    }

    public function testTheDecoyCostsWhatARealHashCostsOnThisPhp(): void
    {
        $hasher = new PasswordHasher();

        self::assertFalse(
            password_needs_rehash($hasher->decoyHash(), PASSWORD_DEFAULT),
            'the decoy must be made with the same algorithm and cost as a real hash, or an unknown address answers at a different speed',
        );
        self::assertSame(password_get_info($hasher->hash('a-real-password'))['options'], password_get_info($hasher->decoyHash())['options']);
        self::assertFalse($hasher->verify(null, 'anything at all'));
    }
}
