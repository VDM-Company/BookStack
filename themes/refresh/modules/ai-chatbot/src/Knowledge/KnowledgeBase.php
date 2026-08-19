<?php

namespace BookStackAiChat\Knowledge;

use BookStack\Entities\Models\Book;
use BookStack\Entities\Models\Chapter;
use BookStack\Entities\Models\Entity;
use BookStack\Entities\Models\Page;
use BookStack\Entities\Queries\EntityQueries;
use BookStackAiChat\Config;
use BookStack\Search\SearchOptions;
use BookStack\Search\SearchRunner;

/**
 * The tools the assistant can call, backed by BookStack's own content queries.
 *
 * Every lookup here goes through a `visible*` query or through SearchRunner,
 * both of which apply the permission rules of the currently authenticated user.
 * The assistant therefore cannot surface anything the asker could not reach by
 * browsing the wiki themselves.
 */
class KnowledgeBase
{
    /** @var array<string, array{type: string, name: string, url: string, book: string}> */
    protected array $sources = [];

    /** Page titles seen in search results, so status messages can name them. */
    protected array $pageNameCache = [];

    public function __construct(
        protected Config $config,
        protected SearchRunner $searchRunner,
        protected EntityQueries $queries,
    ) {
    }

    /**
     * Tool schemas advertised to the model.
     *
     * @return array<int, array<string, mixed>>
     */
    public function toolDefinitions(): array
    {
        return [
            [
                'name' => 'search_wiki',
                'description' =>
                    "Full-text search across this BookStack wiki. Returns matching shelves, books, "
                    . "chapters and pages with a short preview of each. Previews are truncated, so "
                    . "call read_page before relying on the detail of a page. Supports BookStack "
                    . "search syntax: \"quoted phrases\" for exact matches, [tagname] or [tag=value] "
                    . "for tag filters, and -term to exclude a term. Prefer several narrow searches "
                    . "over one broad one.",
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => [
                            'type' => 'string',
                            'description' => 'The search terms.',
                        ],
                        'type' => [
                            'type' => 'string',
                            'enum' => ['all', 'page', 'chapter', 'book', 'bookshelf'],
                            'description' => 'Restrict results to one content type. Defaults to "all".',
                        ],
                    ],
                    'required' => ['query'],
                ],
            ],
            [
                'name' => 'read_page',
                'description' =>
                    'Read the full text of a single wiki page, given the numeric id shown in '
                    . 'search results. Use this whenever the answer depends on the actual '
                    . 'contents of a page rather than just its title.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'page_id' => [
                            'type' => 'integer',
                            'description' => 'The numeric id of the page to read.',
                        ],
                    ],
                    'required' => ['page_id'],
                ],
            ],
            [
                'name' => 'list_books',
                'description' =>
                    'List the books in this wiki with their descriptions. Useful for orienting '
                    . 'yourself when a question is broad, or when search terms return nothing and '
                    . 'you need to work out what vocabulary this wiki actually uses.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => new \stdClass(),
                ],
            ],
        ];
    }

    /**
     * Run a tool and return the text handed back to the model.
     */
    public function execute(string $tool, array $input): string
    {
        return match ($tool) {
            'search_wiki' => $this->search(
                trim((string) ($input['query'] ?? '')),
                (string) ($input['type'] ?? 'all'),
            ),
            'read_page' => $this->readPage((int) ($input['page_id'] ?? 0)),
            'list_books' => $this->listBooks(),
            default => "Unknown tool \"{$tool}\".",
        };
    }

    /**
     * A short human-readable label describing a tool call, shown in the UI
     * while the assistant works.
     */
    public function describe(string $tool, array $input): string
    {
        return match ($tool) {
            'search_wiki' => trim((string) ($input['query'] ?? '')),
            'read_page' => (string) ($this->pageNameCache[(int) ($input['page_id'] ?? 0)] ?? ''),
            default => '',
        };
    }

    protected function search(string $query, string $type): string
    {
        if ($query === '') {
            return 'No query given. Provide search terms.';
        }

        $types = ['all', 'page', 'chapter', 'book', 'bookshelf'];
        $type = in_array($type, $types, true) ? $type : 'all';

        $result = $this->searchRunner->searchEntities(
            SearchOptions::fromString($query),
            $type,
            1,
            $this->config->searchResultLimit(),
        );

        /** @var \Illuminate\Support\Collection<int, Entity> $entities */
        $entities = $result['results'];

        if ($entities->isEmpty()) {
            return "No results for \"{$query}\". Either this wiki has nothing on the subject, or "
                . "the current user lacks permission to see it. Try different wording, or "
                . 'call list_books to see what this wiki covers.';
        }

        $lines = ["Found {$result['total']} result(s) for \"{$query}\"; showing " . $entities->count() . ':'];

        foreach ($entities as $entity) {
            $lines[] = '';
            $lines[] = $this->describeEntity($entity);
            $this->recordSource($entity);
        }

        return implode("\n", $lines);
    }

    protected function describeEntity(Entity $entity): string
    {
        $type = $entity::getType();
        $identity = $type === 'page' ? "page id={$entity->id}" : $type;

        $parts = ["## {$entity->name}  [{$identity}]"];

        $location = $this->locationOf($entity);
        if ($location !== '') {
            $parts[] = "Located in: {$location}";
        }

        $preview = ContentText::preview($entity, 400);
        if ($preview !== '') {
            $parts[] = "Preview: {$preview}";
        }

        if ($type === 'page') {
            $this->pageNameCache[$entity->id] = $entity->name;
        }

        return implode("\n", $parts);
    }

    protected function readPage(int $pageId): string
    {
        if ($pageId <= 0) {
            return 'Invalid page id.';
        }

        /** @var Page|null $page */
        $page = $this->queries->pages->visibleForContent()->find($pageId);

        if ($page === null) {
            return "No page with id {$pageId} is visible to the current user. It may not exist, "
                . 'or it may be restricted. Use search_wiki to find pages you can read.';
        }

        $this->pageNameCache[$page->id] = $page->name;
        $this->recordSource($page);

        $body = ContentText::pageBody($page, $this->config->pageCharLimit());
        $location = $this->locationOf($page);

        $header = "# {$page->name}";
        if ($location !== '') {
            $header .= "\n(Located in: {$location})";
        }

        if (trim($body) === '') {
            return "{$header}\n\nThis page is empty.";
        }

        return "{$header}\n\n{$body}";
    }

    protected function listBooks(): string
    {
        $books = $this->queries->books->visibleForList()
            ->orderBy('name', 'asc')
            ->limit(100)
            ->get();

        if ($books->isEmpty()) {
            return 'This wiki has no books visible to the current user.';
        }

        $lines = ['Books in this wiki:'];

        foreach ($books as $book) {
            $description = ContentText::preview($book, 200);
            $lines[] = $description === ''
                ? "- {$book->name}"
                : "- {$book->name} — {$description}";
        }

        return implode("\n", $lines);
    }

    /**
     * A breadcrumb-ish description of where an entity sits.
     *
     * Reads the `book`/`chapter` relations rather than re-querying them:
     * SearchRunner hydrates its results with parents already loaded, so going
     * through the relation accessor costs nothing for search hits and only one
     * query for a directly-fetched page.
     */
    protected function locationOf(Entity $entity): string
    {
        if (!$entity instanceof Page && !$entity instanceof Chapter) {
            return '';
        }

        $parts = [];

        try {
            $book = $entity->book;

            if ($book instanceof Book) {
                $parts[] = $book->name;
            }

            if ($entity instanceof Page && $entity->chapter_id) {
                $chapter = $entity->chapter;

                if ($chapter instanceof Chapter) {
                    $parts[] = $chapter->name;
                }
            }
        } catch (\Throwable) {
            // A missing parent should never stop an answer being given.
        }

        return implode(' > ', $parts);
    }

    protected function recordSource(Entity $entity): void
    {
        $key = $entity::getType() . ':' . $entity->id;

        if (isset($this->sources[$key])) {
            return;
        }

        try {
            $url = $entity->getUrl();
        } catch (\Throwable) {
            return;
        }

        $this->sources[$key] = [
            'type' => $entity::getType(),
            'name' => $entity->name,
            'url' => $url,
            'book' => $this->locationOf($entity),
        ];
    }

    /**
     * Every distinct entity touched while answering, for display as citations.
     *
     * @return array<int, array{type: string, name: string, url: string, book: string}>
     */
    public function sources(): array
    {
        return array_values($this->sources);
    }
}
