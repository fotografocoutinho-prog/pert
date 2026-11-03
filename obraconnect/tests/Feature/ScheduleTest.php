<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * O agendador tem de correr sem proc_open (desativado em muitos cPanel, incluindo o
 * de produção): nenhuma tarefa pode ser um Schedule::command(), que lança um processo.
 */
class ScheduleTest extends TestCase
{
    public function test_every_scheduled_task_runs_in_process(): void
    {
        $events = $this->app->make(Schedule::class)->events();

        $this->assertNotEmpty($events);

        foreach ($events as $event) {
            $this->assertInstanceOf(CallbackEvent::class, $event, "Tarefa com processo à parte: {$event->command}");
            $this->assertNotEmpty($event->description, 'Tarefas com withoutOverlapping() precisam de name()');
        }
    }

    public function test_queue_worker_is_scheduled_every_minute(): void
    {
        $names = collect($this->app->make(Schedule::class)->events())->pluck('description');

        $this->assertContains('obraconnect-fila', $names);
    }
}
