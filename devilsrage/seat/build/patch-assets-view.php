<?php
/*
 * The Devil's Rage: adds "All characters" / "Only this character" buttons above the
 * character picker on SeAT's character Assets page, so a pilot can search the assets
 * of all their own characters in one go.
 *
 * Runs at image build time. Only touches eveseat/web's assets view, and refuses to
 * patch (failing the build) if the expected markers are not found exactly once, so a
 * future SeAT update can never be patched blindly.
 *
 * The picker lists the characters of the pilot who owns the viewed character, so on
 * your own character the buttons select your own alts. SeAT still checks permissions
 * for every selected character exactly as before; nothing here widens access.
 */

$file = $argv[1] ?? 'vendor/eveseat/web/src/resources/views/character/assets/assets.blade.php';
$view = file_get_contents($file);
if ($view === false) {
    fwrite(STDERR, "Cannot read $file\n");
    exit(1);
}

if (str_contains($view, 'dt-select-all-characters')) {
    echo "Assets view already patched.\n";
    exit(0);
}

$select_marker = '<select multiple="multiple" id="dt-character-selector"';
if (substr_count($view, $select_marker) !== 1) {
    fwrite(STDERR, "Character picker marker not found exactly once in $file; SeAT changed this page, re-check the patch.\n");
    exit(1);
}

$buttons = <<<'HTML'
<div class="btn-group btn-group-sm mb-2" role="group" aria-label="Character selection">
          <button type="button" class="btn btn-default" id="dt-select-all-characters">
            <i class="fas fa-users"></i> All characters
          </button>
          <button type="button" class="btn btn-default" id="dt-select-this-character" data-character-id="{{ $character->character_id }}">
            <i class="fas fa-user"></i> Only this character
          </button>
        </div>
HTML;

$view = str_replace($select_marker, $buttons . "\n        " . $select_marker, $view);

$script = <<<'BLADE'

@push('javascript')
  <script>
    // The Devil's Rage: quick character selection for the assets table.
    $(document).ready(function () {
      var selector = $('#dt-character-selector');

      $('#dt-select-all-characters').on('click', function () {
        var ids = selector.find('option').map(function () { return this.value; }).get();
        selector.val(ids).trigger('change');
      });

      $('#dt-select-this-character').on('click', function () {
        selector.val([String($(this).data('character-id'))]).trigger('change');
      });
    });
  </script>
@endpush

BLADE;

$view = rtrim($view) . "\n" . $script;

if (file_put_contents($file, $view) === false) {
    fwrite(STDERR, "Cannot write $file\n");
    exit(1);
}

echo "Assets view patched: All characters / Only this character buttons added.\n";
