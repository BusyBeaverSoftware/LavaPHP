<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * The cookie's contents, as a value object. Immutable on purpose: a request
 * either arrived with a valid session or it did not, and "changing" one means
 * minting a new one — which is exactly the operation that has to rotate the
 * CSRF nonce on login (session fixation), so the shape of the type is what makes
 * that hard to forget.
 */
final readonly class SessionData
{
    public function __construct(
        public ?int $userId,
        public int $epoch,
        public string $csrf,
        public int $issuedAt,
    ) {
    }

    /** A session that exists but belongs to nobody: the CSRF nonce before login. */
    public static function anonymous(string $csrf, int $issuedAt): self
    {
        return new self(null, 0, $csrf, $issuedAt);
    }

    public static function forUser(int $userId, int $epoch, string $csrf, int $issuedAt): self
    {
        return new self($userId, $epoch, $csrf, $issuedAt);
    }

    public function authenticated(): bool
    {
        return $this->userId !== null;
    }

    /**
     * Server-side expiry, checked in addition to the cookie's own `Max-Age`.
     * `Max-Age` is a request to the client, and a client is free to ignore it —
     * a cookie replayed a year later must be rejected by us, not by the browser.
     */
    public function expiredAt(int $now, int $ttl): bool
    {
        return $now - $this->issuedAt > $ttl;
    }

    /** @return array<string, mixed> */
    public function json(): array
    {
        return [
            'u' => $this->userId,
            'e' => $this->epoch,
            'c' => $this->csrf,
            't' => $this->issuedAt,
        ];
    }

    /**
     * Rebuild from the cookie's payload. Returns null for anything that is not
     * exactly the shape we wrote — a cookie is attacker-controlled input until
     * its signature has been checked, and this is the part that runs after.
     * It is decoded JSON, so not even the keys are known to be strings.
     *
     * @param array<mixed> $data
     */
    public static function fromJson(array $data): ?self
    {
        $userId = $data['u'] ?? null;
        $epoch = $data['e'] ?? null;
        $csrf = $data['c'] ?? null;
        $issuedAt = $data['t'] ?? null;

        if (!is_int($userId) && $userId !== null) {
            return null;
        }
        if (!is_int($epoch) || !is_string($csrf) || !is_int($issuedAt)) {
            return null;
        }

        // The nonce is used in a `hash_equals` comparison later, so a value of
        // the wrong shape is rejected here rather than at the comparison.
        if (preg_match('/^[0-9a-f]{32,64}$/', $csrf) !== 1) {
            return null;
        }

        return new self($userId, $epoch, $csrf, $issuedAt);
    }
}
