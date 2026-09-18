<?php

namespace App\Services\Account;

use DOMDocument;
use DOMNode;

final class SafeContent
{
    public static function html(?string $value): string
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8"><body>'.($value ?? '').'</body>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
            $body = $document->getElementsByTagName('body')->item(0);

            return $body ? self::children($body) : '';
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private static function children(DOMNode $parent): string
    {
        $html = '';
        foreach ($parent->childNodes as $node) {
            if ($node->nodeType === XML_TEXT_NODE) {
                $html .= htmlspecialchars($node->textContent, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

                continue;
            }
            if ($node->nodeType !== XML_ELEMENT_NODE || in_array($node->nodeName, ['script', 'style', 'iframe', 'object', 'svg', 'form', 'input'], true)) {
                continue;
            }
            $text = self::children($node);
            $tag = $node->nodeName;
            if ($tag === 'a') {
                $href = self::link($node->getAttribute('href'));
                $html .= $href ? '<a href="'.htmlspecialchars($href, ENT_QUOTES, 'UTF-8').'" target="_blank" rel="noopener noreferrer">'.$text.'</a>' : $text;
            } elseif (in_array($tag, ['p', 'br', 'strong', 'b', 'em', 'i', 'u', 'ul', 'ol', 'li', 'h2', 'h3', 'h4', 'blockquote', 'table', 'thead', 'tbody', 'tr', 'th', 'td'], true)) {
                $html .= '<'.$tag.'>'.$text.($tag === 'br' ? '' : '</'.$tag.'>');
            } else {
                $html .= $text;
            }
        }

        return $html;
    }

    private static function link(string $href): ?string
    {
        if (preg_match('/[\x00-\x20\\\\]/', $href)) {
            return null;
        }
        $host = parse_url($href, PHP_URL_HOST);
        if ($host && $host === parse_url(config('app.url'), PHP_URL_HOST)) {
            $href = (string) parse_url($href, PHP_URL_PATH);
        }
        if (preg_match('~^/panel/agreement-doc/(\d+)$~', $href, $match)) {
            return '/panel/documents/'.$match[1];
        }
        if ($href === '/panel/user-alert') {
            return '/panel/notifications';
        }
        if (preg_match('~^/panel/(dashboard|profile|notifications|documents|tournaments|team|templates|rating|exams|settings|about)(/[^?#]*)?$~', $href)) {
            return $href;
        }
        if (filter_var($href, FILTER_VALIDATE_URL) && parse_url($href, PHP_URL_SCHEME) === 'https' && ! parse_url($href, PHP_URL_USER)) {
            return $href;
        }

        return null;
    }
}
