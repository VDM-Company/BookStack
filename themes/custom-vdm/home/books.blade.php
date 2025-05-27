@extends('layouts.tri')

@section('body')
<style>
    .category-card {
        padding: 0.75rem;
        border: 1px solid #e0e0e0;
        border-radius: 8px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        transition: box-shadow 0.3s ease-in-out;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
    }
    .category-card:hover {
        box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    }
    .mb-s {
        margin-bottom: 0.5rem !important;
    }
    
    .category-card a {
        text-decoration: none;
        color: inherit;
        display: contents; /* Makes the <a> take up the space of its children for clickability */
    }

    .card.content-wrap{
        min-height: 20vh;
    }    

        /* CSS-only Tab System */
        .tab-input {
        display: none; /* Hide radio buttons */
    }
    
    /* Style for tab buttons */
    .tab-label {
        padding: 10px 20px;
        background: none;
        border: none;
        border-bottom: 3px solid transparent;
        cursor: pointer;
        font-weight: 500;
        color: #666;
        transition: all 0.3s ease;
        white-space: nowrap;
        display: inline-block;
    }
    
    .tab-label:hover {
        color: var(--color-primary);
    }
    
    /* When radio button is checked, style its label */
    .tab-input:checked + .tab-label {
        color: var(--color-primary);
        border-bottom-color: var(--color-primary);
    }
    
    /* Hide all tab panes by default */
    .tab-pane {
        display: none;
        padding: 10px 0;
        width: 100%;
    }
    
    /* Hide all tab panes by default */
    .tab-pane {
        display: none;
    }
    
    /* First tab is visible by default (will be controlled by JS) */
    .tabs-content .tab-pane:first-child {
        display: block;
    }
    
    /* Fix for tab layout */
    .tabs-header {
        display: flex;
        flex-wrap: nowrap;
        border-bottom: 1px solid #e0e0e0;
        margin-bottom: 20px;
        overflow-x: auto;
        padding-bottom: 1px;
        position: relative;
    }
    
    .tabs-container {
        margin-top: 20px;
        position: relative;
        display: flex;
        flex-direction: column;
    }
    
    .tabs-content {
        margin-top: 10px;
        position: relative;
        width: 100%;
    }
    
    /* CSS-only Page Toggle */
    .page-toggle-input {
        display: none; /* Hide checkbox */
    }
    
    .page-toggle-label {
        cursor: pointer;
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 12px 15px;
        background-color: #f5f5f5;
        font-weight: 500;
    }
    
    .page-toggle-label:hover {
        background-color: #eaeaea;
    }
    
    .page-toggle-icon {
        font-size: 18px;
        transition: transform 0.3s ease;
    }
    
    .page-content {
        padding: 0;
        max-height: 0;
        overflow: hidden;
        transition: max-height 0.3s ease, padding 0.3s ease;
    }
    
    /* When checkbox is checked, show content and rotate icon */
    .page-toggle-input:checked ~ .page-content {
        padding: 15px;
        max-height: 2000px; /* Arbitrary large value */
    }
    
    .page-toggle-input:checked + .page-toggle-label .page-toggle-icon {
        transform: rotate(45deg);
    }
</style>

    <div class="card content-wrap mb-xl">
        <h2 class="list-heading">Important Updates</h2>
        <div class="page-content" style="max-height: none; padding: 15px;">
            @php
                // Get the "Important Updates" book
                $updatesBook = \BookStack\Entities\Models\Book::query()
                    ->where('slug', 'important-updates')
                    ->first();
                
                // Get all chapters in the book
                $chapters = [];
                if ($updatesBook) {
                    $chapters = \BookStack\Entities\Models\Chapter::query()
                        ->where('book_id', $updatesBook->id)
                        ->orderBy('priority', 'asc')
                        ->get();
                }
            @endphp

            @if($updatesBook && count($chapters) > 0)
                <!-- Tab Navigation with CSS-only toggle -->
                <div class="tabs-container">
                    <div class="tabs-header">
                        @foreach($chapters as $index => $chapter)
                            <input type="radio" name="tabs" id="tab-input-{{ $chapter->id }}" class="tab-input" {{ $index === 0 ? 'checked' : '' }}>
                            <label for="tab-input-{{ $chapter->id }}" class="tab-label" data-tab-target="tab-{{ $chapter->id }}">{{ $chapter->name }}</label>
                        @endforeach
                    </div>
                    <div class="tabs-content">
                        @foreach($chapters as $index => $chapter)
                            <div class="tab-pane" id="tab-{{ $chapter->id }}" data-tab-id="tab-for-input-{{ $chapter->id }}" style="{{ $index === 0 ? 'display: block;' : '' }}">
                                @php
                                    $pages = \BookStack\Entities\Models\Page::query()
                                        ->where('chapter_id', $chapter->id)
                                        ->orderBy('priority', 'asc')
                                        ->get();
                                @endphp
                                
                                @if(count($pages) > 0)
                                    <div class="page-list">
                                        @foreach($pages as $page)
                                            <div class="page-item">
                                                <input type="checkbox" id="page-toggle-{{ $page->id }}" class="page-toggle-input">
                                                <label for="page-toggle-{{ $page->id }}" class="page-toggle-label">
                                                    <span class="page-title">{{ $page->name }}</span>
                                                    <span class="page-toggle-icon">+</span>
                                                </label>
                                                <div class="page-content">
                                                    {!! $page->html !!}
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                @else
                                    <p class="text-muted">No pages found in this chapter.</p>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @else
                <div class="callout info">
                    <p><strong>No updates available</strong></p>
                    <p>To display important updates here, create a book with the slug "important-updates" in your BookStack wiki. Add chapters to organize different categories of updates, and pages within those chapters for individual update items.</p>
                    <p>Updates will automatically appear in this section once they are added to the book.</p>
                </div>
            @endif
        </div>
    </div>

    @include('books.parts.list', ['books' => $books, 'view' => $view])
@stop

@section('left')
    @include('home.parts.sidebar')
@stop

@section('right')
    <div class="actions mb-xl">
        <h5>{{ trans('common.actions') }}</h5>
        <div class="icon-list text-link">
            @if(user()->can('book-create-all'))
                <a href="{{ url("/create-book") }}" class="icon-list-item">
                    <span>@icon('add')</span>
                    <span>{{ trans('entities.books_create') }}</span>
                </a>
            @endif
            @include('entities.view-toggle', ['view' => $view, 'type' => 'books'])
            <a href="{{ url('/tags') }}" class="icon-list-item">
                <span>@icon('tag')</span>
                <span>{{ trans('entities.tags_view_tags') }}</span>
            </a>
            @include('home.parts.expand-toggle', ['classes' => 'text-link', 'target' => '.entity-list.compact .entity-item-snippet', 'key' => 'home-details'])
            @include('common.dark-mode-toggle', ['classes' => 'icon-list-item text-link'])
        </div>
    </div>
@stop