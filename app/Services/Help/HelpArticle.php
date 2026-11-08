<?php

namespace App\Services\Help;

/** One parsed help article. Blocks are plain arrays so they serialize straight to Inertia. */
final class HelpArticle
{
    /**
     * @param  list<string>  $pages  route names whose `?` panel lists this article
     * @param  list<string>  $keywords
     * @param  list<string>  $related  article keys ("topic/slug")
     * @param  list<array<string, mixed>>  $blocks
     */
    public function __construct(
        public readonly string $topic,
        public readonly string $slug,
        public readonly string $title,
        public readonly string $summary,
        public readonly ?string $module,
        public readonly array $pages,
        public readonly array $keywords,
        public readonly array $related,
        public readonly array $blocks,
    ) {}

    public function key(): string
    {
        return $this->topic.'/'.$this->slug;
    }

    public function href(): string
    {
        return '/help/'.$this->key();
    }

    public function stepCount(): int
    {
        return count(array_filter($this->blocks, fn (array $b): bool => $b['type'] === 'step'));
    }

    /** Reading estimate: about two steps a minute, never less than one. */
    public function minutes(): int
    {
        return max(1, (int) ceil($this->stepCount() / 2));
    }

    /** @return list<string> */
    public function headings(): array
    {
        return array_values(array_map(
            fn (array $b): string => $b['heading'],
            array_filter($this->blocks, fn (array $b): bool => $b['type'] === 'step'),
        ));
    }

    public function withBlocks(array $blocks): self
    {
        return new self($this->topic, $this->slug, $this->title, $this->summary, $this->module, $this->pages, $this->keywords, $this->related, $blocks);
    }

    /** @return array{key:string, topic:string, slug:string, title:string, summary:string, minutes:int, href:string} */
    public function summary(): array
    {
        return [
            'key' => $this->key(),
            'topic' => $this->topic,
            'slug' => $this->slug,
            'title' => $this->title,
            'summary' => $this->summary,
            'minutes' => $this->minutes(),
            'href' => $this->href(),
        ];
    }
}
