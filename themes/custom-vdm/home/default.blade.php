@extends('layouts.simple')

@section('body')

    <div class="container px-xl py-s flex-container-row gap-l wrap justify-space-between">
        <div class="icon-list inline block">
            @include('home.parts.expand-toggle', ['classes' => 'text-muted text-link', 'target' => '.entity-list.compact .entity-item-snippet', 'key' => 'home-details'])
        </div>
        <div>
            <div class="icon-list inline block">
                @include('common.dark-mode-toggle', ['classes' => 'text-muted icon-list-item text-link'])
            </div>
        </div>
    </div>

    <div class="container py-xl">
        <h1>This is custom vdm theme</h1>
        <h4>Everything you need to know to assist our customers</h4>
    </div>

    <div class="container" id="home-default">
        
        <!-- Important Updates Section -->
        {{-- <div class="card content-wrap mb-xl">
            <h2 class="list-heading">Important Updates</h2>

            <div class="px-m">
                <!-- Include content from the BookStack page -->
                <div class="page-content">
                    @php
                    try {
                        // Try to fetch the page by its slug
                        $importantUpdatesPage = \BookStack\Entities\Models\Page::query()
                            ->where('slug', 'updates-to-be-shown-at-main-page')
                            ->whereHas('book', function($query) {
                                $query->where('slug', 'general-updates');
                            })
                            ->first();
                        
                        // Output the page content if found
                        if ($importantUpdatesPage) {
                            echo $importantUpdatesPage->html;
                        }
                    } catch (\Exception $e) {
                        // Silently fail
                    }
                    @endphp
                </div>
            </div>

            <div class="px-m py-m">
                <div class="dropdown-container">
                    <a href="#" class="text-link dropdown-toggle" id="calendar-toggle">Calendar for meetings, events, important reminders <span class="dropdown-arrow">▼</span></a>
                    <div id="calendar-dropdown" class="dropdown-content" style="display: none;">
                        @php
                        try {
                            // Try to fetch the page by its slug
                            $calendarPage = \BookStack\Entities\Models\Page::query()
                                ->where('slug', 'details')
                                ->whereHas('book', function($query) {
                                    $query->where('slug', 'calendar-for-meetings-events-important-reminders');
                                })
                                ->first();
                            
                            // Output the page content if found
                            if ($calendarPage) {
                                echo $calendarPage->html;
                            }
                        } catch (\Exception $e) {
                            // Silently fail
                        }
                        @endphp
                    </div>
                </div>
                <style>
                    .dropdown-container {
                        position: relative;
                    }
                    .dropdown-toggle {
                        cursor: pointer;
                    }
                    .dropdown-arrow {
                        font-size: 0.8em;
                        display: inline-block;
                        transition: transform 0.3s;
                    }
                    .dropdown-toggle.active .dropdown-arrow {
                        transform: rotate(180deg);
                    }
                    .dropdown-content {
                        background-color: #f9f9f9;
                        border-radius: 4px;
                        border: 1px solid #ddd;
                        padding: 10px;
                        margin-top: 10px;
                        max-height: 400px;
                        overflow-y: auto;
                    }
                </style>
            </div>
            <div class="px-m py-m">
                <a href="#" class="text-link">Click here to see latest updates</a>
            </div>
            <div class="px-m py-m">
                <a href="#" class="text-link">Products and Services</a>
            </div>
            <div class="px-m py-m">
                <a href="#" class="text-link">Search by category</a>
            </div>
            <div class="px-m py-m">
                <a href="#" class="text-link">Important Links</a>
            </div>
        </div> --}}

        <!-- Search by Category Section -->
        <div class="card content-wrap mb-xl">
            <h2 class="list-heading">Search by category</h2><br/>
            <div class="grid second gap-sm" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));">
                <!-- Row 1 -->
                <div class="text-center">
                    <div class="mb-m">
                        <img src="/images/icons/replacement-plan-change.svg" alt="Replacement/Plan Change" style="width: 128px; height: 128px;">
                    </div>
                    <a href="#" class="text-link">Replacement/Plan Change</a>
                </div>
                <div class="text-center">
                    <div class="mb-m">
                        <img src="/images/icons/home-hikari.svg" alt="HOME Hikari" style="width: 128px; height: 128px;">
                    </div>
                    <a href="#" class="text-link">HOME Hikari</a>
                </div>
                <div class="text-center">
                    <div class="mb-m">
                        <img src="/images/icons/pocket-wifi.svg" alt="Pocket WiFi" style="width: 128px; height: 128px;">
                    </div>
                    <a href="#" class="text-link">Pocket WiFi</a>
                </div>
                <div class="text-center">
                    <div class="mb-m">
                        <img src="/images/icons/sim-card.svg" alt="SIM Card" style="width: 128px; height: 128px;">
                    </div>
                    <a href="#" class="text-link">SIM Card</a>
                </div>
                
                <!-- Row 2 -->
                <div class="text-center">
                    <div class="mb-m">
                        <img src="/images/icons/sim-card-apn-settings.svg" alt="SIM Card APN Settings" style="width: 128px; height: 128px;">
                    </div>
                    <a href="#" class="text-link">SIM Card APN Settings</a>
                </div>
                <div class="text-center">
                    <div class="mb-m">
                        <img src="/images/icons/apps.svg" alt="Apps (Untone, CRM, etc.)" style="width: 128px; height: 128px;">
                    </div>
                    <a href="#" class="text-link">Apps (Kintone, CRM, etc.)</a>
                </div>
                <div class="text-center">
                    <div class="mb-m">
                        <img src="/images/icons/payments.svg" alt="Payments" style="width: 128px; height: 128px;">
                    </div>
                    <a href="#" class="text-link">Payments</a>
                </div>
                
                <!-- Row 3 -->
                <div class="text-center">
                    <div class="mb-m">
                        <img src="/images/icons/discount-fees.svg" alt="Discounts/Fees" style="width: 128px; height: 128px;">
                    </div>
                    <a href="#" class="text-link">Discounts/Fees</a>
                </div>
                <div class="text-center">
                    <div class="mb-m">
                        <img src="/images/icons/general.svg" alt="General" style="width: 128px; height: 128px;">
                    </div>
                    <a href="#" class="text-link">General</a>
                </div>
                <div class="text-center">
                    <div class="mb-m">
                        <img src="/images/icons/others.svg" alt="Others" style="width: 128px; height: 128px;">
                    </div>
                    <a href="#" class="text-link">Others</a>
                </div>
            </div>
        </div>
        
        <!-- Important Links Section -->
        {{-- <div class="card content-wrap mb-xl">
            <h2 class="list-heading">Important Links</h2>
            <div class="px-m">
                <!-- Include content from the BookStack page -->
                <div class="page-content">
                    @php
                    try {
                        // Try to fetch the page by its slug
                        $importantLinksPage = \BookStack\Entities\Models\Page::query()
                            ->where('slug', 'details')
                            ->whereHas('book', function($query) {
                                $query->where('slug', 'important-links');
                            })
                            ->first();
                        
                        // Output the page content if found
                        if ($importantLinksPage) {
                            echo $importantLinksPage->html;
                        }
                    } catch (\Exception $e) {
                        // Silently fail
                    }
                    @endphp
                </div>
                
                <!-- Link to the full page -->
                {{-- <div class="mt-m">
                    <a href="{{ url('/books/important-links/page/details') }}" class="card-footer-link">View full Important Links page</a>
                </div>
            </div>
        </div> --}}

        <!-- Original BookStack Content -->
        <div class="grid third gap-x-xxl no-row-gap">
            <div>
                @if(count($draftPages) > 0)
                    <div id="recent-drafts" class="card mb-xl">
                        <h3 class="card-title">{{ trans('entities.my_recent_drafts') }}</h3>
                        <div class="px-m">
                            @include('entities.list', ['entities' => $draftPages, 'style' => 'compact'])
                        </div>
                    </div>
                @endif

                <div id="{{ auth()->check() ? 'recently-viewed' : 'recent-books' }}" class="card mb-xl">
                    <h3 class="card-title">{{ trans('entities.' . (auth()->check() ? 'my_recently_viewed' : 'books_recent')) }}</h3>
                    <div class="px-m">
                        @include('entities.list', [
                        'entities' => $recents,
                        'style' => 'compact',
                        'emptyText' => auth()->check() ? trans('entities.no_pages_viewed') : trans('entities.books_empty')
                        ])
                    </div>
                </div>
            </div>

            <div>
                @if(count($favourites) > 0)
                    <div id="top-favourites" class="card mb-xl">
                        <h3 class="card-title">{{ trans('entities.my_most_viewed_favourites') }}</h3>
                        <div class="px-m">
                            @include('entities.list', [
                            'entities' => $favourites,
                            'style' => 'compact',
                            ])
                        </div>
                        <a href="{{ url('/favourites')  }}" class="card-footer-link">{{ trans('common.view_all') }}</a>
                    </div>
                @endif

                <div id="recent-pages" class="card mb-xl">
                    <h3 class="card-title">{{ trans('entities.recently_updated_pages') }}</h3>
                    <div id="recently-updated-pages" class="px-m">
                        @include('entities.list', [
                        'entities' => $recentlyUpdatedPages,
                        'style' => 'compact',
                        'emptyText' => trans('entities.no_pages_recently_updated'),
                        ])
                    </div>
                    @if(count($recentlyUpdatedPages) > 0)
                        <a href="{{ url("/pages/recently-updated") }}" class="card-footer-link">{{ trans('common.view_all') }}</a>
                    @endif
                </div>
            </div>

            <div>
                <div id="recent-activity" class="card mb-xl">
                    <h3 class="card-title">{{ trans('entities.recent_activity') }}</h3>
                    <div class="px-m">
                        @include('common.activity-list', ['activity' => $activity])
                    </div>
                </div>
            </div>

        </div>
    </div>

    <div class="container">
        <div class="text-right text-muted">
            <a href="{{ url('/site-map') }}" class="text-muted">Site map</a>
        </div>
    </div>

@stop

@section('body-end')
<script nonce="{{ $cspNonce ?? '' }}">
    document.addEventListener('DOMContentLoaded', function() {
        var calendarToggle = document.getElementById('calendar-toggle');
        if (calendarToggle) {
            calendarToggle.addEventListener('click', function(event) {
                event.preventDefault();
                var content = document.getElementById('calendar-dropdown');
                if (content.style.display === 'none') {
                    content.style.display = 'block';
                    this.classList.add('active');
                } else {
                    content.style.display = 'none';
                    this.classList.remove('active');
                }
            });
        }
    });
</script>
@stop
