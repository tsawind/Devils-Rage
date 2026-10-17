@extends('web::layouts.grids.12')

@section('title', 'Suggestions')
@section('page_header', 'Suggestions')

@section('full')
  @include('devilsrage::partials.flash')

  <div class="row">
    <div class="col-lg-8">
      <div class="card">
        <div class="card-header">
          <h3 class="card-title">Ideas for the corp, SeAT, the mapper and the bot</h3>
          <div class="card-tools">
            <a href="{{ route('devilsrage.suggestions.index') }}" class="btn btn-xs {{ $filter === null ? 'btn-primary' : 'btn-outline-secondary' }}">Open &amp; planned</a>
            @foreach($statuses as $key => $label)
              <a href="{{ route('devilsrage.suggestions.index', ['status' => $key]) }}" class="btn btn-xs {{ $filter === $key ? 'btn-primary' : 'btn-outline-secondary' }}">{{ $label }}</a>
            @endforeach
          </div>
        </div>
        <div class="card-body p-0">
          @forelse($suggestions as $suggestion)
            <div class="d-flex border-bottom p-2" style="gap: .75rem;">
              <form method="post" action="{{ route('devilsrage.suggestions.vote', $suggestion) }}" class="text-center" style="min-width: 3rem;">
                @csrf
                <button type="submit" class="btn btn-sm {{ $mine->has($suggestion->id) ? 'btn-success' : 'btn-outline-secondary' }}" title="{{ $mine->has($suggestion->id) ? 'Take your vote back' : 'Vote for this' }}">
                  <i class="fas fa-arrow-up"></i><div>{{ (int) ($votes[$suggestion->id] ?? 0) }}</div>
                </button>
              </form>
              <div class="flex-grow-1" style="min-width: 0;">
                <div>
                  <strong>{{ $suggestion->title }}</strong>
                  <span class="badge {{ $suggestion->badgeClass() }}">{{ $statuses[$suggestion->status] ?? $suggestion->status }}</span>
                  @if($suggestion->status_note)<span class="small text-muted">· {{ $suggestion->status_note }}</span>@endif
                </div>
                <div class="small text-muted">{{ $nameOf($suggestion->author) }} · {{ $suggestion->created_at?->diffForHumans() }}</div>
                @if($suggestion->body)<div class="mt-1" style="white-space: pre-line;">{{ $suggestion->body }}</div>@endif

                <details class="mt-1" @if($suggestion->comments->isNotEmpty()) open @endif>
                  <summary class="small text-muted">{{ $suggestion->comments->count() }} comment{{ $suggestion->comments->count() === 1 ? '' : 's' }}</summary>
                  @foreach($suggestion->comments as $comment)
                    <div class="small border-left pl-2 my-1"><strong>{{ $nameOf($comment->author) }}</strong> <span class="text-muted">{{ $comment->created_at?->diffForHumans() }}</span><div style="white-space: pre-line;">{{ $comment->body }}</div></div>
                  @endforeach
                  <form method="post" action="{{ route('devilsrage.suggestions.comment', $suggestion) }}" class="d-flex mt-1" style="gap: .4rem;">
                    @csrf
                    <input type="text" name="body" maxlength="2000" class="form-control form-control-sm" placeholder="Add a comment" required>
                    <button type="submit" class="btn btn-sm btn-outline-primary">Comment</button>
                  </form>
                </details>

                <div class="d-flex flex-wrap mt-1" style="gap: .4rem;">
                  @if($isLeader)
                    <form method="post" action="{{ route('devilsrage.suggestions.status', $suggestion) }}" class="form-inline" style="gap: .3rem;">
                      @csrf
                      <select name="status" class="form-control form-control-sm">
                        @foreach($statuses as $key => $label)
                          <option value="{{ $key }}" @selected($suggestion->status === $key)>{{ $label }}</option>
                        @endforeach
                      </select>
                      <input type="text" name="status_note" value="{{ $suggestion->status_note }}" maxlength="255" class="form-control form-control-sm" placeholder="Note (optional)">
                      <button type="submit" class="btn btn-sm btn-outline-secondary">Set</button>
                    </form>
                  @endif
                  @if($isLeader || (int) $suggestion->user_id === (int) auth()->id())
                    <form method="post" action="{{ route('devilsrage.suggestions.delete', $suggestion) }}" onsubmit="return confirm('Remove this suggestion?')">
                      @csrf
                      <button type="submit" class="btn btn-sm btn-link text-danger p-0">Remove</button>
                    </form>
                  @endif
                </div>
              </div>
            </div>
          @empty
            <div class="p-3 text-muted">Nothing here yet.</div>
          @endforelse
        </div>
      </div>
    </div>
    <div class="col-lg-4">
      <div class="card card-outline card-primary">
        <div class="card-header"><h3 class="card-title">Suggest something</h3></div>
        <div class="card-body">
          <form method="post" action="{{ route('devilsrage.suggestions.store') }}">
            @csrf
            <div class="form-group">
              <input type="text" name="title" value="{{ old('title') }}" maxlength="120" class="form-control" placeholder="In a few words" required>
            </div>
            <div class="form-group">
              <textarea name="body" rows="5" maxlength="4000" class="form-control" placeholder="Details (optional)">{{ old('body') }}</textarea>
            </div>
            <button type="submit" class="btn btn-primary">Post</button>
          </form>
        </div>
      </div>
    </div>
  </div>
@stop
