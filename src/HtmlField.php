<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license MIT
 */


namespace craft\htmlfield;

use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\base\Volume;
use craft\elements\Asset;
use craft\helpers\FileHelper;
use craft\helpers\Html;
use craft\helpers\HtmlPurifier;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use craft\validators\HandleValidator;
use HTMLPurifier_Config;
use yii\db\Schema;

/**
 * Base HTML Field
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 1.0.0
 */
abstract class HtmlField extends Field
{
    /**
     * @var string|null The HTML Purifier config file to use
     */
    public ?string $purifierConfig = null;

    /**
     * @var bool Whether the HTML should be purified on save
     */
    public bool $purifyHtml = true;

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
     * @var string The type of database column the field should have in the content table
     */
    public string $columnType = Schema::TYPE_TEXT;

    /**
     * @inheritdoc
     */
    public function getContentColumnType(): array|string
    {
        return $this->columnType;
    }

    /**
     * @inheritdoc
     */
    public function settingsAttributes(): array
    {
        $attributes = parent::settingsAttributes();
        $attributes[] = 'purifierConfig';
        $attributes[] = 'removeInlineStyles';
        $attributes[] = 'removeEmptyTags';
        $attributes[] = 'removeNbsp';
        $attributes[] = 'purifyHtml';
        $attributes[] = 'columnType';
        return $attributes;
    }

    /**
     * @inheritdoc
     */
    public function normalizeValue(mixed $value, ?\craft\base\ElementInterface $element = null): mixed
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
     *
     * @param string $content
     * @param int|null $siteId
     * @return HtmlFieldData
     */
    protected function createFieldData(string $content, ?int $siteId): HtmlFieldData
    {
        return new HtmlFieldData($content, $siteId);
    }

    /**
     * Returns the value prepped for the input.
     *
     * @param HtmlFieldData|string|null $value
     * @param ElementInterface|null $element
     * @return string
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
     * @inheritdoc
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
     * @inheritdoc
     */
    protected function searchKeywords(mixed $value, ElementInterface $element): string
    {
        $keywords = parent::searchKeywords($value, $element);

        if (Craft::$app->getDb()->getIsMysql()) {
            $keywords = StringHelper::encodeMb4($keywords);
        }

        return $keywords;
    }

    /**
     * @inheritdoc
     */
    public function serializeValue(mixed $value, ?\craft\base\ElementInterface $element = null): mixed
    {
        /** @var HtmlFieldData|string|null $value */
        if (!$value) {
            return null;
        }

        if ($value instanceof HtmlFieldData) {
            $value = $value->getRawContent();
        }

        if ($value === '') {
            return null;
        }

        if ($this->purifyHtml) {
            // Parse reference tags so HTMLPurifier doesn't encode the curly braces
            $value = $this->_parseRefs($value, $element);

            // Sanitize & tokenize any SVGs
            $svgTokens = [];
            $svgContent = [];
            $value = preg_replace_callback('/<svg\b.*>.*<\/svg>/Uis', function(array $match) use (&$svgTokens, &$svgContent): string {
                $svgContent[] = Html::sanitizeSvg($match[0]);
                return $svgTokens[] = 'svg:' . StringHelper::randomString(10);
            }, $value);

            $value = HtmlPurifier::process($value, $this->purifierConfig());

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
                function(array $matches) use ($allowedStyles) {
                    // Only allow certain styles through
                    $allowed = [];
                    $styles = explode(';', $matches[2]);
                    foreach ($styles as $style) {
                        [$name, $value] = array_map('trim', array_pad(explode(':', $style, 2), 2, ''));
                        if (isset($allowedStyles[$name])) {
                            $allowed[] = "$name: $value";
                        }
                    }
                    return $matches[1] . ($allowed ? sprintf(' style="%s"', implode('; ', $allowed)) : '');
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

        // Find any element URLs and swap them with ref tags
        $value = preg_replace_callback(
            sprintf('/(href=|src=)([\'"])([^\'"\?#]*)(\?[^\'"\?#]+)?(#[^\'"\?#]+)?(?:#|%%23)([\w\\\\]+)\:(\d+)(?:@(\d+))?(\:(?:transform\:)?%s)?\2/', HandleValidator::$handlePattern),
            function($matches) {
                [, $attr, $q, $url, $query, $hash, $elementType, $ref, $siteId, $transform] = array_pad($matches, 10, null);

                // Create the ref tag, and make sure :url is in there
                $ref = "$elementType:$ref" . ($siteId ? "@$siteId" : '') . ($transform ?: ':url');

                if ($query || $hash) {
                    // Make sure that the query/hash isn't actually part of the parsed URL
                    // - someone's Entry URL Format could include "?slug={slug}" or "#{slug}", etc.
                    // - assets could include ?mtime=X&focal=none, etc.
                    $parsed = Craft::$app->getElements()->parseRefs("{{$ref}}");
                    if ($query) {
                        // Decode any HTML entities, e.g. &amp;
                        $query = Html::decode($query);
                        if (mb_strpos($parsed, $query) !== false) {
                            $url .= $query;
                            $query = '';
                        }
                    }
                    if ($hash && mb_strpos($parsed, $hash) !== false) {
                        $url .= $hash;
                        $hash = '';
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

        foreach (Craft::$app->getSites()->getAllSites(false) as $site) {
            if ($site->hasUrls && ($baseUrl = $site->getBaseUrl())) {
                $baseUrls[] = $baseUrl;
                $siteIds[] = $site->id;
                $volumeIds[] = null;
            }
        }

        foreach (Craft::$app->getVolumes()->getAllVolumes() as $volume) {
            $fs = $volume->getFs();
            if ($fs->hasUrls && ($baseUrl = $fs->getRootUrl())) {
                $baseUrls[] = $baseUrl;
                $siteIds[] = null;
                $volumeIds[] = $volume->id;
            }
        }

        foreach ($baseUrls as &$baseUrl) {
            // just to be safe
            $baseUrl = StringHelper::ensureRight($baseUrl, '/');
            $baseUrlLengths[] = strlen($baseUrl);
        }

        array_multisort($baseUrlLengths, SORT_DESC, SORT_NUMERIC, $baseUrls, $siteIds, $volumeIds);

        $value = preg_replace_callback(
            '/(href=|src=)([\'"])((?:\/|http).*?)\2/',
            function($matches) use ($baseUrls, $siteIds, $volumeIds) {
                $url = $matches[3] ?? null;

                if (!$url) {
                    return '';
                }

                foreach ($baseUrls as $key => $baseUrl) {
                    if (StringHelper::startsWith($url, $baseUrl)) {
                        // Drop query
                        $query = parse_url($url, PHP_URL_QUERY);

                        if (!empty($query)) {
                            break;
                        }

                        $uri = preg_replace('/\?.*/', '', $url);

                        // Drop page trigger
                        $pageTrigger = Craft::$app->getConfig()->getGeneral()->getPageTrigger();
                        if (strpos($pageTrigger, '?') !== 0) {
                            $pageTrigger = preg_quote($pageTrigger, '/');
                            $uri = preg_replace("/^(?:(.*)\/)?$pageTrigger(\d+)$/", '', $uri);
                        }

                        // Drop the base URL
                        $uri = StringHelper::removeLeft($uri, $baseUrl);

                        if ($siteIds[$key] !== null) {
                            // site URL
                            if ($element = Craft::$app->getElements()->getElementByUri($uri, $siteIds[$key], true)) {
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
                                ->select(['elements.id'])
                                ->scalar();

                            if ($assetId) {
                                $url = sprintf('{asset:%s:url||%s}', $assetId, $url);
                                break;
                            }
                        }
                    }
                }

                return $matches[1] . $matches[2] . $url . $matches[2];
            },
            $value
        );

        if (Craft::$app->getDb()->getIsMysql()) {
            // Encode any 4-byte UTF-8 characters.
            $value = StringHelper::encodeMb4($value);
        }

        return $value;
    }

    /**
     * Parse ref tags in URLs, while preserving the original tag values in the URL fragments
     * (e.g. `href="{entry:id:url}"` => `href="[entry-url]#entry:id:url"`)
     *
     * @param string $value
     * @param ElementInterface|null $element
     * @return string
     */
    private function _parseRefs(string $value, ?ElementInterface $element = null): string
    {
        if (!StringHelper::contains($value, '{')) {
            return $value;
        }

        return preg_replace_callback(
            sprintf('/(href=|src=)([\'"])(\{([\w\\\\]+\:\d+(?:@\d+)?\:(?:transform\:)?%s)(?:\|\|[^\}]+)?\})(?:\?([^\'"#]*))?(#[^\'"#]+)?\2/', HandleValidator::$handlePattern),
            function($matches) use ($element) {
                [$fullMatch, $attr, $q, $refTag, $ref, $query, $fragment] = array_pad($matches, 7, null);
                $parsed = Craft::$app->getElements()->parseRefs($refTag, $element->siteId ?? null);

                // If the ref tag couldn't be parsed, leave it alone
                if ($parsed === $refTag) {
                    return $fullMatch;
                }

                if ($query) {
                    // Decode any HTML entities, e.g. &amp;
                    $query = Html::decode($query);
                    if (mb_strpos($parsed, $query) !== false) {
                        $parsed = UrlHelper::urlWithParams($parsed, $query);
                    }
                }

                return sprintf('%s%s%s', "$attr$q$parsed", $fragment ?? '', "#$ref$q");
            },
            $value
        );
    }

    /**
     * Returns the HTML Purifier config used by this field.
     *
     * @return HTMLPurifier_Config
     */
    protected function purifierConfig(): HTMLPurifier_Config
    {
        $purifierConfig = HTMLPurifier_Config::createDefault();
        $purifierConfig->autoFinalize = false;

        $config = $this->config('htmlpurifier', $this->purifierConfig) ?: $this->defaultPurifierOptions();

        foreach ($config as $option => $value) {
            $purifierConfig->set($option, $value);
        }

        return $purifierConfig;
    }

    /**
     * Returns the default HTML Purifier config options, if no config is specified/exists.
     *
     * @return array
     */
    protected function defaultPurifierOptions(): array
    {
        return [
            'Attr.AllowedFrameTargets' => ['_blank'],
            'Attr.EnableID' => true,
            'HTML.SafeIframe' => true,
            'URI.SafeIframeRegexp' => '%^(https?:)?//(www\.youtube(-nocookie)?\.com/embed/|player\.vimeo\.com/video/)%',
        ];
    }

    /**
     * Returns the available config options in a given directory.
     *
     * @param string $dir The directory name within the config/ folder to look for config files
     * @return array
     */
    protected function configOptions(string $dir): array
    {
        $options = ['' => Craft::t('app', 'Default')];
        $path = Craft::$app->getPath()->getConfigPath() . DIRECTORY_SEPARATOR . $dir;

        if (is_dir($path)) {
            $files = FileHelper::findFiles($path, [
                'only' => ['*.json'],
                'recursive' => false,
            ]);

            foreach ($files as $file) {
                $filename = basename($file);
                if ($filename !== 'Default.json') {
                    $options[$filename] = pathinfo($file, PATHINFO_FILENAME);
                }
            }
        }

        ksort($options);

        return $options;
    }

    /**
     * Returns a JSON-decoded config, if it exists.
     *
     * @param string $dir The directory name within the config/ folder to look for the config file
     * @param string|null $file The filename to load.
     * @return array|false The config, or false if the file doesn't exist
     */
    protected function config(string $dir, string $file = null)
    {
        if (!$file) {
            $file = 'Default.json';
        }

        $path = Craft::$app->getPath()->getConfigPath() . DIRECTORY_SEPARATOR . $dir . DIRECTORY_SEPARATOR . $file;

        if (!is_file($path)) {
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
}
