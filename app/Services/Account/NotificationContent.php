<?php

namespace App\Services\Account;

use DOMDocument;
use DOMNode;

final class NotificationContent
{
    public static function runs(string $message): array
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8"><body>'.SafeContent::html($message).'</body>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
            $body = $document->getElementsByTagName('body')->item(0);
            $runs = [];
            if ($body) {
                self::walk($body, $runs);
            }
            if ($runs) {
                $runs[0]['text'] = ltrim($runs[0]['text']);
                $last = array_key_last($runs);
                $runs[$last]['text'] = rtrim($runs[$last]['text']);
            }

            return array_values(array_filter($runs, fn (array $run) => $run['text'] !== ''));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private static function append(array &$runs, string $text, ?string $href): void
    {
        $text = str_replace("\u{00A0}", ' ', $text);
        $last = array_key_last($runs);
        if ($last !== null && $runs[$last]['href'] === $href) {
            $runs[$last]['text'] .= $text;
        } else {
            $runs[] = ['text' => $text, 'href' => $href];
        }
    }

    private static function walk(DOMNode $parent, array &$runs, ?string $href = null): void
    {
        foreach ($parent->childNodes as $node) {
            if ($node->nodeType === XML_TEXT_NODE) {
                self::append($runs, $node->textContent, $href);

                continue;
            }
            if ($node->nodeType !== XML_ELEMENT_NODE) {
                continue;
            }
            $block = in_array($node->nodeName, ['p', 'li', 'h2', 'h3', 'h4', 'blockquote', 'tr'], true);
            if ($block && $runs && ! str_ends_with($runs[array_key_last($runs)]['text'], "\n")) {
                self::append($runs, "\n", null);
            }
            if ($node->nodeName === 'li') {
                self::append($runs, '• ', null);
            }
            self::walk($node, $runs, $node->nodeName === 'a' ? $node->getAttribute('href') : $href);
            if ($block || $node->nodeName === 'br') {
                self::append($runs, "\n", null);
            } elseif (in_array($node->nodeName, ['td', 'th'], true)) {
                self::append($runs, ' ', null);
            }
        }
    }
}
