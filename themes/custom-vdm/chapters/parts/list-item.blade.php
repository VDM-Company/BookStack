{{--This view display child pages in a list if pre-loaded onto a 'visible_pages' property,--}}
{{--To ensure that the pages have been loaded efficiently with permissions taken into account.--}}

<style>
.folder-card {
    border: 1px solid #e0e0e0;
    border-radius: 8px; /* Slightly more rounded corners */
    padding: 16px;
    margin-bottom: 16px;
    transition: all 0.2s ease;
    background-color: #fff;
    display: flex;
    flex-direction: column;
}

.folder-card:hover {
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
}

.folder-header-link {
    text-decoration: none;
    color: inherit;
    display: block; /* Make the link take up header space */
}

.folder-header {
    display: flex;
    align-items: center;
}

.folder-icon {
    margin-right: 12px;
    color: #0288d1; /* Default icon color */
    font-size: 28px; 
    display: flex;
    align-items: center;
}

.folder-title {
    font-size: 1.1em;
    font-weight: 500;
    margin: 0;
    flex: 1;
}

.folder-meta {
    font-size: 0.85em;
    color: #757575;
    margin-top: 4px;
}

.chapter-pages-container {
    padding-top: 12px; 
    border-top: 1px solid #f0f0f0; 
    margin-top: 12px; 
}

.chapter-contents-toggle {
    background: none;
    border: none;
    cursor: pointer;
    padding: 8px 0; 
    display: flex;
    align-items: center;
    width: 100%;
    text-align: left;
    font-size: 0.9em;
    color: #555;
}

.chapter-contents-toggle .icon {
    margin-right: 8px;
    transition: transform 0.2s ease-in-out; /* Handled by BookStack's JS */
}

.chapter-contents-list {
    margin-top: 8px;
    padding-left: 28px; 
}

/* Optional: if you want to show chapter excerpt */
/*.folder-description-excerpt {
    font-size: 0.9em;
    color: #666;
    margin-top: 8px;
    padding-bottom: 8px; // Add some space if dropdown follows 
} */
</style>

<div class="folder-card" data-entity-type="chapter" data-entity-id="{{$chapter->id}}">
    <a href="{{ $chapter->getUrl() }}" class="folder-header-link">
        <div class="folder-header">
            <div class="folder-icon">@icon('chapter', ['style' => 'width: 28px; height: 28px;'])</div>
            <div style="flex: 1;">
                <h4 class="folder-title break-text">{{ $chapter->name }}</h4>
                <div class="folder-meta break-text">{{ $chapter->getExcerpt(25) }}</div>
            </div>
        </div>
    </a>

    {{-- Optional: Display full chapter excerpt if needed, separate from meta --}}
    {{-- <div class="folder-description-excerpt break-text">
        {{ $chapter->getExcerpt() }}
    </div> --}}

    @if ($chapter->visible_pages->count() > 0)
        <div class="chapter-pages-container" component="chapter-contents">
            <button type="button"
                    refs="chapter-contents@toggle"
                    aria-expanded="false"
                    class="text-muted chapter-contents-toggle">
                @icon('caret-right', ['class' => 'icon', 'style' => 'width: 24px; height: 24px;']) 
                <span>{{ trans_choice('entities.x_pages', $chapter->visible_pages->count()) }}</span>
            </button>
            <div refs="chapter-contents@list" class="inset-list chapter-contents-list" style="display: none;">
                <div class="entity-list-item-children">
                    {{-- Use 'simple' style for a cleaner list inside dropdown --}}
                    @include('entities.list', ['entities' => $chapter->visible_pages, 'style' => 'simple'])
                </div>
            </div>
        </div>
    @endif
</div>