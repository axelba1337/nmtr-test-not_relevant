@extends('public.layout')

@section('title', __('ui.public.contact.title'))

@section('content')
    <p class="eyebrow">{{ __('ui.public.contact.eyebrow') }}</p>
    <h1>{{ __('ui.public.contact.title') }}</h1>
    <p class="public-page-lead">{{ __('ui.public.contact.helper') }}</p>

    <dl class="contact-list">
        <div>
            <dt>{{ __('ui.public.contact.email') }}</dt>
            <dd><a href="mailto:ardiansahbelliandriaxel@gmail.com">ardiansahbelliandriaxel@gmail.com</a></dd>
        </div>
        <div>
            <dt>{{ __('ui.public.contact.linkedin') }}</dt>
            <dd><a href="https://www.linkedin.com/in/axel-belliandri-220620276/" target="_blank" rel="noopener noreferrer">linkedin.com/in/axel-belliandri-220620276</a></dd>
        </div>
        <div>
            <dt>{{ __('ui.public.contact.instagram') }}</dt>
            <dd><a href="https://www.instagram.com/speirs.dev/" target="_blank" rel="noopener noreferrer">instagram.com/speirs.dev</a></dd>
        </div>
    </dl>

    <a class="public-back-link" href="{{ route('chatbot') }}">← {{ __('ui.public.back_to_chatbot') }}</a>
@endsection
