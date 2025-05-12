<?php $type = $entity->getType(); ?>
<a href="{{ $entity->getUrl() }}" class="{{$type}} {{$type === 'page' && $entity->draft ? 'draft' : ''}} {{$classes ?? ''}} entity-list-item" data-entity-type="{{$type}}" data-entity-id="{{$entity->id}}">
    <span role="presentation" class="icon text-{{$type}}">@icon($type)</span>
    <div class="content">
            <h4 class="entity-list-item-name break-text">
                <span style="display: inline-block; vertical-align: middle;">@icon('page', ['style' => 'width: 24px; height: 24px;'])</span>
                {{ $entity->preview_name ?? $entity->name }}</h4>
            
            {{-- 
            Disable excerpt for now.
            {{ $slot ?? '' }} 
             --}}
             
    </div>
</a>