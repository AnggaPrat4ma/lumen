<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Event;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class GenerateEventSlugs extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'events:generate-slugs';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate slugs for all events that don\'t have one';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('Starting slug generation...');
        $this->newLine();

        // Get events without slug
        $events = Event::whereNull('slug')
            ->orWhere('slug', '')
            ->get();

        if ($events->isEmpty()) {
            $this->info('✅ All events already have slugs!');
            return 0;
        }

        $this->info("Found {$events->count()} events without slugs");
        $this->newLine();

        $bar = $this->output->createProgressBar($events->count());
        $bar->start();

        $successCount = 0;
        $errorCount = 0;

        foreach ($events as $event) {
            try {
                $slug = $this->generateUniqueSlug($event->nama_event, $event->id_event);
                
                $event->slug = $slug;
                $event->save();

                $this->newLine();
                $this->line("✅ Generated: {$event->nama_event} → {$slug}");
                
                $successCount++;
            } catch (\Exception $e) {
                $this->newLine();
                $this->error("❌ Failed: {$event->nama_event} - {$e->getMessage()}");
                $errorCount++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        // Summary
        $this->info("=================================");
        $this->info("Slug Generation Complete!");
        $this->info("=================================");
        $this->line("✅ Success: {$successCount}");
        
        if ($errorCount > 0) {
            $this->error("❌ Failed: {$errorCount}");
        }
        
        $this->newLine();

        return 0;
    }

    /**
     * Generate unique slug
     */
    private function generateUniqueSlug($nama_event, $ignoreId = null)
    {
        $slug = Str::slug($nama_event);
        $originalSlug = $slug;
        $count = 1;

        // Ensure uniqueness
        while ($this->slugExists($slug, $ignoreId)) {
            $slug = $originalSlug . '-' . $count;
            $count++;
        }

        return $slug;
    }

    /**
     * Check if slug exists
     */
    private function slugExists($slug, $ignoreId = null)
    {
        $query = Event::where('slug', $slug);
        
        if ($ignoreId) {
            $query->where('id_event', '!=', $ignoreId);
        }
        
        return $query->exists();
    }
}