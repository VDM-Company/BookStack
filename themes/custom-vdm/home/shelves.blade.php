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
    /* Page List Styles */
    .page-list {
        margin-top: 15px;
    }
    
    .page-item {
        margin-bottom: 15px;
        border: 1px solid #e0e0e0;
        border-radius: 4px;
        overflow: hidden;
    }
    
    .chapter-description {
        margin-bottom: 20px;
    }
</style>

<style>
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

    /* Tag Styles */
    .label-info {
        background-color: #077b70;
        border: none;
        color: white;
        padding: 4px 8px;
    
        text-align: center;
        text-decoration: none;
        display: inline-block;
        font-size: 1rem;
        font-weight: 500;

        margin: 4px 2px;
        cursor: pointer;
        border-radius: 20px;
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

                    // Move the chapter named "General" to the first position if it exists
                    $generalChapterIndex = $chapters->search(function($chapter) {
                        return strtolower($chapter->name) === 'general';
                    });
                    
                    if ($generalChapterIndex !== false && $generalChapterIndex !== 0) {
                        $generalChapter = $chapters->pull($generalChapterIndex);
                        $chapters->prepend($generalChapter);
                    }
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
                        
                            <a href="{{ url('/books/important-updates/') }}" style="text-decoration: none;" target="_blank"><label class="tab-label">View All Contents</label></a>
                        
                    </div>
                    <div class="tabs-content">
                        @foreach($chapters as $index => $chapter)
                            <div class="tab-pane" id="tab-{{ $chapter->id }}" data-tab-id="tab-for-input-{{ $chapter->id }}" style="{{ $index === 0 ? 'display: block;' : '' }}">
                                @php
                                    $pages = \BookStack\Entities\Models\Page::query()
                                        ->with('tags')
                                        ->where('chapter_id', $chapter->id)
                                        ->orderBy('created_at', 'desc')
                                        ->take(5)
                                        ->get();          
                                        
                                    $tagStyles = [
                                        'update' => '#077b70',
                                    ];
                                @endphp                                
                                @if(count($pages) > 0)
                                    <div class="page-list">
                                        @foreach($pages as $page)
                                            <div class="page-item">
                                                <input type="checkbox" id="page-toggle-{{ $page->id }}" class="page-toggle-input">
                                                <label for="page-toggle-{{ $page->id }}" class="page-toggle-label">
                                                    <span class="page-title">

                                                        @if($page->tags->isNotEmpty())
                                                        @foreach($page->tags as $tag)
                                                          @php $key = strtolower($tag->name); @endphp
                                                  
                                                          @if(array_key_exists($key, $tagStyles))
                                                            <span
                                                              class="tag label label-info"
                                                              style="background-color: {{ $tagStyles[$key] }};">
                                                              {{ $tag->name }}
                                                            </span>
                                                          @endif
                                                        @endforeach
                                                      @endif

                                                      {{ $page->name }}
                                                  
                                                      @if(auth()->check())
                                                        <a href="{{ url('/books/important-updates/page/' . $page->slug . '/edit') }}"
                                                           class="btn btn-primary btn-sm"
                                                           style="margin-top: 8px;">
                                                          Edit
                                                        </a>
                                                      @endif
                                                      
                                                    </span>
                                                  
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

    <!-- Search by Category Section -->
    <div class="card content-wrap mb-xl">
        <h2 class="list-heading">Categories</h2><br/>
        <div class="grid second gap-sm" style="display: grid; grid-template-columns: repeat(4, 1fr); grid-auto-rows: minmax(0, auto);">
            @php
                $categoryBooks = \BookStack\Entities\Models\Book::query()
                    ->whereHas('shelves', function($query) {
                        $query->where('name', 'categories');
                    })
                    ->get();

                    // Move the book named "General" to the first position if it exists
                    $generalCategoryIndex = $categoryBooks->search(function($categoryBook) {
                        return strtolower($categoryBook->name) === 'general';
                    });
                    
                    if ($generalCategoryIndex !== false && $generalCategoryIndex !== 0) {
                        $categoryBook = $categoryBooks->pull($generalCategoryIndex);
                        $categoryBooks->prepend($categoryBook);
                    }
            @endphp

            @foreach($categoryBooks as $book)
            <a href="{{ url('/books/' . $book->slug) }}" class="text-link" target="_blank">
                <div class="category-card text-center">
                    
                        {{-- <div class="mb-s">
                            @if($book->cover_image)
                                <img src="{{ $book->cover_image }}" alt="{{ $book->name }}" style="width: 96px; height: 96px; object-fit: cover;">
                            @else
                                <img src="/images/icons/book.svg" alt="{{ $book->name }}" style="width: 96px; height: 96px;">
                            @endif
                        </div> --}}

                        {{ $book->name }}
                    
                </div>
            </a>
            @endforeach
        </div>
    </div>

    @include('shelves.parts.list', ['shelves' => $shelves, 'view' => $view])
@stop

@section('left')
    @include('home.parts.sidebar')
@stop

@section('right')
    <div class="actions mb-xl">
        <h5>{{ trans('common.actions') }}</h5>
        <div class="icon-list text-link">
            @if(user()->can('bookshelf-create-all'))
                <a href="{{ url("/create-shelf") }}" class="icon-list-item">
                    <span>@icon('add')</span>
                    <span>{{ trans('entities.shelves_new_action') }}</span>
                </a>
            @endif
            @include('entities.view-toggle', ['view' => $view, 'type' => 'bookshelves'])
            <a href="{{ url('/tags') }}" class="icon-list-item">
                <span>@icon('tag')</span>
                <span>{{ trans('entities.tags_view_tags') }}</span>
            </a>
            @include('home.parts.expand-toggle', ['classes' => 'text-link', 'target' => '.entity-list.compact .entity-item-snippet', 'key' => 'home-details'])
            @include('common.dark-mode-toggle', ['classes' => 'icon-list-item text-link'])
        </div>
    </div>

    <script nonce="{{ $cspNonce ?? '' }}">
        // Tab switching functionality
        document.addEventListener('DOMContentLoaded', function() {
            const tabInputs = document.querySelectorAll('.tab-input');
            const tabPanes = document.querySelectorAll('.tab-pane');
            
            // Add click event listeners to tab inputs
            tabInputs.forEach(input => {
                input.addEventListener('change', function() {
                    // Hide all tab panes
                    tabPanes.forEach(pane => {
                        pane.style.display = 'none';
                    });
                    
                    // Show the selected tab pane
                    const targetLabel = document.querySelector(`label[for="${this.id}"]`);
                    const targetId = targetLabel.getAttribute('data-tab-target');
                    const targetPane = document.getElementById(targetId);
                    
                    if (targetPane) {
                        targetPane.style.display = 'block';
                    }
                });
            });
        });
    </script>
@stop