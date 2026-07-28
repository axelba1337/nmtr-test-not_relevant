@extends('admin.layout')

@section('title', 'Dashboard')
@section('page-title', 'Dashboard')

@section('content')
    <div class="summary-grid" aria-label="Ringkasan chatbot">
        <article class="summary-card">
            <p class="summary-card__label">Total Pertanyaan Hari Ini</p>
            <p class="summary-card__value">{{ number_format($todayQuestions, 0, ',', '.') }}</p>
            <p class="summary-card__note">Pertanyaan valid yang tercatat hari ini.</p>
        </article>

        <article class="summary-card">
            <p class="summary-card__label">Total FAQ Aktif</p>
            <p class="summary-card__value">{{ number_format($activeKnowledgeCount, 0, ',', '.') }}</p>
            <p class="summary-card__note">FAQ yang tersedia untuk retrieval.</p>
        </article>

        <article class="summary-card">
            <p class="summary-card__label">Rata-rata Top-1 Cosine Similarity</p>
            <p class="summary-card__value">
                {{ $averageTop1Similarity === null ? '—' : number_format($averageTop1Similarity, 4, ',', '.') }}
            </p>
            <p class="summary-card__note">Monitoring query hari ini yang memiliki kandidat.</p>
        </article>

        <article class="summary-card">
            <p class="summary-card__label">Status Knowledge</p>
            <p class="summary-card__value">
                {{ number_format($knowledgeStats['ready'], 0, ',', '.') }}
                <span class="summary-card__unit">siap</span>
            </p>
            <ul class="summary-card__details">
                <li>{{ number_format($knowledgeStats['active'], 0, ',', '.') }} FAQ aktif</li>
                <li>{{ number_format($knowledgeStats['failed'], 0, ',', '.') }} embedding gagal</li>
                <li>{{ number_format($knowledgeStats['pending'], 0, ',', '.') }} belum dibuat/kedaluwarsa</li>
                @if ($knowledgeStats['warnings'] > 0)
                    <li>{{ number_format($knowledgeStats['warnings'], 0, ',', '.') }} siap dengan peringatan rebuild</li>
                @endif
            </ul>
        </article>
    </div>

    <section class="admin-card" aria-labelledby="activity-title">
        <div class="admin-card__heading">
            <div>
                <p class="admin-card__eyebrow">Monitoring</p>
                <h2 id="activity-title">Aktivitas Chatbot 7 Hari Terakhir</h2>
            </div>
        </div>

        @if ($maximumActivity === 0)
            <div class="empty-state">
                <p>Belum ada aktivitas chatbot dalam tujuh hari terakhir.</p>
            </div>
        @else
            <div
                class="activity-chart"
                role="img"
                aria-label="Grafik jumlah pertanyaan chatbot selama tujuh hari terakhir"
            >
                <div class="activity-chart__body">
                    <div class="activity-chart__y-axis" aria-hidden="true">
                        <span>{{ number_format($maximumActivity, 0, ',', '.') }}</span>
                        <span>{{ number_format((int) ceil($maximumActivity / 2), 0, ',', '.') }}</span>
                        <span>0</span>
                    </div>

                    <div class="activity-chart__plot">
                        @foreach ($activity as $activityDay)
                            <div class="activity-chart__column">
                                <span class="activity-chart__total">
                                    {{ number_format($activityDay['total'], 0, ',', '.') }}
                                </span>
                                <span
                                    class="activity-chart__bar"
                                    style="--activity-height: {{ $activityDay['percentage'] }}%"
                                    aria-hidden="true"
                                ></span>
                                <time datetime="{{ $activityDay['date'] }}">
                                    {{ $activityDay['label'] }}
                                </time>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        @endif
    </section>
@endsection
