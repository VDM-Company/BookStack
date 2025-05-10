@extends('layouts.tri')

@section('body')
    <!-- Search by Category Section -->
    <div class="card content-wrap mb-xl">
        <h2 class="list-heading">Categories</h2><br/>
        <div class="grid second gap-sm" style="display: grid; grid-template-columns: repeat(3, 1fr); grid-template-rows: repeat(4, auto);">
            
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
            
            <!-- Row 2 -->
            <div class="text-center">
                <div class="mb-m">
                    <img src="/images/icons/sim-card.svg" alt="SIM Card" style="width: 128px; height: 128px;">
                </div>
                <a href="#" class="text-link">SIM Card</a>
            </div>
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
            
            <!-- Row 3 -->
            <div class="text-center">
                <div class="mb-m">
                    <img src="/images/icons/payments.svg" alt="Payments" style="width: 128px; height: 128px;">
                </div>
                <a href="#" class="text-link">Payments</a>
            </div>
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
            
            <!-- Row 4 -->
            <div class="text-center">
                <div class="mb-m">
                    <img src="/images/icons/others.svg" alt="Others" style="width: 128px; height: 128px;">
                </div>
                <a href="#" class="text-link">Others</a>
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
