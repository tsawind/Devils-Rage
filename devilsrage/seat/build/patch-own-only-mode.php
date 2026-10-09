<?php
/*
 * The Devil's Rage: "My characters only" mode for directors, CEOs and SeAT admins.
 *
 * A button next to the top search bar switches the mode on/off for the current login
 * session. While it is on, SeAT's character lists (the top search bar's Character
 * Assets / Skills / Mail tabs, the Characters list, ...) only contain the viewer's own
 * characters. It only ever NARROWS what is shown; it never grants access to anything.
 * Pages where you pick characters explicitly (the character picker) keep working as before.
 *
 * Usage (image build time):
 *   php patch-own-only-mode.php <CharacterScope.php> <includes/header.blade.php>
 * Each file must contain its marker exactly once, otherwise the script fails (and with it
 * the build). Running it twice is harmless.
 */

const TAG = 'dr_own_only';

function fail(string $msg): never
{
    fwrite(STDERR, $msg . "\n");
    exit(1);
}

function patch_file(string $file, string $marker, string $insert, bool $before): void
{
    $src = @file_get_contents($file);
    if ($src === false) {
        fail("Cannot read $file");
    }
    if (str_contains($src, TAG)) {
        echo "already patched: $file\n";
        return;
    }
    if (substr_count($src, $marker) !== 1) {
        fail("Marker not found exactly once in $file; SeAT changed this file, re-check the patch.");
    }
    $src = str_replace($marker, $before ? $insert . $marker : $marker . $insert, $src);
    if (file_put_contents($file, $src) === false) {
        fail("Cannot write $file");
    }
    echo "patched: $file\n";
}

[$scope_file, $header_file] = array_slice($argv, 1) + [null, null];
if ($scope_file === null || $header_file === null) {
    fail('Usage: php patch-own-only-mode.php <CharacterScope.php> <header.blade.php>');
}

// 1. CharacterScope: when the mode is on, only the viewer's own characters (before the admin shortcut).
patch_file($scope_file, "        if (auth()->user()->isAdmin())\n            return \$query;", <<<'PHP'
        // The Devil's Rage: "My characters only" mode (session toggle in the top bar).
        if (session('dr_own_only', false))
            return $query->whereIntegerInRaw($table . '.character_id', auth()->user()->associatedCharacterIds());


PHP, true);

// 2. Header: the toggle button, right after the top search form.
patch_file($header_file, "  <!-- /.search form -->", <<<'BLADE'

  <!-- The Devil's Rage: "My characters only" mode toggle -->
  @php
    if (request()->has('dr_own_only')) {
        session(['dr_own_only' => request()->query('dr_own_only') === '1']);
    }
    $drOwnOnly = (bool) session('dr_own_only', false);
    $drUser = auth()->user();
    $drIds = $drUser ? $drUser->associatedCharacterIds() : [];
    $drSeesMore = $drUser && ($drOwnOnly || $drUser->isAdmin()
        || \Seat\Eveapi\Models\Corporation\CorporationRole::whereIn('character_id', $drIds)
            ->where('role', 'Director')->where('type', 'roles')->exists()
        || \Seat\Eveapi\Models\Corporation\CorporationInfo::whereIn('ceo_id', $drIds)->exists());
  @endphp
  @if($drSeesMore)
    <ul class="navbar-nav ml-2">
      <li class="nav-item">
        <a href="{{ request()->fullUrlWithQuery(['dr_own_only' => $drOwnOnly ? '0' : '1']) }}"
           class="btn btn-sm {{ $drOwnOnly ? 'btn-success' : 'btn-outline-light' }}"
           title="{{ $drOwnOnly ? 'Showing only your own characters in search and lists. Click to show everything you are allowed to see.' : 'Showing everything you are allowed to see (director/CEO/admin). Click to show only your own characters.' }}">
          <i class="fas {{ $drOwnOnly ? 'fa-user' : 'fa-users' }}"></i>
          {{ $drOwnOnly ? 'My characters only' : 'Director view' }}
        </a>
      </li>
    </ul>
  @endif
BLADE, false);
