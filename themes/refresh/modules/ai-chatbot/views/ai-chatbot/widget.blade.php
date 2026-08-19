@php
    /**
     * AI Chatbot widget mount point.
     *
     * Rendered after layouts.parts.base-body-end, which sits just inside the
     * closing </body> tag of layouts/base.blade.php. Export layouts do not
     * include that partial, so this never reaches PDF or HTML exports.
     */
    $aiChatConfig = \BookStackAiChat\Config::instance();
    $aiChatVisible = \BookStackAiChat\Access::allows(user(), $aiChatConfig);
@endphp

@if($aiChatVisible)
    <link rel="stylesheet" href="{{ \BookStackAiChat\Module::asset('chat.css') }}">

    <div id="ai-chatbot"
         class="print-hidden"
         data-endpoint="{{ url('/ai-chat/message') }}"
         data-report-endpoint="{{ url('/ai-chat/client-error') }}"
         data-app-name="{{ setting('app-name') }}"
         data-strings="{{ json_encode(trans('aichat')) }}"></div>

    <script type="module"
            src="{{ \BookStackAiChat\Module::asset('chat.js') }}"
            nonce="{{ $cspNonce ?? '' }}"></script>
@endif
