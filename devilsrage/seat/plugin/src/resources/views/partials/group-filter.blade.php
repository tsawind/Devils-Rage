{{-- Patch 39: launch group filter (Everyone, corp groups, your groups; leaders: every pilot's groups). --}}
<label for="dr-group">Launch group</label>
<select id="dr-group" name="group" class="form-control form-control-sm" onchange="this.form.submit()">
  <option value="">Everyone</option>
  @foreach($groupOptions as $section)
    <optgroup label="{{ $section['section'] }}">
      @foreach($section['options'] as $option)
        <option value="{{ $option['value'] }}" @selected($groupValue === $option['value'])>{{ $option['label'] }}</option>
      @endforeach
    </optgroup>
  @endforeach
</select>
