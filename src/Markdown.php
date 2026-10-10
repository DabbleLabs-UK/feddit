<?php
declare(strict_types=1);

require_once __DIR__ . '/third-party/parsedown/Parsedown.php';

/** Reddit-style body formatting. All content remains untrusted Markdown. */
final class FedditMarkdown extends Parsedown
{
    private int $renderDepth = 0;

    public function __construct()
    {
        $this->setSafeMode(true);
        // Preserve the line breaks already visible in historical Feddit text.
        $this->setBreaksEnabled(true);
        $this->InlineTypes['>'] = ['Spoiler'];
        $this->InlineTypes['^'] = ['Superscript'];
        $this->inlineMarkerList .= '>^';
        $this->specialCharacters[] = '^';
        $this->specialCharacters[] = '>';
    }

    protected function blockQuote($Line)
    {
        return str_starts_with($Line['text'], '>!') ? null : parent::blockQuote($Line);
    }

    protected function blockQuoteContinue($Line, array $Block)
    {
        return str_starts_with($Line['text'], '>!') ? null : parent::blockQuoteContinue($Line, $Block);
    }

    protected function inlineSpoiler($Excerpt)
    {
        if (!preg_match('/^>!(.+?)!</s', $Excerpt['text'], $match)) {
            return null;
        }
        // Build escaped element nodes, never concatenate user text into HTML.
        return ['extent' => strlen($match[0]), 'element' => [
            'name' => 'button',
            'attributes' => ['type' => 'button', 'class' => 'md-spoiler',
                'aria-expanded' => 'false', 'aria-label' => 'Reveal spoiler'],
            'element' => ['name' => 'span', 'attributes' => ['aria-hidden' => 'true'],
                'elements' => $this->lineElements($match[1], ['Spoiler', 'Link', 'Url', 'UrlTag', 'EmailTag', 'Image'])],
        ]];
    }

    protected function inlineSuperscript($Excerpt)
    {
        if (!preg_match('/^\^(?:\(([^()\n]+)\)|([^\s^<]+))/', $Excerpt['text'], $match)) {
            return null;
        }
        return ['extent' => strlen($match[0]), 'element' => [
            'name' => 'sup', 'elements' => $this->lineElements($match[1] !== '' ? $match[1] : $match[2]),
        ]];
    }

    protected function inlineImage($Excerpt)
    {
        $image = parent::inlineImage($Excerpt);
        if ($image === null) {
            return null;
        }
        $attributes = $image['element']['attributes'];
        $image['element'] = ['name' => 'a', 'text' => $attributes['alt'] ?: 'image',
            'attributes' => ['href' => $attributes['src'], 'title' => $attributes['title'] ?? null]];
        return $image;
    }

    protected function element(array $Element)
    {
        if (($Element['name'] ?? '') === 'a') {
            $href = html_entity_decode((string)($Element['attributes']['href'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            // Browsers normalize whitespace/control characters in schemes.
            // Allow web/mail links and ordinary relative URLs only.
            $safe = $href !== '' && !preg_match('/[\x00-\x20\x7f\\\\]/', $href);
            if (preg_match('/^([a-z][a-z0-9+.-]*):/i', $href, $scheme)) {
                $safe = $safe && in_array(strtolower($scheme[1]), ['http', 'https', 'mailto'], true);
            }
            if ($safe) {
                $Element['attributes']['href'] = $href;
                $Element['attributes']['rel'] = 'nofollow ugc noopener noreferrer';
            } else {
                unset($Element['name'], $Element['attributes']);
            }
        }
        return $this->guarded(fn() => parent::element($Element));
    }

    protected function lineElements($text, $nonNestables = [])
    {
        return $this->guarded(fn() => parent::lineElements($text, $nonNestables));
    }

    private function guarded(callable $render)
    {
        if ($this->renderDepth >= 64) {
            throw new LengthException('Markdown nesting exceeds the rendering limit.');
        }
        $this->renderDepth++;
        try {
            return $render();
        } finally {
            $this->renderDepth--;
        }
    }
}
