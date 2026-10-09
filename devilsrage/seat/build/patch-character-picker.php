<?php
/*
 * The Devil's Rage: adds "All characters" / "Only this character" buttons above the
 * character picker on SeAT's character pages (Assets, Fittings, Wallet, Mail, Contracts,
 * Industry, Killmails, ...), so a pilot can see/search all their own characters at once.
 *
 * Usage (image build time): php patch-character-picker.php <blade view> [<blade view> ...]
 * Each file must contain the picker marker exactly once, otherwise the script fails
 * (and with it the build), so a future SeAT update can never be patched blindly.
 * Running it twice on a file is harmless.
 *
 * The picker lists the characters of the pilot who owns the viewed character, so on
 * your own character the buttons select your own alts. SeAT still checks permissions
 * for every selected character exactly as before; nothing here widens access.
 */

const SELECT_MARKER = '<select multiple="multiple" id="dt-character-selector"';

const BUTTONS = <<<'HTML'
<div class="btn-group btn-group-sm mb-2" role="group" aria-label="Character selection">
          <button type="button" class="btn btn-default" id="dt-select-all-characters">
            <i class="fas fa-users"></i> All characters
          </button>
          <button type="button" class="btn btn-default" id="dt-select-this-character" data-character-id="{{ $character->character_id }}">
            <i class="fas fa-user"></i> Only this character
          </button>
        </div>
HTML;

// Uses the page's own picker: setting its value and firing "change" runs SeAT's existing
// handler on every page, which reloads that page's table(s) for the chosen characters.
const SCRIPT = <<<'BLADE'

@push('javascript')
  <script>
    // The Devil's Rage: quick character selection.
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

function patch_view(string $file): bool
{
    $view = @file_get_contents($file);
    if ($view === false) {
        fwrite(STDERR, "Cannot read $file\n");
        return false;
    }

    if (str_contains($view, 'dt-select-all-characters')) {
        echo "already patched: $file\n";
        return true;
    }

    if (substr_count($view, SELECT_MARKER) !== 1) {
        fwrite(STDERR, "Character picker marker not found exactly once in $file; SeAT changed this page, re-check the patch.\n");
        return false;
    }

    $view = str_replace(SELECT_MARKER, BUTTONS . "\n        " . SELECT_MARKER, $view);
    $view = rtrim($view) . "\n" . SCRIPT;

    if (file_put_contents($file, $view) === false) {
        fwrite(STDERR, "Cannot write $file\n");
        return false;
    }

    echo "patched: $file\n";
    return true;
}

$files = array_slice($argv, 1);
if ($files === []) {
    fwrite(STDERR, "No view files given.\n");
    exit(1);
}

$ok = true;
foreach ($files as $file) {
    $ok = patch_view($file) && $ok;
}

exit($ok ? 0 : 1);
