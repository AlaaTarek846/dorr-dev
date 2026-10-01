<?php

namespace App\Support\Translations;

use App\Enums\TranslationPlatform;
use Illuminate\Contracts\Translation\Loader;

/**
 * Wraps Laravel's file loader: source locales (ar, en) keep loading from lang/ untouched;
 * any other locale gets its published backend JSON merged on top. Keys still missing fall
 * back to `app.fallback_locale` (en) through the translator itself.
 */
final class TranslationLoader implements Loader
{
    public function __construct(
        private readonly Loader $files,
        private readonly PublishedTranslations $published,
    ) {}

    /**
     * @param  string  $locale
     * @param  string  $group
     * @param  string|null  $namespace
     * @return array<mixed>
     */
    public function load($locale, $group, $namespace = null)
    {
        $lines = $this->files->load($locale, $group, $namespace);

        if (($namespace !== null && $namespace !== '*') || $group === '*'
            || in_array(strtolower((string) $locale), PublishedTranslations::sourceLocales(), true)
            || ! TranslationPlatform::Backend->hasGroup((string) $group)) {
            return $lines;
        }

        $published = $this->published->lines((string) $locale, TranslationPlatform::Backend, (string) $group);

        return $published === [] ? $lines : array_replace_recursive($lines, $published);
    }

    /**
     * @param  string  $namespace
     * @param  string  $hint
     */
    public function addNamespace($namespace, $hint)
    {
        $this->files->addNamespace($namespace, $hint);
    }

    /**
     * @param  string  $path
     */
    public function addJsonPath($path)
    {
        $this->files->addJsonPath($path);
    }

    /**
     * @return array<string, string>
     */
    public function namespaces()
    {
        return $this->files->namespaces();
    }

    /**
     * @param  array<mixed>  $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        return $this->files->{$method}(...$arguments);
    }
}
