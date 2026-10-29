<?php

/**
 * @link https://craftcms.com/
 *
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license MIT
 */

namespace CraftCms\HtmlField;

use Closure;
use CraftCms\Cms\Asset\Data\Volume;
use CraftCms\Cms\Asset\Elements\Asset;
use CraftCms\Cms\Element\Contracts\ElementInterface;
use CraftCms\Cms\Field\Concerns\TracksReferences;
use CraftCms\Cms\Field\Conditions\EmptyFieldConditionRule;
use CraftCms\Cms\Field\Contracts\PreviewableFieldInterface;
use CraftCms\Cms\Field\Contracts\TracksReferencesFieldInterface;
use CraftCms\Cms\Field\Field;
use CraftCms\Cms\Site\Data\Site;
use CraftCms\Cms\Support\Facades\Elements;
use CraftCms\Cms\Support\Facades\HtmlSanitizers;
use CraftCms\Cms\Support\Facades\Sites;
use CraftCms\Cms\Support\Facades\Volumes;
use CraftCms\Cms\Support\Html;
use CraftCms\Cms\Support\Json;
use CraftCms\Cms\Support\Str;
use CraftCms\Cms\Support\Url;
use CraftCms\Cms\Validation\Rules\HandleRule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use League\Uri\Uri;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;

use function CraftCms\Cms\t;

/**
 * Base HTML Field
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 *
 * @since 1.0.0
 */
abstract class HtmlField extends Field implements PreviewableFieldInterface, TracksReferencesFieldInterface
{
    use TracksReferences;

    /**
     * {@inheritdoc}
     */
    public static function phpType(): string
    {
        return sprintf('%s|null', HtmlFieldData::class);
    }

    /**
     * @var bool Whether the HTML should be sanitized on save
     *
     * @since 4.0.0
     */
    public bool $sanitizeHtml = true;

    /**
     * @var string|null The name of the HTML sanitizer to use (see `HtmlSanitizers::names()`).
     *                  Craft’s default sanitizer is used when this is `null`.
     *
     * @since 4.0.0
     */
    public ?string $htmlSanitizer = null;

    /**
     * @var bool Whether `<font>` tags and disallowed inline styles should be removed on save
     */
    public bool $removeInlineStyles = false;

    /**
     * @var bool Whether empty tags should be removed on save
     */
    public bool $removeEmptyTags = false;

    /**
     * @var bool Whether non-breaking spaces should be replaced by regular spaces on save
     */
    public bool $removeNbsp = false;

    /**
     * Constructor
     */
    public function __construct($config = [])
    {
        // Map the Craft 5 HTML Purifier settings onto their HTML sanitizer equivalents.
        // Legacy config/craft/htmlpurifier/*.json files are registered as sanitizers named after the file.
        if (array_key_exists('purifyHtml', $config)) {
            $config['sanitizeHtml'] ??= (bool) $config['purifyHtml'];
        }
        if (array_key_exists('purifierConfig', $config) && ! array_key_exists('htmlSanitizer', $config)) {
            $config['htmlSanitizer'] = $config['purifierConfig']
                ? pathinfo($config['purifierConfig'], PATHINFO_FILENAME)
                : null;
        }
        if (($config['htmlSanitizer'] ?? null) === '') {
            $config['htmlSanitizer'] = null;
        }

        // remove unused settings
        unset($config['columnType'], $config['purifyHtml'], $config['purifierConfig']);

        parent::__construct($config);
    }

    public function getPreviewHtml(mixed $value, ElementInterface $element): string
    {
        return strip_tags((string) $value);
    }

    /**
     * {@inheritdoc}
     */
    public function settingsAttributes(): array
    {
        $attributes = parent::settingsAttributes();
        $attributes[] = 'sanitizeHtml';
        $attributes[] = 'htmlSanitizer';
        $attributes[] = 'removeInlineStyles';
        $attributes[] = 'removeEmptyTags';
        $attributes[] = 'removeNbsp';

        return array_values(array_unique($attributes));
    }

    /**
     * {@inheritdoc}
     */
    public function getRules(): array
    {
        return array_merge(parent::getRules(), [
            'sanitizeHtml' => ['boolean'],
            'htmlSanitizer' => [
                'nullable',
                function ($attribute, mixed $value, Closure $fail) {
                    if ($this->sanitizeHtml && $value !== null && ! HtmlSanitizers::has($value)) {
                        $fail(t('Invalid HTML sanitizer.'));
                    }
                },
            ],
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function normalizeValue(mixed $value, ?ElementInterface $element = null): mixed
    {
        if ($value === null || $value instanceof HtmlFieldData) {
            return $value;
        }

        $value = trim($value);

        if (in_array($value, ['<p><br></p>', '<p>&nbsp;</p>', '<p></p>', ''], true)) {
            return null;
        }

        return $this->createFieldData($value, $element->siteId ?? null);
    }

    /**
     * Creates the field data object with the given value and site ID.
     */
    protected function createFieldData(string $content, ?int $siteId): HtmlFieldData
    {
        return new HtmlFieldData($content, $siteId);
    }

    /**
     * Returns the value prepped for the input.
     *
     * @param  HtmlFieldData|string|null  $value
     */
    protected function prepValueForInput($value, ?ElementInterface $element): string
    {
        if ($value instanceof HtmlFieldData) {
            $value = $value->getRawContent();
        }

        if ($value !== null) {
            $value = $this->_parseRefs($value, $element);
        }

        return $value ?? '';
    }

    /**
     * {@inheritdoc}
     */
    public function isValueEmpty(mixed $value, ElementInterface $element): bool
    {
        /** @var HtmlFieldData|null $value */
        if ($value === null) {
            return true;
        }

        return parent::isValueEmpty($value->getRawContent(), $element);
    }

    /**
     * {@inheritdoc}
     */
    protected function searchKeywords(mixed $value, ElementInterface $element): string
    {
        $keywords = parent::searchKeywords($value, $element);

        if (! DB::supportsMb4()) {
            $keywords = Str::encodeMb4($keywords);
        }

        return $keywords;
    }

    /**
     * {@inheritdoc}
     */
    public function serializeValue(mixed $value, ?ElementInterface $element): mixed
    {
        /** @var HtmlFieldData|string|null $value */
        if (! $value) {
            return null;
        }

        if ($value instanceof HtmlFieldData) {
            $value = $value->getRawContent();
        }

        if ($value === '') {
            return null;
        }

        if ($this->sanitizeHtml) {
            // Parse reference tags so the sanitizer doesn't drop ref tag URLs
            $value = $this->_parseRefs($value, $element);

            // Sanitize & tokenize any SVGs
            $svgTokens = [];
            $svgContent = [];
            $value = preg_replace_callback('/<svg\b.*>.*<\/svg>/Uis', function (array $match) use (&$svgTokens, &$svgContent): string {
                $svgContent[] = Html::sanitizeSvg($match[0]);

                return $svgTokens[] = 'svg:'.Str::random(10);
            }, $value);

            $value = $this->htmlSanitizer()->sanitize($value);

            // Symfony’s sanitizer entity-encodes these characters, which would break ref tags (`{entry:1@2:url}`)
            // and needlessly change URLs and text, so restore them
            $value = str_replace(['&#43;', '&#61;', '&#64;'], ['+', '=', '@'], $value);

            // Put the sanitized SVGs back
            $value = str_replace($svgTokens, $svgContent, $value);
        }

        if ($this->removeInlineStyles) {
            // Remove <font> tags
            $value = preg_replace('/<(?:\/)?font\b[^>]*>/', '', $value);

            // Remove disallowed inline styles
            $allowedStyles = $this->allowedStyles();
            $value = preg_replace_callback(
                '/(<(?:h1|h2|h3|h4|h5|h6|p|div|blockquote|pre|strong|em|b|i|u|a|span|img|table|thead|tbody|tr|td|th)\b[^>]*)\s+style="([^"]*)"/',
                function (array $matches) use ($allowedStyles) {
                    // Only allow certain styles through
                    $allowed = [];
                    $styles = explode(';', $matches[2]);
                    foreach ($styles as $style) {
                        [$name, $value] = array_map('trim', array_pad(explode(':', $style, 2), 2, ''));
                        if (isset($allowedStyles[$name])) {
                            $allowed[] = "$name: $value";
                        }
                    }

                    return $matches[1].($allowed ? sprintf(' style="%s"', implode('; ', $allowed)) : '');
                },
                $value
            );
        }

        if ($this->removeEmptyTags) {
            // Remove empty tags
            $value = preg_replace('/<(h1|h2|h3|h4|h5|h6|p|div|blockquote|pre|strong|em|a|b|i|u|span)\s*><\/\1>/', '', $value);
        }

        if ($this->removeNbsp) {
            // Replace non-breaking spaces with regular spaces
            $value = preg_replace('/(&nbsp;|&#160;|\x{00A0})/u', ' ', $value);
            $value = preg_replace('/  +/', ' ', $value);
        }

        // Find any alt text element refs and swap them with ref tags
        $value = preg_replace_callback(
            '/(alt=)([\'"])([^\'"]*)(?:#|%%23)asset:(\d+)(?:@(\d+))?:alt\2/',
            function ($matches) {
                [, $attr, $q, $text, $ref, $siteId] = array_pad($matches, 6, null);

                $ref = sprintf('asset:%s%s:alt', $ref, $siteId ? "@$siteId" : '');

                return sprintf('%s%s{%s%s}%s', $attr, $q, $ref, $text ? "||$text" : '', $q);
            },
            $value
        );

        // Find any element URLs and swap them with ref tags
        $value = preg_replace_callback(
            sprintf('/(href=|src=)([\'"])([^\'"\?#]*)(\?[^\'"\?#]+)?(#[^\'"\?#]+)?(?:#|%%23)([\w\\\\]+)\:(\d+)(?:@(\d+))?(\:(?:transform\:)?%s)?\2/', HandleRule::$handlePattern),
            function ($matches) {
                [, $attr, $q, $url, $query, $hash, $elementType, $ref, $siteId, $transform] = array_pad($matches, 10, null);

                // Create the ref tag, and make sure :url is in there
                $ref = "$elementType:$ref".($siteId ? "@$siteId" : '').($transform ?: ':url');

                if ($query || $hash) {
                    // Make sure that the query/hash isn't actually part of the parsed URL
                    // - someone's Entry URL Format could include "?slug={slug}" or "#{slug}", etc.
                    // - assets could include ?mtime=X&focal=none, etc.
                    $parsed = Elements::parseRefs("{{$ref}}");
                    if ($query) {
                        // Decode any HTML entities, e.g. &amp;
                        $query = Html::decode($query);
                        // split the query into parts
                        $queryParts = explode('&', ltrim($query, '?'));
                        foreach ($queryParts as $key => $queryPart) {
                            // if the $queryPart is a part of the parsed url, update the URL
                            // otherwise, we'll add it after the ref ({} portion of the URL)
                            if (mb_strpos($parsed, $queryPart) !== false) {
                                $url = Url::urlWithParams($url, $queryPart);
                                unset($queryParts[$key]);
                            }
                        }
                        $queryParts = array_values($queryParts);
                        if (empty($queryParts)) {
                            $query = '';
                        } else {
                            $query = '?'.implode('&', $queryParts);
                        }
                    }

                    // if we have a $hash
                    if ($hash) {
                        // and there's a hash in the $parsed URL, and they match - add $hash to the $url and empty $hash
                        if (mb_strpos($parsed, $hash) !== false) {
                            $url .= $hash;
                            $hash = '';
                        } elseif (mb_strpos($parsed, '#') !== false) {
                            // if the $parsed URL has a hash, use it and discard the "new" hash
                            try {
                                $uri = Uri::new($parsed);
                                $hash = $uri->getFragment();
                                if ($hash) {
                                    $url .= '#'.$hash;
                                }
                            } catch (\Exception $e) {
                                // oh, well
                            }
                            $hash = '';
                        }
                        // otherwise, use that new hash
                    }
                }

                return sprintf('%s%s%s', "$attr$q{", "$ref||$url", "}$query$hash$q");
            },
            $value
        );

        // Swap any regular URLS with element refs, too

        // Get all base URLs, sorted by longest first
        $baseUrls = [];
        $baseUrlLengths = [];
        $siteIds = [];
        $volumeIds = [];

        /** @var Site $site */
        foreach (Sites::getAllSites(false) as $site) {
            if ($site->hasUrls && ($baseUrl = $site->getBaseUrl())) {
                $baseUrls[] = $baseUrl;
                $siteIds[] = $site->id;
                $volumeIds[] = null;
            }
        }

        /** @var Volume $volume */
        foreach (Volumes::getAllVolumes() as $volume) {
            if ($volume->sourceHasUrls() && ($baseUrl = $volume->sourceDisk()->url(''))) {
                $baseUrls[] = $baseUrl;
                $siteIds[] = null;
                $volumeIds[] = $volume->id;
            }
        }

        foreach ($baseUrls as &$baseUrl) {
            // just to be safe
            $baseUrl = Str::finish($baseUrl, '/');
            $baseUrlLengths[] = strlen($baseUrl);
        }

        array_multisort($baseUrlLengths, SORT_DESC, SORT_NUMERIC, $baseUrls, $siteIds, $volumeIds);

        $value = preg_replace_callback(
            '/(href=|src=)([\'"])((?:\/|http).*?)\2/',
            function ($matches) use ($baseUrls, $siteIds, $volumeIds) {
                $url = $matches[3];

                foreach ($baseUrls as $key => $baseUrl) {
                    if (Str::startsWith($url, $baseUrl)) {
                        // Drop query
                        $query = parse_url($url, PHP_URL_QUERY);

                        if (! empty($query)) {
                            break;
                        }

                        $uri = preg_replace('/\?.*/', '', $url);

                        // Drop the base URL
                        $uri = Str::chopStart($uri, $baseUrl);

                        if ($siteIds[$key] !== null) {
                            // site URL
                            if ($element = Elements::getElementByUri($uri, $siteIds[$key], true)) {
                                $refHandle = $element::refHandle();
                                if ($refHandle) {
                                    $url = sprintf('{%s:%s@%s:url||%s}', $refHandle, $element->id, $siteIds[$key], $url);
                                }
                                break;
                            }
                        } else {
                            // volume URL
                            $filename = basename($uri);
                            $folderPath = dirname($uri);

                            $assetId = Asset::find()
                                ->volumeId($volumeIds[$key])
                                ->filename($filename)
                                ->folderPath($folderPath !== '.' ? $folderPath : '')
                                ->ids()[0] ?? null;

                            if ($assetId) {
                                $url = sprintf('{asset:%s:url||%s}', $assetId, $url);
                                break;
                            }
                        }
                    }
                }

                return $matches[1].$matches[2].$url.$matches[2];
            },
            $value
        );

        if (! DB::supportsMb4()) {
            // Encode any 4-byte UTF-8 characters.
            $value = Str::encodeMb4($value);
        }

        return $value;
    }

    /**
     * Parse ref tags in URLs, while preserving the original tag values in the URL fragments
     * (e.g. `href="{entry:id:url}"` => `href="[entry-url]#entry:id:url"`)
     */
    private function _parseRefs(string $value, ?ElementInterface $element = null): string
    {
        if (! str_contains($value, '{')) {
            return $value;
        }

        // Parse alt attribute asset ref tags: {asset:123:alt||fallback text}
        $value = preg_replace_callback(
            '/(alt=)([\'"])(\{asset:(\d+(?:@\d+)?):alt(?:\|\|[^\}]+)?\})\2/',
            function ($matches) use ($element) {
                [$fullMatch, $attr, $q, $refTag, $ref] = $matches;
                $parsed = Elements::parseRefs($refTag, $element->siteId ?? null);

                // If the ref tag couldn't be resolved, leave it alone
                if ($parsed === $refTag) {
                    return $fullMatch;
                }

                // Output: alt="[resolved text]#asset:123:alt"
                return $attr.$q.$parsed.'#asset:'.$ref.':alt'.$q;
            },
            $value
        );

        return preg_replace_callback(
            sprintf('/(href=|src=)([\'"])(\{([\w\\\\]+)(\:\d+(?:@\d+)?\:(?:transform\:)?%s)(?:\|\|[^\}]+)?\})(?:\?([^\'"#]*))?(#[^\'"#]+)?\2/', HandleRule::$handlePattern),
            function ($matches) use ($element) {
                [$fullMatch, $attr, $q, $refTag, $refHandle, $refRemainder, $query, $fragment] = array_pad($matches, 8, null);
                $parsed = Elements::parseRefs($refTag, $element->siteId ?? null);

                // If the ref tag couldn't be parsed, leave it alone
                if ($parsed === $refTag) {
                    return $fullMatch;
                }

                if ($query) {
                    // Decode any HTML entities, e.g. &amp;
                    $query = Html::decode($query);
                    // we want to keep the whole query, regardless of whether it's part of the element's URI format or not
                    $parsed = Url::urlWithParams($parsed, $query);
                }

                // Make sure the ref handle matches the real ref handle from the resolved element type
                /** @var string|ElementInterface|null $class */
                $class = Elements::getElementTypeByRefHandle($refHandle);
                if ($class) {
                    $realRefHandle = $class::refHandle();
                    if ($realRefHandle) {
                        $refHandle = $realRefHandle;
                    }
                }

                return $attr.$q.$parsed.($fragment ?? '').'#'.$refHandle.$refRemainder.$q;
            },
            $value
        );
    }

    /**
     * Returns the HTML sanitizer that should be used to sanitize field values on save.
     *
     * Subclasses can override this to return a customized sanitizer, e.g. one built from
     * `HtmlSanitizers::defaultConfig()` with additional allowed elements.
     *
     * @since 4.0.0
     */
    protected function htmlSanitizer(): HtmlSanitizerInterface
    {
        if ($this->htmlSanitizer !== null && HtmlSanitizers::has($this->htmlSanitizer)) {
            return HtmlSanitizers::sanitizer($this->htmlSanitizer);
        }

        return HtmlSanitizers::sanitizer();
    }

    /**
     * Returns the available HTML sanitizer options, for use in a field’s settings.
     *
     * @return array<array{label: string, value: string}>
     *
     * @since 4.0.0
     */
    protected function htmlSanitizerOptions(): array
    {
        return array_map(fn (string $name) => [
            'label' => $name === 'default' ? t('Default') : $name,
            'value' => $name,
        ], HtmlSanitizers::names());
    }

    /**
     * Returns the available config options in a given directory.
     *
     * @param  string  $dir  The directory name within the config/craft/ folder to look for config files
     */
    protected function configOptions(
        string $dir,
        array $only = ['*.json'],
        bool $includeDefault = true,
        bool $includeExtensions = false,
    ): array {
        $options = [];

        if ($includeDefault) {
            $options[''] = t('Default');
        }

        $path = config_path('craft'.DIRECTORY_SEPARATOR.$dir);

        if (is_dir($path)) {
            $files = [];
            foreach ($only as $pattern) {
                array_push($files, ...(File::glob($path.DIRECTORY_SEPARATOR.$pattern) ?: []));
            }

            foreach (array_unique($files) as $file) {
                $filename = basename($file);
                // Exclude `default` files if $includeDefault is true, to prevent confusion between e.g. "Default" and "Default.json"
                if ($includeDefault && strtolower(pathinfo($filename, PATHINFO_FILENAME)) === 'default') {
                    continue;
                }

                $options[$filename] = $includeExtensions ? $filename : pathinfo($file, PATHINFO_BASENAME);
            }
        }

        ksort($options);

        return $options;
    }

    /**
     * Returns a JSON-decoded config, if it exists.
     *
     * @param  string  $dir  The directory name within the config/craft/ folder to look for the config file
     * @param  string|null  $file  The filename to load.
     * @return array|false The config, or false if the file doesn't exist
     */
    protected function config(string $dir, ?string $file = null)
    {
        if (! $file) {
            $file = 'Default.json';
        }

        $path = config_path('craft'.DIRECTORY_SEPARATOR.$dir.DIRECTORY_SEPARATOR.$file);

        if (! is_file($path)) {
            if ($file !== 'Default.json') {
                // Try again with Default
                return $this->config($dir);
            }

            return false;
        }

        return Json::decode(file_get_contents($path));
    }

    /**
     * Returns the allowed inline CSS styles, based on the plugins that are enabled.
     *
     * @return array<string,bool>
     */
    protected function allowedStyles(): array
    {
        return [];
    }

    /**
     * {@inheritdoc}
     */
    public function getElementConditionRuleType(): string
    {
        return EmptyFieldConditionRule::class;
    }
}
