<?php

namespace Modules\Freelance\Services\Consultations;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Modules\Chat\Support\LiveKitEgress;
use Modules\Chat\Support\LiveKitRooms;
use Modules\Chat\Support\LiveKitToken;
use Modules\Chat\Support\RecordingStorage;
use Modules\Freelance\Exceptions\FreelanceException;
use Modules\Freelance\Models\FreelanceConsultation as Consultation;
use Modules\Freelance\Models\FreelanceConsultationAttendance as Attendance;
use Modules\Freelance\Models\FreelanceConsultationRecording as Recording;
use Modules\Freelance\Models\Freelancer;
use Modules\User\Models\User;
use Throwable;

/**
 * The consultation's video session (FL-L16).
 *
 * - **The room** (`consult-{uuid}`, two people at most) lets the client and the freelancer in from `open_before_minutes` before the start to
 *   the end; the server closes it at the end (everyone is put out) and the staff may close it earlier and open it again while time is left.
 *   Leaving by mistake is no harm: either side comes back in while the time lasts.
 * - **Attendance:** the app says when it is in the room (`connected`) and when it leaves; a stay the app never closed ends with the room.
 * - **Recording** is a condition of booking (both sides agreed). Every stretch of the session is recorded by LiveKit into the bucket — a
 *   new part when the room is entered again after everyone left — then moved to our private disk and taken off the bucket.
 * - **Keeping:** both sides download the parts; a week after the end they are deleted, unless there is a complaint: then the staff watch
 *   them and they are deleted a week after the decision. Every download and every viewing is written down.
 */
class ConsultationSessions
{
    public const SIDES = ['client', 'freelancer'];

    public function __construct(
        private readonly LiveKitRooms $rooms,
        private readonly LiveKitEgress $egress,
        private readonly RecordingStorage $storage,
        private readonly Consultations $consultations,
    ) {}

    /** A session can be held (LiveKit is set up). */
    public function available(): bool
    {
        return LiveKitToken::url() !== null && filled(config('chat.livekit.api_key')) && filled(config('chat.livekit.api_secret'));
    }

    /** The recording can be made (the bucket is set up). Until it is, sessions are held without one. */
    public function recordingAvailable(): bool
    {
        return $this->available() && $this->storage->configured();
    }

    // -------------------------------------------------------------- the room

    /**
     * A ticket into the room: LiveKit's address and a token good until the end. The room is made on the first ticket.
     *
     * @return array<string, mixed>
     */
    public function join(Consultation $c, string $side, Model $person): array
    {
        if (! $this->available()) {
            throw new FreelanceException('consultation_session_unavailable', 503);
        }
        $this->assertOpen($c);
        $room = $this->room($c);
        $identity = $side.'-'.$person->getKey();
        $ttl = max(60, (int) now()->diffInSeconds($c->ends_at, false) + 60);
        $token = LiveKitToken::issue($room, $identity, (string) $person->name, [
            'side' => $side, 'name' => (string) $person->name,
            'avatar' => method_exists($person, 'getFirstMediaUrl') ? ($person->getFirstMediaUrl('avatar') ?: null) : null,
        ], [], $ttl);

        return [
            'url' => LiveKitToken::url(), 'token' => $token, 'room' => $room, 'identity' => $identity,
            'starts_at' => $c->starts_at->toIso8601String(), 'ends_at' => $c->ends_at->toIso8601String(), 'server_time' => now()->toIso8601String(),
            'warn_before_end_minutes' => (int) $this->cfg('warn_before_end_minutes'),
            'recording' => $this->recordingAvailable(),
            'other' => $this->otherSide($c, $side),
        ];
    }

    /** The app is in the room: their stay starts, the recording starts if it is not running, and the other side hears they came. */
    public function connected(Consultation $c, string $side): array
    {
        $this->assertOpen($c);
        $first = false;
        DB::transaction(function () use ($c, $side, &$first): void {
            $locked = Consultation::query()->whereKey($c->id)->lockForUpdate()->first();
            $first = ! Attendance::query()->where('consultation_id', $c->id)->where('side', $side)->exists();
            // A stay the app never closed (it was killed) ends where the new one starts.
            Attendance::query()->where('consultation_id', $c->id)->where('side', $side)->whereNull('left_at')->update(['left_at' => now(), 'updated_at' => now()]);
            Attendance::query()->create(['consultation_id' => $c->id, 'side' => $side, 'joined_at' => now()]);
            $locked->forceFill(['session_opened_at' => $locked->session_opened_at ?? now()])->save();
        });
        $this->ensureRecording($c->refresh());
        if ($first) {
            $this->consultations->tell($c, $side === 'client' ? 'freelancer' : 'client', 'consultation.session_joined');
        }

        return $this->presentFor($c->refresh(), $side);
    }

    /** The app left the room. When nobody is left the recording part ends; coming back starts a new one. */
    public function leave(Consultation $c, string $side): array
    {
        Attendance::query()->where('consultation_id', $c->id)->where('side', $side)->whereNull('left_at')->update(['left_at' => now(), 'updated_at' => now()]);
        if (! Attendance::query()->where('consultation_id', $c->id)->whereNull('left_at')->exists()) {
            $this->stopRecording($c);
        }

        return $this->presentFor($c->refresh(), $side);
    }

    /** The staff close the room now (both are put out and cannot come back until they open it again). */
    public function closeByStaff(Consultation $c): Consultation
    {
        if ($c->room_name === null || $c->session_closed_at !== null) {
            throw new FreelanceException('consultation_session_not_open', 409);
        }
        $this->close($c, 'staff');
        foreach (self::SIDES as $side) {
            $this->consultations->tell($c, $side, 'consultation.session_closed');
        }

        return $c->refresh();
    }

    /** The staff open a room they closed, while its time is not over. */
    public function reopenByStaff(Consultation $c): Consultation
    {
        if ($c->session_closed_by !== 'staff' || now()->gte($c->ends_at) || $c->status !== Consultation::BOOKED) {
            throw new FreelanceException('consultation_session_cannot_reopen', 409);
        }
        $c->forceFill(['session_closed_at' => null, 'session_closed_by' => null])->save();
        foreach (self::SIDES as $side) {
            $this->consultations->tell($c, $side, 'consultation.session_reopened');
        }

        return $c->refresh();
    }

    /** The client: the freelancer never came in — a complaint for the staff, with the attendance as the proof. */
    public function noShow(Consultation $c, User $client): Consultation
    {
        $after = $c->starts_at->copy()->addMinutes((int) $this->cfg('no_show_minutes'));
        if ($c->status !== Consultation::BOOKED || now()->lt($after) || Attendance::query()->where('consultation_id', $c->id)->where('side', 'freelancer')->exists()) {
            throw new FreelanceException('consultation_no_show_invalid', 409);
        }

        return $this->consultations->complain($c, $client, __('freelance.sessions.no_show_complaint', ['minutes' => (int) $this->cfg('no_show_minutes')]));
    }

    // -------------------------------------------------------------- the recordings

    /**
     * A recorded part, for its signed link: the file on our disk, after writing down who took it.
     *
     * @param  'client'|'freelancer'|'staff'  $viewer
     */
    public function file(Recording $r, string $viewer, int $viewerId, string $action): string
    {
        abort_unless($r->status === Recording::STORED && $r->file_path !== null && Storage::disk(Recording::DISK)->exists($r->file_path), 404);
        DB::table('freelance_consultation_recording_views')->insert([
            'recording_id' => $r->id, 'viewer_type' => $viewer, 'viewer_id' => $viewerId, 'action' => $action, 'created_at' => now(),
        ]);

        return Storage::disk(Recording::DISK)->path($r->file_path);
    }

    /** A link to one part, good for a few minutes, that knows who asked for it. */
    public function link(Recording $r, string $viewer, int $viewerId, string $action = 'download'): string
    {
        // Signed on the path only, so an app that swaps the host (a local server's name) still opens it.
        return url(URL::temporarySignedRoute('api.freelance.consultation.recording', now()->addMinutes((int) $this->cfg('link_minutes')),
            ['recording' => $r->uuid, 'viewer' => $viewer, 'viewer_id' => $viewerId, 'action' => $action], absolute: false));
    }

    /** Holds the recordings while there is a complaint (null = not deleted on time). */
    public function hold(Consultation $c): void
    {
        $c->forceFill(['recordings_delete_at' => null])->save();
    }

    /** After the staff's decision, the recordings go a week later. */
    public function releaseAfterDecision(Consultation $c): void
    {
        if ($c->recordings_deleted_at === null) {
            $c->forceFill(['recordings_delete_at' => now()->addDays((int) $this->cfg('keep_days'))])->save();
        }
    }

    // -------------------------------------------------------------- the clock

    /** Every minute: warns before the end, closes rooms whose time is over, follows LiveKit's files, moves them home, deletes old ones. */
    public function sync(): array
    {
        $done = ['warned' => 0, 'closed' => 0, 'started' => 0, 'saved' => 0, 'moved' => 0, 'deleted' => 0];
        $warn = (int) $this->cfg('warn_before_end_minutes');

        Consultation::query()->where('status', Consultation::BOOKED)->whereNotNull('session_opened_at')->whereNull('session_closed_at')->whereNull('ending_told_at')
            ->whereBetween('ends_at', [now(), now()->addMinutes($warn)])->get()
            ->each(function (Consultation $c) use (&$done, $warn): void {
                $c->forceFill(['ending_told_at' => now()])->save();
                foreach (self::SIDES as $side) {
                    $this->consultations->tell($c, $side, 'consultation.session_ending', ['minutes' => $warn]);
                }
                $done['warned']++;
            });

        Consultation::query()->whereNotNull('room_name')->whereNull('session_closed_at')->where('ends_at', '<=', now())->get()
            ->each(function (Consultation $c) use (&$done): void {
                $this->close($c, 'time');
                $done['closed']++;
            });

        // Someone is in the room but nothing records (LiveKit did not answer, or the bucket was set up since): try again.
        Consultation::query()->where('status', Consultation::BOOKED)->whereNotNull('room_name')->whereNull('session_closed_at')->where('ends_at', '>', now())
            ->whereHas('attendance', fn ($q) => $q->whereNull('left_at'))
            ->whereDoesntHave('recordings', fn ($q) => $q->where('status', Recording::RECORDING))->get()
            ->each(function (Consultation $c) use (&$done): void {
                $done['started'] += (int) $this->ensureRecording($c);
            });

        Recording::query()->whereIn('status', [Recording::RECORDING, Recording::PROCESSING])->lazyById()
            ->each(function (Recording $r) use (&$done): void {
                $done['saved'] += (int) $this->follow($r);
            });

        Recording::query()->where('status', Recording::MOVING)->lazyById()
            ->each(function (Recording $r) use (&$done): void {
                $done['moved'] += (int) $this->move($r);
            });

        Consultation::query()->whereNotNull('recordings_delete_at')->whereNull('recordings_deleted_at')->where('recordings_delete_at', '<=', now())
            ->where('status', '!=', Consultation::DISPUTED)->get()
            ->each(function (Consultation $c) use (&$done): void {
                $done['deleted'] += (int) $this->purge($c);
            });

        return $done;
    }

    // -------------------------------------------------------------- presenting

    /**
     * What the consultation screen shows about the session, for one side (or the staff).
     *
     * @param  'client'|'freelancer'|'staff'  $side
     * @return array<string, mixed>
     */
    public function presentFor(Consultation $c, string $side, ?int $viewerId = null): array
    {
        $opensAt = $c->starts_at->copy()->subMinutes((int) $this->cfg('open_before_minutes'));
        $open = $c->status === Consultation::BOOKED && $c->session_closed_at === null && now()->gte($opensAt) && now()->lt($c->ends_at);
        $stays = $c->relationLoaded('attendance') ? $c->attendance : $c->attendance()->orderBy('id')->get();
        $parts = $c->recordings()->whereNot('status', Recording::FAILED)->orderBy('part')->orderBy('id')->get();
        $viewerId ??= $side === 'client' ? (int) $c->client_id : (int) $c->freelancer_id;
        $freelancerCame = $stays->contains('side', 'freelancer');
        $noShowAt = $c->starts_at->copy()->addMinutes((int) $this->cfg('no_show_minutes'));

        return [
            'available' => $this->available(),
            'room_open' => $open && $this->available(),
            'opens_at' => $opensAt->toIso8601String(),
            'starts_at' => $c->starts_at->toIso8601String(),
            'ends_at' => $c->ends_at->toIso8601String(),
            'closed' => $c->session_closed_at === null ? null : ['by' => $c->session_closed_by, 'at' => $c->session_closed_at->toIso8601String()],
            'recorded' => true,
            'recording_available' => $this->recordingAvailable(),
            'consent' => ['client' => $c->recording_consent_at?->toIso8601String(), 'freelancer' => $c->freelancer_consent_at?->toIso8601String()],
            'in_room' => collect(self::SIDES)->mapWithKeys(fn (string $s) => [$s => $stays->where('side', $s)->whereNull('left_at')->isNotEmpty()])->all(),
            'attendance' => $stays->map(fn (Attendance $a) => ['side' => $a->side, 'joined_at' => $a->joined_at->toIso8601String(), 'left_at' => $a->left_at?->toIso8601String()])->values()->all(),
            'recordings' => $parts->map(fn (Recording $r) => [
                'uuid' => $r->uuid, 'part' => $r->part, 'status' => $r->status, 'duration_seconds' => $r->duration_seconds, 'size_bytes' => $r->size_bytes,
                'started_at' => $r->started_at?->toIso8601String(), 'stopped_at' => $r->stopped_at?->toIso8601String(),
                'download_url' => $r->status === Recording::STORED ? $this->link($r, $side, $viewerId, 'download') : null,
                'watch_url' => $r->status === Recording::STORED ? $this->link($r, $side, $viewerId, 'watch') : null,
            ])->values()->all(),
            'recordings_delete_at' => $c->recordings_delete_at?->toIso8601String(),
            'recordings_held' => $c->status === Consultation::DISPUTED && $c->recordings_deleted_at === null && $parts->isNotEmpty(),
            'recordings_deleted_at' => $c->recordings_deleted_at?->toIso8601String(),
            'can' => [
                'join' => $open && $this->available() && in_array($side, self::SIDES, true),
                'no_show' => $side === 'client' && $c->status === Consultation::BOOKED && now()->gte($noShowAt) && ! $freelancerCame,
                'close' => $side === 'staff' && $c->room_name !== null && $c->session_closed_at === null,
                'reopen' => $side === 'staff' && $c->session_closed_by === 'staff' && now()->lt($c->ends_at) && $c->status === Consultation::BOOKED,
            ],
        ];
    }

    /** @return list<array<string, mixed>> who took which part, newest first (the staff) */
    public function views(Consultation $c): array
    {
        return DB::table('freelance_consultation_recording_views as v')->join('freelance_consultation_recordings as r', 'r.id', '=', 'v.recording_id')
            ->where('r.consultation_id', $c->id)->orderByDesc('v.id')->limit(200)
            ->get(['r.part', 'v.viewer_type', 'v.viewer_id', 'v.action', 'v.created_at'])
            ->map(fn ($v) => ['part' => (int) $v->part, 'viewer' => $v->viewer_type, 'viewer_id' => (int) $v->viewer_id, 'action' => $v->action, 'at' => $v->created_at === null ? null : \Illuminate\Support\Carbon::parse($v->created_at, 'UTC')->toIso8601String()])->all();
    }

    // -------------------------------------------------------------- inside

    private function assertOpen(Consultation $c): void
    {
        if ($c->status !== Consultation::BOOKED) {
            throw new FreelanceException('consultation_session_not_booked', 409);
        }
        if ($c->session_closed_by === 'staff' && $c->session_closed_at !== null) {
            throw new FreelanceException('consultation_session_closed', 409);
        }
        $opensAt = $c->starts_at->copy()->subMinutes((int) $this->cfg('open_before_minutes'));
        if (now()->lt($opensAt)) {
            throw new FreelanceException('consultation_session_not_yet', 409, ['minutes' => (int) $this->cfg('open_before_minutes')], ['opens_at' => $opensAt->toIso8601String()]);
        }
        if (now()->gte($c->ends_at) || $c->session_closed_at !== null) {
            throw new FreelanceException('consultation_session_over', 409);
        }
    }

    /** The room's name, made at LiveKit (two people at most) on the first ticket. */
    private function room(Consultation $c): string
    {
        if ($c->room_name !== null) {
            return $c->room_name;
        }
        $room = 'consult-'.$c->uuid;
        $this->rooms->create($room, 2, (int) $this->cfg('empty_timeout_seconds'));
        Consultation::query()->whereKey($c->id)->whereNull('room_name')->update(['room_name' => $room]);

        return $room;
    }

    /** @return array{side: string, name: ?string, in_room: bool} */
    private function otherSide(Consultation $c, string $side): array
    {
        $other = $side === 'client' ? 'freelancer' : 'client';
        $person = $other === 'client' ? $c->client : $c->freelancer;

        return ['side' => $other, 'name' => $person?->name, 'in_room' => Attendance::query()->where('consultation_id', $c->id)->where('side', $other)->whereNull('left_at')->exists()];
    }

    /** Starts a part when nothing records. Without the bucket the session runs unrecorded (and says so). */
    private function ensureRecording(Consultation $c): bool
    {
        if (! $this->recordingAvailable() || $c->room_name === null) {
            return false;
        }
        $recording = DB::transaction(function () use ($c): ?Recording {
            Consultation::query()->whereKey($c->id)->lockForUpdate()->first();
            if (Recording::query()->where('consultation_id', $c->id)->where('status', Recording::RECORDING)->exists()) {
                return null;
            }

            return Recording::query()->create([
                'consultation_id' => $c->id, 'part' => (int) Recording::query()->where('consultation_id', $c->id)->max('part') + 1,
                'status' => Recording::RECORDING, 'started_at' => now(),
            ]);
        });
        if ($recording === null) {
            return false;
        }
        $key = trim((string) $this->cfg('prefix'), '/').'/'.$c->uuid.'/'.$recording->uuid.'.mp4';
        $info = $this->egress->startRoomComposite($c->room_name, false, $key, $this->storage->egressUpload());
        $id = $info !== null ? LiveKitEgress::id($info) : null;
        $recording->update($id !== null
            ? ['egress_id' => $id, 'bucket_key' => $key]
            : ['status' => Recording::FAILED, 'error' => 'egress_start', 'stopped_at' => now()]);

        return $id !== null;
    }

    private function stopRecording(Consultation $c): void
    {
        Recording::query()->where('consultation_id', $c->id)->where('status', Recording::RECORDING)->get()
            ->each(function (Recording $r): void {
                $r->update(['status' => Recording::PROCESSING, 'stopped_at' => now()]);
                if ($r->egress_id !== null) {
                    $this->egress->stop($r->egress_id);
                }
            });
    }

    /** The room's time is over (or the staff closed it): out go the people, the recording stops, the stays end, the week starts. */
    private function close(Consultation $c, string $by): void
    {
        $c->forceFill(['session_closed_at' => now(), 'session_closed_by' => $by])->save();
        if ($c->room_name !== null) {
            $this->rooms->delete($c->room_name);
        }
        $this->stopRecording($c);
        $end = $by === 'time' ? $c->ends_at : now();
        Attendance::query()->where('consultation_id', $c->id)->whereNull('left_at')->update(['left_at' => $end, 'updated_at' => now()]);
        if ($by === 'time' && $c->recordings_deleted_at === null) {
            $c->forceFill(['recordings_delete_at' => $c->status === Consultation::DISPUTED ? null : $c->ends_at->copy()->addDays((int) $this->cfg('keep_days'))])->save();
        }
    }

    /** What LiveKit says about a part: still going, saved (then it moves home), or failed. */
    private function follow(Recording $r): bool
    {
        if ($r->egress_id === null) {
            if ($r->updated_at?->lte(now()->subHours(6))) {
                $r->update(['status' => Recording::FAILED, 'error' => 'egress_lost', 'stopped_at' => $r->stopped_at ?? now()]);

                return true;
            }

            return false;
        }
        $info = $this->egress->info($r->egress_id);
        if ($info === null) {
            return false;
        }
        $status = LiveKitEgress::status($info);
        if (in_array($status, ['EGRESS_STARTING', 'EGRESS_ACTIVE', 'EGRESS_ENDING'], true)) {
            return false;
        }
        $file = LiveKitEgress::file($info);
        if ($status === 'EGRESS_COMPLETE' || ($status === 'EGRESS_LIMIT_REACHED' && $file !== null)) {
            $r->update([
                'status' => Recording::MOVING, 'bucket_key' => $file['key'] ?? $r->bucket_key, 'size_bytes' => $file['size'] ?? null,
                'duration_seconds' => $file['seconds'] ?? null, 'stopped_at' => $r->stopped_at ?? now(),
            ]);
        } else {
            $r->update(['status' => Recording::FAILED, 'error' => mb_substr((string) ($info['error'] ?? $status), 0, 250), 'stopped_at' => $r->stopped_at ?? now()]);
        }

        return true;
    }

    /** Copies a saved part from the bucket to our private disk, then takes it off the bucket. Tried again every minute for a day. */
    private function move(Recording $r): bool
    {
        $path = 'freelance/consultation-recordings/'.$r->consultation?->uuid.'/'.$r->uuid.'.mp4';
        $full = Storage::disk(Recording::DISK)->path($path);
        try {
            File::ensureDirectoryExists(dirname($full));
            $response = Http::timeout(900)->sink($full)->get($this->storage->url((string) $r->bucket_key));
            if ($response->successful() && (! is_file($full) || filesize($full) === 0)) {
                // A client that hands the body back instead of writing it.
                file_put_contents($full, $response->body());
            }
            if (! $response->successful() || ! is_file($full) || filesize($full) === 0) {
                throw new \RuntimeException('download '.$response->status());
            }
        } catch (Throwable $e) {
            @unlink($full);
            Log::warning('[freelance] consultation recording move: '.$e->getMessage(), ['recording' => $r->uuid]);
            if ($r->stopped_at?->lte(now()->subDay())) {
                $r->update(['status' => Recording::FAILED, 'error' => 'move_failed']);
            }

            return false;
        }
        $this->storage->delete((string) $r->bucket_key);
        $r->update(['status' => Recording::STORED, 'file_path' => $path, 'size_bytes' => filesize($full) ?: $r->size_bytes, 'stored_at' => now(), 'bucket_key' => null]);
        $c = $r->consultation;
        if ($c !== null && ! Recording::query()->where('consultation_id', $c->id)->whereIn('status', [Recording::RECORDING, Recording::PROCESSING, Recording::MOVING])->exists()) {
            $until = $c->recordings_delete_at?->toDateString() ?? '—';
            foreach (self::SIDES as $side) {
                $this->consultations->tell($c, $side, 'consultation.recording_ready', ['date' => $until]);
            }
        }

        return true;
    }

    /** A week is over: the files go from our disk (and from the bucket if one is still there). */
    private function purge(Consultation $c): bool
    {
        $c->recordings()->get()->each(function (Recording $r): void {
            if ($r->file_path !== null) {
                Storage::disk(Recording::DISK)->delete($r->file_path);
            }
            if ($r->bucket_key !== null && $this->storage->configured()) {
                $this->storage->delete($r->bucket_key);
            }
            if ($r->status === Recording::RECORDING && $r->egress_id !== null) {
                $this->egress->stop($r->egress_id);
            }
            $r->update(['status' => Recording::DELETED, 'file_path' => null, 'bucket_key' => null]);
        });
        Storage::disk(Recording::DISK)->deleteDirectory('freelance/consultation-recordings/'.$c->uuid);
        $c->forceFill(['recordings_deleted_at' => now()])->save();

        return true;
    }

    private function cfg(string $key): mixed
    {
        return config('freelance.sessions.'.$key);
    }
}
