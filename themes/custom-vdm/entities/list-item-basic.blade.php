<?php $type = $entity->getType(); ?>
<style>
    .entity-list.compact .hide-in-compact { display: none !important; }
    .important-update-label {
        display: inline-block;
        background-color: #077b70;
        color: white;
        padding: 2px 8px;
        border-radius: 12px;
        font-size: 0.75em;
        font-weight: bold;
        vertical-align: middle;
        break-after: always;
    }

    @media (max-width: 1100px) {
        .important-update-label {
           display:inline;
        }
        .important-update-label:after {
            content:"\a";
            white-space: pre;
        }
    }
</style>
<a href="{{ $entity->getUrl() }}" class="{{$type}} {{$type === 'page' && $entity->draft ? 'draft' : ''}} {{$classes ?? ''}} entity-list-item" data-entity-type="{{$type}}" data-entity-id="{{$entity->id}}">
    <span role="presentation" class="icon text-{{$type}}">@icon($type)</span>
    <div class="content">
            <h4 class="entity-list-item-name break-text">
                @if($type === 'page' && isset($entity->tags))
                    @foreach($entity->tags as $tag)
                        @if(strtolower($tag->name) === 'update')
                            <span class="important-update-label">Important Updates</span>
                            @break
                        @endif
                    @endforeach
                @endif
                <span class="hide-in-compact" style="display: inline-block; vertical-align: middle;">@icon('page', ['style' => 'width: 24px; height: 24px;'])</span>                
                {{ $entity->preview_name ?? $entity->name }}
            </h4>            
            {{-- Disable excerpt for now.
            {{ $slot ?? '' }} --}}
    </div>
</a>