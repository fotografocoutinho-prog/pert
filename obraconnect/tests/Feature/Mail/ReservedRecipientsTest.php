<?php

namespace Tests\Feature\Mail;

use App\Mail\DropReservedRecipients;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\SentMessage;
use Tests\TestCase;

class ReservedRecipientsTest extends TestCase
{
    /** @return list<SentMessage> */
    private function sent(): array
    {
        return iterator_to_array(app('mailer')->getSymfonyTransport()->messages());
    }

    public function test_reserved_domains_never_receive_email(): void
    {
        config(['mail.default' => 'array']);

        Mail::raw('Olá', function (Message $m): void {
            $m->to(['ana@atlantico-demo.test', 'rui@empresa.pt'])->cc('x@example.com')->bcc('y@obra.invalid')->subject('Mista');
        });
        Mail::raw('Olá', fn (Message $m) => $m->to('helena@atlantico-demo.test')->subject('Só demo'));

        $sent = $this->sent();
        $this->assertCount(1, $sent);
        $email = $sent[0]->getOriginalMessage();
        $this->assertSame(['rui@empresa.pt'], array_map(fn ($a) => $a->getAddress(), $email->getTo()));
        $this->assertSame([], $email->getCc());
        $this->assertSame([], $email->getBcc());
    }

    public function test_recognises_reserved_domains(): void
    {
        foreach (['a@b.test', 'a@demo.obraconnect.test', 'a@example.com', 'a@mail.example.org', 'a@x.example', 'a@x.invalid', 'a@localhost'] as $email) {
            $this->assertTrue(DropReservedRecipients::isReserved($email), $email);
        }
        foreach (['a@coda.pt', 'a@gmail.com', 'a@testes.pt', 'a@example.pt', 'a@notexample.com'] as $email) {
            $this->assertFalse(DropReservedRecipients::isReserved($email), $email);
        }
    }
}
