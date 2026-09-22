<?php
declare(strict_types=1);

namespace SolarSeo;

/** Byte-offset edits keep all untouched HTML, attributes and scripts exactly intact. */
final class Document
{
    public array $fields = [];
    public array $meta = ['title' => '', 'description' => '', 'keywords' => ''];
    private array $metaSpans = [];
    private int $headEnd = -1;
    private int $bodyEnd = -1;
    private array $anchors = [];

    public function __construct(public string $html)
    {
        $pattern = '~<!--[\s\S]*?-->|<![^>]*>|<\?.*?\?>|</?[a-zA-Z][^>"\']*(?:(?:"[^"]*"|\'[^\']*\')[^>"\']*)*>~s';
        preg_match_all($pattern, $html, $matches, PREG_OFFSET_CAPTURE);
        $stack = []; $cursor = 0; $raw = null;
        foreach ($matches[0] as [$tag, $offset]) {
            if ($raw !== null && !preg_match('~^</' . $raw . '\s*>~i', $tag)) continue;
            $this->text($cursor, $offset, $stack);
            $cursor = $offset + strlen($tag);
            if (!preg_match('~^<( /)?~x', $tag) || !preg_match('~^<(/?)([a-zA-Z][\w:-]*)~', $tag, $m)) continue;
            $name = strtolower($m[2]); $closing = $m[1] === '/';
            if ($closing) {
                if ($name === 'head') $this->headEnd = $offset;
                if ($name === 'body') $this->bodyEnd = $offset;
                for ($i = count($stack) - 1; $i >= 0; $i--) {
                    if ($stack[$i]['name'] === $name) { $stack = array_slice($stack, 0, $i); break; }
                }
                $raw = null;
                continue;
            }
            $attrs = self::attributes($tag, $offset);
            if ($name === 'meta') {
                $key = strtolower($attrs['name']['value'] ?? $attrs['property']['value'] ?? '');
                $type = match ($key) {
                    'description', 'og:description', 'twitter:description' => 'description',
                    'keywords' => 'keywords',
                    'og:title', 'twitter:title' => 'title',
                    default => null
                };
                if ($type !== null) {
                    $this->metaSpans[$type][] = ['start' => $offset, 'length' => strlen($tag), 'tag' => $tag, 'attrs' => $attrs];
                    if ($key === $type) $this->meta[$type] = $attrs['content']['value'] ?? '';
                }
            }
            if (in_array($name, ['area','base','br','col','embed','hr','img','input','link','meta','param','source','track','wbr']) || str_ends_with($tag, '/>')) continue;
            $parent = end($stack) ?: [];
            $group = $parent['group'] ?? 'page';
            if (($name === 'header' || $name === 'footer') && (!in_array('main', array_column($stack, 'name')) || str_contains($attrs['class']['value'] ?? '', 'site-'))) $group = 'chrome';
            $hidden = ($parent['hidden'] ?? false) || isset($attrs['hidden']) || ($attrs['aria-hidden']['value'] ?? '') === 'true' || preg_match('~display\s*:\s*none|visibility\s*:\s*hidden~i', $attrs['style']['value'] ?? '');
            $stack[] = ['name' => $name, 'group' => $group, 'hidden' => $hidden, 'attrs' => $attrs];
            if (in_array($name, ['script', 'style', 'textarea', 'title'])) $raw = $name;
        }
    }

    private static function attributes(string $tag, int $base): array
    {
        preg_match_all('~\s+([\w:-]+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+)))?~', $tag, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL);
        $attrs = [];
        foreach ($matches as $m) {
            $value = $m[2][0] ?? $m[3][0] ?? $m[4][0] ?? '';
            $attrs[strtolower($m[1][0])] = ['value' => html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'), 'start' => $base + $m[0][1], 'length' => strlen($m[0][0])];
        }
        return $attrs;
    }

    private function text(int $start, int $end, array $stack): void
    {
        if ($end <= $start || !$stack) return;
        $raw = substr($this->html, $start, $end - $start);
        $names = array_column($stack, 'name');
        $parent = end($stack);
        if ($parent['name'] === 'title') {
            $this->meta['title'] = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $this->metaSpans['title'][] = ['start' => $start, 'length' => strlen($raw), 'text' => true];
            return;
        }
        if (!in_array('body', $names) || array_intersect($names, ['script','style','svg','math','template','noscript','textarea','select']) || $parent['hidden']) return;
        preg_match('~^(\s*)([\s\S]*?)(\s*)$~u', $raw, $m);
        if (!isset($m[2]) || trim(html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8')) === '') return;
        $start += strlen($m[1]);
        $value = html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $id = 't' . $start;
        $this->fields[$id] = ['id' => $id, 'label' => strtoupper($parent['name']), 'group' => $parent['group'], 'value' => $value, 'multiline' => mb_strlen($value) > 110 || in_array($parent['name'], ['p','blockquote']), 'start' => $start, 'length' => strlen($m[2])];
        if (isset($parent['attrs']['data-count'])) {
            $this->fields[$id]['counter'] = $parent['attrs']['data-count'];
            $this->fields[$id]['value'] = $parent['attrs']['data-count']['value'];
            $this->fields[$id]['label'] = 'COUNTER (' . ($parent['attrs']['data-suffix']['value'] ?? 'number') . ')';
            $this->fields[$id]['numeric'] = true;
        }
        if (!array_intersect($names, ['a','button','form'])) $this->anchors[$id] = $start + strlen($m[2]);
    }

    public function editable(): array
    {
        return array_map(fn($f) => array_diff_key($f, ['start' => 1, 'length' => 1, 'counter' => 1]) + ['canLink' => isset($this->anchors[$f['id']]) && !isset($f['counter'])], array_values($this->fields));
    }

    public static function plain(mixed $value, int $max, bool $required = false): string
    {
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || mb_strlen($value) > $max || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) || ($required && trim($value) === '')) throw new Problem(422, 'Enter valid text within the field length limit.');
        return $value;
    }

    public static function escape(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'); }

    public function render(array $input, ?array $link = null): string
    {
        if ($this->headEnd < 0 || $this->bodyEnd < 0) throw new Problem(422, 'This file is not a complete editable HTML page.');
        $edits = [];
        if (!is_array($input['texts'] ?? null) || !is_array($input['meta'] ?? null)) throw new Problem(422, 'The page content is missing.');
        foreach ($input['texts'] as $id => $value) {
            if (!isset($this->fields[$id])) throw new Problem(409, 'The page structure changed. Reload before saving.');
            $f = $this->fields[$id];
            $value = self::plain($value, 12000);
            if ($value !== $f['value']) {
                if (isset($f['counter'])) {
                    if (!preg_match('~^\d{1,9}(?:\.\d{1,4})?$~D', $value)) throw new Problem(422, 'Animated counters must be positive numbers with up to nine digits.');
                    $attr = $f['counter'];
                    $edits[] = [$attr['start'], $attr['length'], ' data-count="' . $value . '"'];
                }
                $edits[] = [$f['start'], $f['length'], self::escape($value)];
            }
        }
        $append = '';
        foreach (['title' => 200, 'description' => 500, 'keywords' => 1000] as $key => $max) {
            $value = self::plain($input['meta'][$key] ?? null, $max, $key === 'title');
            if ($value === $this->meta[$key]) continue;
            $hasPrimary = false;
            foreach ($this->metaSpans[$key] ?? [] as $span) {
                if (isset($span['text'])) { $hasPrimary = true; $replacement = self::escape($value); }
                else {
                    $attrs = $span['attrs'];
                    if (strtolower($attrs['name']['value'] ?? '') === $key) $hasPrimary = true;
                    if (isset($attrs['content'])) {
                        $a = $attrs['content'];
                        $replacement = substr_replace($span['tag'], ' content="' . self::escape($value) . '"', $a['start'] - $span['start'], $a['length']);
                    } else $replacement = preg_replace('~/?>$~', ' content="' . self::escape($value) . '" />', $span['tag']);
                }
                $edits[] = [$span['start'], $span['length'], $replacement];
            }
            if (!$hasPrimary) $append .= $key === 'title' ? '<title>' . self::escape($value) . "</title>\n" : '<meta name="' . $key . '" content="' . self::escape($value) . '" />' . "\n";
        }
        if ($append !== '') $edits[] = [$this->headEnd, 0, $append];
        if ($link !== null) {
            $id = $link['after'] ?? '';
            if (!isset($this->anchors[$id]) || isset($this->fields[$id]['counter'])) throw new Problem(422, 'Choose a text location outside an existing link, counter or form.');
            $label = self::plain($link['label'] ?? null, 160, true);
            $edits[] = [$this->anchors[$id], 0, ' <a href="/' . self::escape($link['target']) . '">' . self::escape($label) . '</a>'];
        }
        usort($edits, static fn($a, $b) => $b[0] <=> $a[0]);
        $html = $this->html;
        foreach ($edits as [$start, $length, $value]) $html = substr_replace($html, $value, $start, $length);
        return $html;
    }
}
