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
</style>
    <!-- Search by Category Section -->
    <div class="card content-wrap mb-xl">
        <h2 class="list-heading">Categories</h2><br/>
        <div class="grid second gap-sm" style="display: grid; grid-template-columns: repeat(4, 1fr); grid-auto-rows: minmax(0, auto);">
            
            <!-- Row 1 -->
            <div class="category-card text-center">
                <a href="#" class="text-link">
                    <div class="mb-s">
                        <img src="/images/icons/replacement-plan-change.svg" alt="Replacement/Plan Change" style="width: 96px; height: 96px;">
                    </div>
                    Replacement/Plan Change
                </a>
            </div>
            <div class="category-card text-center">
                <a href="#" class="text-link">
                    <div class="mb-s">
                        <img src="/images/icons/home-hikari.svg" alt="HOME Hikari" style="width: 96px; height: 96px;">
                    </div>
                    HOME Hikari
                </a>
            </div>
            <div class="category-card text-center">
                <a href="#" class="text-link">
                    <div class="mb-s">
                        <img src="/images/icons/pocket-wifi.svg" alt="Pocket WiFi" style="width: 96px; height: 96px;">
                    </div>
                    Pocket WiFi
                </a>
            </div>
            
            <!-- Row 2 -->
            <div class="category-card text-center">
                <a href="#" class="text-link">
                    <div class="mb-s">
                        <img src="/images/icons/sim-card.svg" alt="SIM Card" style="width: 96px; height: 96px;">
                    </div>
                    SIM Card
                </a>
            </div>
            <div class="category-card text-center">
                <a href="#" class="text-link">
                    <div class="mb-s">
                        <img src="/images/icons/sim-card-apn-settings.svg" alt="SIM Card APN Settings" style="width: 96px; height: 96px;">
                    </div>
                    SIM Card APN Settings
                </a>
            </div>
            <div class="category-card text-center">
                <a href="#" class="text-link">
                    <div class="mb-s">
                        <img src="/images/icons/apps.svg" alt="Apps (Untone, CRM, etc.)" style="width: 96px; height: 96px;">
                    </div>
                    Apps (Kintone, CRM, etc.)
                </a>
            </div>
            
            <!-- Row 3 -->
            <div class="category-card text-center">
                <a href="#" class="text-link">
                    <div class="mb-s">
                        <img src="/images/icons/payments.svg" alt="Payments" style="width: 96px; height: 96px;">
                    </div>
                    Payments
                </a>
            </div>
            <div class="category-card text-center">
                <a href="#" class="text-link">
                    <div class="mb-s">
                        <img src="/images/icons/discount-fees.svg" alt="Discounts/Fees" style="width: 96px; height: 96px;">
                    </div>
                    Discounts/Fees
                </a>
            </div>
            <div class="category-card text-center">
                <a href="#" class="text-link">
                    <div class="mb-s">
                        <img src="/images/icons/general.svg" alt="General" style="width: 96px; height: 96px;">
                    </div>
                    General
                </a>
            </div>
            
            <!-- Row 4 -->
            <div class="category-card text-center">
                <a href="#" class="text-link">
                    <div class="mb-s">
                        <img src="/images/icons/others.svg" alt="Others" style="width: 96px; height: 96px;">
                    </div>
                    Others
                </a>
            </div>

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
@stop
