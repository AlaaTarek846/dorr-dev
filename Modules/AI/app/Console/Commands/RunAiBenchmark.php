<?php

namespace Modules\AI\Console\Commands;

use Illuminate\Console\Command;
use Modules\Admin\Models\Admin;
use Modules\AI\Models\AiBenchmarkRun;
use Modules\AI\Services\AiBenchmarkRunner;

/**
 * v2.0 requirements doc §19: headless batch entry point for Benchmark
 * DORR, so a run can be triggered from CI/cron as well as the admin UI
 * (AiBenchmarkRunController::store()), sharing the same AiBenchmarkRunner
 * so results are scored identically either way.
 *
 * Routing needs an Authenticatable "owner" (AiRoutingEngine::resolve());
 * a scheduled/CLI run has no logged-in admin, so it uses the first admin
 * account found purely as a routing identity (global-scope routing
 * policies do not actually read anything else off it - see
 * AiRoutingEngine::resolve()). If no admin account exists yet, the
 * command fails loudly rather than silently using a fake identity.
 */
class RunAiBenchmark extends Command
{
    protected $signature = 'ai:run-benchmark {--provider= : Force a specific ai_providers.id instead of live routing} {--domain= : Restrict to one domain_key}';

    protected $description = 'Run the Benchmark DORR case bank through the real chat pipeline and score the results.';

    public function handle(AiBenchmarkRunner $runner): int
    {
        $admin = Admin::query()->orderBy('id')->first();

        if (! $admin) {
            $this->error('No admin account exists to route the benchmark run through - create one first.');

            return self::FAILURE;
        }

        $this->info('Running Benchmark DORR...');

        $run = $runner->run(
            $admin,
            $this->option('provider') ? (int) $this->option('provider') : null,
            $this->option('domain') ?: null,
        );

        $this->table(
            ['Run #', 'Status', 'Total', 'Passed', 'Abstained', 'Pass rate'],
            [[
                $run->id,
                $run->status,
                $run->total_cases,
                $run->passed_cases,
                $run->abstained_cases,
                $run->pass_rate !== null ? $run->pass_rate.'%' : 'n/a',
            ]]
        );

        if ($run->status !== AiBenchmarkRun::STATUS_COMPLETED) {
            $this->warn('Run did not complete cleanly - check ai_benchmark_results.failure_reason for details.');
        }

        return self::SUCCESS;
    }
}
