# Translating an app

LavaPHP has no translator (Lava Notes, R2-G6). Two kinds of text are in play,
and only one of them is translated:

- **What developers and agents read** — problem sentences and fixes, `lava`
  output, the `validation_failed` messages of the built-in rules — is English
  and stays English. It describes the code, and it should not be shown to a
  visitor as it stands.
- **What visitors read** — templates, flash messages, form errors — belongs to
  the app, which picks a translator and says what each page reads.

## Choose a translator

A translator is a service registered in `app/Services.php`: a small class of
your own that reads `lang/<locale>.php` arrays, or a library such as
symfony/translation. For plurals and dates, use ICU through `ext-intl`
(`MessageFormatter`, `IntlDateFormatter`) rather than `count === 1`, which is
right for English and wrong for many other languages.

## Reach templates through an extension installed at boot

```php
// app/View/TranslationExtension.php
final class TranslationExtension extends AbstractExtension
{
    public function __construct(private readonly Translator $translator)
    {
    }

    public function getFilters(): array
    {
        return [
            // needs_context: the locale comes from this render's context, never from a global.
            new TwigFilter('t', fn (array $context, string $text, array $params = []): string => $this->translator->translate(
                is_string($context['locale'] ?? null) ? $context['locale'] : 'en',
                $text,
                $params,
            ), ['needs_context' => true]),
        ];
    }
}
```

```php
// app/Services.php
$c->singleton(Translator::class, static fn (): Translator => new Translator(dirname(__DIR__) . '/lang'));
$c->singleton(TranslationExtension::class, static fn (Container $c): TranslationExtension => new TranslationExtension($c->get(Translator::class)));
```

```php
// config/view.php
'extensions' => [App\View\TranslationExtension::class],
```

```twig
<a rel="next" href="…">{{ 'Older posts'|t }}</a>
<span>{{ 'Page {page} of {pages}'|t({page: pagination.page, pages: pagination.pages}) }}</span>
```

The handler passes the locale with everything else it renders:
`$view->render('home', ['locale' => $locale, 'posts' => $posts])`. Three rules
keep this correct:

- **No global for the locale.** The renderer is one object for the whole
  process, so `addGlobal('locale', …)` set for one visitor would still answer
  the next one a worker serves (DECISIONS.md 242). The context is per render.
- **Do not mark the filter `is_safe`.** Translated text and the values put into
  it stay escaped, so a translation file cannot inject markup and neither can a
  parameter.
- **Key a page cache on the locale.** Otherwise the first visitor's language is
  the one everyone gets.

`view.extensions` installs the extension while boot builds the renderer, which
matters because Twig refuses a new filter after its first render
([lava-view.md](packs/lava-view.md#configure)).

## Form errors in the visitor's language

A failed validation gives one `validation_failed` problem per field, and its
`context` says what went wrong without prose: `field`, `rule` (`required`,
`email`, `max`, or the name given to `custom()`) and `expects` (what the rule
wanted, as [lava-validate.md's rules table](packs/lava-validate.md#the-rules)
lists it: `{"bound":254,"of":"characters"}` for `max(254)`,
`{"present":true}` for `required`, the allowed list for `in`, `null` for
`email`). Translate from those, and keep the English sentence for logs and API
clients. A placeholder takes a scalar, so pass the parts a sentence uses; `of`
tells "at least 2 characters" from "at least 2":

```php
$errors = [];
foreach ($input->problems() as $problem) {
    $field = $problem->context['field'];
    $errors[$field] = $translator->translate($locale, 'validation.' . $problem->context['rule'], [
        'field' => $translator->translate($locale, 'field.' . $field),
        'bound' => $problem->context['expects']['bound'] ?? null,
        'of' => $problem->context['expects']['of'] ?? null,
    ]);
}
```

A `custom()` rule takes its own message; for a visitor-facing form, give it a
stable name and translate by name like any built-in rule.
