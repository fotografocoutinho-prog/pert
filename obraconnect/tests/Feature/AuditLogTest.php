<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use LogicException;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    public function test_audit_logs_cannot_be_changed_or_deleted(): void
    {
        $this->createCompany();
        $log = AuditLog::query()->firstOrFail();

        try {
            $log->update(['event' => 'x']);
            $this->fail('Devia ter recusado a alteração.');
        } catch (LogicException) {
        }

        $this->expectException(LogicException::class);
        $log->delete();
    }

    public function test_company_only_sees_its_own_audit_trail(): void
    {
        [$ownerA, $a] = $this->createCompany('A');
        [, $b] = $this->createCompany('B');

        $this->actingAs($ownerA)->putJson('/api/v1/company', ['name' => 'A renomeada', 'country_code' => 'PT'])->assertOk();

        $logs = $this->actingAs($ownerA)->getJson('/api/v1/audit-logs')->assertOk()->json('data');

        $this->assertContains('company.updated', array_column($logs, 'event'));
        $this->assertSame(
            0,
            AuditLog::query()->where('tenant_id', $b->id)->where('event', 'company.updated')->count(),
        );
        $this->assertCount(
            AuditLog::query()->where('tenant_id', $a->id)->count(),
            $logs,
        );
    }

    public function test_secrets_are_never_written_to_the_audit_log(): void
    {
        [$owner] = $this->createCompany();
        $owner->forceFill(['password' => 'Segura2026obra'])->save();

        $this->actingAs($owner)->putJson('/auth/user/password', [
            'current_password' => 'Segura2026obra',
            'password' => 'NovaSegura2027',
            'password_confirmation' => 'NovaSegura2027',
        ])->assertOk();

        $all = AuditLog::query()->get()->toJson();
        $this->assertStringNotContainsString('NovaSegura2027', $all);
        $this->assertStringNotContainsString('Segura2026obra', $all);
    }
}
