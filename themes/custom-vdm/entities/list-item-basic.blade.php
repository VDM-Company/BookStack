<?php $type = $entity->getType(); ?>
<style>
    .entity-list.compact .hide-in-compact { display: none !important; }
    .important-update-label {
        display: inline-block;
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
<a href="{{ $entity->getUrl() }}" class="{{$type}} {{$type === 'page' && $entity->draft ? 'draft' : ''}} {{$classes ?? ''}} entity-list-item" data-entity-type="{{$type}}" data-entity-id="{{$entity->id}}">
    <span role="presentation" class="icon text-{{$type}}">@icon($type)</span>
    <div class="content">
            
            <h4 class="entity-list-item-name break-text">
                <span class="hide-in-compact" style="display: inline-block; vertical-align: middle;">@icon('page', ['style' => 'width: 24px; height: 24px;'])</span>           

                @if($type === 'page' && isset($entity->tags))
                    @foreach($entity->tags as $tag)
                        @if(strtolower($tag->name) === 'update')
                            <span class="important-update-label">Important Update</span>
                            @break
                        @endif
                    @endforeach
                @endif

                {{ $entity->preview_name ?? $entity->name }}
            </h4>
            
            {{-- Disable excerpt for now.
            {{ $slot ?? '' }} --}}

    </div>
</a>