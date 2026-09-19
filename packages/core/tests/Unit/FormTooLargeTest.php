<?php

declare(strict_types=1);

namespace Lava\Core\Tests\Unit;

use Lava\Core\Http\RequestBody;
use Lava\Core\Problem\RequestTooLarge;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\UploadedFile;
use PHPUnit\Framework\TestCase;

/**
 * A form post PHP discarded for exceeding `post_max_size` (Lava Notes, R3-G2).
 *
 * The limit is passed in: `post_max_size` is `PHP_INI_PERDIR`, so no test can
 * set it, and a test that could only assert the ini default would assert
 * nothing. The served case — `php -S -d post_max_size=1K` — is what the
 * reproduction ran.
 */
final class FormTooLargeTest extends TestCase
{
    private static function form(string $type, mixed $parsed, int $length): ServerRequest
    {
        return (new ServerRequest('POST', '/posts', ['Content-Type' => $type, 'Content-Length' => (string) $length], 'ignored'))
            ->withParsedBody($parsed);
    }

    public function testAFormPhpDiscardedIsRefusedBeforeRouting(): void
    {
        foreach (['multipart/form-data; boundary=----x', 'application/x-www-form-urlencoded'] as $type) {
            try {
                RequestBody::parsed(self::form($type, [], 4412), 1024);
                self::fail("A discarded {$type} post must be refused.");
            } catch (RequestTooLarge $problem) {
                self::assertSame('request_too_large', $problem->code());
                self::assertSame(413, $problem->httpStatus());
                self::assertStringContainsString('4412 bytes', $problem->getMessage());
                self::assertStringContainsString("post_max_size is 1024", $problem->getMessage());
                self::assertStringContainsString('post_max_size', $problem->fix);
                self::assertSame([4412, 1024], [$problem->context['content_length'], $problem->context['post_max_size']]);
            }
        }
    }

    public function testEveryOrdinaryRequestIsUntouched(): void
    {
        // Fields arrived, so nothing was discarded, however long the body is.
        $withFields = self::form('application/x-www-form-urlencoded', ['title' => 'hello'], 4412);
        self::assertSame(['title' => 'hello'], RequestBody::parsed($withFields, 1024)->getParsedBody());

        // Files arrived: a multipart post PHP parsed.
        $withFile = self::form('multipart/form-data; boundary=----x', [], 4412)
            ->withUploadedFiles(['cover' => new UploadedFile('x', 1, \UPLOAD_ERR_OK)]);
        self::assertSame([], RequestBody::parsed($withFile, 1024)->getParsedBody());

        // An empty form that is simply small.
        $small = self::form('application/x-www-form-urlencoded', [], 0);
        self::assertSame([], RequestBody::parsed($small, 1024)->getParsedBody());

        // A JSON body over the limit: PHP parses no JSON, so it discarded
        // nothing, and core parses it here as usual.
        $json = new ServerRequest('POST', '/posts', ['Content-Type' => 'application/json', 'Content-Length' => '4012'], '{"title":"hello"}');
        self::assertSame(['title' => 'hello'], RequestBody::parsed($json, 1024)->getParsedBody());

        // No limit configured means PHP discards nothing.
        self::assertSame([], RequestBody::parsed(self::form('application/x-www-form-urlencoded', [], 9999), 0)->getParsedBody());
    }
}
