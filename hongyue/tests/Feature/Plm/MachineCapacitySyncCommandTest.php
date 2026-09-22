<?php

namespace Tests\Feature\Plm;

use App\Models\Machine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\WithSeedData;
use Tests\TestCase;

class MachineCapacitySyncCommandTest extends TestCase
{
    use RefreshDatabase, WithSeedData;

    public function test_sync_command_preserves_capacities_maintained_in_machine_management(): void
    {
        Machine::where('code', 'P-01')->update(['capacity_per_hour' => 550]);
        Machine::where('code', 'P-02')->update(['capacity_per_hour' => 540]);
        Machine::where('code', 'P-03')->update(['capacity_per_hour' => 1000]);

        $this->assertSame(0, Artisan::call('schedule:sync-line-capacity'));

        $this->assertSame(550.0, (float) Machine::where('code', 'P-01')->value('capacity_per_hour'));
        $this->assertSame(540.0, (float) Machine::where('code', 'P-02')->value('capacity_per_hour'));
        $this->assertSame(1000.0, (float) Machine::where('code', 'P-03')->value('capacity_per_hour'));
    }
}
