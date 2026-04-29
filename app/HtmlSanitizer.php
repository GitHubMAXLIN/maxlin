<?php

declare(strict_types=1);

final class HtmlSanitizer
{
    private const MAX_HTML_BYTES = 1048576;

    private const ALLOWED_TAGS = [
        'p' => [],
        'br' => [],
        'strong' => [],
        'b' => [],
        'em' => [],
        'i' => [],
        'u' => [],
        's' => [],
        'ul' => [],
        'ol' => [],
        'li' => [],
        'blockquote' => [],
        'pre' => [],
        'code' => [],
        'h2' => [],
        'h3' => [],
        'h4' => [],
        'hr' => [],
        'span' => ['class'],
        'a' => ['href', 'title', 'target', 'rel'],
        'img' => ['src', 'alt', 'title', 'width', 'height'],
    ];

    private const DROP_WITH_CHILDREN = [
        'script', 'style', 'iframe', 'object', 'embed', 'svg', 'form', 'input', 'button', 'textarea', 'select', 'meta', 'link', 'base', 'template', 'math'
    ];

    public static function purify(string $html): string
    {
        if (mb_strlen($html, '8bit') > self::MAX_HTML_BYTES) {
            throw new InvalidArgumentException('正文内容过长。');
        }

        $html = str_replace("\0", '', $html);
        $doc = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $wrapped = '<?xml encoding="UTF-8"><div id="__safe_root__">' . $html . '</div>';
        $doc->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $doc->getElementById('__safe_root__');
        if (!$root instanceof DOMElement) {
            return '';
        }

        $result = '';
        foreach ($root->childNodes as $child) {
            $result .= self::cleanNode($child);
        }
        return trim($result);
    }

    private static function cleanNode(DOMNode $node): string
    {
        if ($node instanceof DOMText || $node instanceof DOMCdataSection) {
            return e($node->nodeValue ?? '');
        }

        if (!$node instanceof DOMElement) {
            return '';
        }

        $tag = strtolower($node->tagName);
        if (in_array($tag, self::DROP_WITH_CHILDREN, true)) {
            return '';
        }

        if (!array_key_exists($tag, self::ALLOWED_TAGS)) {
            $children = '';
            foreach ($node->childNodes as $child) {
                $children .= self::cleanNode($child);
            }
            return $children;
        }

        $children = '';
        foreach ($node->childNodes as $child) {
            $children .= self::cleanNode($child);
        }

        $attrs = self::cleanAttributes($node, $tag);
        if (in_array($tag, ['br', 'hr', 'img'], true)) {
            return '<' . $tag . $attrs . '>';
        }
        return '<' . $tag . $attrs . '>' . $children . '</' . $tag . '>';
    }

    private static function cleanAttributes(DOMElement $node, string $tag): string
    {
        $allowed = self::ALLOWED_TAGS[$tag] ?? [];
        if ($allowed === [] || !$node->hasAttributes()) {
            return '';
        }

        $safe = [];
        foreach ($node->attributes as $attr) {
            $name = strtolower($attr->name);
            $value = trim((string)$attr->value);
            if ($name === '' || str_starts_with($name, 'on') || !in_array($name, $allowed, true)) {
                continue;
            }
            if (preg_match('/[\x00-\x1F\x7F]/', $value)) {
                continue;
            }

            if ($tag === 'a' && $name === 'href') {
                if (!self::safeHref($value)) {
                    continue;
                }
            }

            if ($tag === 'img' && $name === 'src') {
                if (!self::safeImageSrc($value)) {
                    continue;
                }
            }

            if (in_array($name, ['width', 'height'], true)) {
                $dimension = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 8000]]);
                if (!is_int($dimension)) {
                    continue;
                }
                $value = (string)$dimension;
            }

            if ($tag === 'a' && $name === 'target') {
                $value = $value === '_blank' ? '_blank' : '';
                if ($value === '') {
                    continue;
                }
                $safe['rel'] = 'noopener noreferrer';
            }

            if ($name === 'class') {
                $value = preg_replace('/[^a-zA-Z0-9_\-\s]/', '', $value) ?? '';
                $value = trim(mb_substr($value, 0, 80, 'UTF-8'));
                if ($value === '') {
                    continue;
                }
            }

            $safe[$name] = $value;
        }

        if ($tag === 'a' && isset($safe['href']) && !isset($safe['rel'])) {
            $safe['rel'] = 'noopener noreferrer';
        }

        $out = '';
        foreach ($safe as $name => $value) {
            $out .= ' ' . $name . '="' . e($value) . '"';
        }
        return $out;
    }

    private static function safeHref(string $href): bool
    {
        if ($href === '' || str_starts_with($href, '#')) {
            return true;
        }
        if (str_starts_with($href, '/')) {
            return !str_starts_with($href, '//');
        }
        $scheme = strtolower((string)(parse_url($href, PHP_URL_SCHEME) ?? ''));
        return in_array($scheme, ['http', 'https'], true);
    }

    private static function safeImageSrc(string $src): bool
    {
        if (preg_match('#^/media\.php\?id=\d+$#', $src)) {
            return true;
        }
        if (preg_match('#^media\.php\?id=\d+$#', $src)) {
            return true;
        }
        return false;
    }

    public static function extractMediaImageIds(string $cleanHtml): array
    {
        preg_match_all('#(?:^|["\'])/?media\.php\?id=(\d+)(?:["\']|$)#', $cleanHtml, $matches);
        $ids = [];
        foreach ($matches[1] ?? [] as $id) {
            $value = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (is_int($value)) {
                $ids[$value] = $value;
            }
        }
        return array_values($ids);
    }
}
