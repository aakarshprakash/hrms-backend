<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Moves employee documents uploaded before they were made private off the
 * public disk (where anyone with the URL could download them) onto the
 * private disk. Idempotent; safe to run on every deploy.
 */
class SecureEmployeeDocuments extends Command
{
    protected $signature = 'hrms:secure-documents {--dry-run : Only report what would move}';

    protected $description = 'Move employee documents from the public disk to private storage';

    public function handle(): int
    {
        $moved = 0;
        $missing = 0;

        Media::query()
            ->where('collection_name', 'documents')
            ->where('disk', 'public')
            ->orderBy('id')
            ->each(function (Media $media) use (&$moved, &$missing) {
                $relative = $media->getPathRelativeToRoot();
                $public = Storage::disk('public');

                if (! $public->exists($relative)) {
                    $missing++;
                    $this->warn("Missing file for media #{$media->id}: {$relative}");

                    return;
                }

                if ($this->option('dry-run')) {
                    $this->line("Would move #{$media->id} {$relative}");
                    $moved++;

                    return;
                }

                Storage::disk('local')->put($relative, $public->readStream($relative));
                $media->forceFill(['disk' => 'local', 'conversions_disk' => 'local'])->save();
                $public->delete($relative);
                $moved++;
            });

        $this->info(($this->option('dry-run') ? 'Would move' : 'Moved') . " {$moved} document(s); {$missing} missing.");

        return self::SUCCESS;
    }
}
