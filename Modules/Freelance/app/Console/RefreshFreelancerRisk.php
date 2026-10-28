<?php

namespace Modules\Freelance\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Freelance\Models\Freelancer;
use Modules\User\Models\User;
use Modules\Freelance\Services\Moderation\QualityScore;

/** Daily (FL-707): the quality score and risk level kept on each freelancer for the admin list and its filter. It changes nothing else. */
class RefreshFreelancerRisk extends Command
{
    protected $signature = 'freelance:refresh-risk';

    protected $description = 'Recompute the internal quality score and risk level of freelancers (FL-707)';

    public function handle(QualityScore $quality): int
    {
        $n = 0;
        Freelancer::query()->select('*')->chunkById(200, function ($list) use ($quality, &$n): void {
            foreach ($list as $f) {
                $quality->refresh($f);
                $n++;
            }
        });
        // Clients who ever started a project (a Dorr user with none has nothing to score).
        User::query()->whereIn('id', DB::table('freelance_projects')->select('client_id'))->chunkById(200, function ($list) use ($quality, &$n): void {
            foreach ($list as $client) {
                $quality->refresh($client);
                $n++;
            }
        });
        $this->info($n.' people checked.');

        return self::SUCCESS;
    }
}
