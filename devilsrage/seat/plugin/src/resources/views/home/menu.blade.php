@extends('web::layouts.grids.12')

@section('title', 'Arrange menu')
@section('page_header', 'Arrange menu')

@section('full')
  @include('devilsrage::partials.flash')

  <div class="card">
    <div class="card-body">
      <p class="mb-1">Put each menu at the top, in a folder, or hide it. Lower numbers come first. Type a new folder name to make one.</p>
      <p class="mb-0 small text-muted">
        @if($hasOwn) You use your own arrangement. @else You use the corp default (leaders set it). Saving makes it yours. @endif
        Hidden menus still work by link; only what SeAT lets you open is listed.
      </p>
    </div>
  </div>

  <form method="post" action="{{ route('devilsrage.menu.save') }}">
    @csrf
    <datalist id="dr-folders">
      @foreach($folders as $folder)<option value="{{ $folder }}">@endforeach
    </datalist>
    <div class="card">
      <div class="card-body p-0">
        <table class="table table-sm mb-0">
          <thead><tr><th>Menu</th><th style="width: 16rem;">Where</th><th style="width: 8rem;">Folder name</th><th style="width: 6rem;">Order</th></tr></thead>
          <tbody>
            @foreach($rows as $row)
              @php($place = $row['folder'] === '' ? 'top' : ($row['folder'] === '-' ? 'hidden' : 'folder'))
              <tr>
                <td><i class="{{ $row['icon'] }} text-muted" style="width: 1.2rem;"></i> {{ $row['name'] }} <span class="text-muted small">{{ $row['key'] }}</span></td>
                <td>
                  <select class="form-control form-control-sm dr-place" data-key="{{ $row['key'] }}" aria-label="Where {{ $row['name'] }} goes">
                    <option value="top" @selected($place === 'top')>At the top</option>
                    <option value="folder" @selected($place === 'folder')>In a folder</option>
                    @if($row['key'] !== 'devilsrage')<option value="hidden" @selected($place === 'hidden')>Hidden</option>@endif
                  </select>
                </td>
                <td>
                  <input type="hidden" name="menus[{{ $row['key'] }}][folder]" value="{{ $row['folder'] }}" class="dr-folder-value" data-key="{{ $row['key'] }}">
                  <input type="text" list="dr-folders" maxlength="30" value="{{ $place === 'folder' ? $row['folder'] : 'Lookup' }}" class="form-control form-control-sm dr-folder-name" data-key="{{ $row['key'] }}" @disabled($place !== 'folder') aria-label="Folder for {{ $row['name'] }}">
                </td>
                <td><input type="number" name="menus[{{ $row['key'] }}][order]" value="{{ $row['order'] }}" min="0" max="9999" class="form-control form-control-sm" aria-label="Order of {{ $row['name'] }}"></td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
      <div class="card-footer d-flex flex-wrap align-items-center" style="gap: .5rem;">
        <label class="mb-0 mr-3"><input type="hidden" name="start" value="0"><input type="checkbox" name="start" value="1" @checked($startPage)> Start on the Devil's Rage home instead of SeAT's</label>
        <button type="submit" name="action" value="mine" class="btn btn-sm btn-primary">Save my menu</button>
        @if($hasOwn)<button type="submit" name="action" value="reset" class="btn btn-sm btn-outline-secondary">Back to the corp default</button>@endif
        @if($isLeader)<button type="submit" name="action" value="default" class="btn btn-sm btn-outline-warning ml-auto">Save as corp default</button>@endif
      </div>
    </div>
  </form>
@stop

@push('javascript')
<script>
(function () {
  function sync(key) {
    var place = document.querySelector('.dr-place[data-key="' + key + '"]').value;
    var name = document.querySelector('.dr-folder-name[data-key="' + key + '"]');
    var value = document.querySelector('.dr-folder-value[data-key="' + key + '"]');
    name.disabled = place !== 'folder';
    value.value = place === 'top' ? '' : (place === 'hidden' ? '-' : (name.value.trim() || 'More'));
  }
  document.querySelectorAll('.dr-place').forEach(function (el) {
    el.addEventListener('change', function () { sync(el.dataset.key); });
  });
  document.querySelectorAll('.dr-folder-name').forEach(function (el) {
    el.addEventListener('input', function () { sync(el.dataset.key); });
  });
})();
</script>
@endpush
