<?php

/**
 * @link https://craftcms.com/
 *
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license MIT
 */

namespace CraftCms\HtmlField;

use CraftCms\Cms\Support\Facades\Elements;
use DOMElement;
use Illuminate\Contracts\Support\Htmlable;
use League\HTMLToMarkdown\HtmlConverter;
use LogicException;
use Symfony\Component\DomCrawler\Crawler;
use Twig\Markup;

/**
 * Stores the HTML field data.
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 *
 * @since 1.0.0
 */
class HtmlFieldData extends Markup implements Htmlable
{
    public const BASE_MARKDOWN_CONFIG = [
        'header_style' => 'atx',
        'remove_nodes' => 'meta style script',
    ];

    /**
     * Converts the given HTML into Markdown.
     *
     * @param  array  $config  (see HtmlConverter::__construct() for options)
     *
     * @since 3.4.0
     */
    public static function toMarkdown(string $html, array $config = []): string
    {
        $body = (new Crawler("<html><body>$html</body></html>"))->filter('body');

        // Replace <figure> children with <p>s
        $figures = $body->filter('figure');
        foreach ($figures as $figure) {
            foreach ($figure->childNodes as $child) {
                /** @var DOMElement $child */
                switch ($child->nodeName) {
                    case 'p':
                        $newNode = $child->cloneNode(true);
                        break;
                    case 'figcaption':
                        $newNode = new DOMElement('p');
                        $body->getNode(0)->appendChild($newNode);
                        foreach ($child->childNodes as $subChild) {
                            $newNode->appendChild($subChild->cloneNode(true));
                        }
                        break;
                    default:
                        $newNode = new DOMElement('p');
                        $body->getNode(0)->appendChild($newNode);
                        $newNode->appendChild($child->cloneNode(true));
                }
                $figure->parentNode->insertBefore($newNode, $figure);
            }
            /** @var DOMElement $figure */
            $figure->remove();
        }

        $converter = new HtmlConverter([
            ...static::BASE_MARKDOWN_CONFIG,
            ...$config,
        ]);

        return $converter->convert($body->html());
    }

    /**
     * Converts the given HTLM into plain text.
     *
     * @since 3.4.0
     */
    public static function toPlainText(string $html): string
    {
        $body = (new Crawler("<html><body>$html</body></html>"))->filter('body');

        // Remove <figure>s, etc.
        $nodes = $body->filter('figure,img,video,embed,object,hr,br,button,acronym,abbr,sup');
        foreach ($nodes as $node) {
            /** @var DOMElement $node */
            $node->remove();
        }

        // Replace inline elements with their child nodes
        $nodes = $body->filter('a,b,strong,cite,code,em,i,q');
        foreach ($nodes as $node) {
            foreach ($node->childNodes as $child) {
                $node->parentNode->insertBefore($child->cloneNode(true), $node);
            }
            /** @var DOMElement $node */
            $node->remove();
        }

        // Replace headings with paragraphs
        $nodes = $body->filter('h1,h2,h3,h4,h5,h6');
        foreach ($nodes as $node) {
            $newNode = new DOMElement('p');
            $body->getNode(0)->appendChild($newNode);
            foreach ($node->childNodes as $child) {
                $newNode->appendChild($child->cloneNode(true));
            }
            $node->parentNode->insertBefore($newNode, $node);
            /** @var DOMElement $node */
            $node->remove();
        }

        $converter = new HtmlConverter([
            'strip_tags' => true,
            'strip_placeholder_links' => true,
            'hard_break' => true,
        ]);

        return html_entity_decode($converter->convert($body->html()));
    }

    protected string $rawContent;

    protected ?int $siteId;

    /**
     * Constructor
     */
    public function __construct(string $content, ?int $siteId = null)
    {
        // Save the raw content in case we need it later
        $this->rawContent = $content;
        $this->siteId = $siteId;

        // Parse the ref tags
        $content = Elements::parseRefs($content, $siteId);

        parent::__construct($content, 'UTF-8');
    }

    public function __get(string $name)
    {
        return match ($name) {
            'rawContent' => $this->getRawContent(),
            'parsedContent' => $this->getParsedContent(),
            'markdown' => $this->getMarkdown(),
            'plainText' => $this->getPlainText(),
            default => throw new LogicException(sprintf('Getting unknown property: %s::%s', static::class, $name)),
        };
    }

    /**
     * Returns the raw content, with reference tags still in-tact.
     */
    public function getRawContent(): string
    {
        return $this->rawContent;
    }

    /**
     * Returns the parsed content, with reference tags returned as HTML links.
     */
    public function getParsedContent(): string
    {
        return (string) $this;
    }

    /**
     * {@inheritdoc}
     *
     * @since 4.0.0
     */
    public function toHtml(): string
    {
        return $this->getParsedContent();
    }

    /**
     * Returns the content as Markdown.
     *
     * @param  array  $config  HtmlConverter configuration
     *
     * @since 3.2.0
     */
    public function getMarkdown(array $config = []): string
    {
        return static::toMarkdown($this->getParsedContent());
    }

    /**
     * Returns the content as Markdown.
     *
     * @since 3.2.0
     */
    public function getPlainText(): string
    {
        return static::toPlainText($this->getParsedContent());
    }
}
