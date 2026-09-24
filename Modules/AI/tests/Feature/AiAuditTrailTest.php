<?php

namespace Modules\AI\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\AI\Models\AiAuditEvent;
use Modules\AI\Models\AiConversation;
use Modules\AI\Services\AiAuditTrail;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * v2.0 requirements doc S17.6 (audit trail for sensitive events, protected
 * from unauthorized modification). Proves three things: the ledger
 * actually gets written to by the real, non-mocked service (not just
 * "the class exists"), it is append-only at the model level (a tamper
 * attempt fails loudly rather than silently succeeding), and a failure to
 * write an audit row never breaks the operation being audited.
 */
class AiAuditTrailTest extends TestCase
{
    use RefreshDatabase;

    protected function makeUser(): User
    {
        return User::query()->create([
            'name' => 'Audit Test User',
            'email' => 'audit-'.uniqid().'@example.test',
            'password' => bcrypt('test-password-not-real'),
            'status' => true,
        ]);
    }

    public function test_record_writes_a_real_row_with_the_expected_fields(): void
    {
        $owner = $this->makeUser();

        app(AiAuditTrail::class)->record(
            AiAuditTrail::EVENT_DATA_EXPORTED,
            owner: $owner,
            metadata: ['conversation_count' => 3],
        );

        $this->assertDatabaseHas('ai_audit_events', [
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->id,
            'event_type' => AiAuditTrail::EVENT_DATA_EXPORTED,
            'severity' => 'info',
        ]);

        $event = AiAuditEvent::query()->latest('id')->first();
        $this->assertSame(3, $event->metadata['conversation_count']);
    }

    public function test_the_event_row_cannot_be_updated_or_deleted_once_written(): void
    {
        $owner = $this->makeUser();

        app(AiAuditTrail::class)->record(AiAuditTrail::EVENT_DATA_ERASED, owner: $owner, severity: 'high');

        $event = AiAuditEvent::query()->latest('id')->first();

        $this->expectException(\LogicException::class);
        $event->update(['severity' => 'info']);
    }

    public function test_deleting_an_audit_event_throws(): void
    {
        $owner = $this->makeUser();
        app(AiAuditTrail::class)->record(AiAuditTrail::EVENT_DATA_ERASED, owner: $owner, severity: 'high');
        $event = AiAuditEvent::query()->latest('id')->first();

        $this->expectException(\LogicException::class);
        $event->delete();
    }

    public function test_export_owner_data_writes_an_audit_event(): void
    {
        $owner = $this->makeUser();
        AiConversation::query()->create([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->id,
            'title' => 'Export audit test',
        ]);

        app(\Modules\AI\Services\AiChatService::class)->exportOwnerData($owner);

        $this->assertDatabaseHas('ai_audit_events', [
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->id,
            'event_type' => AiAuditTrail::EVENT_DATA_EXPORTED,
        ]);
    }

    public function test_erase_owner_data_writes_a_high_severity_audit_event(): void
    {
        $owner = $this->makeUser();
        AiConversation::query()->create([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->id,
            'title' => 'Erase audit test',
        ]);

        app(\Modules\AI\Services\AiChatService::class)->eraseOwnerData($owner);

        $this->assertDatabaseHas('ai_audit_events', [
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->id,
            'event_type' => AiAuditTrail::EVENT_DATA_ERASED,
            'severity' => 'high',
        ]);
    }

    public function test_a_write_failure_is_swallowed_rather_than_breaking_the_caller(): void
    {
        // Simulate a broken audit sink (e.g. the table is unavailable) by
        // dropping it, then prove record() does not throw.
        \Illuminate\Support\Facades\Schema::drop('ai_audit_events');

        $owner = $this->makeUser();

        $this->assertNull(
            app(AiAuditTrail::class)->record(AiAuditTrail::EVENT_DATA_EXPORTED, owner: $owner),
        );
    }
}
