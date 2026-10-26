@php
    $demoCompany = auth()->check() ? auth()->user()->company : null;
@endphp
@if($demoCompany && $demoCompany->is_demo)
    @php
        $demoState = app(\App\Services\DemoTourService::class)->payload(auth()->user());
    @endphp
    <link rel="stylesheet" href="{{ asset('css/sellix-tour.css') }}?v=2">
    <div class="sx-demo-banner">
        <span>Demo business. This shop uses sample data, and the sales you record here stay inside the demo.</span>
        <form method="post" action="{{ route('demo.reset') }}" onsubmit="return confirm('Restore the demo sample data? Records added in this demo will be removed.');">
            @csrf
            <button type="submit">Reset demo</button>
        </form>
    </div>
    @if(! $demoState['mode'])
    <div class="sx-tour-backdrop" id="sx-tour-backdrop"></div>
    <div class="sx-tour-modal" id="sx-tour-welcome" role="dialog" aria-labelledby="sx-tour-welcome-title">
        <h2 id="sx-tour-welcome-title">Welcome to Your Interactive POS Demo!</h2>
        <p>Explore how to manage your business using this system. Follow the guided walkthrough through the main activities, or skip the guide and look around at your own pace.</p>
        <p>The demo already contains sample products, customers, a purchase history and a sale. Anything you add is for demonstration and can be cleared with Reset demo.</p>
        <div class="sx-tour-choice">
            <button type="button" data-demo-action="choose" data-mode="guided">Start Guided Tour</button>
            <button type="button" class="is-quiet" data-demo-action="choose" data-mode="explore">Explore on My Own</button>
        </div>
    </div>
    @endif
    <button type="button" class="sx-tour-launcher" id="sx-tour-launcher" data-demo-action="resume" @if(! $demoState['mode']) hidden @endif>Start guided tour</button>
    <section class="sx-tour-panel" id="sx-tour-panel" hidden aria-live="polite">
        <div class="sx-tour-progress" id="sx-tour-kicker"></div>
        <h2 id="sx-tour-title"></h2>
        <p id="sx-tour-body"></p>
        <div class="sx-tour-links" id="sx-tour-links"></div>
        <p id="sx-tour-missing" hidden>The button for this step is not on this screen. Choose Skip step to continue.</p>
        <p class="sx-tour-error" id="sx-tour-error"></p>
        <div class="sx-tour-actions" id="sx-tour-standard">
            <button type="button" class="is-quiet" data-demo-action="back">Back</button>
            <button type="button" class="is-quiet" data-demo-action="skip">Skip step</button>
            <button type="button" data-demo-action="next">Next</button>
            <button type="button" class="is-quiet" data-demo-action="pause">Pause tour</button>
            <button type="button" class="is-quiet" data-demo-action="exit">Exit tour</button>
        </div>
        <div class="sx-tour-actions" id="sx-tour-done" hidden>
            <button type="button" data-demo-action="exit">Explore the System</button>
            <button type="button" class="is-quiet" data-demo-action="restart">Restart Guided Tour</button>
            <a href="#" id="sx-tour-contact" hidden>Contact sales</a>
        </div>
    </section>
    <script>
        window.SellixDemo = {
            demo: true,
            actUrl: @json(route('demo.tour')),
            csrf: @json(csrf_token()),
            page: @json(optional(request()->route())->getName()),
            state: @json($demoState)
        };
        document.body.classList.add('sx-has-demo');
    </script>
    <script src="{{ asset('js/sellix-tour.js') }}?v=3"></script>
@endif
