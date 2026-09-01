@extends('admin.layout')
@section('title', __('ui.admin_pages.unanswered.show.title'))
@section('page-title', __('ui.admin_pages.unanswered.show.title'))

@section('content')
    <div class="detail-grid">
        <section class="admin-card" aria-labelledby="query-detail-title">
            <div class="admin-card__heading"><div><p class="admin-card__eyebrow">{{ __('ui.admin_pages.unanswered.show.user_question') }}</p><h2 id="query-detail-title">{{ $queryLog->query_text ?: __('ui.admin_pages.unanswered.common.query_not_stored') }}</h2><p>{{ __('ui.admin_pages.unanswered.show.received_at', ['date' => $queryLog->created_at->translatedFormat('d M Y H:i:s')]) }}</p></div></div>
            <dl class="data-list">
                <div><dt>{{ __('ui.admin_pages.unanswered.show.normalized') }}</dt><dd>{{ $queryLog->normalized_query ?: __('ui.admin_pages.unanswered.common.not_available') }}</dd></div>
                <div><dt>{{ __('ui.admin_pages.unanswered.show.unanswered_reason') }}</dt><dd>{{ $queryLog->unanswered_reason ? str($queryLog->unanswered_reason)->replace('_', ' ')->title() : __('ui.admin_pages.unanswered.common.unknown') }}</dd></div>
                <div><dt>{{ __('ui.admin_pages.unanswered.show.result_count') }}</dt><dd>{{ number_format($queryLog->result_count, 0, ',', '.') }}</dd></div>
                <div><dt>{{ __('ui.admin_pages.unanswered.show.top_one_similarity') }}</dt><dd>{{ $queryLog->top1_similarity === null ? __('ui.admin_pages.unanswered.common.not_available') : number_format($queryLog->top1_similarity, 6, ',', '.') }}</dd></div>
                <div><dt>{{ __('ui.admin_pages.unanswered.show.process_status') }}</dt><dd>{{ str($queryLog->status)->replace('_', ' ')->title() }}</dd></div>
            </dl>

            <h3 class="section-title">{{ __('ui.admin_pages.unanswered.show.structured_intent') }}</h3>
            <pre class="code-block">{{ json_encode($queryLog->intent ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
            <h3 class="section-title">{{ __('ui.admin_pages.unanswered.show.backend_filters') }}</h3>
            <pre class="code-block">{{ json_encode($queryLog->filters ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
        </section>

        <section class="admin-card" aria-labelledby="review-title">
            <div class="admin-card__heading"><div><p class="admin-card__eyebrow">{{ __('ui.admin_pages.unanswered.show.follow_up') }}</p><h2 id="review-title">{{ __('ui.admin_pages.unanswered.show.review_status') }}</h2><p>{{ __('ui.admin_pages.unanswered.show.review_description') }}</p></div></div>
            <form method="POST" action="{{ route('admin.unanswered.update', $queryLog) }}" data-admin-form>
                @csrf @method('PATCH')
                <input type="hidden" name="reviewed" value="0">
                <label class="check-field"><input name="reviewed" type="checkbox" value="1" @checked(old('reviewed', $queryLog->reviewed_at !== null))><span><strong>{{ __('ui.admin_pages.unanswered.show.reviewed') }}</strong><small>{{ __('ui.admin_pages.unanswered.show.uncheck_hint') }}</small></span></label>
                <div class="form-field review-notes"><label for="review-notes">{{ __('ui.admin_pages.unanswered.show.review_notes') }}</label><textarea id="review-notes" name="review_notes" maxlength="5000">{{ old('review_notes', $queryLog->review_notes) }}</textarea>@error('review_notes')<p class="form-error">{{ $message }}</p>@enderror</div>
                @if($queryLog->reviewed_at)<p class="form-help">{{ $queryLog->reviewer ? __('ui.admin_pages.unanswered.show.reviewed_at_by', ['date' => $queryLog->reviewed_at->translatedFormat('d M Y H:i'), 'name' => $queryLog->reviewer->name]) : __('ui.admin_pages.unanswered.show.reviewed_at', ['date' => $queryLog->reviewed_at->translatedFormat('d M Y H:i')]) }}</p>@endif
                <div class="form-actions"><a class="button button--secondary" href="{{ route('admin.unanswered.index') }}">{{ __('ui.admin_pages.unanswered.show.back') }}</a><button class="button button--primary" type="submit" data-busy-label="{{ __('ui.admin_pages.unanswered.show.saving') }}">{{ __('ui.admin_pages.unanswered.show.submit') }}</button></div>
            </form>
        </section>
    </div>
@endsection
