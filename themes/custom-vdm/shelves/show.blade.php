@extends('layouts.tri')

<style>
    .page-item {
    border: 1px solid #e0e0e0;
    border-radius: 4px;
    overflow: hidden;
    margin-bottom: 15px;
    }

    .page-toggle-label {
        background-color: #eeeeee;
        padding: 12px 16px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        font-weight: 500;
        cursor: pointer;
    }

    .page-toggle-label:hover {
        background-color: #e5e5e5;
    }

    .page-title {
        display: flex;
        align-items: center;
        gap: 6px;
    }

    .tag-pill {
        color: #fff;
        font-size: 0.75rem;
        padding: 2px 10px;
        border-radius: 999px;
        line-height: 1.4;
    }

    .edit-link {
        font-size: 0.75rem;
        margin-left: 4px;
    }

    .page-toggle-icon {
        font-size: 18px;
        transition: transform 0.25s ease;
    }

    .page-content {
        max-height: 0;
        overflow: hidden;
        transition: max-height 0.3s ease, padding 0.3s ease;
        padding: 0 16px;
    }

    .page-toggle-input{
        display: none;
    }

    .page-toggle-input:checked + .page-toggle-label .page-toggle-icon {
        transform: rotate(45deg);
    }
    .page-toggle-input:checked ~ .page-content {
        max-height: 2000px;
        padding: 16px;
        background-color: #ffffff;
    }
</style>

@push('social-meta')
    <meta property="og:description" content="{{ Str::limit($shelf->description, 100, '...') }}">
    @if($shelf->cover)
        <meta property="og:image" content="{{ $shelf->getBookCover() }}">
    @endif
@endpush

@include('entities.body-tag-classes', ['entity' => $shelf])

@section('body')

    <div class="mb-s print-hidden">
        @include('entities.breadcrumbs', ['crumbs' => [
            $shelf,
        ]])
    </div>

    <main class="card content-wrap">

        <div class="flex-container-row wrap v-center">
            <h1 class="flex fit-content break-text">{{ $shelf->name }}</h1>
            <div class="flex"></div>
            <div class="flex fit-content text-m-right my-m ml-m">
                @include('common.sort', $listOptions->getSortControlData())
            </div>
        </div>

        <div class="flex-container-row wrap v-center">
            <h2 class="list-heading mb-s">Important Updates</h2>
        
            @php
                // Fetch pages that own an “Update” tag
                $updatePages = \BookStack\Entities\Models\Page::query()
                    ->with(['book', 'tags'])
                    ->whereHas('tags', fn($q) => $q->whereRaw('LOWER(name) = ?', ['update']))
                    ->orderBy('created_at', 'desc')
                    ->get();
        
                $tagStyles = ['update' => '#077b70'];
            @endphp
        
            @if ($updatePages->isNotEmpty())
                <div class="page-list">
                    @foreach ($updatePages as $page)
                        <div class="page-item"> 
        
                            <input  type="checkbox"
                                    id="page-toggle-{{ $page->id }}"
                                    class="page-toggle-input">
        
                            <label  for="page-toggle-{{ $page->id }}"
                                    class="page-toggle-label">
        
                                <span class="page-title">
                                    @foreach ($page->tags as $tag)
                                        @php $key = strtolower($tag->name); @endphp
                                        @isset($tagStyles[$key])
                                            <span class="tag-pill"
                                                  style="background-color:{{ $tagStyles[$key] }};">
                                                Important Update
                                            </span>
                                        @endisset
                                    @endforeach
        
                                    {{-- “Important Updates > {Page} – {Book}” --}}
                                    &nbsp;{{ $page->name }}&nbsp;>&nbsp;{{ $page->book->name }}
        
                                    {{-- quick-edit link --}}
                                    @auth
                                        <a href="{{ $page->getUrl('/edit') }}" class="edit-link">Edit</a>
                                    @endauth
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
                <p class="text-muted mt-s">No pages tagged “Update” were found.</p>
            @endif
        </div>

        <div class="book-content">
            <div class="text-muted break-text">{!! $shelf->descriptionHtml() !!}</div>
            @if(count($sortedVisibleShelfBooks) > 0)
                @if($view === 'list')
                    <div class="entity-list">
                        @foreach($sortedVisibleShelfBooks as $book)
                            @include('books.parts.list-item', ['book' => $book])
                        @endforeach
                    </div>
                @else
                    <div class="grid third">
                        @foreach($sortedVisibleShelfBooks as $book)
                            @include('entities.grid-item', ['entity' => $book])
                        @endforeach
                    </div>
                @endif
            @else
                <div class="mt-xl">
                    <hr>
                    <p class="text-muted italic mt-xl mb-m">{{ trans('entities.shelves_empty_contents') }}</p>
                    <div class="icon-list inline block">
                        @if(userCan('book-create-all') && userCan('bookshelf-update', $shelf))
                            <a href="{{ $shelf->getUrl('/create-book') }}" class="icon-list-item text-book">
                                <span class="icon">@icon('add')</span>
                                <span>{{ trans('entities.books_create') }}</span>
                            </a>
                        @endif
                        @if(userCan('bookshelf-update', $shelf))
                            <a href="{{ $shelf->getUrl('/edit') }}" class="icon-list-item text-bookshelf">
                                <span class="icon">@icon('edit')</span>
                                <span>{{ trans('entities.shelves_edit_and_assign') }}</span>
                            </a>
                        @endif
                    </div>
                </div>
            @endif
        </div>
    </main>

@stop

@section('left')

    @if($shelf->tags->count() > 0)
        <div id="tags" class="mb-xl">
            @include('entities.tag-list', ['entity' => $shelf])
        </div>
    @endif

    <div id="details" class="mb-xl">
        <h5>{{ trans('common.details') }}</h5>
        <div class="blended-links">
            @include('entities.meta', ['entity' => $shelf, 'watchOptions' => null])
            @if($shelf->hasPermissions())
                <div class="active-restriction">
                    @if(userCan('restrictions-manage', $shelf))
                        <a href="{{ $shelf->getUrl('/permissions') }}" class="entity-meta-item">
                            @icon('lock')
                            <div>{{ trans('entities.shelves_permissions_active') }}</div>
                        </a>
                    @else
                        <div class="entity-meta-item">
                            @icon('lock')
                            <div>{{ trans('entities.shelves_permissions_active') }}</div>
                        </div>
                    @endif
                </div>
            @endif
        </div>
    </div>

    @if(count($activity) > 0)
        <div id="recent-activity" class="mb-xl">
            <h5>{{ trans('entities.recent_activity') }}</h5>
            @include('common.activity-list', ['activity' => $activity])
        </div>
    @endif
@stop

@section('right')
    <div class="actions mb-xl">
        <h5>{{ trans('common.actions') }}</h5>
        <div class="icon-list text-link">

            @if(userCan('book-create-all') && userCan('bookshelf-update', $shelf))
                <a href="{{ $shelf->getUrl('/create-book') }}" data-shortcut="new" class="icon-list-item">
                    <span class="icon">@icon('add')</span>
                    <span>{{ trans('entities.books_new_action') }}</span>
                </a>
            @endif

            @include('entities.view-toggle', ['view' => $view, 'type' => 'bookshelf'])

            <hr class="primary-background">

            @if(userCan('bookshelf-update', $shelf))
                <a href="{{ $shelf->getUrl('/edit') }}" data-shortcut="edit" class="icon-list-item">
                    <span>@icon('edit')</span>
                    <span>{{ trans('common.edit') }}</span>
                </a>
            @endif

            @if(userCan('restrictions-manage', $shelf))
                <a href="{{ $shelf->getUrl('/permissions') }}" data-shortcut="permissions" class="icon-list-item">
                    <span>@icon('lock')</span>
                    <span>{{ trans('entities.permissions') }}</span>
                </a>
            @endif

            @if(userCan('bookshelf-delete', $shelf))
                <a href="{{ $shelf->getUrl('/delete') }}" data-shortcut="delete" class="icon-list-item">
                    <span>@icon('delete')</span>
                    <span>{{ trans('common.delete') }}</span>
                </a>
            @endif

            @if(!user()->isGuest())
                <hr class="primary-background">
                @include('entities.favourite-action', ['entity' => $shelf])
            @endif

        </div>
    </div>
@stop