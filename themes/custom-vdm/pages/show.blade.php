<style>
    .update-page-label {
        margin-top: 2rem;
       background-color: #077b70;
       border: none;
       color: white;
       padding: 4px 8px;    
       border-radius: 20px;
   }
   
    /* Revision list styles */
    .revision-list {
        max-height: 300px;
        overflow-y: auto;
        border: 1px solid #ddd;
        border-radius: 4px;
        margin-bottom: 1rem;
    }
    .revision-list-item {
        padding: 8px 12px;
        border-bottom: 1px solid #eee;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }
    .revision-list-item > .additional-links {
        display: none;
    }

    .revision-list-item:hover {
        background-color: #f7f7f7;        
    }
    .revision-list-item.active {
        background-color: #e7f3ff;
    }

    .revision-list-item:hover > .additional-links {
        display: flex;
    }
</style>

@extends('layouts.tri')

@push('social-meta')
    <meta property="og:description" content="{{ Str::limit($page->text, 100, '...') }}">
@endpush

@include('entities.body-tag-classes', ['entity' => $page])

@section('body')

    <div class="mb-m print-hidden">
        @include('entities.breadcrumbs', ['crumbs' => [
            $page->book,
            $page->hasChapter() ? $page->chapter : null,
            $page,
        ]])
    </div>

    <main class="content-wrap card">

        
        @if(isset($page->tags) && $page->tags->isNotEmpty())
            @foreach($page->tags as $tag)
                @if(strtolower($tag->name) === 'update')
                <br/>
                    <span class="update-page-label">Important Updates</span>
                @break
                @endif
            @endforeach
        @endif


        <div component="page-display"
             option:page-display:page-id="{{ $page->id }}"
             class="page-content clearfix">

            @include('pages.parts.page-display')
        </div>
        @include('pages.parts.pointer', ['page' => $page])
    </main>

    @include('entities.sibling-navigation', ['next' => $next, 'previous' => $previous])

    @if ($commentTree->enabled())
        @if(($previous || $next))
            <div class="px-xl print-hidden">
                <hr class="darker">
            </div>
        @endif

        <div class="comments-container mb-l print-hidden">
            @include('comments.comments', ['commentTree' => $commentTree, 'page' => $page])
            <div class="clearfix"></div>
        </div>
    @endif
@stop

@section('left')

    @if($page->tags->count() > 0)
        <section>
            @include('entities.tag-list', ['entity' => $page])
        </section>
    @endif

    @if ($page->attachments->count() > 0)
        <div id="page-attachments" class="mb-l">
            <h5>{{ trans('entities.pages_attachments') }}</h5>
            <div class="body">
                @include('attachments.list', ['attachments' => $page->attachments])
            </div>
        </div>
    @endif

    @if (isset($pageNav) && count($pageNav))
        <nav id="page-navigation" class="mb-xl" aria-label="{{ trans('entities.pages_navigation') }}">
            <h5>{{ trans('entities.pages_navigation') }}</h5>
            <div class="body">
                <div class="sidebar-page-nav menu">
                    @foreach($pageNav as $navItem)
                        <li class="page-nav-item h{{ $navItem['level'] }}">
                            <a href="{{ $navItem['link'] }}" class="text-limit-lines-1 block">{{ $navItem['text'] }}</a>
                            <div class="link-background sidebar-page-nav-bullet"></div>
                        </li>
                    @endforeach
                </div>
            </div>
        </nav>
    @endif

    @include('entities.book-tree', ['book' => $book, 'sidebarTree' => $sidebarTree])
@stop

@section('right')
    <div id="page-details" class="entity-details mb-xl">
        <h5>{{ trans('common.details') }}</h5>
        <div class="blended-links">
            @include('entities.meta', ['entity' => $page, 'watchOptions' => $watchOptions])

            @if($book->hasPermissions())
                <div class="active-restriction">
                    @if(userCan('restrictions-manage', $book))
                        <a href="{{ $book->getUrl('/permissions') }}" class="entity-meta-item">
                            @icon('lock')
                            <div>{{ trans('entities.books_permissions_active') }}</div>
                        </a>
                    @else
                        <div class="entity-meta-item">
                            @icon('lock')
                            <div>{{ trans('entities.books_permissions_active') }}</div>
                        </div>
                    @endif
                </div>
            @endif

            @if($page->chapter && $page->chapter->hasPermissions())
                <div class="active-restriction">
                    @if(userCan('restrictions-manage', $page->chapter))
                        <a href="{{ $page->chapter->getUrl('/permissions') }}" class="entity-meta-item">
                            @icon('lock')
                            <div>{{ trans('entities.chapters_permissions_active') }}</div>
                        </a>
                    @else
                        <div class="entity-meta-item">
                            @icon('lock')
                            <div>{{ trans('entities.chapters_permissions_active') }}</div>
                        </div>
                    @endif
                </div>
            @endif

            @if($page->hasPermissions())
                <div class="active-restriction">
                    @if(userCan('restrictions-manage', $page))
                        <a href="{{ $page->getUrl('/permissions') }}" class="entity-meta-item">
                            @icon('lock')
                            <div>{{ trans('entities.pages_permissions_active') }}</div>
                        </a>
                    @else
                        <div class="entity-meta-item">
                            @icon('lock')
                            <div>{{ trans('entities.pages_permissions_active') }}</div>
                        </div>
                    @endif
                </div>
            @endif

            @if($page->template)
                <div class="entity-meta-item">
                    @icon('template')
                    <div>{{ trans('entities.pages_is_template') }}</div>
                </div>
            @endif
        </div>
        
        <br>

        <!-- New Revision List Section -->
        <div class="revision-section mt-m">
            <h5>Versions</h5>
            <div class="revision-list">
                @foreach($page->revisions()->orderBy('created_at', 'desc')->take(10)->get() as $revision)
                    <div class="revision-list-item {{ $page->revision_count === $revision->revision_number ? 'active' : '' }}" 
                         data-revision-id="{{ $revision->id }}" >
                        <div>
                            <span class="revision-number">#{{ $revision->revision_number }} - </span>
                            <span class="revision-name">{{ $revision->summary ? Str::limit($revision->summary, 30) : 'No summary' }}</span>                                            
                        </div>                        
                        <div class="revision-date">{{ $revision->created_at->diffForHumans() }}</div>
                        <div class="additional-links">     
                            <a href="/books/{{ $page->book->slug }}/page/{{ $page->slug }}/revisions/{{ $revision->id }}" target="_blank"><span class="revision-link">Preview</span></a>
                            <span class="text-muted opacity-70">&nbsp;|&nbsp;</span>&nbsp;    
                            <a href="/books/{{ $page->book->slug }}/page/{{ $page->slug }}/revisions/{{ $revision->id }}/changes" target="_blank"><span class="revision-changes">Changes</span></a>
                        </div>                                           
                    </div>
                @endforeach
            </div>
            <div class="text-right mt-s">
                <a href="{{ $page->getUrl('/revisions') }}" class="text-small">{{ trans('common.view_all') }}</a>
            </div>
        </div>
    </div>

    <div class="actions mb-xl">
        <h5>{{ trans('common.actions') }}</h5>

        <div class="icon-list text-link">

            {{--User Actions--}}
            @if(userCan('page-update', $page))
                <a href="{{ $page->getUrl('/edit') }}" data-shortcut="edit" class="icon-list-item">
                    <span>@icon('edit')</span>
                    <span>{{ trans('common.edit') }}</span>
                </a>
            @endif
            @if(userCanOnAny('create', \BookStack\Entities\Models\Book::class) || userCanOnAny('create', \BookStack\Entities\Models\Chapter::class) || userCan('page-create-all') || userCan('page-create-own'))
                <a href="{{ $page->getUrl('/copy') }}" data-shortcut="copy" class="icon-list-item">
                    <span>@icon('copy')</span>
                    <span>{{ trans('common.copy') }}</span>
                </a>
            @endif
            @if(userCan('page-update', $page))
                @if(userCan('page-delete', $page))
	                <a href="{{ $page->getUrl('/move') }}" data-shortcut="move" class="icon-list-item">
	                    <span>@icon('folder')</span>
	                    <span>{{ trans('common.move') }}</span>
	                </a>
                @endif
            @endif
            <a href="{{ $page->getUrl('/revisions') }}" data-shortcut="revisions" class="icon-list-item">
                <span>@icon('history')</span>
                <span>{{ trans('entities.revisions') }}</span>
            </a>
            @if(userCan('restrictions-manage', $page))
                <a href="{{ $page->getUrl('/permissions') }}" data-shortcut="permissions" class="icon-list-item">
                    <span>@icon('lock')</span>
                    <span>{{ trans('entities.permissions') }}</span>
                </a>
            @endif
            @if(userCan('page-delete', $page))
                <a href="{{ $page->getUrl('/delete') }}" data-shortcut="delete" class="icon-list-item">
                    <span>@icon('delete')</span>
                    <span>{{ trans('common.delete') }}</span>
                </a>
            @endif

            <hr class="primary-background"/>

            @if($watchOptions->canWatch() && !$watchOptions->isWatching())
                @include('entities.watch-action', ['entity' => $page])
            @endif
            @if(!user()->isGuest())
                @include('entities.favourite-action', ['entity' => $page])
            @endif
            @if(userCan('content-export'))
                @include('entities.export-menu', ['entity' => $page])
            @endif
        </div>

    </div>
@stop