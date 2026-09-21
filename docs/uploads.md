# Accepting uploads

LavaPHP has no upload storage and no file response, and an app that takes files
writes both. Uploaded files already reach a handler as PSR-7
`UploadedFileInterface` objects in `$request->getUploadedFiles()`, and
lavaphp/validate checks them with the rule every custom check uses. This page
collects what an outside build got right and the two things a review found it
had not (Lava Notes, round-1 G5 and R2-G9).

## Validate the file with `custom()`

`Validator::validate()` takes any array, so validate the form fields and the
files together, and a bad file is an ordinary `validation_failed` for its field:

```php
use Lava\Validate\Validation\Field;
use Lava\Validate\Validation\Validator;
use Psr\Http\Message\UploadedFileInterface;

$input = Validator::of([
    'title' => Field::str()->required()->max(200),
    'cover' => Field::any()->required()->custom(
        'image',
        static fn (mixed $file): bool => $file instanceof UploadedFileInterface
            && $file->getError() === UPLOAD_ERR_OK
            && Images::extensionOf($file) !== null,
        "'{field}' must be a JPEG, PNG, GIF or WebP image.",
        "Choose an image file for '{field}'.",
    ),
])->validate(($request->getParsedBody() ?? []) + $request->getUploadedFiles());
```

A file larger than `upload_max_filesize` arrives with `UPLOAD_ERR_INI_SIZE`.
A request larger than `post_max_size` is worse: PHP drops the parsed form
before any app code runs, so every field looks missing. Core answers that one
itself, before routing: a form content type with no fields, no files and a
`Content-Length` over the limit is `request_too_large` (413), naming the length
and the limit, so nothing downstream blames a field the visitor did fill in. A
form that PHP *did* parse is never touched, and a JSON body is not affected —
PHP parses none of it, so there is nothing for it to discard.

## Decide the type from the bytes

## Refuse the size before you read the bytes

`UploadedFileInterface::getSize()` is the size PHP already measured, so it costs
nothing to ask. Everything below reads the file into a string to sniff it, and a
check that runs after the read has already spent the memory it was meant to
save. Decide a ceiling — a few megabytes for an avatar, more for a document —
and refuse above it first:

```php
$maxBytes = 5 * 1024 * 1024;

$size = $file->getSize();
if ($size === null || $size > $maxBytes) {
    // refuse: too large, or a stream that will not say how large it is
}
```

`upload_max_filesize` and `post_max_size` are the deployment's outer limits and
they are not this: they are the same for every field in the app, they are set by
whoever deploys it, and above them the framework never sees the request at all
(`request_too_large`, 413). The ceiling above is the one this field means.

The client's file name and `Content-Type` are the client's to invent. Sniff the
content, and accept only formats you can name:

```php
final class Images
{
    public const TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];

    /** The extension to store an upload under, or null when it is not one of TYPES. */
    public static function extensionOf(UploadedFileInterface $file): ?string
    {
        $bytes = (string) $file->getStream();
        $type = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);

        return is_string($type) && isset(self::TYPES[$type]) && @getimagesizefromstring($bytes) !== false
            ? self::TYPES[$type]
            : null;
    }
}
```

Leave SVG out unless you sanitise it: an SVG is XML that can carry script, and
it runs when the file is opened from your origin.

## Cap the pixels by the memory there is

A few-megabyte JPEG can declare 40 megapixels, and GD decodes it to four bytes
a pixel, about 160 MB: more than PHP-FPM's default `memory_limit` of 128 MB. The
request then dies of a memory fatal instead of being refused. Before any
decoding, compare the declared size with what the remaining memory holds:

```php
[$width, $height] = getimagesizefromstring($bytes) ?: [0, 0];

$limit = ini_parse_quantity((string) ini_get('memory_limit'));
$cap = $limit <= 0
    ? 40_000_000
    : min(40_000_000, intdiv($limit - memory_get_usage() - 16 * 1024 * 1024, 5));

if ($width * $height > $cap) {
    // refuse: the image is larger than this server can resize
}
```

Five bytes a pixel is GD's four plus one to spare, and 16 MB is kept back for
the rest of the request. Without a resizer nothing is decoded, and the fixed
ceiling alone stops a decompression bomb.

## Store it outside `public/`, named by its content

- Keep uploads in a directory the web server does not serve, configured and
  resolved against the app directory like any other path.
- Name each file by a hash of its bytes plus the extension you sniffed:
  `sha1($bytes) . '.' . $extension`. No client-supplied name reaches the
  filesystem, and the same image uploaded twice is one file.
- Write to a temporary name and `rename()` it into place, so a reader never
  sees half a file.
- Make every size before writing anything, and delete what you wrote if the
  database insert fails. Written first, an original whose resize dies of a
  memory fatal stays on disk with no row pointing at it.

## Serve it through a route

A route whose param type admits only stored names keeps every path inside the
directory:

```php
$r->pattern('upload', '[a-f0-9]{40}\.(?:jpg|png|gif|webp)');
$r->get('/uploads/{file:upload}', 'uploads.show')->handler([UploadController::class, 'show']);
```

```php
public function show(RouteArgs $args, Uploads $uploads): ResponseInterface
{
    $name = $args->str('file');
    $path = $uploads->path($name);
    if ($path === null) {
        return Responses::text('Not found', 404);
    }

    return Responses::of(
        (string) file_get_contents($path),
        (string) array_search(pathinfo($name, PATHINFO_EXTENSION), Images::TYPES, true),
    )->withHeader('Cache-Control', 'public, max-age=31536000, immutable');
}
```

The content type comes from the extension you stored, never from the upload, and
`nosniff` — which every response from `Responses` carries — stops a browser
second-guessing it. A content-hashed name never changes content, which is what
makes `immutable` safe.

`Responses::of()` reads the file into memory, which is the right trade for
images the size cap above allows. For something large enough to matter, build a
streamed response with your own PSR-17 factory: that is one of the few places
reaching past this framework is the correct answer.

## Test it with the real client

An upload handler takes attacker-supplied bytes, so it is the last route to
leave uncovered. `TestClient::upload()` sends a multipart request the way a
browser does — files beside fields, with this client's cookies, so the test can
sign in through the real form first:

```php
$client = new TestClient($app);
$client->form('POST', '/sign-in', ['email' => 'editor@example.com', 'password' => 'correct horse', 'csrf' => $token]);

$response = $client->upload('POST', '/admin/media', ['cover' => __DIR__ . '/fixtures/cover.png'], ['alt' => 'A cover']);

self::assertSame(303, $response->status());
```

A file is a path, `['bytes' => …, 'name' => …, 'type' => …]` for content that
never touched a disk, or an `UploadedFile` you built yourself — which is how to
test the refusals PHP makes before your handler runs:

```php
$refused = new UploadedFile(Stream::create(''), 0, UPLOAD_ERR_INI_SIZE, 'huge.png', 'image/png');
$response = $client->upload('POST', '/admin/media', ['cover' => $refused]);
```

Two things to expect, because they are what production does. The body stream is
empty: PHP parses a multipart body into `$_POST` and `$_FILES` itself and leaves
`php://input` empty, so a handler reading the raw stream sees nothing here
either. And `getClientMediaType()` is what the *client* claimed — sniff the bytes
yourself, as above, because a real browser guesses from the extension and can be
lied to.

## Mind what the file says about its author

A photo straight from a phone carries EXIF data, GPS position included, and
serving the original serves that too. Re-encoding through GD
(`imagecreatefromstring()`, then `imagejpeg()`) drops it, at the cost of a
decode the pixel cap has to allow for; resized copies are already clean. Decide
which one visitors get.
