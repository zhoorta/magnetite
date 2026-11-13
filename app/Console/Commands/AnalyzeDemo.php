<?php

namespace App\Console\Commands;

use App\Services\TrackAnalyzer;
use App\Support\DemoTracks;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('magnetite:demo-analyze')]
#[Description('Measure the demo tracks and write resources/demo/analysis.json, which the demo library shows to visitors')]
class AnalyzeDemo extends Command
{
    public function handle(TrackAnalyzer $analyzer): int
    {
        if ($missing = $analyzer->missing()) {
            $this->error('Needed but not found: '.implode(', ', $missing).'.');

            return self::FAILURE;
        }

        $tracks = DemoTracks::available();
        if ($tracks === []) {
            $this->error('No demo files in '.config('magnetite.demo_path').': run bin/prepare-demo.sh first.');

            return self::FAILURE;
        }

        // What is already measured stays for the tracks whose file is not here.
        $results = DemoTracks::analysis();
        foreach ($tracks as $id => $track) {
            try {
                $results[$id] = $analyzer->measure(DemoTracks::path($track['file']));
                $this->line(sprintf('%s: DR%s, %s LUFS', $id, $results[$id]['dr']['rounded'] ?? '?', $results[$id]['loudness']['integrated'] ?? '?'));
            } catch (Throwable $e) {
                $this->error("{$id}: {$e->getMessage()}");

                return self::FAILURE;
            }
        }

        ksort($results);
        file_put_contents(DemoTracks::analysisPath(), json_encode($results, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
        $this->info('Wrote '.DemoTracks::analysisPath());

        return self::SUCCESS;
    }
}
