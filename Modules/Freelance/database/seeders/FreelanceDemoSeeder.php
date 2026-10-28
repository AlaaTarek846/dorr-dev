<?php

namespace Modules\Freelance\Database\Seeders;

use App\Models\Country;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Modules\Freelance\Enums\FreelanceProjectStatus as Status;
use Modules\Freelance\Models\FreelanceProject;
use Modules\Freelance\Models\FreelanceSkill;
use Modules\Freelance\Models\FreelanceSpecialty;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Models\FreelancerAvailability;
use Modules\Freelance\Models\FreelancerExperience;
use Modules\Freelance\Models\FreelancerPortfolioItem;
use Modules\Freelance\Models\FreelanceVerificationRequest;
use Modules\Freelance\Services\Policy\FreelancePolicies;
use Modules\Freelance\Services\Projects\ProjectStateMachine;
use Modules\User\Models\User;
use Modules\Wallet\Enums\WalletBucket;
use Modules\Wallet\Enums\WalletTransactionType;
use Modules\Wallet\Services\WalletService;

/**
 * Demo data for trying freelance work end to end (development only — it refuses to run in production). Everything is keyed
 * (phone for people, client + title for projects), so running it again updates the same rows and never duplicates them.
 *
 *   php artisan db:seed --class="Modules\Freelance\Database\Seeders\FreelanceDemoSeeder"
 *
 * Sign-in: the phones below, with the development OTP. Needs the catalogs, the switches and the countries seeded first
 * (DatabaseSeeder does it in that order). Works in every active country where the freelance service is switched on.
 */
class FreelanceDemoSeeder extends Seeder
{
    /** @var list<array{0: string, 1: string}> name, city */
    private const CLIENTS = [['Mona Client', 'Riyadh'], ['Khaled Client', 'Jeddah'], ['Huda Client', 'Dammam'], ['Omar Client', 'Riyadh']];

    /** @var list<array{0: string, 1: string, 2: string, 3: string, 4: list<string>, 5: list<string>}> name, headline, status, specialty (en), skills, more specialties */
    private const FREELANCERS = [
        ['Sara Developer', 'Laravel and Vue developer', 'verified', 'Web development', ['Laravel', 'PHP', 'Vue.js', 'JavaScript'], ['Software & APIs']],
        ['Yousef Mobile', 'Android and Flutter apps', 'verified', 'Mobile apps', ['Kotlin', 'Flutter'], []],
        ['Lina Designer', 'Logo and brand identity', 'verified', 'Logo & branding', ['Logo design', 'Brand identity', 'Illustrator'], []],
        ['Ahmad Data', 'Data analysis and machine learning', 'verified', 'Data & AI', ['Python', 'Data analysis', 'Machine learning'], []],
        ['Nora UX', 'Product and UX design', 'verification_submitted', 'UI / UX design', ['Figma', 'User research'], []],
        ['Faisal New', 'Just joined', 'registered', '', [], []],
        ['Reem Profile', 'Profile ready, not sent yet', 'profile_ready', 'Web development', ['WordPress'], []],
        ['Tariq Rejected', 'Verification was rejected', 'verification_rejected', 'Software & APIs', ['REST APIs'], []],
    ];

    /** @var list<array{0: string, 1: string, 2: string, 3: list<string>, 4: int, 5: int, 6: int}> title, description, specialty (en), skills, budget min, max (SAR), days */
    private const TEMPLATES = [
        ['A booking website for my clinic', 'A small site where patients pick a doctor, a day and a time, and get a reminder by message.', 'Web development', ['Laravel', 'Vue.js'], 3000, 5000, 30],
        ['Android app for a delivery shop', 'An app to browse products, order and follow the delivery, with push notifications.', 'Mobile apps', ['Kotlin'], 8000, 12000, 60],
        ['Logo and brand identity for a cafe', 'A logo, colours and a small brand guide for a new specialty coffee shop.', 'Logo & branding', ['Logo design', 'Brand identity'], 800, 1500, 14],
        ['Sales dashboard from our spreadsheets', 'Turn monthly sales spreadsheets into a clear dashboard and a short monthly report.', 'Data & AI', ['Data analysis', 'Python'], 2000, 3500, 21],
        ['UX review of our checkout', 'Review the checkout flow of our online store and propose fixes with mockups.', 'UI / UX design', ['Figma', 'User research'], 1500, 2500, 10],
        ['REST API for an inventory system', 'A documented REST API for products, stock and orders, with tests.', 'Software & APIs', ['REST APIs', 'Laravel'], 4000, 6000, 30],
        ['WordPress site for a law office', 'A five-page bilingual site with a contact form and a news section.', 'Web development', ['WordPress'], 1200, 2000, 20],
        ['Test plan and automation for a web app', 'Write a test plan and automate the main user journeys.', 'QA & testing', ['Test automation'], 2500, 4000, 25],
    ];

    /** The status of each of the 24 demo projects, in order. */
    private const STATUSES = [
        'published', 'published', 'published', 'published', 'published', 'published', 'published', 'published', 'published', 'published',
        'pending_review', 'pending_review', 'pending_review', 'pending_review', 'pending_review',
        'changes_requested', 'changes_requested', 'rejected', 'rejected',
        'draft', 'draft', 'draft', 'cancelled', 'cancelled',
    ];

    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->error('FreelanceDemoSeeder only runs outside production.');

            return;
        }

        $countries = Country::query()->where('status', true)->with('currency')->get()
            ->filter(fn (Country $c) => \Modules\Freelance\Models\FreelanceFeatureRule::query()->where('country_id', $c->id)->where('feature', 'freelance')->where('enabled', true)->exists());
        if ($countries->isEmpty()) {
            $this->command?->warn('No country has the freelance service switched on: run FreelanceSettingsSeeder first.');

            return;
        }

        foreach ($countries as $index => $country) {
            $this->country($country, $index);
        }
        FreelancePolicies::forget();
    }

    private function country(Country $country, int $index): void
    {
        $base = 500000000 + $index * 1000;
        $dial = $country->dial_code;

        // A guide minimum for projects, so the budget rules can be seen working (an admin can change or remove it).
        if (app(FreelancePolicies::class)->own($country->id, null) === null) {
            app(FreelancePolicies::class)->save($country->id, null, ['project.min_budget_minor' => 50000]);
        }

        $clients = [];
        foreach (self::CLIENTS as $i => [$name, $city]) {
            $clients[] = User::query()->updateOrCreate(
                ['phone' => $dial.($base + 900 + $i)],
                ['name' => $name, 'country_id' => $country->id, 'status' => 'active', 'phone_verified_at' => now()],
            );
        }

        $freelancers = [];
        foreach (self::FREELANCERS as $i => $row) {
            $freelancers[] = $this->freelancer($country, $dial.($base + 100 + $i), $row);
        }

        $this->projects($country, $clients);
        $this->offers($freelancers, $clients);
        $this->command?->info("Freelance demo ({$country->code}): ".count($clients).' clients, '.count($freelancers).' freelancers, '.count(self::STATUSES).' projects. Phones '.$dial.($base + 100).' … ('.($dial.($base + 900)).' clients).');
    }

    /** @param  array{0: string, 1: string, 2: string, 3: string, 4: list<string>, 5: list<string>}  $row */
    private function freelancer(Country $country, string $phone, array $row): Freelancer
    {
        [$name, $headline, $status, $specialty, $skills, $more] = $row;
        $verified = $status === 'verified';

        $f = Freelancer::query()->updateOrCreate(['phone' => $phone], [
            'name' => $name, 'headline' => $headline, 'bio' => $headline.'. Demo profile for trying the app.', 'years_of_experience' => 3 + strlen($name) % 7,
            'contact_languages' => ['ar', 'en'], 'city' => 'Riyadh', 'country_id' => $country->id, 'phone_verified_at' => now(), 'status' => $status,
            'verified_at' => $verified ? now() : null, 'terms_accepted_at' => now(), 'terms_version' => (string) config('freelance.terms_version'),
        ]);

        $specialtyIds = collect([$specialty, ...$more])->filter()->map(fn ($en) => $this->specialty($en)?->id)->filter()->values();
        $f->specialties()->sync($specialtyIds->mapWithKeys(fn ($id, $k) => [$id => ['is_primary' => $k === 0]])->all());
        $f->skills()->sync(collect($skills)->map(fn ($en) => $this->skill($en)?->id)->filter()->mapWithKeys(fn ($id) => [$id => ['level' => 'intermediate']])->all());

        if ($status !== 'registered') {
            FreelancerExperience::query()->updateOrCreate(['freelancer_id' => $f->id, 'title' => 'Senior '.$headline], ['company' => 'Demo Studio', 'started_on' => now()->subYears(3)->toDateString(), 'is_current' => true]);
            $item = FreelancerPortfolioItem::query()->updateOrCreate(
                ['freelancer_id' => $f->id, 'title' => 'Sample project by '.$name],
                ['description' => 'A finished piece of work, shown here for demonstration.', 'completed_on' => now()->subMonths(2)->toDateString(), 'visibility' => 'public', 'rights_confirmed_at' => now(), 'freelance_specialty_id' => $specialtyIds->first(), 'sort_order' => 1],
            );
            // Plain generated pictures (GD): an avatar, and two images for the work — added once, not again on a second run.
            if (! $f->hasMedia('avatar')) {
                $f->setSingleMedia('avatar', $this->picture($name, 400, initials: true));
            }
            if ($item->getMedia('images')->isEmpty()) {
                foreach ([1, 2] as $n) {
                    $item->addImage($this->picture($name.' '.$n, 800, 600));
                }
            }
        }

        // Verification in each state the screens show.
        if ($status === 'verification_submitted') {
            FreelanceVerificationRequest::query()->updateOrCreate(['freelancer_id' => $f->id, 'status' => FreelanceVerificationRequest::SUBMITTED], ['submitted_at' => now()->subDay()]);
        }
        if ($status === 'verification_rejected') {
            FreelanceVerificationRequest::query()->updateOrCreate(['freelancer_id' => $f->id, 'status' => FreelanceVerificationRequest::REJECTED], ['submitted_at' => now()->subDays(3), 'decided_at' => now()->subDay(), 'reason' => 'The ID photo is not readable.']);
        }

        if ($verified) {
            // A guide price, weekly hours; one of them is paused so the notice on the home can be seen.
            FreelancerAvailability::query()->updateOrCreate(['freelancer_id' => $f->id], [
                'accepting_requests' => $name !== 'Ahmad Data', 'paused_until' => $name === 'Ahmad Data' ? now()->addDays(10)->toDateString() : null,
                'rate_type' => 'hourly', 'rate_min_minor' => 10000, 'rate_max_minor' => 30000,
                'weekly_hours' => [['day' => 0, 'from' => '09:00', 'to' => '17:00'], ['day' => 1, 'from' => '09:00', 'to' => '17:00'], ['day' => 2, 'from' => '09:00', 'to' => '17:00']],
            ]);
            // Some money to look at (once: a second run does not add more).
            $wallets = app(WalletService::class);
            $wallet = $wallets->firstOrCreateWallet($f, $country);
            if ((int) $wallet->withdrawable_minor === 0) {
                $wallets->credit($wallet, 250000, WalletBucket::Withdrawable, WalletTransactionType::Topup);
            }
        }

        return $f;
    }

    /** @param  list<User>  $clients */
    private function projects(Country $country, array $clients): void
    {
        $machine = app(ProjectStateMachine::class);
        foreach (self::STATUSES as $i => $statusValue) {
            [$title, $description, $specialty, $skills, $min, $max, $days] = self::TEMPLATES[$i % count(self::TEMPLATES)];
            $client = $clients[$i % count($clients)];
            $status = Status::from($statusValue);
            $round = intdiv($i, count(self::TEMPLATES));
            $title = $round === 0 ? $title : $title.' ('.($round + 1).')';
            $range = $i % 2 === 0;

            $project = FreelanceProject::query()->updateOrCreate(['client_id' => $client->id, 'title' => $title], [
                'country_id' => $country->id, 'freelance_specialty_id' => $this->specialty($specialty)?->id, 'description' => $description,
                'budget_type' => $range ? 'range' : 'fixed', 'budget_min_minor' => ($range ? $min : $max) * 100, 'budget_max_minor' => $range ? $max * 100 : null,
                'currency_code' => (string) $country->currency?->code, 'duration_days' => $days, 'visibility' => $i % 6 === 5 ? 'private' : 'public',
                'status' => $status,
                'status_reason' => match ($status) {
                    Status::ChangesRequested => 'Say how the patients are reminded, and which languages the site needs.',
                    Status::Rejected => 'The description is too vague to publish. Add what exactly you need and send it again.',
                    default => null,
                },
                'submitted_at' => $status === Status::Draft ? null : now()->subDays(8 - min($i, 7)),
                'published_at' => $status === Status::Published ? now()->subDays($i % 9)->subHours($i) : null,
                'closed_at' => $status === Status::Cancelled ? now()->subDay() : null,
                // The offers window: most are open (a few days left), one ran out, one was closed by its client.
                'offers_deadline_at' => $status === Status::Published ? ($i === 8 ? now()->subDays(2) : now()->addDays(14 - $i % 9)) : null,
                'offers_closed_at' => $status === Status::Published && $i === 9 ? now()->subDay() : null,
                'offers_close_reason' => $status === Status::Published && $i === 9 ? 'client' : null,
            ]);
            $project->skills()->sync(collect($skills)->map(fn ($en) => $this->skill($en)?->id)->filter()->all());

            if ($project->events()->doesntExist()) {
                $machine->record($project, 'project.created', 'client', $client->id, [], null, Status::Draft);
                if ($status !== Status::Draft) {
                    $machine->record($project, 'project.submitted', 'client', $client->id, [], Status::Draft, Status::PendingReview);
                }
                $decided = ['published' => 'project.published', 'changes_requested' => 'project.changes_requested', 'rejected' => 'project.rejected', 'cancelled' => 'project.cancelled'][$statusValue] ?? null;
                if ($decided !== null) {
                    $machine->record($project, $decided, $status === Status::Cancelled ? 'client' : 'admin', $status === Status::Cancelled ? $client->id : null, [], Status::PendingReview, $status);
                }
            }
        }
    }

    /** A plain picture: a colour taken from the text, with the initials (or a few shapes) on it — a PNG upload ready to store. */
    private function picture(string $text, int $width, int $height = 0, bool $initials = false): UploadedFile
    {
        $height = $height ?: $width;
        $hash = crc32($text);
        $image = imagecreatetruecolor($width, $height);
        $base = [60 + $hash % 120, 60 + ($hash >> 8) % 120, 60 + ($hash >> 16) % 120];
        imagefill($image, 0, 0, imagecolorallocate($image, ...$base));
        $light = imagecolorallocate($image, min(255, $base[0] + 70), min(255, $base[1] + 70), min(255, $base[2] + 70));
        $white = imagecolorallocate($image, 255, 255, 255);

        if ($initials) {
            imagefilledellipse($image, intdiv($width, 2), intdiv($height, 2), (int) ($width * 0.9), (int) ($height * 0.9), $light);
            $letters = collect(explode(' ', $text))->map(fn ($w) => mb_substr($w, 0, 1))->take(2)->implode('');
            // The built-in font is small, so the letters are drawn on a tiny canvas and scaled up.
            $tiny = imagecreatetruecolor(24, 16);
            imagefill($tiny, 0, 0, $light);
            imagestring($tiny, 5, 12 - (int) (strlen($letters) * 4.5), 0, $letters, $white);
            imagecopyresampled($image, $tiny, intdiv($width, 2) - (int) ($width * 0.3), intdiv($height, 2) - (int) ($width * 0.2), 0, 0, (int) ($width * 0.6), (int) ($width * 0.4), 24, 16);
            imagedestroy($tiny);
        } else {
            imagefilledrectangle($image, (int) ($width * 0.1), (int) ($height * 0.15), (int) ($width * 0.55), (int) ($height * 0.6), $light);
            imagefilledellipse($image, (int) ($width * 0.72), (int) ($height * 0.62), (int) ($width * 0.4), (int) ($width * 0.4), $white);
            imagefilledrectangle($image, 0, (int) ($height * 0.85), $width, $height, $light);
        }

        $path = tempnam(sys_get_temp_dir(), 'demo').'.png';
        imagepng($image, $path);
        imagedestroy($image);

        return new UploadedFile($path, Str::slug($text).'.png', 'image/png', null, true);
    }

    /**
     * A few offers from the verified freelancers on the open public projects (FL-301), with the commission the policy gives — enough to
     * see «my offer» on the freelancer app and «offers received» on the Dorr app. Once per freelancer and project.
     *
     * @param  list<Freelancer>  $freelancers
     * @param  list<User>  $clients
     */
    private function offers(array $freelancers, array $clients): void
    {
        $offers = app(\Modules\Freelance\Services\Offers\FreelancerOffers::class);
        $verified = array_values(array_filter($freelancers, fn (Freelancer $f) => $f->status?->value === 'verified'));
        $open = FreelanceProject::query()->where('status', Status::Published->value)->where('visibility', 'public')->whereNull('offers_closed_at')
            ->where('offers_deadline_at', '>', now())->whereIn('client_id', array_map(fn (User $u) => $u->id, $clients))->orderBy('id')->get();

        foreach ($open as $i => $project) {
            foreach ($verified as $j => $freelancer) {
                // Not every freelancer on every project: about half.
                if (($i + $j) % 2 === 1) {
                    continue;
                }
                $price = (int) round(($project->budget_min_minor ?? 100000) * (0.9 + 0.05 * $j));
                $preview = $offers->preview($project, $price);
                \Modules\Freelance\Models\FreelanceOffer::query()->firstOrCreate(['project_id' => $project->id, 'freelancer_id' => $freelancer->id], [
                    'price_minor' => $price, 'currency_code' => $project->currency_code, 'duration_days' => max(3, (int) $project->duration_days - $j * 2),
                    'details' => 'I have done similar work before. I will share a plan on day one and a working version every week until delivery.',
                    'status' => 'submitted', 'commission_minor' => $preview['commission_minor'], 'net_minor' => $preview['net_minor'],
                    'fees' => $preview['commission'], 'free' => true,
                ]);
            }
        }
    }

    private function specialty(string $english): ?FreelanceSpecialty
    {
        return FreelanceSpecialty::query()->whereHas('translations', fn ($q) => $q->where('locale', 'en')->where('name', $english))->first();
    }

    private function skill(string $english): ?FreelanceSkill
    {
        return FreelanceSkill::query()->whereHas('translations', fn ($q) => $q->where('locale', 'en')->where('name', $english))->first();
    }
}
