@extends('public.layout')

@section('title', __('ui.public.about.title'))

@section('content')
    <p class="eyebrow">{{ __('ui.public.about.eyebrow') }}</p>
    <h1>{{ __('ui.public.about.title') }}</h1>
    <p class="public-page-lead">{{ __('ui.public.about.intro') }}</p>

    <div class="public-info-grid">
        <section>
            <h2>{{ __('ui.public.about.scope_title') }}</h2>
            <p>{{ __('ui.public.about.scope_body') }}</p>
        </section>
        <section>
            <h2>{{ __('ui.public.about.sources_title') }}</h2>
            <p>{{ __('ui.public.about.sources_body') }}</p>
        </section>
    </div>

    <p>{{ __('ui.public.about.data_note') }}</p>
    <p class="public-page-note">{{ __('ui.public.about.disclaimer') }}</p>

    <a class="public-back-link" href="{{ route('chatbot') }}">← {{ __('ui.public.back_to_chatbot') }}</a>
@endsection
