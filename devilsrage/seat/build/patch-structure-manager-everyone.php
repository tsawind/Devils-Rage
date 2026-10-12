<?php
/*
 * The Devil's Rage: let structure-manager's role mention boxes ping @everyone / @here.
 * Its WebhookDispatcher::formatMention() only understands role or user IDs; Discord
 * ignores @everyone sent as a role ID, so "@everyone", "<@everyone>", "@here" and
 * "<@here>" are turned into the real everyone/here mention with allowed_mentions.
 *
 * Usage (image build time): php patch-structure-manager-everyone.php <WebhookDispatcher.php>
 * The marker must appear exactly once, otherwise the build fails. Idempotent.
 */

const MARKER = "        // Unrecognized format — drop silently";
const TAG = 'dr_everyone';

$file = $argv[1] ?? '';
$src = @file_get_contents($file);
if ($src === false) {
    fwrite(STDERR, "Cannot read $file\n");
    exit(1);
}
if (str_contains($src, TAG)) {
    echo "already patched: $file\n";
    exit(0);
}
if (substr_count($src, MARKER) !== 1) {
    fwrite(STDERR, "Marker not found exactly once in $file; structure-manager changed, re-check the patch.\n");
    exit(1);
}

$insert = <<<'PHP'
        // The Devil's Rage (dr_everyone): @everyone / @here typed in a role box.
        if (preg_match('/^<?@(everyone|here)>?$/i', $mention, $m)) {
            $who = strtolower($m[1]);

            return ["@{$who} ", ['parse' => ['everyone'], 'users' => [], 'roles' => []]];
        }


PHP;

if (file_put_contents($file, str_replace(MARKER, $insert . MARKER, $src)) === false) {
    fwrite(STDERR, "Cannot write $file\n");
    exit(1);
}
echo "patched: $file\n";
