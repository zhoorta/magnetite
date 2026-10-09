<?php

namespace App\Console\Commands;

use App\Models\Track;
use App\Services\TrackAnalyzer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

#[Signature('magnetite:analyze {--force : Measure every track again, not just the new or changed ones} {--limit= : Stop after this many tracks}')]
#[Description('Measure loudness, dynamic range, clipping and the spectrum of the tracks, to tell good files from bad ones')]
class AnalyzeLibrary extends Command
{
    public function handle(TrackAnalyzer $analyzer): int
    {
        if ($missing = $analyzer->missing()) {
            $this->error('Needed but not found: '.implode(', ', $missing).'. Install them or set MAGNETITE_FFMPEG, MAGNETITE_FFPROBE and MAGNETITE_NODE.');

            return self::FAILURE;
        }

        $tracks = Track::query()->with('user')->whereNotNull('user_id')
            ->when(! $this->option('force'), fn ($q) => $q->where(fn ($q) => $q->whereNull('analysis_version')->orWhere('analysis_version', '<', TrackAnalyzer::VERSION)))
            ->orderBy('id')
            ->when($this->option('limit'), fn ($q, $n) => $q->limit((int) $n))
            ->get();

        if ($tracks->isEmpty()) {
            $this->info('Everything is analysed already.');

            return self::SUCCESS;
        }

        $done = $failed = 0;
        $this->withProgressBar($tracks, function (Track $track) use ($analyzer, &$done, &$failed) {
            try {
                $analyzer->analyze($track);
                $done++;
            } catch (Throwable $e) {
                // One broken file must not stop the rest.
                Log::warning('Analysis failed for a file', ['path' => $track->path, 'error' => $e->getMessage()]);
                $this->newLine();
                $this->warn("  {$track->path}: ".$e->getMessage());
                $failed++;
            }
        });

        $this->newLine(2);
        $this->table(['analysed', 'failed'], [[$done, $failed]]);

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
