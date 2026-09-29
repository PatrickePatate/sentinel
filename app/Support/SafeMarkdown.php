<?php

namespace App\Support;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\ExternalLink\ExternalLinkExtension;
use League\CommonMark\Extension\Strikethrough\StrikethroughExtension;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\MarkdownConverter;
use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;

/**
 * Renders model output (untrusted: it may relay text found on a compromised machine) to HTML that is safe to
 * inject in the page, including through wire:stream.
 *  - raw HTML is escaped, never passed through;
 *  - javascript:/data: links are dropped;
 *  - images are never rendered (a remote image URL is a data-exfiltration channel): only the alt text is shown;
 *  - links open in a new tab with noopener/nofollow.
 */
class SafeMarkdown
{
    private static ?MarkdownConverter $converter = null;

    public static function render(string $markdown): string
    {
        return rtrim((string) self::converter()->convert($markdown));
    }

    private static function converter(): MarkdownConverter
    {
        if (self::$converter) {
            return self::$converter;
        }

        $environment = new Environment([
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 20,
            'external_link' => [
                'internal_hosts' => [],
                'open_in_new_window' => true,
                'html_class' => 'md-link',
                'nofollow' => 'external',
                'noopener' => 'external',
                'noreferrer' => 'external',
            ],
        ]);

        $environment->addExtension(new CommonMarkCoreExtension);
        $environment->addExtension(new StrikethroughExtension);
        $environment->addExtension(new TableExtension);
        $environment->addExtension(new ExternalLinkExtension);
        $environment->addRenderer(Image::class, new class implements NodeRendererInterface
        {
            public function render(Node $node, ChildNodeRendererInterface $childRenderer): string
            {
                /** @var Image $node */
                return e('[image: '.strip_tags($childRenderer->renderNodes($node->children())).']');
            }
        }, 100);

        return self::$converter = new MarkdownConverter($environment);
    }
}
